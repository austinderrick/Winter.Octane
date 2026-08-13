<?php namespace Winter\Octane;

use System\Classes\PluginBase;
use Winter\Octane\Classes\ResetsRequestState;

/**
 * Laravel Octane support for Winter CMS.
 *
 * Registers Octane's own service provider and attaches Winter's request-state reset to the start
 * of every Octane operation. The worker-safety primitives this builds on — resettable static
 * caches, the ResetsWorkerState contract, per-request manager resets — live in Winter core and
 * Storm; this plugin is the wiring that connects them to Octane's lifecycle.
 */
class Plugin extends PluginBase
{
    /**
     * The request-boundary reset is a correctness invariant, not a feature: a worker serving
     * requests without it silently leaks state — auth, execution context, error-handler masks,
     * open transactions — across requests. Elevation keeps the plugin registered on the
     * privileged routes and commands where ordinary plugin initialization is skipped
     * (PluginManager::$noInit), so the first request a worker serves cannot decide that the
     * reset never attaches for the worker's whole lifetime.
     *
     * @var bool
     */
    public $elevated = true;

    /**
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name'        => 'Octane',
            'description' => 'Serves Winter from a persistent application server (FrankenPHP, Swoole or RoadRunner) via Laravel Octane.',
            'author'      => 'Winter CMS',
            'icon'        => 'icon-bolt',
            'homepage'    => 'https://github.com/austinderrick/Winter.Octane',
        ];
    }

    /**
     * Register Octane's service provider and Winter's request-boundary reset.
     *
     * Winter disables Laravel's package auto-discovery (`app.loadDiscoveredPackages`), so the
     * installed `laravel/octane` package never registers its own provider. Without it the
     * `octane` binding is missing and Octane's ApplicationGateway fails on every request, so
     * this registration is a hard prerequisite for worker mode rather than a convenience.
     *
     * Registering the provider is inert under PHP-FPM: it binds services and reads
     * configuration, but Octane's events are only dispatched by an Octane worker.
     *
     * @return void
     */
    public function register()
    {
        /*
         * The plugin's composer.json requires laravel/octane, but the plugin's files can be
         * present before composer has run, and registration must degrade to a no-op rather than
         * fatal in that window.
         */
        if (!class_exists(\Laravel\Octane\OctaneServiceProvider::class)) {
            return;
        }

        $this->app->register(\Laravel\Octane\OctaneServiceProvider::class);

        /*
         * Attach Winter's reset to the start of every operation, appended after Octane's own
         * listeners so the new request, application and configuration have already been injected.
         *
         * The reset deliberately runs at the start rather than the end. An exception that escapes
         * the HTTP kernel skips ApplicationGateway::terminate(), so RequestTerminated — and every
         * listener registered against Octane's OperationTerminated contract — never fires. Cleaning
         * up on the way in is the only boundary that also holds after a failed operation.
         *
         * WorkerErrorOccurred is included so a failed operation is cleaned up promptly rather than
         * leaving the worker dirty until the next request arrives.
         */
        foreach ([
            \Laravel\Octane\Events\RequestReceived::class,
            \Laravel\Octane\Events\TaskReceived::class,
            \Laravel\Octane\Events\TickReceived::class,
            \Laravel\Octane\Events\WorkerErrorOccurred::class,
        ] as $event) {
            $this->app->make('events')->listen($event, [ResetsRequestState::class, 'handle']);
        }
    }
}
