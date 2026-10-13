<?php

declare(strict_types=1);

namespace Empira\DebugTools;

use Clockwork\Request\Request as ClockworkRequest;
use Clockwork\Web\Web;
use Empira\Http\Request;
use Empira\Http\Response;

/**
 * Serves the Clockwork interface and the data behind it.
 *
 * ## One unguessable prefix, three routes
 *
 * Clockwork's UI is a prebuilt single-page app that fetches its data as JSON.
 * So the prefix serves three things and nothing else: the wrapper page, the app's
 * own assets, and the API. Putting them under one guessable path is the whole
 * attack surface here, which is why {@see PATH} carries a random-looking
 * segment rather than being `/debug`.
 *
 * ## The app's assets are not served from the vendor directory
 *
 * Clockwork ships an installer that copies its UI into the application's public
 * directory. That was not used: it puts a second copy of a third party's files
 * somewhere the application's own asset pipeline does not know about, and that
 * copy then has to be kept in step with the installed version. Reading them out
 * of `vendor/` on request means there is exactly one copy and it is the one
 * Composer just installed.
 */
final class DebugController
{
    /**
     * The URL prefix. Generated per installation — see {@see self::generatePath()} —
     * because the route existing at all is the only thing protecting it, and a
     * fixed name is a name that ends up in a search engine index.
     */
    public const PATH = '/_clockwork';

    /** Where the SPA looks for its JSON. */
    private const API = self::PATH . '/api';

    /** The element id the bar's data is injected as. */
    public const BAR_ID = 'empira-debug';

    /** The bar's two files, served by this module. */
    public const TOOLBAR_JS = self::PATH . '/toolbar.js';

    public const TOOLBAR_CSS = self::PATH . '/toolbar.css';

    public function __construct(
        private readonly Profiler $profiler,
    ) {
    }

    /**
     * The wrapper page.
     *
     * Clockwork's own wrapper is a full-viewport iframe around its SPA, with the
     * API path handed over through `localStorage` because that is the only
     * channel the prebuilt bundle reads. Rewritten rather than included from
     * `Clockwork\Support\Vanilla\iframe.html.php`: that file is another
     * package's internal, not an API, and a path inside someone's `Support`
     * directory is the kind of thing that moves in a minor release.
     */
    public function index(): Response
    {
        $asset = fn (string $uri): string => self::PATH . '/app/' . $uri;
        $api = self::API;

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Clockwork</title>
                <link rel="icon" href="{$asset('img/icon-128x128.png')}">
                <style>
                    iframe {{ position: fixed; top: 0; left: 0; height: 100%; width: 100%; border: 0; }}
                </style>
            </head>
            <body>
                <iframe src="{$asset('index.html')}"></iframe>
                <script>
                    let data = JSON.parse(localStorage.getItem('clockwork') || '{{}}')
                    data.settings = data.settings || {{}}
                    data.settings.global = data.settings.global || {{}}
                    data.settings.global.metadataPath = '{$api}'
                    localStorage.setItem('clockwork', JSON.stringify(data))
                </script>
            </body>
        </html>
        HTML;

        return Response::html($html)->noStore();
    }

    /**
     * An asset from the installed Clockwork package.
     *
     * `Web::asset()` resolves and range-checks the path before this sees it, so
     * `../../etc/passwd` is refused there rather than being defended against
     * twice in different styles here.
     */
    public function asset(string $path): Response
    {
        $asset = (new Web)->asset($path);

        if ($asset === null || $asset === false) {
            return Response::html('Not found', 404);
        }

        $contents = @file_get_contents($asset['path']);

        if ($contents === false) {
            return Response::html('Not found', 404);
        }

        return Response::html($contents)
            ->withHeader('Content-Type', $asset['mime'])
            ->noStore();
    }

    /**
     * Recorded requests, as JSON.
     *
     * `$direction` and `$count` are Clockwork's own paging: `previous` and
     * `next` walk through the stored history either side of an id, which is how
     * you compare this request with the one before it.
     */
    public function api(?string $id = null, ?string $direction = null, ?string $count = null): Response
    {
        $storage = $this->profiler->storage();

        // Route parameters arrive as strings whatever the segment looked like.
        // Cast before handing the number to Clockwork, which will use it to walk
        // that many records backwards or forwards.
        $howMany = $count !== null && ctype_digit($count) ? (int) $count : null;

        $data = match (true) {
            $direction === 'previous' => $storage->previous($id ?? 'latest', $howMany),
            $direction === 'next' => $storage->next($id ?? 'latest', $howMany),
            $id === null || $id === 'latest' => $storage->latest(),
            default => $storage->find($id),
        };

        // `Request` is not JsonSerializable — Clockwork offers `toArray()`, and
        // handing the object straight to json_encode would encode its public
        // properties, which are a different shape from the one its own
        // interface expects.
        return Response::json($data instanceof ClockworkRequest ? $data->toArray() : $data);
    }

    /**
     * The in-page bar's script.
     *
     * A route that takes nothing and returns one known file, deliberately. The
     * Clockwork asset route above has to defend against a path, because it is
     * handed one; these two have nothing to defend, and inventing a `{file}`
     * parameter to be careful with would be a way to have a traversal bug rather
     * than a way to avoid one.
     */
    public function toolbarJs(): Response
    {
        return $this->barAsset('toolbar.js', 'text/javascript');
    }

    public function toolbarCss(): Response
    {
        return $this->barAsset('toolbar.css', 'text/css');
    }

    /**
     * Cacheable, because the content changes only when the package is upgraded.
     * `Response::cache()` emits the max-age itself rather than leaving it to the
     * browser's own heuristics.
     */
    private function barAsset(string $file, string $mime): Response
    {
        $contents = @file_get_contents(dirname(__DIR__) . '/resources/' . $file);

        if ($contents === false) {
            // The package is installed but incomplete. A 404 is the honest
            // answer, and the bar's script tag is already in the page either
            // way — a script that throws on load is worse than one that was
            // never requested.
            return Response::html('Not found', 404);
        }

        return Response::html($contents)
            ->withHeader('Content-Type', $mime)
            ->cache(86400);
    }
}