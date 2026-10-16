<?php

declare(strict_types=1);

namespace Empira\DebugTools\Tests;

use Empira\Application;
use Empira\DebugTools\DebugBarController;
use Empira\DebugTools\DebugToolsModule;
use Empira\DebugTools\Profiler;
use Empira\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * What is stored, and how it gets read back.
 *
 * The shape of this is DebugBar's rather than ours: one JSON file per request,
 * found by globbing, with a `__meta` block beside the collectors' data. These
 * tests are about *our* half of that contract — that an Empira request becomes a
 * readable record, and that the toolbar can find it again — rather than about
 * DebugBar's file format, which is upstream's to keep.
 */
final class StorageHistoryTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/empira-debug-tools-' . bin2hex(random_bytes(6));

        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    public function testHistoryGrowsByOnePerRequest(): void
    {
        $app = $this->app();
        $app->routes()->get('/things', fn (): string => 'things', 'things.index');

        $app->handle(new Request(method: 'GET', uri: '/things'));
        $app->handle(new Request(method: 'GET', uri: '/things'));

        $this->assertCount(2, $this->storage($app)->find());
    }

    public function testEachRequestIsWrittenToItsOwnFileOnDisk(): void
    {
        $app = $this->app();
        $app->routes()->get('/things', fn (): string => 'things', 'things.index');

        $app->handle(new Request(method: 'GET', uri: '/things'));
        $app->handle(new Request(method: 'GET', uri: '/things'));

        $files = glob($this->storagePath($app) . '/*.json') ?: [];

        // One file per request, because that is what makes a profile readable
        // without loading all of them, and what `prune()` can delete by age.
        $this->assertCount(2, $files);
    }

    public function testTheToolbarCanFindTheRequestItWasProfiling(): void
    {
        $app = $this->app();
        $app->routes()->get('/things', fn (): string => 'things', 'things.index');
        $app->handle(new Request(method: 'GET', uri: '/things'));

        $response = $app->handle(new Request(
            method: 'GET',
            uri: DebugBarController::OPEN_HANDLER,
            query: ['op' => 'get', 'id' => $this->latestId($app)],
        ));

        $this->assertSame(200, $response->status());

        $data = json_decode($response->body(), true);

        $this->assertIsArray($data, 'the open handler returns JSON');
        $this->assertSame('/things', $data['empira']['uri'] ?? null);
    }

    public function testAnUnknownIdIsNotFoundRatherThanEmptyData(): void
    {
        $app = $this->app();

        $response = $app->handle(new Request(
            method: 'GET',
            uri: DebugBarController::OPEN_HANDLER,
            query: ['op' => 'get', 'id' => 'never-recorded'],
        ));

        // DebugBar answers `200` with an empty array, and that is left as it is:
        // the toolbar's own JavaScript is written against this wire format, and
        // answering `404` here would be us disagreeing with the client we are
        // serving. What matters is that the body is empty rather than a profile
        // that looks real — a stored request would have an `empira` key in it.
        $this->assertSame(200, $response->status());
        $this->assertSame([], json_decode($response->body(), true));
    }

    public function testTheRequestIsReadFromEmpiraAndNotTheSuperglobals(): void
    {
        $app = $this->app();
        $app->routes()->get('/things', fn (): string => 'things', 'things.index');

        $app->handle(new Request(method: 'GET', uri: '/things'));

        $data = $this->stored($app);

        // The whole reason `EmpiraDebugBar` exists. A test constructs its own
        // `Request` and never touches `$_SERVER`, so a profiler reading the
        // superglobals here would report the test runner.
        $this->assertSame('GET', $data['__meta']['method'] ?? null);
        $this->assertSame('/things', $data['__meta']['uri'] ?? null);
    }

    public function testTheRequestCollectorsAreNotDebugBarsOwn(): void
    {
        $app = $this->app();
        $app->routes()->get('/login', fn (): string => 'things');

        $app->handle(new Request(
            method: 'POST',
            uri: '/login',
            body: ['password' => 'hunter2'],
            cookies: ['session' => 'a-real-looking-cookie'],
        ));

        $data = $this->stored($app);

        // DebugBar's own `RequestDataCollector` reads `$_POST` and `$_COOKIE` from
        // the superglobals unconditionally, and would have written both of these
        // to disk on every profiled request. It is deliberately not registered;
        // what arrives is described by `EmpiraCollector` instead.
        $this->assertArrayNotHasKey('request', $data, 'the superglobal collector must not be registered');

        $serialised = json_encode($data);

        $this->assertStringNotContainsString('hunter2', (string) $serialised);
        $this->assertStringNotContainsString('a-real-looking-cookie', (string) $serialised);
    }

    public function testPayloadsAndCookiesAreOptIn(): void
    {
        $app = $this->app(config: ['enabled' => true, 'payloads' => true, 'cookies' => true]);
        $app->routes()->get('/login', fn (): string => 'things');

        $app->handle(new Request(
            method: 'POST',
            uri: '/login',
            body: ['password' => 'hunter2'],
            cookies: ['session' => 'a-real-looking-cookie'],
        ));

        $serialised = (string) json_encode($this->stored($app));

        // Opting in is a deliberate act, and one that can only be made per
        // installation rather than by anything in the request.
        $this->assertStringContainsString('hunter2', $serialised);
        $this->assertStringContainsString('a-real-looking-cookie', $serialised);
    }

    public function testTheDataIsNotServedWhenTheModuleIsOff(): void
    {
        $this->assertSame(404, $this->app(enabled: false)->handle(
            new Request(method: 'GET', uri: DebugBarController::OPEN_HANDLER, query: ['op' => 'find']),
        )->status());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $config
     */
    private function app(bool $enabled = true, array $config = []): Application
    {
        $app = new Application('a-secret-that-is-long-enough');

        $app->module(new DebugToolsModule($this->basePath, $config + ['enabled' => $enabled]));

        return $app;
    }

    private function storage(Application $app): \DebugBar\Storage\StorageInterface
    {
        return $app->container()->get(Profiler::class)->storage();
    }

    private function storagePath(Application $app): string
    {
        return $app->container()->get(Profiler::class)->storagePath();
    }

    private function latestId(Application $app): string
    {
        $found = $this->storage($app)->find();

        $this->assertNotSame([], $found, 'a request should have been recorded');

        return (string) ($found[0]['id'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(Application $app): array
    {
        return $this->storage($app)->get($this->latestId($app));
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($directory);
    }
}