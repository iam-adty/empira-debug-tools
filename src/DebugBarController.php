<?php

declare(strict_types=1);

namespace Empira\DebugTools;

use DebugBar\AssetHandler;
use DebugBar\DebugBarException;
use DebugBar\OpenHandler;
use Empira\Http\Request;
use Empira\Http\Response;

/**
 * The two endpoints the toolbar talks to.
 *
 * ## Why the panel is served by JavaScript rather than by this controller
 *
 * DebugBar's interface is an iframe of its own, built from the CSS and
 * JavaScript this class hands it. There is no page here to render — the two
 * endpoints below return a stylesheet, a script, or JSON, and that is the whole
 * of what the module serves over HTTP.
 *
 * ## Why `echo` and `sendHeader` are both off
 *
 * `handle()` on either upstream handler writes headers with `header()` and prints
 * to stdout by default, because it was written to be the last thing in a
 * front controller that has given up control of the response. Empira has not: a
 * handler here returns a {@see Response} and the pipeline emits it, so letting
 * upstream write first would mean two writers racing for one response, and the
 * headers would be whatever `php_sapi` decided rather than what we chose.
 */
final class DebugBarController
{
    /**
     * Where the toolbar's assets live, and nothing else.
     *
     * DebugBar serves no HTML of its own, so there is no page to be wrong about
     * and no `{id}` to collide with — the `/api` shape Clockwork needed, and the
     * `[0-9-]+|latest` constraint that came with it, have no counterpart here.
     */
    public const PATH = '/__debugbar';

    /** The stylesheets and scripts the toolbar is built from. */
    public const ASSETS = self::PATH . '/assets';

    /** The JSON endpoint the toolbar reads stored and current data from. */
    public const OPEN_HANDLER = self::PATH . '/openhandler';

    public function __construct(
        private readonly Profiler $profiler,
    ) {
    }

    /**
     * `?type=css` or `?type=js` — every asset of that kind, concatenated.
     *
     * Upstream decides the content type from the parameter and there is no file
     * path in the request at all, so unlike an asset route that walks a directory
     * there is nothing here to traverse out of.
     */
    public function assets(Request $request): Response
    {
        $query = $request->query;

        try {
            $handler = new AssetHandler($this->profiler->debugBar());

            $contents = $handler->handle($query, echo: false, sendHeader: false);
        } catch (DebugBarException) {
            return Response::html('Not found', 404);
        }

        $type = $query['type'] ?? '';

        return Response::html($contents)
            ->withHeader('Content-Type', $type === 'css' ? 'text/css' : 'text/javascript')
            ->cache(86400);
    }

    /**
     * `?op=find|get|clear` — the toolbar's read, fetch and reset.
     *
     * Requires a storage backend, which is configured rather than optional: a
     * toolbar that could not reopen the request it was profiling would be worse
     * than one that never claimed to.
     */
    public function openHandler(Request $request): Response
    {
        $query = $request->query;

        try {
            $handler = new OpenHandler($this->profiler->debugBar());

            $body = $handler->handle($query, echo: false, sendHeader: false);
        } catch (DebugBarException) {
            return Response::html('Not found', 404);
        }

        return Response::html($body)
            ->withHeader('Content-Type', 'application/json')
            ->noStore();
    }
}