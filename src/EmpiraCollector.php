<?php

declare(strict_types=1);

namespace Empira\DebugTools;

use DebugBar\DataCollector\DataCollectorInterface;
use DebugBar\DataCollector\Resettable;
use Empira\Http\Request;
use Empira\Http\Response;
use Empira\Routing\Route;

/**
 * Reports an Empira request to DebugBar.
 *
 * DebugBar knows nothing about Empira, which is the whole reason it can be
 * wrapped rather than forked. Something still has to answer the two questions it
 * asks — what arrived, and what went back — and this is that something. The
 * alternative is an application reporting by hand, which is how debug tooling
 * ends up describing only what somebody remembered to describe.
 *
 * ## Why this replaces DebugBar's own RequestDataCollector
 *
 * `StandardDebugBar` registers one, and it collects `$_GET`, `$_POST` and
 * `$_COOKIE` **unconditionally**, straight from the superglobals. Those are
 * somebody's credentials: a session cookie and a password field, written to disk
 * and then served back over HTTP by a tool that is on by default in development.
 * That is precisely the credential store the two flags below exist to prevent,
 * and adopting it would have reintroduced the problem empira's design was written
 * to avoid.
 *
 * So this package assembles its own collector list and leaves that one out. What
 * arrives is described here instead, under the same opt-in rules.
 *
 * ## Payloads and credentials are opt-in
 *
 * `$collectPayloads` and `$collectCookies` default to off, per instance, because
 * "we are debugging right now" is exactly the moment nobody reads a diff. Set them
 * through {@see Profiler}'s `config` — `payloads` and `cookies`.
 *
 * @see DebugBarMiddleware for why a collector is *not* enough on its own.
 */
final class EmpiraCollector implements DataCollectorInterface, Resettable
{
    private ?Request $request = null;

    private ?Response $response = null;

    private ?Route $route = null;

    private float $startedAt = 0.0;

    private ?\Throwable $error = null;

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
     * after that would be fiction.
     */
    public function begin(Request $request, ?Route $route = null): void
    {
        $this->request = $request;
        $this->route = $route;
        $this->response = null;
        $this->error = null;
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
     * {@see Profiler} also puts this on the exceptions collector, which is what
     * DebugBar's own error tab reads.
     */
    public function logError(\Throwable $e): void
    {
        $this->error = $e;
    }

    /**
     * When this request entered, for the timeline's origin.
     */
    public function startedAt(): float
    {
        return $this->startedAt;
    }

    /**
     * The request's method, or null before {@see begin()}.
     *
     * Read by {@see EmpiraDebugBar} when it reports the request to DebugBar. They
     * are asked here rather than kept in a second place so the two cannot disagree
     * about which request is being profiled.
     */
    public function method(): ?string
    {
        return $this->request?->method;
    }

    /** The request's path, or null before {@see begin()}. */
    public function uri(): ?string
    {
        return $this->request?->path();
    }

    /** The client's address, or null before {@see begin()}. */
    public function ip(): ?string
    {
        return $this->request?->ip();
    }

    public function getName(): string
    {
        return 'empira';
    }

    /**
     * What arrived and what went back, as DebugBar collects it.
     *
     * Durations are in **seconds**, because that is what DebugBar's own
     * `TimeDataCollector` works in and what its formatter expects. Converting
     * here would report a thousandfold of the real number in a table sitting
     * beside every other measurement.
     */
    public function collect(): array
    {
        if ($this->request === null) {
            return [];
        }

        $empira = $this->request;

        $data = [
            'method' => $empira->method,
            'uri' => $empira->path(),
            'url' => $empira->fullUrl(),
            'ip' => $empira->ip(),
            'route' => $this->route?->name,
            'started_at' => $this->startedAt,
            'duration' => microtime(true) - $this->startedAt,
            'memory' => memory_get_peak_usage(true),
            'headers' => $empira->headers(),
            'query' => $this->normalise($empira->query),
        ];

        if ($this->response !== null) {
            $data['status'] = $this->response->status();
            $data['content_type'] = $this->header($this->response, 'content-type');
        }

        if ($this->collectPayloads) {
            $data['payload'] = $this->normalise($empira->body);
        }

        if ($this->collectCookies) {
            $data['cookies'] = $empira->cookies;
        }

        if ($this->error !== null) {
            $data['error'] = sprintf('%s: %s', $this->error::class, $this->error->getMessage());
        }

        return $data;
    }

    public function reset(): void
    {
        $this->request = null;
        $this->response = null;
        $this->route = null;
        $this->error = null;
        $this->startedAt = 0.0;
    }

    /**
     * One response header, case-insensitively.
     *
     * {@see Response::headers()} returns names as they were written, so
     * `Content-Type` and `content-type` are the same header and a plain array
     * lookup finds only one of them.
     */
    private function header(Response $response, string $name): string
    {
        foreach ($response->headers() as $header => $value) {
            if (strcasecmp($header, $name) === 0) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Make these values plain before they reach JSON.
     *
     * A query string carrying an object or a closure produces a payload that
     * cannot be stored and a panel that cannot open it, so the values are
     * flattened here rather than at the point of failure.
     *
     * @return array<array-key, mixed>
     */
    private function normalise(mixed $data): array
    {
        return is_array($data) ? $data : [];
    }
}