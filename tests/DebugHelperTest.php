<?php

declare(strict_types=1);

namespace Empira\DebugTools\Tests;

use Empira\Application;
use Empira\DebugTools\Debug;
use Empira\DebugTools\DebugToolsModule;
use Empira\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * {@see Debug}, exercised through a real request.
 *
 * The calls are made from inside a handler, which is the only place they work,
 * and read back from the **stored profile** rather than from the objects that
 * produced them. A helper that fills a buffer nothing drains would pass every
 * test written against the buffer.
 */
final class DebugHelperTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/empira-debug-marks-' . bin2hex(random_bytes(6));

        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        // `Debug` holds the request it is currently collecting for, and a test
        // that leaves one active would hand the next test someone else's marks.
        Debug::deactivate();

        $this->removeDirectory($this->basePath);
    }

    public function testAMarkLandsOnTheProfileWithItsPayload(): void
    {
        $recorded = $this->record(fn () => Debug::mark('cache miss', ['key' => 'user:7']));

        $mark = $this->event($recorded, 'cache miss');

        $this->assertNotNull($mark, 'the mark did not reach the stored profile');

        // Under `data`, because `Event` reads `$data['data']` and silently
        // discards anything else. A payload at the top level produces an event
        // with `data: null` and no warning that it was lost.
        $this->assertSame(['key' => 'user:7'], $mark['data']);
    }

    public function testRepeatMarksAreSeparateEvents(): void
    {
        // `Timeline::event()` finds-or-creates by name, so three marks called
        // `mark('row')` would collapse into one and lose two payloads. Marks go
        // through `create()` precisely so this holds.
        $recorded = $this->record(function (): void {
            Debug::mark('row', ['n' => 1]);
            Debug::mark('row', ['n' => 2]);
            Debug::mark('row', ['n' => 3]);
        });

        $rows = array_values(array_filter($recorded['timelineData'], static fn (array $e): bool => $e['description'] === 'row'));

        $this->assertCount(3, $rows);
        $this->assertSame([['n' => 1], ['n' => 2], ['n' => 3]], array_column($rows, 'data'));
    }

    public function testMeasureReturnsItsValueAndRecordsTheTime(): void
    {
        $captured = null;

        $recorded = $this->record(function () use (&$captured): void {
            $captured = Debug::measure('reindex', function (): string {
                usleep(2000);

                return 'done';
            });
        });

        // The return value first: a debug helper that swallowed it would be a
        // production bug caused by a debugging tool.
        $this->assertSame('done', $captured);

        $event = $this->event($recorded, 'reindex');

        $this->assertNotNull($event);
        $this->assertGreaterThan(1.5, $event['duration'], 'the closure took ~2ms');
    }

    public function testMeasureStillReturnsItsValueWhenNothingIsRecording(): void
    {
        Debug::deactivate();

        $this->assertSame('untouched', Debug::measure('noop', fn (): string => 'untouched'));
        $this->assertFalse(Debug::enabled());
    }

    public function testMeasureEndsItsSpanEvenWhenTheClosureThrows(): void
    {
        $app = $this->app();
        $app->routes()->get('/boom', function (): void {
            Debug::measure('failing work', function (): void {
                usleep(1000);

                throw new \RuntimeException('inner');
            });
        });

        try {
            $app->handle(new Request(method: 'GET', uri: '/boom'));
        } catch (\Throwable) {
            // The application has no error view, so it rethrows. The point is
            // what was recorded before it did.
        }

        $recorded = $this->profile($app);

        $this->assertNotNull(
            $this->event($recorded, 'failing work'),
            'a timeline that stops at the last successful step points at the wrong place',
        );
    }

    public function testASpanCanBeOpenedAndEndedAroundOtherWork(): void
    {
        $recorded = $this->record(function (): void {
            $span = Debug::span('import');

            $span?->data(['rows' => 10]);
            usleep(2000);
            $span?->data(['done' => 10])?->end();
        });

        $event = $this->event($recorded, 'import');

        $this->assertNotNull($event);
        $this->assertSame(['rows' => 10, 'done' => 10], $event['data']);
        $this->assertGreaterThan(1.5, $event['duration']);
    }

    public function testEndingASpanTwiceKeepsTheFirstTime(): void
    {
        // A `finally` that ends the span, and a happy path that also ends it,
        // is the ordinary shape of code with a cleanup. The version that stopped
        // on every call would report whichever ran last.
        $recorded = $this->record(function (): void {
            $span = Debug::span('once');

            usleep(2000);
            $span?->end();
            usleep(2000);
            $span?->end();
        });

        $this->assertLessThan(3.5, $this->event($recorded, 'once')['duration']);
    }

    public function testSpanIsNullWhenNothingIsRecording(): void
    {
        Debug::deactivate();

        $this->assertNull(Debug::span('nothing'));
    }

    public function testEachLogLevelLandsAtItsOwnLevel(): void
    {
        $recorded = $this->record(function (): void {
            Debug::info('using the v2 parser');
            Debug::debug('sql shape');
            Debug::warning('falling back to v1');
            Debug::error('could not reach the cache');
        });

        $levels = array_column($recorded['log'], 'level');
        $messages = array_column($recorded['log'], 'message');

        $this->assertContains('info', $levels);
        $this->assertContains('debug', $levels);
        $this->assertContains('warning', $levels);
        $this->assertContains('error', $levels);
        $this->assertContains('could not reach the cache', $messages);
    }

    public function testALogEntryKeepsItsContext(): void
    {
        $recorded = $this->record(fn () => Debug::info('slow query', ['ms' => 812, 'table' => 'users']));

        $entry = array_values(array_filter($recorded['log'], static fn (array $e): bool => $e['message'] === 'slow query'));

        $this->assertNotSame([], $entry, 'the message did not reach the log');

        // Clockwork's Serializer tags the context with `__type__`, so the values
        // are asserted individually rather than the whole array — which also
        // states what actually matters, that the numbers survived.
        $context = $entry[0]['context'] ?? [];

        $this->assertSame(812, $context['ms'] ?? null);
        $this->assertSame('users', $context['table'] ?? null);
    }

    public function testALogEntryCarriesWhereItCameFrom(): void
    {
        // Clockwork collects a stack trace per message by default, and for this
        // helper that is most of the value: a mark is only useful next to the
        // line that made it. Asserted so that turning it off would be a
        // deliberate change rather than something nobody notices.
        $recorded = $this->record(fn () => Debug::warning('about to fall back'));

        $entry = array_values(array_filter($recorded['log'], static fn (array $e): bool => $e['message'] === 'about to fall back'));

        $this->assertNotSame([], $entry[0]['trace'] ?? [], 'the log entry has no trace');
    }

    public function testNothingIsRecordedOutsideARequest(): void
    {
        $app = $this->app();

        // Before any request: the facade is inert, and stays inert.
        Debug::mark('stray');
        Debug::info('stray');

        $this->assertFalse(Debug::enabled());

        $app->routes()->get('/quiet', fn (): string => 'ok');
        $app->handle(new Request(method: 'GET', uri: '/quiet'));

        $recorded = $this->profile($app);

        $this->assertNull($this->event($recorded, 'stray'));
    }

    public function testMarksDoNotLeakIntoTheNextRequest(): void
    {
        $app = $this->app();
        $first = true;

        // Marks on the first request only — the point is that the *buffer* does
        // not carry into the second, not that the handler is called once.
        $app->routes()->get('/one', function () use (&$first): string {
            if ($first) {
                Debug::mark('only on the first request');
                $first = false;
            }

            return 'one';
        });

        $app->handle(new Request(method: 'GET', uri: '/one'));
        $app->handle(new Request(method: 'GET', uri: '/one'));

        $history = $this->history($app);

        $this->assertCount(2, $history);
        $this->assertCount(1, $this->eventsNamed($history[0], 'only on the first request'));
        // Under a worker, "one request annotating another" is the failure this
        // guards, and it would not be visible from either profile alone.
        $this->assertCount(0, $this->eventsNamed($history[1], 'only on the first request'));
    }

    public function testMarksSurviveTheTimelineFinalizeClamp(): void
    {
        // The regression this exists for. `Clockwork::resolveRequest()` finalizes
        // the timeline against `$request->time`, and `Event::finalize()` clamps
        // anything starting before it. `$request->time` is set when that object is
        // constructed — which is *after* the handler — so every mark made during
        // the request would be dragged forward onto the instant recording began
        // and given a duration of zero.
        //
        // The order of the two marks is the assertion: a clamp to a single instant
        // would leave them indistinguishable.
        $recorded = $this->record(function (): void {
            Debug::mark('first');
            usleep(2000);
            Debug::mark('second');
        });

        $first = $this->event($recorded, 'first');
        $second = $this->event($recorded, 'second');

        $this->assertGreaterThan(
            1.5,
            ($second['start'] - $first['start']) * 1000,
            'the gap between two marks was flattened, so finalize clamped them together',
        );
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Run a handler through a real request and return the stored profile.
     *
     * @return array<string, mixed>
     */
    private function record(callable $handler): array
    {
        $app = $this->app();
        $app->routes()->get('/work', function () use ($handler): string {
            $handler();

            return 'ok';
        }, 'work.index');

        $app->handle(new Request(method: 'GET', uri: '/work'));

        return $this->profile($app);
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(Application $app): array
    {
        $latest = $app->container()
            ->get(\Empira\DebugTools\Profiler::class)
            ->storage()
            ->latest();

        $this->assertInstanceOf(\Clockwork\Request\Request::class, $latest, 'nothing was recorded');

        return $latest->toArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(Application $app): array
    {
        return array_map(
            static fn (object $request): array => $request->toArray(),
            array_values($app->container()->get(\Empira\DebugTools\Profiler::class)->storage()->all()),
        );
    }

    /**
     * @param array<string, mixed> $recorded
     * @return array<string, mixed>|null
     */
    private function event(array $recorded, string $description): ?array
    {
        foreach ($recorded['timelineData'] ?? [] as $event) {
            if (($event['description'] ?? null) === $description) {
                return $event;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $recorded
     * @return list<array<string, mixed>>
     */
    private function eventsNamed(array $recorded, string $description): array
    {
        return array_values(array_filter(
            $recorded['timelineData'] ?? [],
            static fn (array $e): bool => ($e['description'] ?? null) === $description,
        ));
    }

    private function app(bool $enabled = true): Application
    {
        $app = new Application('a-secret-that-is-long-enough');

        $app->module(new DebugToolsModule($this->basePath, ['enabled' => $enabled]));

        return $app;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new \DirectoryIterator($path) as $item) {
            if ($item->isDot()) {
                continue;
            }

            $item->isDir() ? $this->removeDirectory($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
