# empira-debug-tools

Request profiling for [Empira](https://github.com/iam-adty/empira-principle), as
a **module** — backed by [Clockwork](https://github.com/itsgoingd/clockwork).

Not on Packagist yet, so Composer has to be told where to look. Add both packages
to your `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/iam-adty/empira-principle.git" },
        { "type": "vcs", "url": "https://github.com/iam-adty/empira-debug-tools.git" }
    ]
}
```

Then require **both**, naming the framework explicitly:

```bash
composer require --dev iam-adty/empira-principle:dev-main iam-adty/empira-debug-tools:dev-main
```

Naming the framework is not ceremony. Composer's default `minimum-stability` is
`stable`, so a transitive dependency on a `dev-` branch is rejected — and this
package's dependency on `empira-principle` is exactly that. Requiring it at the
root is what makes it installable:

    - iam-adty/empira-debug-tools dev-main requires iam-adty/empira-principle dev-main
      -> found iam-adty/empira-principle[dev-main, 0.1.x-dev (alias of dev-main)]
      but it does not match your minimum-stability.

`:dev-main` is needed because there is no release to satisfy; `^0.1@dev` works too,
since both packages alias their branch as `0.1.x-dev`. When these reach Packagist,
the repository block and the suffixes both go away.

The framework does not ship a profiler, does not know this package's classes
exist, and boots without them. Authentication and worker mode are modules; so is
this.

## Using it

Two lines, in `bootstrap/app.php`, alongside the other modules:

```php
use Empira\DebugTools\DebugToolsModule;

$app->module(new DebugToolsModule(__DIR__ . '/..'));
```

That is the whole installation. It records every request, and it does nothing at
all unless the environment says it should — see below.

## What it records

The request as it arrived, the response as it went back, and the route that
connected them: method, URL, headers, query string, response status, duration,
peak memory, and a timeline of the request's own milestones. Every page load also
returns a `Server-Timing` header, so a browser's network panel shows the
duration without the profiler being opened, and an `X-Clockwork-Id` header
naming the stored profile.

The interface is at `/_clockwork`, and it is Clockwork's own — the part worth
looking at is the timeline and the request/response detail.

## Reading a profile

Use the **Clockwork Chrome extension** — the one Clockwork publishes for
[its own project](https://underground.works/clockwork/). It reads three headers
off each response and fetches the data itself:

| Header | Meaning |
|---|---|
| `X-Clockwork-Id` | the id this request was stored under |
| `X-Clockwork-Version` | the Clockwork version that stored it |
| `X-Clockwork-Path` | the API base to fetch from — **not** the URL prefix |

A client appends the id to that path, so the data lives at
`/_clockwork/api/{id}`, and `/_clockwork/api/latest`, and
`/_clockwork/api/{id}/previous/5`. Those are the URLs, not decoration: a
`debug:clear` empties them and nothing else.

**This package ships no JavaScript and injects nothing into your pages.** That is
upstream's position too — Clockwork's own in-page toolbar is a separate npm or
CDN component, which this does not pull in. Your responses come back exactly as
the handler wrote them.

## Saying something while debugging

`Empira\DebugTools\Debug` is for the lines you want explained.

```php
use Empira\DebugTools\Debug;

Debug::mark('cache miss', ['key' => $key]);

$user = Debug::measure('find user', fn (): ?User => $this->find($id));

Debug::warning('falling back to the v1 parser', ['version' => 1]);
```

| Call | Does |
|---|---|
| `Debug::mark($label, $data)` | a point on the timeline; repeats stay separate |
| `Debug::measure($label, $fn, ...$args)` | times `$fn` and **returns its value** |
| `Debug::span($label, $data)` | a span for timing what a closure cannot wrap |
| `Debug::info/debug/warning/error($msg, $ctx)` | a message with a level and a stack trace |
| `Debug::enabled()` | whether anything is being recorded |

A span for an import spread across four methods:

```php
$span = Debug::span('import');

foreach ($rows as $row) {
    $this->import($row);
    $span?->data(['done' => ++$n]);
}

$span?->data(['rows' => $n])?->end();
```

Everything is a no-op outside a request being recorded — and `measure()` still
runs its callback and returns its value, because a debugging tool that changed
what your application does when switched off would be a bug in the tool. Guard an
expensive payload with `Debug::enabled()`.

Marks have to happen *during* the request; they are written when the request is
stored. Console commands are not profiled, so marks in one are discarded.

## It only runs when you asked for it

Nothing is registered unless `APP_ENV` is one of `local`, `dev`, `development` or
`test`. That decision is made once, at registration, and it governs *whether the
routes exist at all* rather than what they answer — so in production there is no
URL to probe and no directory to serve, instead of a URL that is present and
refuses.

`APP_ENV` is read from `$_ENV` and from nothing else. This matches how the
framework's own configuration and the skeleton's are read, so the profiler cannot
reach a different answer about the environment than the application it is
profiling.

```php
new DebugToolsModule(__DIR__ . '/..', ['enabled' => true]);   // force on
new DebugToolsModule(__DIR__ . '/..', ['enabled' => false]);  // force off
```

Pass the application's base path so the recorded requests land in your
`storage/clockwork` rather than wherever the process happened to be started. It
defaults to the working directory, which is usually right and occasionally not.

## What it will not collect

POST bodies and cookies are **not** recorded, and asking for them is deliberate
rather than an oversight — they are somebody's credentials, and this module
writes request data to disk and serves that disk back over HTTP. If you have
told your application that its debug payloads are not sensitive:

```php
$source = $container->get(Empira\DebugTools\Profiler::class)->source();
$source->collectPayloads = true;
$source->collectCookies = true;
```

## From the terminal

Two commands, registered by the module:

```bash
composer debug:show      # the most recent request: url, status, duration, route
composer debug:clear     # delete everything recorded
```

`debug:show` answers with exit code 0 and says so when nothing has been
recorded, so it is usable as a check in a script rather than only as something to
read.

## What this module does not do

It does not touch your routes, your middleware stack, your session, or your error
handling, and it changes no behaviour when switched off. A module that reaches
past its own job to rearrange how an application works is a module that cannot be
removed later.

## Tests

```bash
composer test
```

On the host PHP against SQLite, needing no server and no container — which is
the point of it being a module rather than an application.

Most of the suite asserts against the **stored profile** rather than the objects
that produced it, and one test performs the Chrome extension's exact journey —
read the headers, build `{X-Clockwork-Path}/{X-Clockwork-Id}`, request it — so
the contract a browser depends on is pinned by something that would fail the
moment it stopped working.