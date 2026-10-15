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
 * Records every request through the profiler, and puts {@see Debug} in reach of
 * the application while doing it.
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

        // The developer-facing `Debug` facade writes here, and only for the
        // duration of this request. Set after `begin()` so a mark cannot land on
        // a profile that has not started, and cleared in the `finally` below so a
        // mark can never survive into the next request — under a worker that would
        // be one request annotating another, which is worse than losing it.
        Debug::activate($this->profiler->marks());

        try {
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

            return $this->withHeaders($response, $this->profiler->record());
        } finally {
            Debug::deactivate();
        }
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
     * Add the headers a Clockwork client reads to find this request's data.
     *
     * This is the whole integration with the Chrome extension, and
     * `X-Clockwork-Path` is the part that is easy to get wrong. A client parses
     * these three headers for `{id, path, version}` and then asks for
     * `{path}/{id}` — and the path is the **base the data hangs off**, not the
     * prefix the interface is mounted at. That is now {@see DebugController::PATH},
     * which is Clockwork's own prefix, so a client that reads this header and one
     * that falls back to constructing `/__clockwork/…` itself reach the same URL.
     *
     * `Server-Timing` is the one that pays for itself: browsers surface it in
     * the network panel for every page load, so the profiler is visible without
     * anything being opened.
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