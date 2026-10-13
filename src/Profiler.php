<?php

declare(strict_types=1);

namespace Empira\DebugTools;

use Clockwork\Clockwork;
use Clockwork\Request\Request as ClockworkRequest;
use Clockwork\Storage\FileStorage;
use Clockwork\Storage\StorageInterface;

/**
 * The one place a Clockwork instance lives.
 *
 * Three callers need it — the middleware that records, the controller that
 * displays, and the commands that inspect and clear — and a profiler with three
 * Clockwork instances is three storages and three sets of ids, so they share
 * this one. Built once per application, which is what makes the configuration
 * below matter: `storagePath()` is read at construction, so a worker that kept
 * two would not agree with itself about where the files are.
 *
 * ## Why "enabled" is decided once, at construction
 *
 * A profiler writes request data to disk and serves it back over HTTP. That is
 * fine on a laptop and a liability in production, so the gate is evaluated
 * before the first request rather than per request: a per-request check reads as
 * though it could turn itself off halfway through a session, and the only
 * moment that matters is the moment the environment is misread.
 */
final class Profiler
{
    private readonly Clockwork $clockwork;

    private readonly EmpiraDataSource $source;

    private readonly bool $enabled;

    /** @var array<string, mixed> */
    private readonly array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly string $basePath,
        array $config = [],
    ) {
        $this->config = $config;

        $this->enabled = (bool) ($config['enabled']
            ?? in_array($this->environment(), ['local', 'dev', 'development', 'test'], true));

        $this->clockwork = new Clockwork();
        $this->clockwork->storage(new FileStorage($this->storagePath()));

        // Registered once, here, rather than per request. `addDataSource()`
        // appends without checking for a duplicate, so adding on every request
        // would leave the list one entry longer each time — invisible in a
        // single request and unbounded under a worker that serves a few thousand
        // before it recycles. One source, reused, is what the interface assumes
        // anyway: it is told one request, not several.
        $this->source = new EmpiraDataSource();
        $this->clockwork->addDataSource($this->source);

        // Nothing here serves the UI, so the authenticator is never consulted:
        // access is decided by the route not being registered at all when the
        // profiler is off, rather than by a check on every request. A gate that
        // can be forgotten is a gate that eventually is.
    }

    /**
     * The one data source, for the middleware to feed.
     *
     * Shared deliberately. It looks per-request — it holds the request in hand —
     * but it is cleared and re-primed at both ends of every request, and having
     * the middleware write to a *different* object from the one Clockwork reads
     * would record nothing at all.
     */
    public function source(): EmpiraDataSource
    {
        return $this->source;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function clockwork(): Clockwork
    {
        return $this->clockwork;
    }

    public function storage(): StorageInterface
    {
        $this->ensureStorageExists();

        return $this->clockwork->storage();
    }

    /**
     * Where the recorded requests live.
     *
     * Under the application's `storage/`, not in the package's vendor directory:
     * this is data the application produces, it should be writable by whoever
     * owns the application, and it should survive a `composer install`.
     */
    public function storagePath(): string
    {
        $configured = $this->config['storage_path'] ?? null;

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return $this->basePath . '/storage/clockwork';
    }

    /**
     * Resolve and store the request the source has been given.
     *
     * Returns the id, or null when profiling is off — so the caller can skip
     * adding headers rather than add empty ones.
     *
     * The fresh {@see ClockworkRequest} is the load-bearing part. Clockwork
     * keeps one request object on its own instance and `resolveRequest()` writes
     * into whatever is already there, so without replacing it the second request
     * in a process would be stored under the first request's id, inheriting its
     * duration and accumulating its timeline entries. Under a worker that is one
     * profile growing for as long as the process lives, presented as though each
     * page load were its own.
     */
    public function record(): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        try {
            $this->clockwork->request(new ClockworkRequest());
            $this->clockwork->resolveRequest();
            $this->clockwork->storeRequest();

            return $this->clockwork->request()->id;
        } finally {
            // Cleared whether or not the store succeeded. A source still holding
            // the previous request's objects is what makes the next one wrong,
            // and a profiler that is careful on the happy path is not careful on
            // the one that threw.
            $this->source->reset();
        }
    }

    /**
     * Put the storage into the shape Clockwork expects before anything reads it.
     *
     * Clockwork establishes this itself — directory, an empty `index`, and a
     * `.gitignore` — but only inside a *protected* method it calls when storing
     * a request. Nothing calls it on the way in, so `latest()` on a fresh
     * installation `fopen()`s an index that does not exist and answers with PHP
     * warnings and a misleading false. `debug:show` and the interface both ask
     * that question precisely when nobody has debugged anything yet.
     *
     * So the three things are established here, matching what Clockwork writes.
     * The `.gitignore` is the one that is easy to miss and awkward to discover
     * later: without it, every recorded request — URLs, headers, whatever
     * payloads an application opted into — is a file a consumer's next `git add`
     * would commit.
     */
    private function ensureStorageExists(): void
    {
        $path = $this->storagePath();

        if (!is_dir($path)) {
            @mkdir($path, 0700, true);
            @file_put_contents($path . '/.gitignore', "*.json\n*.json.gz\nindex\n");
        }

        if (!is_file($path . '/index')) {
            @file_put_contents($path . '/index', '');
        }
    }

    /**
     * The environment name, as the rest of the workspace reads it.
 *
 * `$_ENV` first because that is what every other package here reads — the
 * framework's own configuration, the skeleton's logging setup — and a profiler
 * that disagreed with the application about which environment it is running in
 * would be deciding to record against a different answer than everything else
 * already gave. `getenv()` second, for a process variable that arrived without
 * populating the superglobal.
 */
private function environment(): string
    {
        $env = $this->config['environment'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');

        return is_string($env) ? strtolower($env) : 'production';
    }
}