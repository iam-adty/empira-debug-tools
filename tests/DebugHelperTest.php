<?php

declare(strict_types=1);

namespace Empira\DebugTools\Tests;

use DebugBar\Storage\StorageInterface;
use Empira\Application;
use Empira\DebugTools\Debug;
use Empira\DebugTools\DebugToolsModule;
use Empira\DebugTools\Profiler;
use Empira\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * {@see Debug}, exercised through a real request.
 *
 * The calls are made from inside a handler, which is the only place they work,
 * and read back from the **stored profile** rather than from the objects that
 * produced them. A helper that fills a buffer nothing drains would pass every
 * test written against the buffer.
 *
 * ## Read through DebugBar's own shape
 *
 * Marks land as `time` collector measures and messages as `messages` entries, so
 * that is where they are looked for. Assertions are about what a developer asked
 * for surviving the round trip — the payload, the repeat, the ordering — rather
 * than about DebugBar's serialisation, which is upstream's to keep.
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

        $mark = $this->measure($recorded, 'cache miss');

        $this->assertNotNull($mark, 'the mark did not reach the stored profile');
        $this->assertSame('user:7', $mark['params']['key'] ?? null);
    }

    public function testAMarksColourReachesTheProfileAsData(): void
    {
        // DebugBar draws no per-measure colour, so the colour is carried as a
        // parameter. Kept rather than dropped because removing a parameter from a
        // public helper is a break, and a mark that carries its colour is strictly
        // more informative than one that does not.
        $recorded = $this->record(fn () => Debug::mark('careful', [], 'red'));

        $this->assertSame('red', $this->measure($recorded, 'careful')['params']['color'] ?? null);
    }

    public function testRepeatMarksAreSeparateMeasures(): void
    {
        $recorded = $this->record(function (): void {
            Debug::mark('row', ['n' => 1]);
            Debug::mark('row', ['n' => 2]);
            Debug::mark('row', ['n' => 3]);
        });

        $rows = $this->measuresNamed($recorded, 'row');

        // The point of a mark made in a loop. DebugBar can merge repeated measures
        // by label, and that merging is off here — a profiler that collapsed a
        // thousand-row import into one event would have thrown away the data the
        // developer asked to see.
        $this->assertCount(3, $rows);
        $this->assertSame(['1', '2', '3'], array_map(
            static fn (array $m): string => (string) ($m['params']['n'] ?? ''),
            $rows,
        ));
    }

    public function testMeasureReturnsItsValueAndRecordsTheTime(): void
    {
        $returned = null;

        $recorded = $this->record(static function () use (&$returned): void {
            $returned = Debug::measure('slow', static function (): string {
                usleep(2000);

                return 'the value';
            });
        });

        $this->assertSame('the value', $returned, 'swallowing a return value would be a production bug');

        $measure = $this->measure($recorded, 'slow');

        $this->assertNotNull($measure);
        $this->assertGreaterThan(0.001, $measure['duration'], 'a two-millisecond closure was measured as nothing');
    }

    public function testMeasureStillReturnsItsValueWhenNothingIsRecording(): void
    {
        $returned = Debug::measure('stray', static fn (): string => 'the value');

        $this->assertSame('the value', $returned);
        $this->assertFalse(Debug::enabled());
    }

    public function testMeasureEndsItsSpanEvenWhenTheClosureThrows(): void
    {
        $app = $this->app();

        try {
            $this->recordInto($app, static function (): void {
                Debug::measure('doomed', static function (): string {
                    throw new \RuntimeException('it broke');
                });
            });

            $this->fail('the exception should have propagated');
        } catch (\RuntimeException $e) {
            $this->assertSame('it broke', $e->getMessage());
        }

        // The span was still drained, because the profile is stored on the failure
        // path too — a request you most wanted to look at is one that threw, and a
        // span left open by the throw is exactly what you want to see in it.
        $this->assertCount(
            1,
            $this->measuresNamed($this->profile($app), 'doomed'),
            'the span was not recorded on the path that threw',
        );
    }

    public function testASpanCanBeOpenedAndEndedAroundOtherWork(): void
    {
        $recorded = $this->record(static function (): void {
            $span = Debug::span('import');

            usleep(2000);

            $span?->data(['rows' => 10])?->end();
        });

        $span = $this->measure($recorded, 'import');

        $this->assertNotNull($span);
        $this->assertSame('10', $span['params']['rows'] ?? null, 'data set while the span was open was lost');
        $this->assertGreaterThan(0.001, $span['duration']);
    }

    public function testEndingASpanTwiceKeepsTheFirstTime(): void
    {
        $recorded = $this->record(static function (): void {
            $span = Debug::span('import');

            usleep(1000);
            $span?->end();

            usleep(5000);
            $span?->end();
        });

        $span = $this->measure($recorded, 'import');

        $this->assertNotNull($span);
        $this->assertLessThan(0.004, $span['duration'], 'the second end() overwrote the first');
    }

    public function testSpanIsNullWhenNothingIsRecording(): void
    {
        $this->assertNull(Debug::span('stray'));
    }

    public function testEachLogLevelLandsAtItsOwnLevel(): void
    {
        $recorded = $this->record(static function (): void {
            Debug::debug('d');
            Debug::info('i');
            Debug::warning('w');
            Debug::error('e');
        });

        $levels = array_map(
            static fn (array $m): string => (string) ($m['label'] ?? ''),
            $recorded['messages']['messages'] ?? [],
        );

        // PSR-3 levels, which is what DebugBar's collector takes — and it names
        // the field `label`, not `level`. Every logging library in the ecosystem
        // already speaks these, so nothing has to translate them.
        foreach (['debug', 'info', 'warning', 'error'] as $level) {
            $this->assertContains($level, $levels);
        }
    }

    public function testALogEntryKeepsItsContext(): void
    {
        $recorded = $this->record(static fn () => Debug::info('user', ['id' => 7]));

        $entry = null;

        foreach ($recorded['messages']['messages'] ?? [] as $message) {
            if (($message['message'] ?? null) === 'user') {
                $entry = $message;

                break;
            }
        }

        $this->assertNotNull($entry);
        $this->assertStringContainsString('7', (string) json_encode($entry['context'] ?? []));
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

        $this->assertNull($this->measure($this->profile($app), 'stray'));
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
        $this->assertCount(1, $this->measuresNamed($history[0], 'only on the first request'));
        $this->assertCount(0, $this->measuresNamed($history[1], 'only on the first request'));
    }

    public function testMarksKeepTheirOrderAndSpacing(): void
    {
        $recorded = $this->record(static function (): void {
            Debug::mark('first');
            usleep(2000);
            Debug::mark('second');
        });

        $first = $this->measure($recorded, 'first');
        $second = $this->measure($recorded, 'second');

        $this->assertNotNull($first);
        $this->assertNotNull($second);

        // Two marks two milliseconds apart must still be two milliseconds apart.
        // This is the regression the previous profiler had: it finalised the
        // timeline against an instant taken *after* the handler ran, which clamped
        // every mark onto the same moment and gave each a duration of zero.
        $this->assertGreaterThan(
            1.5,
            ($second['start'] - $first['start']) * 1000,
            'the gap between two marks was flattened',
        );
    }

    public function testLifecycleEventsArePointsRatherThanWholeRequestSpans(): void
    {
        $recorded = $this->record(static fn () => Debug::mark('work'));

        foreach (['Request received', 'Matched'] as $point) {
            $measure = $this->measure($recorded, $point);

            $this->assertNotNull($measure, $point . ' should be on the timeline');
            // Not `assertSame`: a zero duration comes back from the JSON store as
            // an int, because `json_encode(0.0)` drops the fraction. The number is
            // the assertion, not its PHP type on the way out of a file.
            $this->assertEquals(
                0.0,
                $measure['duration'],
                $point . ' is a moment, not work performed — a duration here reads as milliseconds spent',
            );
        }
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

        $this->recordInto($app, $handler);

        return $this->profile($app);
    }

    /**
     * Run a handler against an application, without reading the result.
     *
     * Separate from {@see record()} because a handler that throws has no profile
     * to return, and the tests that care about that need the application itself.
     */
    private function recordInto(Application $app, callable $handler): void
    {
        $app->routes()->get('/work', function () use ($handler): string {
            $handler();

            return 'ok';
        }, 'work.index');

        $app->handle(new Request(method: 'GET', uri: '/work'));
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(Application $app): array
    {
        return $this->storage($app)->get($this->latestId($app));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(Application $app): array
    {
        $found = $this->storage($app)->find();

        // Oldest first, by `utime` rather than by reversing `find()` — the id sort
        // is only a millisecond timestamp and two requests here are microseconds
        // apart, so reversing it does not reliably put the first request first.
        usort($found, static fn (array $a, array $b): int => ($a['utime'] ?? 0) <=> ($b['utime'] ?? 0));

        return array_values(array_map(
            fn (array $meta): array => $this->storage($app)->get((string) ($meta['id'] ?? '')),
            $found,
        ));
    }

    /**
     * @param array<string, mixed> $recorded
     * @return array<string, mixed>|null
     */
    private function measure(array $recorded, string $label): ?array
    {
        foreach ($recorded['time']['measures'] ?? [] as $measure) {
            if (($measure['label'] ?? null) === $label) {
                return $measure;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $recorded
     * @return list<array<string, mixed>>
     */
    private function measuresNamed(array $recorded, string $label): array
    {
        return array_values(array_filter(
            $recorded['time']['measures'] ?? [],
            static fn (array $m): bool => ($m['label'] ?? null) === $label,
        ));
    }

    private function app(bool $enabled = true): Application
    {
        $app = new Application('a-secret-that-is-long-enough');

        $app->module(new DebugToolsModule($this->basePath, ['enabled' => $enabled]));

        return $app;
    }

    private function storage(Application $app): StorageInterface
    {
        return $app->container()->get(Profiler::class)->storage();
    }

    private function latestId(Application $app): string
    {
        $found = $this->storage($app)->find();

        $this->assertNotSame([], $found, 'nothing was recorded');

        // By `utime`, not by position: `find()` sorts on the request id, which
        // begins with a millisecond timestamp, so two requests microseconds apart
        // are ordered by the random bytes after it. Reading position 0 made this
        // file fail intermittently rather than reliably.
        $newest = $found[0];

        foreach ($found as $candidate) {
            if (($candidate['utime'] ?? 0) > ($newest['utime'] ?? 0)) {
                $newest = $candidate;
            }
        }

        return (string) ($newest['id'] ?? '');
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
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