# Octane plugin

Serves [Winter CMS](https://wintercms.com) from a persistent application server — FrankenPHP, Swoole or RoadRunner — via [Laravel Octane](https://laravel.com/docs/octane).

Under PHP-FPM every request gets a fresh process, so request-scoped state on a long-lived object is harmless. Under Octane the same booted application serves many requests, and anything derived from one request stays visible to the next. This plugin connects Octane's lifecycle to Winter's worker-safety primitives so that cannot happen:

- Registers `Laravel\Octane\OctaneServiceProvider` explicitly. Winter disables Laravel's package auto-discovery, so without this the `octane` binding never exists and worker mode fails outright.
- Attaches a request-boundary reset (`Winter\Octane\Classes\ResetsRequestState`) to the start of every Octane operation. It discards the state the previous operation produced: the resolved execution context (back-end vs. front-end), authenticated user, error-handler masks, per-request static caches, abandoned database transactions and staged transaction callbacks, and the per-request state of any plugin that opts in.

## Requirements

- Winter CMS with the worker-safety primitives (the `feat/octane-worker-support` branches of `wintercms/winter` and `wintercms/storm`)
- PHP 8.1+
- One of Octane's supported servers: FrankenPHP, Swoole or RoadRunner

## Installation

```bash
composer require winter/wn-octane-plugin
php artisan winter:up
php artisan octane:install
```

Then start a worker:

```bash
php artisan octane:start
```

Octane's own documentation covers server selection, deployment and tuning; nothing about it is Winter-specific.

## Important operational notes

**Do not disable this plugin while serving the site through Octane.** A disabled plugin never registers, so the request-boundary reset would silently stop attaching and workers would leak state — including authentication state — across requests. If you need to stop using Octane, switch the site back to PHP-FPM first; with no Octane worker dispatching events, the plugin is inert and can then be disabled or removed safely.

The plugin declares itself `elevated` so it stays registered on privileged routes and commands where ordinary plugin initialization is skipped. Without that, the first request a worker happened to serve could decide that the reset never attaches for the worker's whole lifetime.

## For plugin authors

If your plugin caches request-derived data on the plugin object, a singleton or a static property, implement a reset so a worker cannot serve one user's data to another:

```php
public function resetWorkerState(): void
{
    $this->somePerRequestCache = [];
}
```

The reset runs at the start of every operation. Two rules keep implementations safe:

- **Be idempotent.** It may be called more than once per operation, including after an operation that threw partway through.
- **Do not unregister boot-time extensions.** Registration callbacks, aliases, navigation definitions and event listeners are built once per worker; discarding them leaves the worker permanently degraded. Clear only what a request produced.

Plugins that only need to run on Winter versions that ship the contract may declare `implements \Winter\Storm\Contracts\ResetsWorkerState` for the compile-time guarantee. Declaring the bare `resetWorkerState()` method works everywhere: `implements` is resolved at class load time, so naming the contract on an older Storm makes the plugin unloadable rather than merely unresettable.

## Tests

The suite dispatches real requests through Octane's `ApplicationGateway` against a single application instance, so a leak in a test is the same leak a worker would exhibit in production. From the plugin directory, inside a Winter installation:

```bash
../../../vendor/bin/phpunit
```

## License

MIT
