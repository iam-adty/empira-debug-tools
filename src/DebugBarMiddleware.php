<?php

declare(strict_types=1);

namespace Empira\DebugTools;

use Empira\Http\Middleware;
use Empira\Http\Request;
use Empira\Http\Response;
use Empira\Routing\RouteCollection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Records every request through the profiler, puts {@see Debug} in reach of the
 * application while doing it, and injects the toolbar into the page on the way out.
 *
 * ## Why a middleware, and why this one
 *
 * A profile is only complete once the response exists, and a request that has
 * not been answered has no status, no duration, and nothing to show the person
 * debugging it. So this wraps the whole request rather than hooking the handler:
 * it starts the clock on the way in and reads the response on the way out.
 *
 * ## This module injects JavaScript, and that is a change of contract
 *
 * Clockwork was headless — the interface was a browser extension reading headers,
 * and this package's responses were byte-identical to the ones its handlers
 * produced. DebugBar's interface is an iframe of its own, which has to be
 * *delivered*, so this middleware rewrites HTML bodies.
 *
 * "Full documents only" is therefore not a nicety, it is the correctness
 * condition. See {@see shouldInject()}.
 *
 * ## The failure case
 *
 * The application catches handler exceptions *outside* this pipeline, so a
 * throw from `$next()` arrives here rather than being rendered by the time we
 * see it. Left alone, that would skip recording entirely and leave the one
 * request you most wanted to look at unrecorded — which is the request you
 * always most want to look at. So the throwable is recorded and rethrown.
 */
final class DebugBarMiddleware implements Middleware
{
    public function __construct(
        private readonly Profiler $profiler,
        private readonly RouteCollection $routes,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->profiler->isEnabled()) {
            return $handler->handle($request);
        }

        // Narrowed, not typed: PSR-15 fixes the parameter at the interface and
        // PHP will not let it be narrower. An Empira pipeline always passes one.
        $empira = Request::of($request);

        $this->profiler->collector()->begin($empira, $this->matchRoute($empira));

        // The developer-facing `Debug` facade writes here, and only for the
        // duration of this request. Set after `begin()` so a mark cannot land on
        // a profile that has not started, and cleared in the `finally` below so a
        // mark can never survive into the next request — that would be one request
        // annotating another, which is worse than losing it.
        Debug::activate($this->profiler->marks());

        try {
            try {
                $response = $handler->handle($empira);
            } catch (\Throwable $e) {
                $this->profiler->collector()->logError($e);

                // Recorded, then re-thrown untouched. There is no response yet to
                // inject into, and the application's error renderer runs outside
                // this pipeline and does not know this module exists. The profile
                // exists and names the failure, which is what matters.
                $this->profiler->record();

                throw $e;
            }

            $this->profiler->collector()->withResponse($response);
            $this->profiler->record();

            return $this->inject($empira, $response);
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
     * Put the toolbar in the page, when the page is a whole page.
     *
     * Returns a **new** response rather than editing one in place: `Response` is
     * immutable, so `withHtml()` hands back a modified copy and the one the
     * handler produced is left exactly as it was. That is better than the
     * alternative this used to take — mutating a response another layer might
     * still be holding — but it means the result has to be returned rather than
     * discarded.
     */
    private function inject(Request $request, Response $response): Response
    {
        if (!$this->shouldInject($request, $response)) {
            return $response;
        }

        $renderer = $this->profiler->debugBar()->getJavascriptRenderer(DebugBarController::ASSETS);

        $body = $response->body();

        $head = $renderer->renderHead();
        $script = $renderer->render();

        // The stylesheet belongs in the head so the toolbar does not flash
        // unstyled, and the script before the closing body tag because that is
        // where the DOM it attaches to already exists. A document with a head and
        // no body closing tag is malformed and is left alone below.
        if (str_contains($body, '</head>')) {
            $body = str_replace('</head>', $head . "\n</head>", $body);
        } else {
            $body = $head . $body;
        }

        return $response->withHtml(str_replace('</body>', $script . "\n</body>", $body));
    }

    /**
     * Whether this response is a full document the toolbar may be added to.
     *
     * Three conditions, and each is load-bearing rather than cautious:
     *
     * - **Not an htmx request.** `HX-Request` is how a swap asks for a fragment.
     *   A toolbar appended to one would land inside the element being replaced,
     *   survive the swap, and be duplicated on the next one. This is the single
     *   reason the previous claim that this module injects nothing could be
     *   dropped without consequence.
     * - **Not a non-HTML content type.** A JSON or CSV response is not a
     *   document, and appending to one produces a body neither the client nor a
     *   parser can read.
     * - **A closing `body` tag.** Present in a document, absent in a partial, and
     *   absent from a streamed file because {@see Response::body()} is `''` for
     *   those — so files are excluded here rather than by a rule about streaming.
     *
     * ## An absent content type means HTML here, deliberately
     *
     * {@see Response::html()} sets **no** `Content-Type` header at all — the
     * framework only sets one where something has to be declared, as
     * `Response::json()` does. So the test cannot require `text/html`; on the
     * commonest HTML response there is no header to require. The content type is
     * therefore only allowed to *veto*, and the `</body>` check is what actually
     * establishes that this is a whole page.
     */
    private function shouldInject(Request $request, Response $response): bool
    {
        if ($request->header('HX-Request') !== null) {
            return false;
        }

        foreach ($response->headers() as $name => $value) {
            if (strcasecmp($name, 'content-type') === 0 && ! str_starts_with($value, 'text/html')) {
                return false;
            }
        }

        return str_contains($response->body(), '</body>');
    }
}