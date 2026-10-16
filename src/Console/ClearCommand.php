<?php

declare(strict_types=1);

namespace Empira\DebugTools\Console;

use Empira\Console\Command;
use Empira\Console\Output;
use Empira\DebugTools\Profiler;

/**
 * Empties the profiler's storage.
 *
 * ## Why this is now three lines rather than a directory walk
 *
 * Clockwork's file storage kept an append-only `index` beside one JSON file per
 * request, and opened that index unsuppressed on every read — so deleting the
 * files and the index together was a mistake waiting to happen, and clearing had
 * to truncate the index rather than remove it. DebugBar's storage has no index:
 * it finds requests by globbing `*.json`, so `clear()` is the whole operation and
 * there is no second file to get out of step.
 *
 * Only JSON files under the storage directory are ever removed, by the storage
 * that owns them. The directory is inside the application's `storage/`, this
 * module was handed a pointer to it rather than ownership of it, and "clear my
 * profiler's data" is not authority over whatever else an application keeps there.
 */
final class ClearCommand implements Command
{
    public function __construct(
        private readonly Profiler $profiler,
    ) {
    }

    public function name(): string
    {
        return 'debug:clear';
    }

    public function description(): string
    {
        return 'Delete every recorded request.';
    }

    public function arguments(): array
    {
        return ['[--hours=<n>]'];
    }

    /**
     * `--hours` deletes only what is older than the given number of hours.
     *
     * Storage that grows forever is the reason a development tool eventually stops
     * being switched on. DebugBar's storage can prune by age, and an age is more
     * useful than a count here: the request you want from yesterday morning is
     * usually the one you are still chasing, and everything older than a day of
     * work is not.
     */
    public function handle(array $options, Output $output): int
    {
        $storage = $this->profiler->storage();

        if (isset($options['hours'])) {
            $hours = (int) $options['hours'];

            if ($hours < 1) {
                $output->warn('--hours must be at least 1.');

                return 1;
            }

            $storage->prune($hours);

            $output->success(sprintf('pruned requests older than %d hour(s).', $hours));

            return 0;
        }

        if ($storage->find() === []) {
            $output->info('nothing recorded, nothing to clear.');

            return 0;
        }

        $count = count($storage->find());

        $storage->clear();

        $output->success(sprintf(
            'cleared %d recorded %s from %s',
            $count,
            $count === 1 ? 'request' : 'requests',
            $this->profiler->storagePath(),
        ));

        return 0;
    }
}