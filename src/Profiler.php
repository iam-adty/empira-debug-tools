<?php

declare(strict_types=1);

namespace Empira\DebugTools;

use DebugBar\DataCollector\ExceptionsCollector;
use DebugBar\DataCollector\MemoryCollector;
use DebugBar\DataCollector\MessagesCollector;
use DebugBar\DataCollector\PhpInfoCollector;
use DebugBar\DataCollector\TimeDataCollector;
use DebugBar\Storage\FileStorage;
use DebugBar\Storage\StorageInterface;

/**
 * The one place a DebugBar instance lives.
 *
 * Three callers need it — the middleware that records and injects, the controller
 * that serves the toolbar, and the commands that inspect and clear — and a profiler
 * with three instances is three storages and three sets of ids, so they share this
 * one. Built once per application, which is what makes the configuration below
 * matter: `storagePath()` is read at construction, so a process that kept two
 * would not agree with itself about where the files are.
 *
 * ## Why the collectors are assembled here rather than taken from StandardDebugBar
 *
 * `StandardDebugBar` is the obvious starting point and it is the wrong one. It
 * registers a `RequestDataCollector`, which harvests `$_GET`, `$_POST` and
 * `$_COOKIE` from the superglobals unconditionally — a session cookie and a
 * password field, written to disk and served back over HTTP by a tool that is on
 * by default in development.
 *
 * The six registered below are the ones that report on *the process* — PHP, time,
 * memory, messages, exceptions — plus {@see EmpiraCollector}, which reports on the
 * request and respects the same opt-in flags for payloads and cookies.
 *
 * ## Why "enabled" is decided once, at construction
 *
 * A profiler writes request data to disk and serves it back over HTTP. That is
 * fine on a laptop and a liability in production, so the gate is evaluated before
 * the first request rather than per request: a per-request check reads as though it
 * could turn itself off halfway through a session, and the only moment that
 * matters is the moment the environment is misread.
 */
final class Profiler
{
    private readonly EmpiraDebugBar $debugBar;

    private readonly StorageInterface $storage;

    private readonly EmpiraCollector $collector;

    /** Where a developer's marks go until the request is stored. */
    private readonly Marks $marks;

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

        $this->marks = new Marks();
        $this->collector = new EmpiraCollector();

        $this->collector->collectPayloads = (bool) ($config['payloads'] ?? false);
        $this->collector->collectCookies = (bool) ($config['cookies'] ?? false);

        $this->debugBar = new EmpiraDebugBar($this->collector);

        // Created before the collectors are added, because a toolbar with nowhere
        // to reopen the request it was profiling would be worse than one that
        // never claimed to. DebugBar's file storage writes one JSON file per
        // request and finds them by globbing, so there is no index to establish
        // here — the directory is all it needs and it makes that itself.
        $this->storage = new FileStorage($this->storagePath());
        $this->ensureStorageExists();

        // Registered once, here. `addCollector()` appends without checking for a
        // duplicate, so adding on every request would leave the list one entry
        // longer each time — invisible in a single request and unbounded in a
        // process that serves a few thousand before it recycles.
        // `PhpInfoCollector` and `MemoryCollector` have no constructor and name
        // themselves; the other two take their name as an argument.
        $this->debugBar->addCollector(new PhpInfoCollector());
        $this->debugBar->addCollector(new MessagesCollector('messages'));
        $this->debugBar->addCollector(new TimeDataCollector());
        $this->debugBar->addCollector(new MemoryCollector());
        $this->debugBar->addCollector(new ExceptionsCollector('exceptions'));
        $this->debugBar->addCollector($this->collector);

        $this->debugBar->setStorage($this->storage());

