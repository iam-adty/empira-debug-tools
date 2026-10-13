<?php

declare(strict_types=1);

namespace Empira\DebugTools;

use Clockwork\DataSource\DataSourceInterface;
use Clockwork\Helpers\Serializer;
use Clockwork\Request\Request as ClockworkRequest;
use Empira\Http\Request;
use Empira\Http\Response;
use Empira\Routing\Route;

/**
 * Reads an Empira request into a Clockwork one.
 *
 * Clockwork knows nothing about Empira, which is the whole reason it can be
 * wrapped rather than forked. Something still has to answer the two questions it
 * asks — what arrived, and what went back — and this is that something. The
 * alternative is an application reporting to Clockwork by hand, which is how
 * debug tooling ends up describing only what somebody remembered to describe.
 *
 * ## Payloads and credentials are opt-in
 *
 * `$body` and `$cookies` sit right there in the object being read, and both are
 * somebody's secrets. A profiler writes them to disk and then serves that disk
 * back over HTTP, which turns a debug tool into a credential store with a
 * convenient read API. So both default to off, per instance, because "we are
 * debugging right now" is precisely the moment nobody reads a diff.
 *
 * @see ClockworkMiddleware for why a data source is *not* enough on its own.
 */
final class EmpiraDataSource implements DataSourceInterface
{
    private ?Request $request = null;

    private ?Response $response = null;

    private ?Route $route = null;

    private float $startedAt = 0.0;

    private ?\Throwable $error = null;

    private string $errorMessage = '';

    /** Collect POST bodies. Off by default; see the class docblock. */
    public bool $collectPayloads = false;

    /** Collect cookies. Off by default; see the class docblock. */
    public bool $collectCookies = false;

    /**
     * Start reading, before the handler runs.
     *
     * The clock starts here rather than in the constructor because one instance
     * serves every request a process handles: a start time set at construction
     * would be the first request's start for all of them, and every duration
     * after that would be fiction. Under a worker that is not a rounding error,
     * it is a number that grows for the life of the process.
     */
    public function begin(Request $request, ?Route $route = null): void
    {
        $this->request = $request;
        $this->route = $route;
        $this->response = null;
        $this->error = null;
        $this->errorMessage = '';
        $this->startedAt = microtime(true);
    }

    /**
     * Record what went back, once the handler has produced it.
     */
    public function withResponse(Response $response): void
    {
        $this->response = $response;
    }

    /**
     * Record a failure, for a request that never got as far as a response.
     *
     * Puts the message on both Clockwork's log and its timeline: the log is what
     * the UI's error panel reads, and the timeline is what the waterfall shows,
     * and a failure that appears in one but not the other looks like two
     * different things happened.
     */
    public function logError(\Throwable $e): void
    {
        $this->error = $e;

        if ($this->request === null) {
            return;
        }

        $this->errorMessage = sprintf('%s: %s', $e::class, $e->getMessage());
    }

    public function resolve(ClockworkRequest $request): ClockworkRequest
    {
        if ($this->request === null) {
            return $request;
        }

        $empira = $this->request;

        $request->method = $empira->method;
        $request->url = $empira->fullUrl();
        $request->uri = $empira->path();
        $request->headers = $empira->headers();
        $request->getData = $this->normalise($empira->query);
        $request->controller = $this->route?->name;

        if ($this->collectPayloads) {
            $request->postData = $this->normalise($empira->body);
        }

        if ($this->collectCookies) {
            $request->cookies = $empira->cookies;
        }

        if ($this->response !== null) {
            $request->responseStatus = $this->response->status();
        }

        // `responseTime` is when the handler finished. `responseDuration` is set
        // explicitly rather than left to Clockwork's `getResponseDuration()`,
        // which subtracts the `Request` object's own construction time — and
        // that object is built in `Profiler::record()`, *after* the handler has
        // run, so its idea of when the request started is when the recording
        // started. Left alone, a handler that took five milliseconds would be
        // reported as having taken the time it takes to serialise itself.
        //
        // Milliseconds, because that is what Clockwork's own computation
        // produces; converting again here would report a thousandfold.
        $request->responseTime = microtime(true);
        $request->responseDuration = ($request->responseTime - $this->startedAt) * 1000;
        $request->memoryUsage = memory_get_peak_usage(true);

        if ($this->error !== null) {
            // On both surfaces, because they are read in different places and a
            // failure shown in only one of them reads as two unrelated things
            // having happened: the log is the interface's error panel, the
            // timeline is the waterfall it shows.
            $request->log()->error($this->errorMessage, [
                'exception' => $this->error,
                'file' => $this->error->getFile(),
                'line' => $this->error->getLine(),
            ]);

            $request->timeline()->event('Request failed', [
                'message' => $this->errorMessage,
                'exception' => $this->error::class,
            ]);
        }

        $this->timeline($request, $empira);

        return $request;
    }

    /**
     * Nothing to add. Everything this source knows it already put on the
     * request; Clockwork calls `extend()` for enrichment and there is none to
     * give. Returning it unchanged is the honest answer, and implementing this
     * as a no-op is better than not implementing the interface at all — which is
     * how data sources end up silently skipped.
     */
    public function extend(ClockworkRequest $request): ClockworkRequest
    {
        return $request;
    }

    public function reset(): void
    {
        $this->request = null;
        $this->response = null;
        $this->route = null;
        $this->error = null;
        $this->errorMessage = '';
        $this->startedAt = 0.0;
    }

    /**
     * The two events worth having whether or not a handler was reached: when the
     * request entered, and what it matched.
     *
     * Under a worker the entry time is the only way to see that a route's
     * duration was mostly spent waiting for the previous request's session lock
     * to clear, which is invisible in a per-request number.
     */
    private function timeline(ClockworkRequest $clockwork, Request $empira): void
    {
        if ($this->startedAt > 0.0) {
            $clockwork->timeline()->event('Request received', [
                'absolute' => $this->startedAt,
                'bootstrap' => true,
            ]);
        }

        $clockwork->timeline()->event('Matched', [
            'method' => $empira->method,
            'path' => $empira->path(),
            'route' => $this->route?->name,
            'client' => $empira->ip(),
        ]);

        if ($this->response !== null) {
            $headers = $this->response->headers();
            $contentType = '';

            foreach ($headers as $name => $value) {
                if (strcasecmp($name, 'content-type') === 0) {
                    $contentType = $value;
                    break;
                }
            }

            $clockwork->timeline()->event('Response sent', [
                'status' => $this->response->status(),
                'content-type' => $contentType,
                'memory' => memory_get_peak_usage(true),
            ]);
        }
    }

    /**
     * Clockwork serialises these to JSON. A query string carrying an object or a
     * closure produces a file that cannot be stored and a UI that cannot open it,
     * so the values have to be made plain before they get that far. Clockwork
     * already has a normaliser written for exactly this; a second set of rules
     * here would be one more thing to keep in step with theirs.
     *
     * @return array<array-key, mixed>
     */
    private function normalise(mixed $data): array
    {
        return (new Serializer)->normalize($data);
    }
}