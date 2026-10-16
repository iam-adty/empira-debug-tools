<?php

declare(strict_types=1);

namespace Empira\DebugTools\Console;

use Empira\Console\Command;
use Empira\Console\Output;
use Empira\DebugTools\Profiler;

/**
 * Prints the most recently recorded request to the terminal.
 *
 * ## Why this exists at all, when there is a toolbar
 *
 * Because the interesting moment is very often the one where you cannot open a
 * browser: inside a container, over a remote shell, in CI, while something else is
 * holding the port. The data is already on disk either way, and reading it with
 * `cat` and `jq` is a worse way of reading it than a command that already knows
 * where it is.
 *
 * It prints a summary rather than the whole profile. The full record includes
 * headers and every timing measurement, and is thousands of lines long — which is
 * the wrong shape for a terminal and the right shape for the toolbar.
 *
 * ## Durations arrive in seconds
 *
 * DebugBar's storage records every measure in seconds, because that is what its
 * formatter works in. The conversion to milliseconds happens here, at the last
 * moment before printing, so nothing stored says one thing and displayed says
 * another.
 */
final class ShowCommand implements Command
{
    public function __construct(
        private readonly Profiler $profiler,
    ) {
    }

    public function name(): string
    {
        return 'debug:show';
    }

    public function description(): string
    {
        return 'Show the most recent profiled request.';
    }

    public function arguments(): array
    {
        return ['[--id=<id>]'];
    }

    public function handle(array $options, Output $output): int
    {
        $storage = $this->profiler->storage();

        $id = isset($options['id']) ? (string) $options['id'] : null;

        if ($id !== null) {
            $data = $storage->get($id);

            if ($data === []) {
                $output->warn(sprintf('no request stored under id %s', $id));

                return 1;
            }
        } else {
            // ## Why not simply `find()[0]`
            //
            // `find()` orders by request id, and an id begins with a millisecond
            // timestamp — "lexical sort = time sort", as its generator says. Two
            // requests handled in the same millisecond therefore share that prefix
            // and are ordered by the random bytes after it, which have nothing to
            // do with which request happened first. That is not hypothetical: two
            // requests in a test are microseconds apart, and `debug:show` reporting
            // the *previous* one would be a bug nobody could explain.
            //
            // `__meta['utime']` is `microtime(true)`, so it orders correctly at a
            // resolution nothing in this path can exceed. Only the ids already
            // returned by `find()` are compared, which is a bounded read.
            $found = $storage->find();

            if ($found === []) {
                $output->info($this->profiler->storagePath() . ' holds no requests yet.');

                return 0;
            }

            $newest = $found[0];

            foreach ($found as $candidate) {
                if (($candidate['utime'] ?? 0) > ($newest['utime'] ?? 0)) {
                    $newest = $candidate;
                }
            }

            $data = $storage->get((string) ($newest['id'] ?? ''));
        }

        if ($data === []) {
            $output->info($this->profiler->storagePath() . ' holds no requests yet.');

            return 0;
        }

        $meta = is_array($data['__meta'] ?? null) ? $data['__meta'] : [];
        $empira = is_array($data['empira'] ?? null) ? $data['empira'] : [];

        $output->line(sprintf('id          %s', (string) ($meta['id'] ?? 'unknown')));
        $output->line(sprintf(
            'url         %s %s',
            (string) ($empira['method'] ?? $meta['method'] ?? '?'),
            (string) ($empira['uri'] ?? $meta['uri'] ?? '?'),
        ));
        $output->line(sprintf('status      %s', (string) ($empira['status'] ?? '?')));

        $duration = $empira['duration'] ?? null;
        $output->line(sprintf(
            'duration    %s',
            is_numeric($duration) ? sprintf('%.1f ms', ((float) $duration) * 1000) : '?',
        ));

        $memory = $empira['memory'] ?? null;
        if (is_numeric($memory)) {
            $output->line(sprintf('memory      %.1f MB', ((float) $memory) / 1048576));
        }

        $route = $empira['route'] ?? null;
        if ($route !== null && $route !== '') {
            $output->line(sprintf('route       %s', (string) $route));
        }

        // An exception means the request failed, and `debug:show` saying so is
        // more useful than exiting zero over a profile of a 500.
        $error = $empira['error'] ?? null;
        if ($error !== null && $error !== '') {
            $output->error((string) $error);

            return 1;
        }

        return 0;
    }
}