        // Nothing here serves the toolbar, so no authenticator is consulted: access
        // is decided by the routes not being registered at all when the profiler is
        // off, rather than by a check on every request. A gate that can be
        // forgotten is a gate that eventually is.
    }

    /**
     * The one collector, for the middleware to feed.
     *
     * Shared deliberately. It looks per-request — it holds the request in hand —
     * but it is cleared and re-primed at both ends of every request, and having
     * the middleware write to a *different* object from the one DebugBar reads
     * would record nothing at all.
     */
    public function collector(): EmpiraCollector
    {
        return $this->collector;
    }

    /**
     * The buffer {@see Debug} writes to.
     *
     * Nothing else should need it: {@see Debug} is the way in.
     */
    public function marks(): Marks
    {
        return $this->marks;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function debugBar(): EmpiraDebugBar
    {
        return $this->debugBar;
    }

    public function storage(): StorageInterface
    {
        return $this->storage;
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

        return $this->basePath . '/storage/debugbar';
    }

    /**
     * Collect everything and write it to storage.
     *
     * Returns the id, or null when profiling is off — so the caller can skip
     * injecting rather than inject a toolbar with nothing behind it.
     */
    public function record(): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        try {
            $this->flushMarks();

            // The origin every `relative_start` is measured from. Set per request
            // rather than at construction for the same reason the collector's
            // clock starts in `begin()`: one instance serves every request.
            $time = $this->debugBar->getCollector('time');
            $time->setRequestStartTime($this->collector->startedAt());

            // `collect()` writes to storage itself when a storage is set, so this
            // one call is both the reading and the saving.
            $this->debugBar->collect();

            return $this->debugBar->getCurrentRequestId();
        } finally {
            // Cleared whether or not the store succeeded. A collector still
            // holding the previous request's objects is what makes the next one
            // wrong, and a profiler that is careful on the happy path is not
            // careful on the one that threw.
            $this->collector->reset();
            $this->marks->reset();
            $this->debugBar->reset();
        }
    }

    /**
     * Move what the developer said into the collectors DebugBar reads.
     *
     * Runs here rather than when marks are made, because the collectors are only
     * assembled by the time a request ends.
     *
     * ## An unfinished span is shown as zero duration
     *
     * `Marks` leaves a span's `end` null until someone closes it. DebugBar's
     * `addMeasure()` defaults a missing end to the start, so a span nobody ended
     * appears as a point rather than stretching to the end of the request — which
     * would claim work took the whole request when the truth is that it was never
     * measured to a finish. Surfacing "you never closed this" is more useful than
     * inventing an end for it.
     */
    private function flushMarks(): void
    {
        $time = $this->debugBar->getCollector('time');
        $messages = $this->debugBar->getCollector('messages');

        foreach ($this->marks->entries() as $entry) {
            $messages->log($entry['level'], $entry['message'], $entry['context']);
        }

        // Every call produces its own measure. That is the point of a mark made in
        // a loop, and it survives because `TimeDataCollector`'s repeated-measure
        // merging is off unless asked for.
        foreach ($this->marks->events() as $event) {
            $params = $event['data'];

            // DebugBar has no per-measure colour, so a mark's colour is carried
            // through as a parameter — visible in the panel rather than painted on
            // the bar, which is the most that can honestly be promised.
            if ($event['color'] !== null) {
                $params['color'] = $event['color'];
            }

            $time->addMeasure(
                $event['description'],
                $event['start'],
                $event['end'] ?? $event['start'],
                $params,
            );
        }

        $this->lifecycle($time);
    }

    /**
     * The two moments worth having whether or not a handler was reached: when the
     * request entered, and what it matched.
     *
     * Shaped as point measures — a start equal to its own end — because that is
     * what they are. Given an end as well, a duration of milliseconds would be
     * read as work performed, and "Matched" performs none.
     */
    private function lifecycle(TimeDataCollector $time): void
    {
        $received = $this->collector->startedAt();

        if ($received > 0.0) {
            $time->addMeasure('Request received', $received, $received, ['bootstrap' => true]);
        }

        $matched = microtime(true);

        $time->addMeasure('Matched', $matched, $matched, [
            'method' => $this->collector->method(),
            'path' => $this->collector->uri(),
            'client' => $this->collector->ip(),
        ]);
    }

    /**
     * Make the storage directory before anything reads it.
     *
     * DebugBar's file storage finds requests by handing the directory to
     * `FilesystemIterator`, and that throws when the directory is not there.
     * Nobody has debugged anything yet is exactly when `debug:show`, the
     * toolbar's `find`, and `debug:clear` all ask — so the directory is
     * established here rather than discovered to be missing by the first person
     * running the tool for the first time.
     *
     * The `.gitignore` is the part that is easy to miss and awkward to find
     * later: every recorded request is a file, with URLs, headers and whatever
     * payloads an installation opted into, and without one a consumer's next
     * `git add` commits all of it.
     */
    private function ensureStorageExists(): void
    {
        $path = $this->storagePath();

        if (! is_dir($path)) {
            @mkdir($path, 0700, true);
            @file_put_contents($path . '/.gitignore', "*.json\n");
        }
    }

    /**
     * The environment name, read from `$_ENV` and from nowhere else.
     *
     * No `getenv()` fallback. This was the only `getenv()` in the workspace, and it
     * was a hole: the application strips its inherited configuration before reading
     * `.env` (`App::CONFIG_KEYS`), so a value arriving through the process
     * environment is one the application never saw — and a profiler deciding
     * whether to write every request to disk should decide on the same answer the
     * application gave, not a second one gathered behind its back.
     *
     * An unset `APP_ENV` reads as absent, which {@see DebugToolsModule} treats as
     * production and therefore disabled. A module that ran because nothing was set
     * would be a module that ran on a server whose operator set nothing at all.
     */
    private function environment(): string
    {
        $env = $this->config['environment'] ?? $_ENV['APP_ENV'] ?? null;

        return is_string($env) ? strtolower($env) : 'production';
    }
}