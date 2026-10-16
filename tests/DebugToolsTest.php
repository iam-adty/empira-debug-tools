<?php

declare(strict_types=1);

namespace Empira\DebugTools\Tests;

use Empira\Application;
use Empira\Console\Output;
use Empira\DebugTools\Console\ClearCommand;
use Empira\DebugTools\Console\ShowCommand;
use Empira\DebugTools\DebugBarController;
use Empira\DebugTools\DebugToolsModule;
use Empira\DebugTools\Profiler;
use Empira\Http\Request;
use Empira\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * The module, exercised through a real application.
 *
 * Every test here drives `$app->handle()` rather than calling the collector
 * directly. That is deliberate: the interesting failures in this kind of module
 * are not in the reading, they are in the wiring — a middleware registered after
 * the pipeline was built, a route that never matches, a storage directory that
 * is one level too high. None of those are visible from the inside.
 *
 * ## Injection is asserted by reading the body, not by opening a browser
 *
 * There is no browser in this workspace and none is needed. The contract is that
 * a toolbar is *in* a response body and not in others, and a body is directly
 * assertable. What a browser would add — that the JavaScript parses and the
 * panel draws — is upstream DebugBar's own testing, not ours.
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

    // ------------------------------------------------------------- recording

    public function testItRecordsARequestAndNamesTheRouteThatServedIt(): void
    {
        $app = $this->app();
        $app->routes()->get('/things', fn (): string => 'things', 'things.index');

        $app->handle(new Request(method: 'GET', uri: '/things'));

        $recorded = $this->latest($app);

        $this->assertSame('GET', $recorded['method']);
        $this->assertSame('/things', $recorded['uri']);
        $this->assertSame(200, $recorded['status']);
        $this->assertSame('things.index', $recorded['route']);
    }

    public function testItRecordsEachRequestSeparately(): void
    {
        $app = $this->app();
        $app->routes()->get('/things', fn (): string => 'things', 'things.index');
        $app->routes()->get('/other', fn (): string => 'other', 'other.index');

        $app->handle(new Request(method: 'GET', uri: '/things'));
        $app->handle(new Request(method: 'GET', uri: '/other'));

        $storage = $app->container()->get(Profiler::class)->storage();

        $this->assertNotSame(
            $this->latest($app)['uri'],
            '/things',
            'the second request should be the latest, not an update of the first',
        );
        $this->assertCount(2, $storage->find());
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

        $this->assertSame(404, $this->latest($app)['status']);
    }

    public function testItRecordsTheRequestThatThrewAndRethrowsIt(): void
    {
        $app = $this->app();
        $app->routes()->get('/boom', function (): string {
            throw new \RuntimeException('it broke');
        });

        try {
            $app->handle(new Request(method: 'GET', uri: '/boom'));
            $this->fail('the exception should have been rethrown, not swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('it broke', $e->getMessage());
        }

        // The one request you most wanted to look at is the one that failed.
        $this->assertStringContainsString('it broke', (string) $this->latest($app)['error']);
    }

    public function testItCollectsNothingWhenDisabled(): void
    {
        $app = $this->app(enabled: false);
        $app->routes()->get('/things', fn (): string => 'things', 'things.index');

        $app->handle(new Request(method: 'GET', uri: '/things'));

        $this->assertSame([], $app->container()->get(Profiler::class)->storage()->find());
    }

    // ------------------------------------------------------------- injection

    public function testTheToolbarIsInjectedIntoAFullHtmlDocument(): void
    {
        $app = $this->app();
        $app->routes()->get('/page', fn (): Response => Response::html(
            '<html><head><title>things</title></head><body><h1>things</h1></body></html>',
        ));

        $body = $app->handle(new Request(method: 'GET', uri: '/page'))->body();

        // The application's own markup is still there, in order, with the toolbar
        // after the content rather than replacing it.
        $this->assertStringContainsString('<h1>things</h1>', $body);
        $this->assertStringContainsString('debugbar', strtolower($body));
        $this->assertGreaterThan(
            strpos($body, '<h1>things</h1>'),
            (int) strrpos($body, 'debugbar'),
            'the toolbar belongs after the page content',
        );
    }

    public function testTheToolbarStylesheetGoesInTheHead(): void
    {
        $app = $this->app();
        $app->routes()->get('/page', fn (): Response => Response::html(
            '<html><head><title>t</title></head><body></body></html>',
        ));

        $body = $app->handle(new Request(method: 'GET', uri: '/page'))->body();

        // Before `</head>`, so the toolbar does not flash unstyled.
        $this->assertLessThan((int) strpos($body, '</head>'), (int) strpos($body, 'debugbar'));
    }

    public function testNothingIsInjectedIntoJson(): void
    {
        $app = $this->app();
        $app->routes()->get('/api', fn (): Response => Response::json(['ok' => true]));

        $response = $app->handle(new Request(method: 'GET', uri: '/api'));

        $this->assertStringNotContainsStringIgnoringCase('debugbar', $response->body());
        $this->assertSame('{"ok":true}', $response->body(), 'a JSON body must stay byte-identical');
    }

    public function testNothingIsInjectedIntoAnHtmxSwap(): void
    {
        $app = $this->app();
        $app->routes()->get('/fragment', fn (): Response => Response::html('<div>partial</div>'));

        // The case that makes the whole injection rule a correctness condition
        // rather than a precaution: a swap is answered with a fragment, and a
        // toolbar appended to one would land inside the element being replaced.
        $body = $app->handle(new Request(
            method: 'GET',
            uri: '/fragment',
            headers: ['HX-Request' => 'true'],
        ))->body();

        $this->assertSame('<div>partial</div>', $body);
    }

    public function testNothingIsInjectedWhenDisabled(): void
    {
        $app = $this->app(enabled: false);
        $app->routes()->get('/page', fn (): Response => Response::html(
            '<html><head></head><body><h1>quiet</h1></body></html>',
        ));

        $body = $app->handle(new Request(method: 'GET', uri: '/page'))->body();

        $this->assertSame('<html><head></head><body><h1>quiet</h1></body></html>', $body);
    }

    public function testNothingIsInjectedIntoAStreamedFile(): void
    {
        $app = $this->app();
        $file = $this->basePath . '/page.html';
        file_put_contents($file, '<html><body>on disk</body></html>');

        $app->routes()->get('/file', fn (): Response => Response::html('')->stream($file));

        // `stream()` leaves the body empty and the file to be read at emit time, so
        // there is nothing to rewrite. Injected into, the page would arrive with a
        // toolbar and a truncated body, or not arrive at all.
        $response = $app->handle(new Request(method: 'GET', uri: '/file'));

        $this->assertSame('', $response->body());
    }

    // ---------------------------------------------------------------- routes

    public function testTheAssetsRouteServesJavaScriptAndCss(): void
    {
        $app = $this->app();

        $js = $app->handle(new Request(method: 'GET', uri: DebugBarController::ASSETS, query: ['type' => 'js']));
        $css = $app->handle(new Request(method: 'GET', uri: DebugBarController::ASSETS, query: ['type' => 'css']));

        $this->assertSame(200, $js->status());
        $this->assertStringContainsStringIgnoringCase('javascript', $this->header($js, 'content-type'));
        $this->assertNotSame('', trim($js->body()), 'the script the toolbar needs');

        $this->assertSame(200, $css->status());
        $this->assertStringContainsString('text/css', $this->header($css, 'content-type'));
    }

    public function testTheAssetsRouteRefusesAnUnknownType(): void
    {
        $app = $this->app();

        $response = $app->handle(new Request(
            method: 'GET',
            uri: DebugBarController::ASSETS,
            query: ['type' => 'php'],
        ));

        $this->assertSame(404, $response->status());
    }

    public function testTheOpenHandlerReturnsTheCurrentRequestAsJson(): void
    {
        $app = $this->app();
        $app->routes()->get('/things', fn (): string => 'things', 'things.index');
        $app->handle(new Request(method: 'GET', uri: '/things'));

        $response = $app->handle(new Request(
            method: 'GET',
            uri: DebugBarController::OPEN_HANDLER,
            query: ['op' => 'find'],
        ));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('application/json', $this->header($response, 'content-type'));

        $found = json_decode($response->body(), true);

        $this->assertIsArray($found);
        $this->assertNotSame([], $found, 'the recorded request should be listed');
    }

    public function testNeitherEndpointIsRoutableWhenDisabled(): void
    {
        $this->assertSame(404, $this->app(enabled: false)->handle(
            new Request(method: 'GET', uri: DebugBarController::ASSETS, query: ['type' => 'js']),
        )->status());

        $this->assertSame(404, $this->app(enabled: false)->handle(
            new Request(method: 'GET', uri: DebugBarController::OPEN_HANDLER, query: ['op' => 'find']),
        )->status());
    }

    // --------------------------------------------------------------- commands

    public function testClearEmptiesTheRecordedRequests(): void
    {
        $app = $this->app();
        $app->routes()->get('/things', fn (): string => 'things', 'things.index');
        $app->handle(new Request(method: 'GET', uri: '/things'));

        $storage = $app->container()->get(Profiler::class)->storage();
        $this->assertNotEmpty($storage->find());

        [$stream, $output] = $this->recorder();

        $exit = (new ClearCommand($app->container()->get(Profiler::class)))->handle([], $output);

        $this->assertSame(0, $exit);
        $this->assertEmpty($storage->find());
        $this->assertStringContainsString('cleared 1', $this->readOutput($stream));
    }

    public function testClearCanPruneByAgeInstead(): void
    {
        $app = $this->app();
        $app->routes()->get('/things', fn (): string => 'things', 'things.index');
        $app->handle(new Request(method: 'GET', uri: '/things'));

        $storage = $app->container()->get(Profiler::class)->storage();
        $this->assertNotEmpty($storage->find());

        [$stream, $output] = $this->recorder();

        $exit = (new ClearCommand($app->container()->get(Profiler::class)))
            ->handle(['hours' => '24'], $output);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('pruned', $this->readOutput($stream));
        // Nothing is an hour old yet, so pruning by age keeps what it was asked to
        // keep rather than clearing everything the way `debug:clear` does.
        $this->assertNotEmpty($storage->find());
    }

    public function testClearRejectsAnAgeOfLessThanAnHour(): void
    {
        $app = $this->app();

        [$stream, $output] = $this->recorder();

        $exit = (new ClearCommand($app->container()->get(Profiler::class)))
            ->handle(['hours' => '0'], $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('at least 1', $this->readOutput($stream));
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

    public function testShowExitsNonZeroForARequestThatFailed(): void
    {
        $app = $this->app();
        $app->routes()->get('/boom', function (): string {
            throw new \RuntimeException('it broke');
        });

        try {
            $app->handle(new Request(method: 'GET', uri: '/boom'));
        } catch (\RuntimeException) {
            // expected
        }

        [$stream, $output] = $this->recorder();

        $exit = (new ShowCommand($app->container()->get(Profiler::class)))->handle([], $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('it broke', $this->readOutput($stream));
    }

    // ---------------------------------------------------------------- helpers

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

    private function app(bool $enabled = true): Application
    {
        $app = new Application('a-secret-that-is-long-enough');

        $app->module(new DebugToolsModule($this->basePath, ['enabled' => $enabled]));

        return $app;
    }

    private function storage(Application $app): \DebugBar\Storage\StorageInterface
    {
        return $app->container()->get(Profiler::class)->storage();
    }

    /**
     * One response header, case-insensitively.
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
     * The most recently recorded request's `empira` data.
     *
     * Storage answers with an array, an empty list when nothing is recorded, and
     * `find()` returns metadata newest-first — so every read in this file goes
     * through here rather than each test rediscovering which shape it is holding.
     *
     * @return array<string, mixed>
     */
    private function latest(Application $app): array
    {
        $data = $this->storage($app)->get($this->latestId($app));

        $this->assertIsArray($data['empira'] ?? null, 'a request should have been recorded');

        return $data['empira'];
    }

    /**
     * The newest request, by `utime` rather than by id.
     *
     * `find()` sorts by request id, which begins with a *millisecond* timestamp, so
     * two requests microseconds apart share that prefix and their order falls to
     * the random bytes after it. Asserting on `find()[0]` here made this file
     * fail about one run in three.
     *
     * @see \Empira\DebugTools\Console\ShowCommand for why the command has the
     *      same problem and the same answer.
     */
    private function latestId(Application $app): string
    {
        $found = $this->storage($app)->find();

        $this->assertNotSame([], $found, 'a request should have been recorded');

        $newest = $found[0];

        foreach ($found as $candidate) {
            if (($candidate['utime'] ?? 0) > ($newest['utime'] ?? 0)) {
                $newest = $candidate;
            }
        }

        return (string) ($newest['id'] ?? '');
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