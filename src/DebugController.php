<?php

declare(strict_types=1);

namespace Empira\DebugTools;

use Clockwork\Request\Request as ClockworkRequest;
use Clockwork\Web\Web;
use Empira\Http\Request;
use Empira\Http\Response;

/**
 * Serves the data behind a Clockwork profile, and Clockwork's own assets.
 *
 * ## The shape mirrors Clockwork's
 *
 * `/__clockwork/{id}`, `/__clockwork/latest`, `/__clockwork/{id}/previous/5` is
 * what Clockwork's Slim and Laravel integrations expose, and what its clients
 * expect — the Chrome extension reads `X-Clockwork-Path` and appends an id to it.
 * **The prefix is upstream's, not a choice this package made.** It used to be
 * `/_clockwork` with an `/api` segment Clockwork does not have, on the reasoning
 * that the family was reproduced under our own prefix. That only ever worked for
 * a client that honoured the header: Clockwork's own JavaScript takes
 * `X-Clockwork-Path` as an *override* and otherwise constructs `/__clockwork/…`
 * itself, which 404'd. Matching upstream exactly means a client that reads the
 * header and one that ignores it resolve to the same place.
 *
 * The `{id}` constraint is load-bearing rather than decorative — see the note on
 * {@see PATH} for why `/app/` has to stay reachable through the asset route.
 *
 * ## Nothing is injected into a page
 *
 * There is no in-page toolbar here, and this package ships no JavaScript. Upstream
 * is deliberate about that too — its own toolbar is a separate npm or CDN
 * component, not part of the Composer package — so the browser side is somebody
 * else's decision and this one serves data.
 *
 * ## The app's assets are read from vendor, not installed into it
 *
 * Clockwork ships an installer that copies its UI into the application's public
 * directory. That was not used: it puts a second copy of a third party's files
 * somewhere the application's own asset pipeline does not know about, and that
 * copy then has to be kept in step with the installed version. Reading them out
 * of `vendor/` on request means there is exactly one copy and it is the one
 * Composer just installed.
 */
final class DebugController
{
    /**
     * The URL prefix, and the only thing protecting the recorded data.
     *
     * Nothing checks a token before answering. That is the design: the route
     * either exists or it does not, and it does not exist unless the environment
     * said to publish it, so there is no authorisation to forget.
     *
     * This is Clockwork's own prefix, not one of ours. It used to be `/_clockwork`
     * with an `/api` segment the upstream has never had, which worked only for a
     * client that reads `X-Clockwork-Path`; the shipped JavaScript falls back to
     * constructing `/__clockwork/…` itself when that header is absent, and
     * 404'd. Matching upstream exactly means both paths land in the same place,
     * and it is the prefix a reader debugging a URL will recognise.
     */
    public const PATH = '/__clockwork';

    public function __construct(
        private readonly Profiler $profiler,
    ) {
    }

    /**
     * An asset from the installed Clockwork package.
     *
     * `Web::asset()` resolves and range-checks the path before this sees it, so
     * `../../etc/passwd` is refused there rather than being defended against
     * twice in different styles here.
     */
    public function asset(string $path): Response
    {
        $asset = (new Web)->asset($path);

        if ($asset === null || $asset === false) {
            return Response::html('Not found', 404);
        }

        $contents = @file_get_contents($asset['path']);

        if ($contents === false) {
            return Response::html('Not found', 404);
        }

        return Response::html($contents)
            ->withHeader('Content-Type', $asset['mime'])
            ->noStore();
    }

    /**
     * Recorded requests, as JSON.
     *
     * `$direction` and `$count` are Clockwork's own paging: `previous` and
     * `next` walk through the stored history either side of an id, which is how
     * you compare this request with the one before it.
     */
    public function api(?string $id = null, ?string $direction = null, ?string $count = null): Response
    {
        $storage = $this->profiler->storage();

        // Route parameters arrive as strings whatever the segment looked like.
        // Cast before handing the number to Clockwork, which will use it to walk
        // that many records backwards or forwards.
        $howMany = $count !== null && ctype_digit($count) ? (int) $count : null;

        $data = match (true) {
            $direction === 'previous' => $storage->previous($id ?? 'latest', $howMany),
            $direction === 'next' => $storage->next($id ?? 'latest', $howMany),
            $id === null || $id === 'latest' => $storage->latest(),
            default => $storage->find($id),
        };

        // An unknown id is `false`, not null, and an empty history is an empty
        // array. Both mean the same thing to a client: there is no such request.
        // Answering `null` with a 200 would let a client cache "no such request"
        // as though it were data.
        if ($data === false || $data === [] || $data === null) {
            return Response::json(['message' => 'No such request.'], 404);
        }

        return Response::json($this->normalise($data));
    }

    /**
     * Clockwork's storage answers with `Request` objects, or with arrays of them
     * for `previous()` and `next()` — and `Request` is **not** `JsonSerializable`.
     *
     * Both shapes have to be mapped, not just the single one. Passing an array
     * straight to `json_encode` encodes each object's *public properties*, which
     * is a subtly different document from `toArray()`: the computed fields are
     * missing entirely, so a paging request would report no duration and no
     * query counts for records that have them.
     *
     * @return array<array-key, mixed>|null
     */
    private function normalise(mixed $data): ?array
    {
        if ($data instanceof ClockworkRequest) {
            return $data->toArray();
        }

        if (is_array($data)) {
            return array_map(
                static fn (mixed $item): mixed => $item instanceof ClockworkRequest ? $item->toArray() : $item,
                $data,
            );
        }

        return null;
    }
}
