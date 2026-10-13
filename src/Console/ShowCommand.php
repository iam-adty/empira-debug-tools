<?php

declare(strict_types=1);

namespace Empira\DebugTools\Console;

use Clockwork\Request\Request as ClockworkRequest;
use Empira\Console\Command;
use Empira\Console\Output;
use Empira\DebugTools\Profiler;

/**
 * Prints the most recently recorded request to the terminal.
 *
 * ## Why this exists at all, when there is a browser interface
 *
 * Because the interesting moment is very often the one where you cannot open a
 * browser: inside a container, over a remote shell, in CI, while something else
 * is holding the port. The data is already on disk either way, and reading it
 * with `cat` and `jq` is a worse way of reading it than a command that already
 * knows where it is.
 *
 * It prints a summary rather than the whole profile. The full record includes
 * headers and every timing measurement, and is thousands of lines long — which
 * is the wrong shape for a terminal and the right shape for the interface.
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
        $id = isset($options['id']) ? (string) $options['id'] : null;
        $found = $id === null ? $this->profiler->storage()->latest() : $this->profiler->storage()->find($id);

        // Storage answers with a `Request` object, or `false` when there is
        // nothing recorded — not null and not an array. `toArray()` is the shape
        // this command reads, and treating "no requests" as its own case is
        // more honest than letting a `false` reach an array type hint.
        $data = $found instanceof ClockworkRequest ? $found->toArray() : [];

        if ($data === []) {
            // An empty read is reported as such rather than as a failure: having
            // no requests yet is a normal state, and a non-zero exit would make
            // this unusable in a script that is checking whether anything was
            // recorded.
            $output->info($this->profiler->storagePath() . ' holds no requests yet.');

            return 0;
        }

        $output->line(sprintf('id          %s', (string) ($data['id'] ?? 'unknown')));
        $output->line(sprintf('url         %s %s', (string) ($data['method'] ?? '?'), (string) ($data['uri'] ?? '?')));
        $output->line(sprintf('status      %s', (string) ($data['responseStatus'] ?? '?')));

        $duration = $data['responseDuration'] ?? null;
        $output->line(sprintf('duration    %s', is_numeric($duration) ? sprintf('%.1f ms', $duration * 1000) : '?'));

        $memory = $data['memoryUsage'] ?? null;
        if (is_numeric($memory)) {
            $output->line(sprintf('memory      %.1f MB', $memory / 1048576));
        }

        if (($data['controller'] ?? null) !== null && $data['controller'] !== '') {
            $output->line(sprintf('route       %s', (string) $data['controller']));
        }

        $errors = $data['log'] ?? [];
        foreach ($errors as $entry) {
            if (($entry['level'] ?? '') === 'error') {
                $output->error(sprintf('%s', (string) ($entry['message'] ?? 'error')));

                return 1;
            }
        }

        return 0;
    }
}