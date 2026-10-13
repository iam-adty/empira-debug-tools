<?php

declare(strict_types=1);

namespace Empira\DebugTools;

use Empira\Application;
use Empira\Console\CommandProvider;
use Empira\DebugTools\Console\ClearCommand;
use Empira\DebugTools\Console\ShowCommand;
use Empira\Module;

/**
 * Request profiling for an Empira application, backed by Clockwork.
 *
 * ## What this module is allowed to do on its own
 *
 * It records requests, serves its own interface, and adds a header. It does not
 * touch the application's routes, middleware stack, session, or error handling,
 * and it does not change how anything behaves when it is switched off — with
 * {@see Profiler::isEnabled()} false, nothing is bound, no route is registered
 * and no middleware runs, so an application that installs this and turns it off
 * behaves exactly as it did before, which is the only honest way for a
 * development tool to be optional.
 *
 * ## Scoping
 *
 * The framework has no opinion about where an application keeps its files — an
 * application passes `__DIR__` where it needs one — so neither does this. The
 * base path is a constructor argument, not something discovered at runtime.
 */
final class DebugToolsModule implements Module, CommandProvider
{
    private ?Application $app = null;

    /**
     * @param string|null $basePath where the application's `storage/` lives;
     *                             defaults to the working directory
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly ?string $basePath = null,
        private readonly array $config = [],
    ) {
    }

    public function register(Application $app): void
    {
        $this->app = $app;

        $container = $app->container();
        $basePath = $this->basePath ?? getcwd();

        // Bound whatever the environment says. A `debug:clear` that stopped
        // working the moment you fixed the thing you were debugging would be a
        // poor way to say the tool is development-only — and these bindings are
        // inert: nothing reads the profiler unless a request comes through the
        // middleware registered below, which is the gated part.
        $container->singleton(Profiler::class, fn (): Profiler => new Profiler(
            $basePath,
            $this->config,
        ));

        // The gate. Decided once, before anything is published. The
        // alternative — registering the routes and middleware and checking a
        // flag inside them — leaves an open, publicly reachable URL to a
        // directory of request data, gated by a condition that has to be right
        // on every code path through it. Nothing registered is not gated.
        if (!$this->isEnabled()) {
            return;
        }

        $app->use(ClockworkMiddleware::class);

        $controller = new DebugController($container->get(Profiler::class));
        $routes = $app->routes();

        // Registration order is matching order. The API and the bar's two files
        // are declared before the catch-all asset route so a path beginning
        // `api/` or ending `.js` is answered as itself, even though both would
        // also satisfy `{path:.*}`.
        $routes->get(DebugController::PATH, [$controller, 'index'], 'debug.index', csrf: false);
        $routes->get(DebugController::PATH . '/api', [$controller, 'api'], 'debug.api', csrf: false);
        $routes->get(DebugController::PATH . '/api/{id}', [$controller, 'api'], 'debug.api.show', csrf: false);
        $routes->get(DebugController::PATH . '/api/{id}/{direction}/{count}', [$controller, 'api'], 'debug.api.page', csrf: false);
        $routes->get(DebugController::TOOLBAR_JS, [$controller, 'toolbarJs'], 'debug.toolbar.js', csrf: false);
        $routes->get(DebugController::TOOLBAR_CSS, [$controller, 'toolbarCss'], 'debug.toolbar.css', csrf: false);
        $routes->get(DebugController::PATH . '/app/{path:.*}', [$controller, 'asset'], 'debug.asset', csrf: false);
    }

    public function commands(): array
    {
        return [
            'debug:show' => new ShowCommand($this->profiler()),
            'debug:clear' => new ClearCommand($this->profiler()),
        ];
    }

    /**
     * The same rule {@see Profiler} applies, evaluated from the same config, so
     * the two cannot disagree — a route registered because one thought it was
     * on while the other recorded nothing is a worse failure than either.
     */
    private function isEnabled(): bool
    {
        if (array_key_exists('enabled', $this->config)) {
            return (bool) $this->config['enabled'];
        }

        $env = $this->config['environment'] ?? $_ENV['APP_ENV'] ?? null;

        return in_array(
            is_string($env) ? strtolower($env) : 'production',
            ['local', 'dev', 'development', 'test'],
            true,
        );
    }

    /**
     * The application's profiler, for the commands.
     *
     * A command asked the module rather than resolving from the container
     * itself, so that `debug:clear` and the middleware can only ever be looking
     * at the same storage directory — there being one place that decides where
     * that is.
     */
    private function profiler(): Profiler
    {
        return $this->app->container()->get(Profiler::class);
    }
}