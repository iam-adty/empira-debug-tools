<?php

declare(strict_types=1);

namespace Empira\DebugTools;

/**
 * A timed span, for timing something a closure cannot wrap.
 *
 * `Debug::measure()` covers a function call, an early return, or a block you can
 * pass in. This covers the rest: an import that runs across four methods, a loop
 * with an early `break`, anything where the interesting end is not the end of a
 * function.
 *
 *     $span = Debug::span('import');
 *
 *     foreach ($rows as $row) {
 *         $this->import($row);
 *         $span?->data(['done' => ++$n]);
 *     }
 *
 *     $span?->data(['rows' => $n])?->end();
 *
 * Every method returns the span, so calls chain, and `?->` means a call site
 * needs no guard when profiling is off.
 *
 * ## Null when profiling is off
 *
 * `Debug::span()` returns null in that case, and every method here tolerates it
 * through `?->`. That is the whole reason for making it nullable rather than
 * handing back a dead object: `Debug::span('x')?->end()` needs no guard at the
 * call site, and a dead object would also have had to be built.
 *
 * ## Ends once
 *
 * `end()` returns the same instance and only the first call stops the clock.
 * Calling it in a `finally` while also calling it on the happy path is therefore
 * safe, which is the point — the version that stops on every call would report
 * whichever one ran last.
 */
final class Span
{
    private bool $ended = false;

    /**
     * @internal use {@see Debug::span()}
     */
    public function __construct(
        private readonly Marks $marks,
        private readonly int $index,
    ) {
    }

    /**
     * Stop the clock. Only the first call counts.
     */
    public function end(): self
    {
        if (!$this->ended) {
            $this->ended = true;
            $this->marks->close($this->index);
        }

        return $this;
    }

    /**
     * Attach data. May be called before or after {@see end()}, and as often as
     * is useful — the keys are merged, so `rows` can be corrected as the count
     * becomes known.
     *
     * @param array<array-key, mixed> $data
     */
    public function data(array $data): self
    {
        $this->marks->update($this->index, $data);

        return $this;
    }

    /**
     * How long, in milliseconds, so far — or in total if already ended.
     */
    public function duration(): float
    {
        $events = $this->marks->events();
        $event = $events[$this->index] ?? null;

        if ($event === null) {
            return 0.0;
        }

        $end = $event['end'] ?? microtime(true);

        return ($end - $event['start']) * 1000;
    }
}
