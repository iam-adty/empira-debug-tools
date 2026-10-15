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

### Removed

- **`DebugController::API`.** It named a path segment that no longer exists —
  `/_clockwork/api`. A public constant pointing at an unregistered route is worse
  than no constant at all, because it reads like a supported value. Use
  `DebugController::PATH` instead.

### Changed

- **The interface moved to Clockwork's own URL shape.** Data is now served at
  `/__clockwork/{id}` rather than `/_clockwork/api/{id}`, and
  `X-Clockwork-Path` reads `/__clockwork` rather than `/_clockwork/api`. This
  changes two things at once, and both were needed:

  - The prefix was `/_clockwork`. Clockwork's own integrations — Slim, Laravel —
    use `/__clockwork`.
  - The data hung off an `/api` segment that **upstream has never had**.

  The old shape was self-consistent, and it worked for a client that reads
  `X-Clockwork-Path`. It did not work for one that does not: Clockwork's shipped
  JavaScript treats that header as an *override* and otherwise constructs
  `/__clockwork/…` itself, which 404'd. Renaming the prefix alone would have
  produced `/__clockwork/api/{id}`, still missing a client that expects
  `/__clockwork/{id}` — so the segment had to go as well as the name.

  Matching upstream exactly means a client that reads the header and one that
  ignores it resolve to the same place.

  Route ids are constrained to `[0-9-]+|latest`, which is Clockwork's own shape.
  This is load-bearing: without it, `/__clockwork/app/assets/index.js` matches
  `{id}` with `id = app` and the asset route is never reached. The `/api` segment
  used to keep those two apart by accident.

### Unchanged

- **Route names.** `debug.api`, `debug.api.show`, `debug.api.page` and
  `debug.asset` all keep their names, so nothing outside this package refers to
  them by anything that moved.