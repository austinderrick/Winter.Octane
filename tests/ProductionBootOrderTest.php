<?php

namespace Winter\Octane\Tests;

use Backend\Classes\AuthManager;
use Illuminate\Http\Request;

/**
 * Exercises the listener wiring in the order production uses it.
 *
 * PluginTestCase registers the plugin under test AFTER the application has booted, and a plugin
 * registered post-boot cannot get its listener order wrong: registering the Octane provider boots
 * it inline, so Octane's listeners always land first. Production is the reverse — the plugin's
 * register() runs during the provider registration phase, before any provider has booted — and
 * that is the order in which a listener attached too early lands AHEAD of Octane's
 * GiveNewRequestInstanceToApplication and observes the previous operation's request.
 *
 * This case rebuilds the application with the real plugins directory in place before providers
 * register, so Winter.Octane is registered by PluginManager::registerAll() exactly as a deployed
 * worker would register it. The ordering assertion below failed against the register-phase
 * attachment this plugin originally shipped with, and passes with the booted() attachment.
 */
class ProductionBootOrderTest extends PersistentWorkerTestCase
{
    /**
     * Mirrors the stock test application, with one difference: the plugins path is set before the
     * RegisterProviders bootstrapper runs, so plugin registration happens during the register
     * phase rather than being injected after boot.
     *
     * @return \Symfony\Component\HttpKernel\HttpKernelInterface
     */
    public function createApplication()
    {
        $app = require __DIR__ . '/../../../../bootstrap/app.php';

        /*
         * A fixture directory containing ONLY this plugin, not the host installation's plugins
         * directory: registerAll() would otherwise register and boot every plugin installed on
         * the site this suite happens to run inside, against an unmigrated in-memory database.
         */
        $app->beforeBootstrapping(
            \Illuminate\Foundation\Bootstrap\RegisterProviders::class,
            fn ($app) => $app->setPluginsPath($this->fixturePluginsPath())
        );

        $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

        $app['cache']->setDefaultDriver('array');
        $app->setLocale('en');
        $app['config']->set('app.key', bin2hex(random_bytes(16)));

        $app->singleton('backend.auth', function ($app) {
            $app['auth.loaded'] = true;

            return AuthManager::instance();
        });

        if (!file_exists(base_path('config/testing/database.php'))) {
            $app['config']->set('database.connections.testing', [
                'driver'   => 'sqlite',
                'database' => ':memory:',
            ]);
            $app['config']->set('database.default', 'testing');
        }

        return $app;
    }

    /**
     * A plugins directory holding a single symlink to this plugin, created on first use.
     *
     * @return string
     */
    protected function fixturePluginsPath(): string
    {
        $path = sys_get_temp_dir() . '/winter-octane-boot-order-plugins';
        $link = $path . '/winter/octane';

        if (!is_link($link)) {
            @mkdir($path . '/winter', 0755, true);
            symlink(realpath(__DIR__ . '/..'), $link);
        }

        return $path;
    }

    /**
     * The reset must observe the operation it is resetting FOR, not the one before it.
     *
     * Octane installs the incoming request into the sandbox from a RequestReceived listener, and
     * listeners fire in registration order. Storm's dispatcher wraps every listener in a closure
     * when it is attached, so the order cannot be asserted structurally; instead a probe plugin
     * records which request is bound at the moment its reset runs.
     */
    public function testTheResetObservesTheIncomingRequestNotThePreviousOne()
    {
        $app = $this->bootWorker();

        $this->addWorkerRoute('_worker/order', fn () => 'ok');

        $probe = new class ($app) extends \System\Classes\PluginBase
        {
            /**
             * @var array Paths of the bound request at each reset, in order.
             */
            public $seenPaths = [];

            /**
             * @return void
             */
            public function resetWorkerState(): void
            {
                $this->seenPaths[] = app('request')->path();
            }
        };

        $this->withFakePlugins(
            ['Winter.Tests.OrderProbe' => $probe],
            fn () => $this->dispatchWorkerRequests(Request::create('/_worker/order', 'GET')),
            keepExisting: true
        );

        $this->assertSame(
            ['_worker/order'],
            $probe->seenPaths,
            'The reset ran against the previous operation\'s request. It must be attached after '
            . 'Octane\'s GiveNewRequestInstanceToApplication, or everything request-derived it '
            . 'touches — the auth throttle IP above all — belongs to the wrong operation.'
        );
    }
}
