# Octane Plugin

[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/austinderrick/Winter.Octane/blob/main/LICENSE)

Run Winter CMS on [Laravel Octane](https://laravel.com/docs/octane) with FrankenPHP, Swoole, or RoadRunner.

PHP normally throws everything away when a request ends. Octane doesn't. One booted copy of Winter serves request after request, which is where the speed comes from. The catch: whatever the last request left in memory is still sitting there when the next one arrives. Without cleanup, one user can end up seeing menus, settings, or preferences that belong to someone else.

This plugin is that cleanup. It:

- Registers Octane with Winter (Winter turns off Laravel's package discovery, so an installed `laravel/octane` does nothing on its own)
- Wipes what the last request left behind before each new one: the logged-in user, back-end vs. front-end detection, cached menus and settings, view data, abandoned database transactions
- Gives your own plugins a hook to wipe their leftovers too

## Requirements

- Winter CMS with the worker-safety changes (right now that means the `feat/octane-worker-support` branches of `wintercms/winter` and `wintercms/storm`)
- PHP 8.1 or later
- FrankenPHP, Swoole, or RoadRunner

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

Octane's own docs cover picking a server, deployment, and tuning. None of it is Winter-specific.

## Don't disable this plugin while Octane is serving

A disabled plugin never loads, so its cleanup never runs. Your workers keep serving anyway, and requests quietly start leaking state between users, including who's logged in. If you want off Octane, point the site back at PHP-FPM first. Once no worker is running, the plugin does nothing and you can disable or remove it safely.

The plugin also marks itself `elevated`, so Winter loads it even on the few routes and commands where normal plugins get skipped. Without that, a worker could boot on one of those and run without cleanup until someone restarts it.

## For plugin authors

Does your plugin keep per-request data on the plugin object, a singleton, or a static property? Under Octane that data survives into the next request, and the next request might belong to a different user. Give your plugin a way to drop it:

```php
public function resetWorkerState(): void
{
    $this->somePerRequestCache = [];
}
```

The cleanup runs before every request. Two things to keep in mind:

- It can run more than once for the same request, for example after a request that crashed halfway through. Write it so running it twice is harmless.
- Only clear what a request created. Event listeners, menu items, aliases, and anything else you registered at boot are built once per worker. Throw those away and they stay gone until the worker restarts.
- Don't read the current request in here. The cleanup runs before the request is fully set up, and its job is to forget the old one, not to look at the new one.

You can also write `implements \Winter\Storm\Contracts\ResetsWorkerState` if your plugin only supports Winter versions that have the contract. For anything older, stick with the plain method: PHP refuses to load a class that names an interface it can't find, so the `implements` version won't load at all on an older install.

## Tests

The tests boot one copy of Winter and push real requests through Octane's gateway, the same way a worker does. If state leaks between two requests in a test, it leaks in production. Run them from the plugin directory inside a Winter installation:

```bash
../../../vendor/bin/phpunit
```

## License

This plugin is licensed under the [MIT license](LICENSE).
