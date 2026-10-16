<?php

declare(strict_types=1);

namespace Empira\DebugTools;

use Psr\Log\LogLevel;

/**
 * Say something about the request you are in the middle of.
 *
 *     Debug::mark('cache miss', ['key' => $key]);
 *     $total = Debug::measure('reindex', fn (): int => $this->reindex());
 *     Debug::warning('falling back to the v1 parser', ['version' => 1]);
 *
 * ## Why a static
 *
 * The calls worth making are the ones three layers down, inside a repository or
 * a queue handler, where there is no `$app` in scope and no sensible way to
 * thread one in. A method on the {@see Profiler} would be the alternative, and
 * it would need `use ($app)` on everything it touched to reach.
 *
 * The cost is process-global state, which is safe here for two reasons. PHP is
 * one request per process under a classic runtime, and under a worker requests
 * are sequential in one process — so a single slot set at the start of a request
 * and cleared in a `finally` cannot be contended.
 *
 * ## Nothing here throws, ever
 *
 * Profiling is off in production, and a debug call in a code path that happens to
 * run there must not become a new way for a request to fail. Every method is a
 * no-op when there is no request being recorded — except {@see measure()}, which
 * still runs its callback and returns its value, because swallowing a return
 * value would be a production bug caused by a debugging tool.
 *
 * ## Marks have to happen during the request
 *
 * They are drained into the profile when the request is being stored. A mark
 * from a shutdown function or a destructed after the response is over is
 * discarded. Console commands are not profiled at all, so marks there are also
 * discarded — the toolbar's data comes from the request pipeline, and a command
 * has no response to be a page.
 *
 * @see Marks for what the calls actually accumulate.
 * @see Span for timing something a closure cannot wrap.
 */
final class Debug
{
    /**
     * The request currently being recorded, or null when there is none.
     *
     * Written by {@see self::activate()} from the middleware and cleared by
     * {@see self::deactivate()} in a `finally`. Never read from application code.
     */
    private static ?Marks $current = null;

    /**
     * @internal
     */
    public static function activate(Marks $marks): void
    {
        self::$current = $marks;
    }

    /**
     * @internal
     *
     * Clears rather than swaps, so a mark made after deactivate is a no-op
     * rather than an entry that appears in the next request's profile.
     */
    public static function deactivate(): void
    {
        self::$current = null;
    }

    /**
     * Whether anything is being recorded right now.
     *
     * Worth checking before building an expensive payload: the whole point of
     * logging why something happened is often at a line that runs on every
     * request, and serialising a large structure nobody will look at is a cost
     * the application pays and the developer does not.
     */
    public static function enabled(): bool
    {
        return self::$current !== null;
    }

    /**
     * A point on the timeline. Repeats are kept as separate events, so a mark
     * inside a loop produces a mark per iteration rather than one.
     *
     * @param array<array-key, mixed> $data shown alongside the event
     * @param string|null $color kept for compatibility and carried through to the
     *                           measure's parameters. DebugBar draws its timeline
     *                           without per-measure colours, so this reaches the
     *                           panel as data rather than as a swatch on the bar.
     */
    public static function mark(string $description, array $data = [], ?string $color = null): void
    {
        self::$current?->mark($description, $data, $color);
    }

    /**
     * Time a closure and return what it returned.
     *
     * The return value is the reason this is not just a span: wrapping a call
     * and getting nothing back would force every call site to assign to a
     * variable it did not otherwise need.
     *
     *     $user = Debug::measure('find user', fn (): ?User => $this->find($id));
     *
     * @param mixed ...$arguments passed through to the closure
     */
    public static function measure(string $description, \Closure $callback, mixed ...$arguments): mixed
    {
        $marks = self::$current;

        if ($marks === null) {
            // Deliberately *not* returning null: the callback has to run. A
            // debugging tool that changed what the application does when it is
            // switched off would be a bug in the tool.
            return $callback(...$arguments);
        }

        $index = $marks->open($description);

        try {
            return $callback(...$arguments);
        } finally {
            // In the `finally`, so an exception is timed too. The request is
            // about to be recorded as a failure, and a timeline that stops at the
            // last successful step would point at the wrong place.
            $marks->close($index);
        }
    }

    /**
     * A span for timing something a closure cannot wrap.
     *
     * Null when profiling is off, so every call site can use `?->` and need no
     * guard. {@see Span} is nullable-aware for exactly this.
     *
     * @param array<array-key, mixed> $data
     */
    public static function span(string $description, array $data = [], ?string $color = null): ?Span
    {
        $marks = self::$current;

        return $marks === null ? null : new Span($marks, $marks->open($description, $data, $color));
    }

    /**
     * A message at an explicit level.
     *
     * @param array<array-key, mixed> $context
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        self::$current?->log($level, $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::log(LogLevel::INFO, $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log(LogLevel::WARNING, $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::log(LogLevel::ERROR, $message, $context);
    }
}
