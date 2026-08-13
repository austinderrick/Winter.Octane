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

        /*
         * The reset only works when core exposes the worker-safety primitives it calls. On a core
         * without them, registering Octane anyway would either serve traffic with no
         * request-boundary reset (cross-user state leaks) or throw an undefined-method Error at
         * the first boundary (an outage), so registration is refused outright and the reason is
         * logged. composer.json constrains winter/storm, but the modules ship inside the
         * application and version independently of it, so every module-side manager the reset
         * invokes is probed individually. An absent class is fine — an install without the CMS
         * module has no ComponentManager to reset — but a present manager without the reset
         * method means a version mismatch.
         */
        $missing = !interface_exists(\Winter\Storm\Contracts\ResetsWorkerState::class)
            || !method_exists(\Winter\Storm\Exception\ErrorHandler::class, 'resetMaskState')
            || !method_exists(\Winter\Storm\Halcyon\Model::class, 'flushRequestCache');

        foreach ([
            \System\Classes\MailManager::class,
            \System\Classes\SettingsManager::class,
            \Backend\Classes\NavigationManager::class,
            \Backend\Classes\WidgetManager::class,
            \Backend\Classes\AuthManager::class,
            \Cms\Classes\ComponentManager::class,
        ] as $manager) {
            if (class_exists($manager) && !method_exists($manager, 'resetWorkerState')) {
                $missing = true;
                break;
            }
        }

        if ($missing) {
            \Illuminate\Support\Facades\Log::warning(
                'Winter.Octane: this Winter installation predates the worker-safety primitives '
                . 'the plugin depends on, so Octane was not registered. Update Winter (all '
                . 'modules) and Storm to versions that ship ResetsWorkerState before serving '
                . 'through Octane.'
            );

            return;
        }

        $this->app->register(\Laravel\Octane\OctaneServiceProvider::class);

        /*
         * The reset deliberately runs at the start of every operation rather than the end. An
         * exception that escapes the HTTP kernel skips ApplicationGateway::terminate(), so
         * RequestTerminated — and every listener registered against Octane's OperationTerminated
         * contract — never fires. Cleaning up on the way in is the only boundary that also holds
         * after a failed operation.
         *
         * WorkerErrorOccurred is included so a failed operation is cleaned up promptly rather than
         * leaving the worker dirty until the next request arrives.
         *
         * The listeners are attached from a booted() callback, not here. Octane attaches its own
         * listeners — including GiveNewRequestInstanceToApplication, which installs the incoming
         * request — in OctaneServiceProvider::boot(). Laravel fires an event's listeners in the
         * order they were registered, so a listener attached during the register phase would run
         * BEFORE Octane's and observe the previous operation's request. booted() runs after every
         * provider has booted (and runs immediately when the application is already booted), which
         * is the earliest point that is guaranteed to be after Octane's own wiring.
         */
        $this->app->booted(function () {
            foreach ([
                \Laravel\Octane\Events\RequestReceived::class,
                \Laravel\Octane\Events\TaskReceived::class,
                \Laravel\Octane\Events\TickReceived::class,
                \Laravel\Octane\Events\WorkerErrorOccurred::class,
            ] as $event) {
                $this->app->make('events')->listen($event, [ResetsRequestState::class, 'handle']);
            }
        });
    }
}
