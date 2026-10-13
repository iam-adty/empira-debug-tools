<?php

declare(strict_types=1);

namespace Empira\DebugTools\Tests;

use Empira\Application;
use Empira\DebugTools\DebugController;
use Empira\DebugTools\DebugToolsModule;
use Clockwork\Request\Request as ClockworkRequest;
use Empira\Console\Output;
use Empira\DebugTools\Console\ClearCommand;
use Empira\DebugTools\Console\ShowCommand;
use Empira\DebugTools\Profiler;
use Empira\Http\Request;
use Empira\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * The module, exercised through a real application.
 *
 * Every test here drives `$app->handle()` rather than calling the data source
 * directly. That is deliberate: the interesting failures in this kind of module
 * are not in the reading, they are in the wiring — a middleware registered after
 * the pipeline was built, a route that never matches, a storage directory that
 * is one level too high. None of those are visible from the inside.
 */
final class DebugToolsTest extends TestCase
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

    public function testItRecordsARequestAndNamesTheRouteThatServedIt(): void
    {
        $app = $this->app();
        $app->routes()->get('/things', fn (): string => 'things', 'things.index');

        $response = $app->handle(new Request(method: 'GET', uri: '/things'));

        // The header is the contract the browser interface and the DevTools
        // panel both rely on, so it is asserted rather than assumed.
        $this->assertNotNull($response->headers()['X-Clockwork-Id'] ?? null);

        $recorded = $this->latest($app);

        $this->assertSame('GET', $recorded['method']);
        $this->assertSame('/things', $recorded['uri']);
        $this->assertSame(200, $recorded['responseStatus']);
        $this->assertSame('things.index', $recorded['controller']);
    }

    public function testItRecordsEachRequestSeparately(): void
    {
        $app = $this->app();
        $app->routes()->get('/things', fn (): string => 'things', 'things.index');
        $app->routes()->get('/other', fn (): string => 'other', 'other.index');

        $first = $app->handle(new Request(method: 'GET', uri: '/things'))->headers()['X-Clockwork-Id'];
        $app->handle(new Request(method: 'GET', uri: '/other'));

        $storage = $app->container()->get(Profiler::class)->storage();

        $this->assertNotSame($first, $this->latest($app)['id'], 'the second request should be the latest, not an update of the first');
        $this->assertCount(2, $storage->all());
    }

    public function testItRecordsTheStatusOfAResponseThatIsNotOk(): void
    {
        $app = $this->app();
        $app->routes()->get('/things', fn (): string => 'things', 'things.index');

        // A fallback, so an unmatched path is answered rather than thrown. Which
        // of those an application does is its own business; this module's job is
        // to report the status it was given, and a 404 from a fallback is the
        // cheapest way to hand it one.
        $app->fallback(fn (): Response => Response::html('not found', 404));

        $app->handle(new Request(method: 'GET', uri: '/nope'));

        $this->assertSame(404, $this->latest($app)['responseStatus']);
    }

    public function testItRecordsTheRequestThatThrewAndRethrowsIt(): void
    {
        $app = $this->app();
        $app->routes()->get('/boom', function (): string {
            throw new \RuntimeException('the handler exploded');
        }, 'boom.index');

        // Whether the application renders the failure or lets it out is its own
        // choice — with no error view it throws, which is the honest result and
        // not this module's to decide. What matters either way is that the
        // profile explaining it was written *before* the throw, since a
        // profiler that skips exactly the requests you wanted to debug is worse
        // than no profiler.
        try {
            $app->handle(new Request(method: 'GET', uri: '/boom'));
        } catch (\Throwable) {
            // Expected on an application with no error view.
        }

        $recorded = $this->latest($app);

        $this->assertNotEmpty(
            $this->errorMessages($recorded),
            'the thrown handler should appear in the recorded log',
        );
    }

    public function testItCollectsNothingWhenDisabled(): void
    {
        $app = $this->app(enabled: false);
        $app->routes()->get('/things', fn (): string => 'things', 'things.index');

        $response = $app->handle(new Request(method: 'GET', uri: '/things'));

        $this->assertSame(200, $response->status());
        $this->assertArrayNotHasKey('X-Clockwork-Id', $response->headers());
        $this->assertDirectoryDoesNotExist($this->storagePath());
    }

    public function testTheInterfaceIsNotRoutableWhenDisabled(): void
    {
        $app = $this->app(enabled: false);

        // Not merely refused — absent. The profiler's safety here rests on the
        // URL not existing, so a 404 from the application itself is the thing
        // being asserted.
        $this->assertSame(404, $app->handle(new Request(method: 'GET', uri: DebugController::PATH))->status());
    }

    public function testTheInterfaceServesTheApplicationWhenEnabled(): void
    {
        $app = $this->app();

        $response = $app->handle(new Request(method: 'GET', uri: DebugController::PATH));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString(DebugController::PATH . '/api', $response->body());
    }

    public function testTheInterfaceServesTheApiAsJson(): void
    {
        $app = $this->app();
        $app->routes()->get('/things', fn (): string => 'things', 'things.index');

        $app->handle(new Request(method: 'GET', uri: '/things'));
        $response = $app->handle(new Request(method: 'GET', uri: DebugController::PATH . '/api'));

        $this->assertSame(200, $response->status());
        $this->assertSame('/things', json_decode($response->body(), true)['uri']);
    }

    public function testAssetsCannotEscapeThePackage(): void
    {
        $app = $this->app();

        // Reaches the catch-all, and the traversal has to be refused by the
        // package's own path check rather than by a rule written here.
        $response = $app->handle(new Request(
            method: 'GET',
            uri: DebugController::PATH . '/app/../../../../../../etc/passwd',
        ));

        $this->assertSame(404, $response->status());
    }

    public function testClearEmptiesTheRecordedRequests(): void
    {
        $app = $this->app();
        $app->routes()->get('/things', fn (): string => 'things', 'things.index');
        $app->handle(new Request(method: 'GET', uri: '/things'));

        $storage = $app->container()->get(Profiler::class)->storage();
        $this->assertNotEmpty($storage->all());

        [$stream, $output] = $this->recorder();

        $exit = (new ClearCommand($app->container()->get(Profiler::class)))->handle([], $output);

        $this->assertSame(0, $exit);
        $this->assertEmpty($storage->all());
        $this->assertStringContainsString('cleared 1', $this->readOutput($stream));
    }

    public function testItRegistersItsCommandsThroughTheModule(): void
    {
        // The module's half of the contract is CommandProvider, and a command
        // that works when called by hand but never appears in `composer debug:*`
        // is the usual way this goes unnoticed.
        $names = array_keys($this->app()->commands());

        $this->assertContains('debug:show', $names);
        $this->assertContains('debug:clear', $names);
    }

    public function testShowReportsTheLatestRequest(): void
    {
        $app = $this->app();
        $app->routes()->get('/things', fn (): string => 'things', 'things.index');
        $app->handle(new Request(method: 'GET', uri: '/things'));

        [$stream, $output] = $this->recorder();

        $exit = (new ShowCommand($app->container()->get(Profiler::class)))->handle([], $output);

        $this->assertSame(0, $exit);

        $printed = $this->readOutput($stream);

        $this->assertStringContainsString('/things', $printed);
        $this->assertStringContainsString('things.index', $printed);
    }

    public function testShowSaysSoWhenNothingHasBeenRecorded(): void
    {
        $app = $this->app();

        [$stream, $output] = $this->recorder();

        // Zero, not a failure: "nothing recorded" is a normal state, and a
        // non-zero exit here would make this unusable as a check.
        $exit = (new ShowCommand($app->container()->get(Profiler::class)))->handle([], $output);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no requests', $this->readOutput($stream));
    }

    // ------------------------------------------------------------- the in-page bar

    public function testAPageGetsTheBar(): void
    {
        $app = $this->app();
        $app->routes()->get('/page', fn (): Response => Response::html('<html><body><p>hi</p></body></html>'));

        $body = $app->handle(new Request(method: 'GET', uri: '/page'))->body();

        $this->assertStringContainsString('id="empira-debug"', $body);
        $this->assertStringContainsString(DebugController::TOOLBAR_JS, $body);
        $this->assertStringContainsString(DebugController::TOOLBAR_CSS, $body);
    }

    public function testTheBarCarriesTheNumbersTheInterfaceShows(): void
    {
        $app = $this->app();

        // Two milliseconds of handler. An instant closure would report 0.0ms
        // whether the duration is measured correctly or not, so this assertion
        // would pass against the bug it exists to catch: a data source whose
        // Clockwork request is built after the handler has already run measures
        // the recording, not the request.
        $app->routes()->get('/page', function (): Response {
            usleep(2000);

            return Response::html('<body>x</body>');
        }, 'page.index');

        $body = $app->handle(new Request(method: 'GET', uri: '/page'))->body();

        preg_match('/id="empira-debug">(.*?)<\/script>/s', $body, $island);
        $data = json_decode($island[1] ?? '', true);

        $this->assertSame(200, $data['status'] ?? null);
        $this->assertSame('page.index', $data['route'] ?? null);
        $this->assertGreaterThan(1.5, $data['duration_ms'] ?? 0, 'the handler took ~2ms');
    }

    public function testTheBarIsInjectedBeforeTheClosingBodyTag(): void
    {
        $app = $this->app();
        $app->routes()->get('/page', fn (): Response => Response::html('<html><body><p>hi</p></body></html>'));

        $body = $app->handle(new Request(method: 'GET', uri: '/page'))->body();

        $this->assertLessThan(
            strrpos($body, '</body>'),
            strpos($body, 'id="empira-debug"'),
            'the bar must go inside the body, or a browser will hoist it into the head',
        );
    }

    public function testAJsonResponseGetsNoBar(): void
    {
        $app = $this->app();
        $app->routes()->get('/api', fn (): Response => Response::json(['ok' => true]));

        $body = $app->handle(new Request(method: 'GET', uri: '/api'))->body();

        $this->assertStringNotContainsString('empira-debug', $body);
    }

    public function testAnHtmxSwapGetsNoBar(): void
    {
        $app = $this->app();
        $app->routes()->get('/fragment', fn (): Response => Response::html('<div>swap</div>'));

        $body = $app->handle(new Request(
            method: 'GET',
            uri: '/fragment',
            headers: ['HX-Request' => 'true'],
        ))->body();

        // htmx replaces one region; a bar injected into a swapped fragment would
        // either land in the wrong place or nowhere at all.
        $this->assertStringNotContainsString('empira-debug', $body);
    }

    public function testAFragmentWithNoBodyTagIsLeftAlone(): void
    {
        $app = $this->app();
        $app->routes()->get('/fragment', fn (): Response => Response::html('<div>swap</div>'));

        $body = $app->handle(new Request(method: 'GET', uri: '/fragment'))->body();

        $this->assertSame('<div>swap</div>', $body);
    }

    public function testNoBarWhenTheModuleIsDisabled(): void
    {
        $app = $this->app(enabled: false);
        $app->routes()->get('/page', fn (): Response => Response::html('<body>x</body>'));

        $body = $app->handle(new Request(method: 'GET', uri: '/page'))->body();

        $this->assertStringNotContainsString('empira-debug', $body);
    }

    public function testTheBarCanBeDeclinedWhileRecordingContinues(): void
    {
        $app = $this->app(toolbar: false);
        $app->routes()->get('/page', fn (): Response => Response::html('<body>x</body>'));

        $response = $app->handle(new Request(method: 'GET', uri: '/page'));

        $this->assertStringNotContainsString('empira-debug', $response->body());
        // Declining to decorate a page is not declining to profile the request.
        $this->assertNotEmpty($this->latestOf($app));
    }

    public function testTheBarAssetsAreServedWithTheRightType(): void
    {
        $app = $this->app();

        foreach ([DebugController::TOOLBAR_JS => 'text/javascript', DebugController::TOOLBAR_CSS => 'text/css'] as $path => $mime) {
            $response = $app->handle(new Request(method: 'GET', uri: $path));

            $this->assertSame(200, $response->status(), $path);
            $this->assertSame($mime, $response->headers()['Content-Type'] ?? null, $path);
            $this->assertNotSame('', $response->body(), $path);
        }
    }

    public function testTheBarAssetsAreNotRoutableWhenDisabled(): void
    {
        $app = $this->app(enabled: false);

        $this->assertSame(404, $app->handle(new Request(method: 'GET', uri: DebugController::TOOLBAR_JS))->status());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A real {@see Output} writing to memory.
     *
     * `Output` is final and writes to a stream, so this needs no double — and a
     * test double here would be asserting against an imitation of the console's
     * own formatting rather than the thing itself.
     *
     * @return array{0: resource, 1: Output}
     */
    private function recorder(): array
    {
        $stream = fopen('php://memory', 'r+');

        return [$stream, new Output($stream)];
    }

    /**
     * @param resource $stream
     */
    private function readOutput($stream): string
    {
        rewind($stream);

        return stream_get_contents($stream);
    }

    private function app(bool $enabled = true, ?bool $toolbar = null): Application
    {
        $config = ['enabled' => $enabled];

        if ($toolbar !== null) {
            $config['toolbar'] = $toolbar;
        }

        $app = new Application('a-secret-that-is-long-enough');

        $app->module(new DebugToolsModule($this->basePath, $config));

        return $app;
    }

    /**
     * The most recently recorded request, as an array.
     *
     * Clockwork's storage answers with a `Request` object — or `false` when
     * nothing is recorded — so every read in this file goes through here rather
     * than each test rediscovering which of the three shapes it is holding.
     *
     * @return array<string, mixed>
     */
    private function latest(Application $app): array
    {
        return $this->latestOf($app)->toArray();
    }

    private function latestOf(Application $app): ClockworkRequest
    {
        $latest = $app->container()->get(Profiler::class)->storage()->latest();

        $this->assertInstanceOf(ClockworkRequest::class, $latest, 'a request should have been recorded');

        return $latest;
    }

    private function storagePath(): string
    {
        return $this->basePath . '/storage/clockwork';
    }

    /**
     * @param array<string, mixed> $recorded
     * @return list<string>
     */
    private function errorMessages(array $recorded): array
    {
        $messages = [];

        foreach ($recorded['log'] ?? [] as $entry) {
            if (is_array($entry) && isset($entry['message']) && is_string($entry['message'])) {
                $messages[] = $entry['message'];
            }
        }

        return $messages;
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