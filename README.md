# empira-debug-tools

Request profiling for [Empira](https://github.com/iam-adty/empira-principle), as
a **module** — backed by [PHP DebugBar](https://php-debugbar.com/).

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
peak memory, and a timeline of the request's own milestones. Alongside those,
DebugBar's own collectors report PHP info, messages, time, memory and exceptions.

## Reading a profile

**The toolbar is in the page.** Every full HTML response comes back with a
DebugBar toolbar at the bottom of it — requests, response, timeline, messages
and exceptions, and a way back through the requests before this one. There is
nothing to install in your browser and nothing to configure.

Upstream's warning is worth repeating: **a debug bar is for development use.**
Do not enable this on anything publicly reachable.

### Injection is confined to whole documents

This module rewrites response bodies, so the rule is narrow and each half of it
is a correctness condition rather than a precaution:

| Response | Toolbar? | Why |
|---|---|---|
| a full HTML document | **yes** | this is what it is for |
| JSON or any other typed body | no | the body would no longer parse |
| an htmx swap (`HX-Request`) | no | it would land inside the element being replaced, and duplicate on the next swap |
| a partial or fragment | no | no `</body>` to append to |
| a streamed file | no | there is no body to rewrite |
| anything, when disabled | no | nothing is bound at all |

Note that `Response::html()` sets **no** `Content-Type` header, so the check
cannot require `text/html` — the content type is only allowed to veto, and the
closing `</body>` tag is what establishes that a response is a whole page.

## The two endpoints

The toolbar is an iframe built from what these return. They serve no page.

| Route | Returns |
|---|---|
| `/__debugbar/assets?type=js` \| `css` | the toolbar's own stylesheet and script |
| `/__debugbar/openhandler?op=find` \| `get` \| `clear` | stored requests, as JSON |

Both are `GET`, neither is CSRF-checked, and neither is registered when the
profiler is off. Asset requests are cacheable for a day; stored data is not.

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
`storage/debugbar` rather than wherever the process happened to be started. It
defaults to the working directory, which is usually right and occasionally not.

## What it will not collect

POST bodies and cookies are **not** recorded, and asking for them is deliberate
rather than an oversight — they are somebody's credentials, and this module
writes request data to disk and serves that disk back over HTTP.

This is also why DebugBar's own `RequestDataCollector` is **not** registered.
It collects `$_GET`, `$_POST` and `$_COOKIE` from the superglobals
unconditionally, on every profiled request, which would write your session cookie
and a password field to disk by default. The collectors this package does
register report on the process — PHP, time, memory, messages, exceptions — and
the request itself is described by this package's own collector, under the same
opt-in rules.

If you have told your application that its debug payloads are not sensitive:

```php
$source = $container->get(Empira\DebugTools\Profiler::class)->source();
$source->collectPayloads = true;
$source->collectCookies = true;
```

## From the terminal

Two commands, registered by the module:

```bash
composer debug:show              # the most recent request: url, status, duration, route
composer debug:clear             # delete everything recorded
composer debug:clear -- --hours=48   # delete only what is older than two days
```

`debug:show` answers with exit code 0 and says so when nothing has been
recorded, so it is usable as a check in a script rather than only as something to
read. It exits non-zero for a request that failed, which is usually what you
wanted to know before reading any of the rest.

Storage that grows forever is the reason a development tool eventually stops
being switched on, so `debug:clear --hours` prunes by age rather than wiping
everything — the request you are still chasing is usually from this morning, and
what is older than a day of work is not.

## What this module does not do

It does not touch your routes, your middleware stack, your session, or your error
handling, and it changes no behaviour when switched off. A module that reaches
past its own job to rearrange how an application works is a module that cannot be
removed later.

Rewriting response bodies to carry the toolbar is the one thing it does to your
responses, and it is bounded as described above.

## Tests

```bash
composer test
```

On the host PHP against SQLite, needing no server and no container — which is
the point of it being a module rather than an application.

Most of the suite asserts against the **stored profile** rather than the objects
that produced it, so a helper that filled a buffer nothing drained would fail.

Injection is asserted by reading the response body — present in a document,
absent from JSON, from an htmx swap, and from a streamed file — rather than by
driving a browser. Each of those conditions was checked by removing it and
watching the suite fail, because a test that passes whether or not the rule
exists is not a test of the rule.