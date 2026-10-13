<?php

declare(strict_types=1);

namespace Empira\DebugTools\Tests;

use Empira\Application;
use Empira\DebugTools\DebugController;
use Empira\DebugTools\DebugToolsModule;
use Empira\DebugTools\Profiler;
use Empira\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The stored history, and the contract a Clockwork client depends on.
 *
 * Everything here reads what was **persisted** — through `FileStorage`, and off
 * the filesystem — rather than the values the recording code returned. The gap
 * that matters is the one between "the handler ran" and "a client can read the
 * result", and nothing in the request path closes it.
 */
final class StorageHistoryTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/empira-history-' . bin2hex(random_bytes(6));

        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    /**
     * The whole integration with the Chrome extension, end to end.
     *
     * A client parses `X-Clockwork-Id`, `X-Clockwork-Path` and
     * `X-Clockwork-Version` off the response, then asks for `{path}/{id}`. It
     * knows nothing about this package's routes, so **the header is the
     * contract** — and when the header named the interface prefix rather than
     * the API base, every client asked for a URL that does not exist. The
     * application looked perfectly healthy.
     *
     * So this does exactly what a browser does: reads the headers, builds the
     * URL from them, and requests it.
     */
    public function testAClientCanGetItsDataFromTheHeadersAlone(): void
    {
        $app = $this->app();
        $app->routes()->get('/things', fn (): string => 'things', 'things.index');

        $response = $app->handle(new Request(method: 'GET', uri: '/things'));
        $headers = $this->headerMap($response);

        $this->assertArrayHasKey('x-clockwork-id', $headers);
        $this->assertArrayHasKey('x-clockwork-version', $headers);
        $this->assertArrayHasKey('x-clockwork-path', $headers);

        $url = $headers['x-clockwork-path'] . '/' . $headers['x-clockwork-id'];

        // Built from the header, not from DebugController::API — if the header
        // were wrong the two would disagree and this request would 404.
        $fetched = $app->handle(new Request(method: 'GET', uri: $url));

        $this->assertSame(200, $fetched->status(), "a client following the headers got {$url}");

        $data = json_decode($fetched->body(), true);

        $this->assertSame('/things', $data['uri']);
        $this->assertSame('things.index', $data['controller']);
        $this->assertSame(200, $data['responseStatus']);
        $this->assertSame($headers['x-clockwork-id'], $data['id']);
    }

    public function testThePathHeaderNamesTheApiBaseRatherThanThePrefix(): void
    {
        $app = $this->app();
        $app->routes()->get('/things', fn (): string => 'things');

        $path = $this->headerMap($app->handle(new Request(method: 'GET', uri: '/things')))['x-clockwork-path'];

        // The prefix itself is not the API base, and saying so here is the
        // difference between a documented trap and one rediscovered by a 404.
        $this->assertNotSame(DebugController::PATH, $path);
        $this->assertSame(DebugController::API, $path);
    }

    public function testHistoryGrowsByOnePerRequestAndReadsOldestFirst(): void
    {
        $app = $this->app();

        foreach (['/a', '/b', '/c'] as $n => $path) {
            $app->routes()->get($path, fn (): string => 'ok', 'page' . $n);
            $app->handle(new Request(method: 'GET', uri: $path));
        }

        $history = $this->history($app);

        $this->assertCount(3, $history);
        // `all()` walks the index forward, so it is oldest first. A history that
        // came back newest-first would make "previous" and "next" mean the
        // opposite of their names.
        $this->assertSame(['/a', '/b', '/c'], array_column($history, 'uri'));

        $this->assertSame('/c', $this->latest($app)['uri']);
    }

    public function testEachRequestIsWrittenToItsOwnFileOnDisk(): void
    {
        $app = $this->app();
        $app->routes()->get('/written', fn (): string => 'ok', 'written.index');

        $id = $this->headerMap($app->handle(new Request(method: 'GET', uri: '/written')))['x-clockwork-id'];

        $file = $this->storagePath() . '/' . $id . '.json';

        $this->assertFileExists($file, 'the header names an id with no file behind it');

        $onDisk = json_decode((string) file_get_contents($file), true);

        $this->assertSame($id, $onDisk['id']);
        $this->assertSame('/written', $onDisk['uri']);
    }

    public function testTheIndexGrowsAsRequestsAreStored(): void
    {
        $app = $this->app();
        $app->routes()->get('/n', fn (): string => 'ok');

        $before = $this->indexLines();

        $app->handle(new Request(method: 'GET', uri: '/n'));
        $app->handle(new Request(method: 'GET', uri: '/n'));

        // The index is the only thing `all()`, `latest()`, `previous()` and
        // `next()` read. Every read going quiet comes from this file, not from
        // the per-request files.
        $this->assertSame($before + 2, $this->indexLines());
    }

    public function testPreviousAndNextWalkEitherSideOfAnId(): void
    {
        $app = $this->app();

        foreach (['/p1', '/p2', '/p3', '/p4'] as $n => $path) {
            $app->routes()->get($path, fn (): string => 'ok', 'walk' . $n);
            $app->handle(new Request(method: 'GET', uri: $path));
        }

        $history = $this->history($app);
        $middle = $history[1]['id'];
        $storage = $app->container()->get(Profiler::class)->storage();

        $before = $storage->previous($middle);
        $after = $storage->next($middle);

        $this->assertSame(['/p1'], array_map(static fn (object $r): string => $r->uri, $before));
        $this->assertSame(['/p3', '/p4'], array_map(static fn (object $r): string => $r->uri, $after));
    }

    public function testPagingEndpointsReturnTheSameShapeAsASingleRequest(): void
    {
        $app = $this->app();

        foreach (['/q1', '/q2', '/q3'] as $n => $path) {
            $app->routes()->get($path, function () use ($n): string {
                usleep(1000);

                return 'ok';
            }, 'shape' . $n);
            $app->handle(new Request(method: 'GET', uri: $path));
        }

        $id = $this->latest($app)['id'];

        $one = json_decode($app->handle(new Request(
            method: 'GET',
            uri: DebugController::API . '/' . $id,
        ))->body(), true);

        $page = json_decode($app->handle(new Request(
            method: 'GET',
            uri: sprintf('%s/%s/previous/5', DebugController::API, $id),
        ))->body(), true);

        $this->assertIsArray($page);
        $this->assertNotEmpty($page);

        // Every field the single-request response has, on every element of the
        // page. `previous()`/`next()` answer with *arrays*, and passing those
        // straight to `json_encode` encodes each object's public properties —
        // which silently omits every computed field, so a paged client would see
        // no duration and no query counts for records that have them.
        foreach (['id', 'uri', 'responseStatus', 'responseDuration', 'memoryUsage', 'timelineData', 'log'] as $field) {
            $this->assertArrayHasKey($field, $one, "single response is missing {$field}");
            $this->assertArrayHasKey($field, $page[0], "paged response is missing {$field}");
        }
    }

    public function testAnUnknownIdIsNotFoundRatherThanNull(): void
    {
        $app = $this->app();
        $app->routes()->get('/real', fn (): string => 'ok');
        $app->handle(new Request(method: 'GET', uri: '/real'));

        $response = $app->handle(new Request(method: 'GET', uri: DebugController::API . '/no-such-request'));

        // `find()` answers `false`, and an empty history answers `[]`. A 200 with
        // `null` lets a client cache "no such request" as though it were data.
        $this->assertSame(404, $response->status());
    }

    public function testTheDataIsNotServedWhenTheModuleIsOff(): void
    {
        $app = $this->app(enabled: false);

        $this->assertSame(404, $app->handle(new Request(method: 'GET', uri: DebugController::API))->status());
        $this->assertSame(404, $app->handle(new Request(method: 'GET', uri: DebugController::PATH . '/app/index.html'))->status());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Response headers keyed in lower case, which is how a client reads them:
     * Clockwork's own bundle compares `l.name.toLowerCase() == 'x-clockwork-id'`.
     *
     * An earlier version of this returned the names as written and compared them
     * directly, which would have failed against a response that spelled a header
     * differently — the very thing case-insensitivity exists to absorb.
     *
     * @return array<string, string>
     */
    private function headerMap(\Empira\Http\Response $response): array
    {
        $map = [];

        foreach ($response->headers() as $name => $value) {
            $map[strtolower($name)] = $value;
        }

        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(Application $app): array
    {
        $all = array_values($app->container()->get(Profiler::class)->storage()->all());

        return array_map(static fn (object $r): array => $r->toArray(), $all);
    }

    /**
     * @return array<string, mixed>
     */
    private function latest(Application $app): array
    {
        $latest = $app->container()->get(Profiler::class)->storage()->latest();

        $this->assertInstanceOf(\Clockwork\Request\Request::class, $latest);

        return $latest->toArray();
    }

    private function storagePath(): string
    {
        return $this->basePath . '/storage/clockwork';
    }

    private function indexLines(): int
    {
        $index = $this->storagePath() . '/index';

        if (!is_file($index)) {
            return 0;
        }

        return count(array_filter(explode("\n", (string) file_get_contents($index)), static fn (string $l): bool => trim($l) !== ''));
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
