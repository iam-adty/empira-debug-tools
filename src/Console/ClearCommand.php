<?php

declare(strict_types=1);

namespace Empira\DebugTools\Console;

use Empira\Console\Command;
use Empira\Console\Output;
use Empira\DebugTools\Profiler;

/**
 * Empties the profiler's storage.
 *
 * ## Truncating the index rather than deleting everything
 *
 * Clockwork's file storage keeps an append-only `index` alongside one JSON file
 * per request, and it opens that index unsuppressed on every *read*. Deleting it
 * along with the requests — which is what "delete every file in the directory"
 * does — leaves a storage that answers every later question with PHP warnings
 * and a false instead of an empty list. The index is emptied and the request
 * files are removed, so the directory still holds what Clockwork needs to be
 * asked what is in it.
 *
 * Only `*.json` and `*.json.gz` are removed. The directory is inside the
 * application's `storage/`, this module was handed a pointer to it rather than
 * ownership of it, and "clear my profiler's data" is not authority over whatever
 * else an application has decided to keep there.
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
        return [];
    }

    public function handle(array $options, Output $output): int
    {
        $path = $this->profiler->storagePath();

        // Reading the storage establishes the directory and its index, so this
        // is one path whether or not anything was ever recorded.
        if ($this->profiler->storage()->all() === []) {
            $output->info('nothing recorded, nothing to clear.');

            return 0;
        }

        $removed = 0;

        foreach (new \DirectoryIterator($path) as $file) {
            if ($file->isDot() || !$file->isFile()) {
                continue;
            }

            $name = $file->getFilename();

            if (!str_ends_with($name, '.json') && !str_ends_with($name, '.json.gz')) {
                continue;
            }

            if (@unlink($file->getPathname())) {
                $removed++;
            } else {
                $output->warn(sprintf('could not delete %s', $name));
            }
        }

        @file_put_contents($path . '/index', '');

        $output->success(sprintf(
            'cleared %d recorded %s from %s',
            $removed,
            $removed === 1 ? 'request' : 'requests',
            $path,
        ));

        return 0;
    }
}