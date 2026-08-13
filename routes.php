<?php

/*
 * Diagnostic routes for the end-to-end smoke test (tests/E2e/FrankenPhpSmokeTest.php).
 *
 * Only registered when WINTER_OCTANE_SMOKE is set in the environment, which the smoke test sets
 * for the server process it starts. Never enable this on a real site: these routes exist to plant
 * request-scoped state and report whether it leaked into a later request.
 */
if (!env('WINTER_OCTANE_SMOKE')) {
    return;
}

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use System\Twig\Loader as TwigLoader;

Route::get('/_octane-smoke/plant', function () {
    TwigLoader::$allowInclude = true;
    View::share('octane_smoke_leak', 'planted');

    (new ReflectionClass(\Cms\Classes\CodeParser::class))
        ->getProperty('cache')->setValue(null, ['octane-smoke' => 'dirty']);

    return response()->json(['planted' => true, 'pid' => getmypid()]);
});

Route::get('/_octane-smoke/read', function () {
    $parserCache = (new ReflectionClass(\Cms\Classes\CodeParser::class))
        ->getProperty('cache')->getValue();

    return response()->json([
        'twig_gate_open'    => TwigLoader::$allowInclude,
        'view_leak_present' => array_key_exists('octane_smoke_leak', app('view')->getShared()),
        'code_parser_dirty' => array_key_exists('octane-smoke', $parserCache ?? []),
        'pid'               => getmypid(),
    ]);
});
