<?php

declare(strict_types=1);

namespace Empira\DebugTools;

use Clockwork\Helpers\ServerTiming;
use Closure;
use Empira\Http\Middleware;
use Empira\Http\Request;
use Empira\Http\Response;
use Empira\Routing\RouteCollection;

/**
 * Records every request through the profiler.
 *
 * ## Why a middleware, and why this one
 *
 * A profile is only complete once the response exists, and a request that has
 * not been answered has no status, no duration, and nothing to show the person
 * debugging it. So this wraps the whole request rather than hooking the handler:
 * it starts the clock on the way in and reads the response on the way out.
 *
 * ## The failure case
 *
 * The application catches handler exceptions *outside* this pipeline, so a
 * throw from `$next()` arrives here rather than being rendered by the time we
 * see it. Left alone, that would skip recording entirely and leave the one
 * request you most wanted to look at unrecorded — which is the request you
 * always most want to look at. So the throwable is recorded and rethrown, and
 * the response gets the id of a profile that says what broke.
 */
final class ClockworkMiddleware implements Middleware
{
    public function __construct(
        private readonly Profiler $profiler,
        private readonly RouteCollection $routes,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->profiler->isEnabled()) {
            return $next($request);
        }

        $this->profiler->source()->begin($request, $this->matchRoute($request));

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->profiler->source()->logError($e);

            // Recorded, then re-thrown untouched. No headers here: there is no
            // response yet to put them on, and the application's error renderer
            // runs outside this pipeline and does not know this module exists.
            // The profile exists and names the failure, which is what matters.
            $this->profiler->record();

            throw $e;
        }

        $this->profiler->source()->withResponse($response);

        $id = $this->profiler->record();

        return $this->addBar($request, $this->withHeaders($response, $id), $id);
    }

    /**
     * Add the in-page bar, when there is a page to add it to.
     *
     * Three things have to be true, and each exclusion is deliberate:
     *
     * - **HTML.** A bar in a JSON body is not a bar, it is a corrupted
     *   response.
     * - **Not an htmx swap.** htmx replaces one region, and the response has no
     *   `</body>` to append to; injecting anyway would produce a stray bar in a
     *   fragment or nothing at all.
     * - **A body tag exists.** A fragment has no closing body, and appending to
     *   the end of one would put markup inside someone else's element.
     *
     * The numbers travel in the page rather than in a cookie:
     * {@see Response::cookie()} replaces the `Set-Cookie` header instead of
     * appending, so this running last in the pipeline would overwrite whatever
     * cookie the application set while dispatching — a failure that would only
     * ever show up on pages being debugged.
     */
    private function addBar(Request $request, Response $response, ?string $id): Response
    {
        if ($id === null || $request->isHtmx() || !$this->isHtml($response)) {
            return $response;
        }

        if (!$this->profiler->isToolbarEnabled()) {
            return $response;
        }

        $body = $response->body();
        $at = strripos($body, '</body>');

        if ($at === false) {
            return $response;
        }

        $summary = $this->profiler->summary($id);

        if ($summary === null) {
            return $response;
        }

        // `JSON_HEX_TAG` matters here: the payload is embedded in a document, and
        // a route name or URL containing `<` would otherwise close the element
        // the data lives in.
        $island = sprintf(
            '<script type="application/json" id="%s">%s</script>',
            DebugController::BAR_ID,
            json_encode($summary, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES),
        );

        $markup = $island
            . sprintf('<link rel="stylesheet" href="%s">', DebugController::TOOLBAR_CSS)
            . sprintf('<script src="%s" defer></script>', DebugController::TOOLBAR_JS);

        return $response->setBody(substr($body, 0, $at) . $markup . substr($body, $at));
    }

    /**
     * Whether this response is a page rather than an API answer.
     *
     * **An absent `Content-Type` counts as HTML**, which is the surprising half.
     * The framework sets none: a layout-rendered page and a handler returning a
     * string both come back from `toResponse()` with the header unset, so
     * requiring `text/html` would mean the bar never appeared on the pages it
     * exists for.
     *
     * That is safe because of the `</body>` check in {@see addBar()} — JSON has
     * no closing body tag, so a response that is not a page cannot be given a
     * bar even if it is admitted through here. The two checks together are
     * stricter than either alone: this one rejects an API that declares itself,
     * the other rejects one that does not.
     */
    private function isHtml(Response $response): bool
    {
        foreach ($response->headers() as $name => $value) {
            if (strcasecmp($name, 'content-type') !== 0) {
                continue;
            }

            $type = strtolower($value);

            return str_contains($type, 'text/html') || str_contains($type, 'application/xhtml');
        }

        return true;
    }

    /**
     * The matched route, for its name.
     *
     * The pipeline hands middleware a `Request` and nothing else — no route, no
     * match — so the table is asked directly. A path with no match resolves to
     * null, which is the right answer for a 404 rather than a reason to guess.
     */
    private function matchRoute(Request $request): ?\Empira\Routing\Route
    {
        return $this->routes->match($request->method, $request->path())?->route;
    }

    /**
     * Add the headers the browser UI reads to find its own data.
     *
     * `Server-Timing` is the one that pays for itself: browsers surface it in
     * the network panel for every page load, so the profiler is visible without
     * being visited.
     */
    private function withHeaders(Response $response, ?string $id): Response
    {
        if ($id === null) {
            return $response;
        }

        return $response
            ->withHeader('X-Clockwork-Id', $id)
            ->withHeader('X-Clockwork-Version', \Clockwork\Clockwork::VERSION)
            ->withHeader('X-Clockwork-Path', DebugController::PATH)
            ->withHeader(
                'Server-Timing',
                ServerTiming::fromRequest($this->profiler->clockwork()->request())->value(),
            );
    }
}