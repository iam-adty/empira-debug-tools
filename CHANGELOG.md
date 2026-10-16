# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**This project is pre-1.0 and has never been released.** The API can change at any
commit, though changes are always documented here. The package is not on
Packagist, so there are no consumers to break yet.

## [Unreleased]

> **Nothing has been released.** No git tag exists in this repository, and the
> package is not on Packagist. Everything below describes `main`.

### Changed

- **The profiler is now PHP DebugBar.** `itsgoingd/clockwork` is replaced by
  `php-debugbar/php-debugbar:^3.8`, and everything Clockwork-shaped is gone: the
  `/__clockwork` routes, the `X-Clockwork-Id` / `X-Clockwork-Version` /
  `X-Clockwork-Path` headers, the `Server-Timing` header, `EmpiraDataSource`, and
  `ClockworkMiddleware`.

  DebugBar's interface is an iframe of its own, which has to be *delivered*, so
  this module now injects its stylesheet and script into full HTML responses. That
  reverses the previous claim that it rewrites no bodies — the honest form of the
  rule is narrower, and the injection check in `DebugBarMiddleware` enforces it: no
  injection into JSON, into an htmx swap, into a partial, into a streamed file, or
  when the profiler is off.

- **`debug:clear` gained `--hours=<n>`**, which prunes by age instead of wiping.
  Storage that grows forever is the reason a development tool eventually stops
  being switched on, and the request you are still chasing is usually from this
  morning.

- **`Marks` is still a buffer, for a new reason.** It used to exist because the
  Clockwork request object did not exist while a handler ran. DebugBar's collector
  is available throughout the request, so the reason changed: `Marks::update()`
  merges data into an *open* span and `Span::duration()` reads one back, and
  DebugBar's `addMeasure()` is append-only and write-only. Neither is expressible
  against a collector.

### Removed

- **`DebugController::API`.** It named a path segment — `/_clockwork/api` — that
  no longer exists. A public constant pointing at an unregistered route is worse
  than no constant at all, because it reads like a supported value.
- **`DebugController::PATH`**, superseded by `DebugBarController::PATH` and its
  `ASSETS` and `OPEN_HANDLER` siblings, which are a different shape: two exact
  endpoints rather than a prefix with an `{id}` under it.

### Added

- **`debug.api`, `debug.api.show`, `debug.api.page` and `debug.asset` are gone**,
  along with the routes they named. There is no `{id}` in a DebugBar path.
- **A deliberate omission: `StandardDebugBar` is not used.** It registers
  `RequestDataCollector`, which harvests `$_GET`, `$_POST` and `$_COOKIE` from the
  superglobals **unconditionally**, on every profiled request. Adopting it as-is
  would have written session cookies and password fields to disk by default and
  served them back over HTTP by a tool that is on by default in development. The
  collectors are assembled by hand in `Profiler` instead, and
  `StorageHistoryTest::testTheRequestCollectorsAreNotDebugBarsOwn()` asserts the
  `request` key is absent and that neither a password nor a cookie appears
  anywhere in the serialised profile.
- **`EmpiraDebugBar`**, a one-method subclass overriding `collectMetaData()` so the
  request reported is Empira's rather than the superglobals'. In a test the
  `Request` is constructed directly and never touches `$_SERVER`, so without it
  the metadata describes the test runner.
- **`DebugBarController`**, serving `?type=js|css` and `?op=find|get|clear`.
  Neither endpoint is registered when the profiler is off.
- **The storage directory is `storage/debugbar`**, and it is established — with a
  `.gitignore` — before anything reads it. DebugBar's `find()` throws on a
  missing directory, and "nothing recorded yet" is exactly when it is first asked.

### Fixed

- **`debug:show` picks the newest request by `utime`, not by id.** DebugBar
  orders stored requests by request id, and an id begins with a *millisecond*
  timestamp — "lexical sort = time sort" for requests seconds apart, but two
  requests handled inside one millisecond share that prefix and are ordered by the
  random bytes after it. `debug:show` could therefore report the *previous*
  request. Same-millisecond requests are routine in a test, where two requests are
  microseconds apart, which is how it was found.

### Unchanged

- **`Debug`, `Marks` and `Span` keep their signatures.** A mark's `$color` no
  longer paints the bar — DebugBar draws no per-measure colours — so it is carried
  through as a measure parameter instead. Dropping the parameter would have been a
  break for no gain.
- **Payload and cookie collection still defaults off**, per instance, through the
  module's `payloads` and `cookies` config keys.

## Superseded

- **The interface moved to Clockwork's own URL shape** — data at
  `/__clockwork/{id}` rather than `/_clockwork/api/{id}`, with
  `X-Clockwork-Path: /__clockwork`. Shipped for one day and then replaced by
  DebugBar above, which is a different integration rather than a different
  spelling of the same one. Kept because it was true when it was written, and
  because `DebugController::API` — the constant it removed — is named in the entry
  above. `dev/planning/CHECKLIST.md` §20 records the same day.