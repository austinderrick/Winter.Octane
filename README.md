# Octane Plugin

[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/austinderrick/Winter.Octane/blob/main/LICENSE)

Serves Winter CMS from a persistent application server via [Laravel Octane](https://laravel.com/docs/octane).

Under PHP-FPM every request gets a fresh process, so leftover state is harmless. Under Octane one booted application serves many requests in a row. Anything derived from one request can leak into the next, including another user's data. This plugin exists to stop that.

Supports:

- FrankenPHP, Swoole and RoadRunner
- Automatic registration of Octane's service provider (Winter turns off Laravel package discovery)
- A request-boundary reset that clears state left behind by the previous request
- A reset hook for plugins that cache their own per-request data

## What the reset clears

The reset runs at the start of every Octane operation. It discards:

- The resolved execution context (back-end vs. front-end)
- The authenticated back-end user
- Error-handler masks
- Per-request static caches in core classes
- Abandoned database transactions and staged transaction callbacks
- Per-request state in any plugin that opts in

It runs at the start of an operation, not the end, on purpose. An exception that escapes the HTTP kernel skips Octane's terminate step. Cleanup on the way in still holds after a failed operation.

## Requirements

- Winter CMS with the worker-safety primitives. Today that means the `feat/octane-worker-support` branches of `wintercms/winter` and `wintercms/storm`.
- PHP 8.1 or later
- One of Octane's supported servers: FrankenPHP, Swoole or RoadRunner

## Installation

This plugin is available for installation via [Composer](http://getcomposer.org/).

```bash
composer require winter/wn-octane-plugin
```

After installing the plugin you will need to run the migrations and (if you are using a [public folder](https://wintercms.com/docs/develop/docs/setup/configuration#using-a-public-folder)) [republish your public directory](https://wintercms.com/docs/develop/docs/console/setup-maintenance#mirror-public-files).

```bash
php artisan migrate
php artisan octane:install
```

Then start a worker:

```bash
php artisan octane:start
```

Octane's own documentation covers server choice, deployment and tuning. None of it is Winter-specific.

## Do not disable this plugin while Octane is serving

A disabled plugin never registers, so disabling this one detaches the request-boundary reset. Workers then leak state across requests, including the authenticated user. To stop using Octane, switch the site back to PHP-FPM first. Once no worker is dispatching events, the plugin is inert and safe to disable or remove.

The plugin marks itself `elevated`. Winter skips normal plugin setup on privileged routes and commands, and the flag keeps this plugin registered there. Without it, the first request a worker served could decide that the reset never attaches at all.

## For plugin authors

Does your plugin cache request-derived data on the plugin object, a singleton or a static property? Add a reset, or a worker can serve one user's data to another:

```php
public function resetWorkerState(): void
{
    $this->somePerRequestCache = [];
}
```

Two rules keep a reset safe. First, make it idempotent: it can run twice in one operation, including after an operation that threw partway through. Second, never unregister boot-time extensions. Things like registration callbacks, aliases, menu definitions and event listeners are built once per worker. Discard them and the worker stays broken until it restarts. Clear only what a request produced.

You can also declare `implements \Winter\Storm\Contracts\ResetsWorkerState` for a compile-time guarantee. The bare method is the safer choice for plugins that support older Winter versions. PHP resolves `implements` when it loads a class, so naming a contract that an older Storm does not ship stops the plugin from loading at all.

## Tests

The suite boots one application and dispatches real requests through Octane's `ApplicationGateway`. A leak in a test is the same leak a worker would show in production. Run it from the plugin directory inside a Winter installation:

```bash
../../../vendor/bin/phpunit
```

## License

This plugin is licensed under the [MIT license](LICENSE).
