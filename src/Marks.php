<?php

declare(strict_types=1);

namespace Empira\DebugTools;

/**
 * What a developer said about the request currently being handled.
 *
 * ## Why this exists rather than writing straight to Clockwork
 *
 * The Clockwork `Request` object does not exist while the handler runs — it is
 * built in `Profiler::record()`, afterwards. So a mark made at the interesting
 * line has to be held somewhere until then, which is this.
 *
 * Buffered, not lost: a mark is dropped only because the request ended before
 * `resolve()` could drain it, which never happens on the paths that exist.
 *
 * ## Two kinds of thing, kept apart
 *
 * **Events** are points on a timeline — "cache miss", "import started". **Log
 * entries** are messages with a level — "falling back to the old parser".
 * Clockwork keeps them in separate structures and shows them separately, so they
 * stay separate here too.
 *
 * ## Cleared per request, not per object
 *
 * One instance serves every request a process handles, because there is one
 * {@see Profiler} and therefore one Clockwork instance. {@see reset()} runs in
 * `Profiler::record()`'s `finally`, so a mark can never survive into the next
 * request — under a worker that would be one request explaining another.
 *
 * @see Debug for the developer-facing API.
 */
final class Marks
{
    /** @var list<array{description: string, start: float, end: float|null, data: array<array-key, mixed>, color: string|null}> */
    private array $events = [];

    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    private array $log = [];

    /**
     * A point in time.
     *
     * Every call produces its own event, which is why this cannot go through
     * `Timeline::event()` — that method *finds or creates* by name, so a loop
     * calling `mark('row')` a thousand times would produce one event, and the
     * nine hundred and ninety-nine payloads after it would go nowhere.
     *
     * @param array<array-key, mixed> $data
     */
    public function mark(string $description, array $data = [], ?string $color = null): void
    {
        $this->events[] = [
            'description' => $description,
            'start' => microtime(true),
            'end' => null,
            'data' => $data,
            'color' => $color,
        ];
    }

    /**
     * Start a span now, to be ended later.
     *
     * @param array<array-key, mixed> $data
     * @return int the index of the open span, to end it by
     */
    public function open(string $description, array $data = [], ?string $color = null): int
    {
        $this->events[] = [
            'description' => $description,
            'start' => microtime(true),
            'end' => null,
            'data' => $data,
            'color' => $color,
        ];

        return array_key_last($this->events);
    }

    /**
     * Merge data into an open span, without ending it.
     *
     * Separate from {@see close()} because the interesting part of a span is
     * usually only known partway through — how many rows so far, which key — and
     * a span that ended to accept data would report the time of the first write
     * rather than the time of the work.
     *
     * @param array<array-key, mixed> $data
     */
    public function update(int $index, array $data): void
    {
        if (isset($this->events[$index])) {
            $this->events[$index]['data'] = array_merge($this->events[$index]['data'], $data);
        }
    }

    /**
     * Close a span opened by {@see open()}, at the current time.
     */
    public function close(int $index): void
    {
        if (isset($this->events[$index])) {
            $this->events[$index]['end'] = microtime(true);
        }
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $this->log[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    /**
     * @return list<array{description: string, start: float, end: float|null, data: array<array-key, mixed>, color: string|null}>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * @return list<array{level: string, message: string, context: array<array-key, mixed>}>
     */
    public function entries(): array
    {
        return $this->log;
    }

    public function reset(): void
    {
        $this->events = [];
        $this->log = [];
    }
}
