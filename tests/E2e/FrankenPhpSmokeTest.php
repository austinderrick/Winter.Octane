<?php

namespace Winter\Octane\Tests\E2e;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Serves the surrounding Winter installation through a REAL Octane server and checks that state
 * planted by one HTTP request is gone by the next.
 *
 * Everything else in this suite runs in-process. This test is the end of the chain: it starts
 * `php artisan octane:start --server=frankenphp` with one worker, plants state through a real HTTP
 * request (a Twig include gate, a shared view variable, a CMS code-parser cache entry), and reads
 * it back through a second request served by the same worker process. A leak here is a leak in
 * production, with no test double anywhere in the path.
 *
 * The diagnostic routes come from the plugin's routes.php and only exist when WINTER_OCTANE_SMOKE
 * is set, which this test sets for the server process it spawns.
 *
 * Opt-in: set WINTER_OCTANE_E2E=1 (CI does). FrankenPHP's binary is downloaded by Octane on first
 * start, so the first run needs the network; the test skips, with the server log attached, when
 * the server cannot come up.
 */
#[Group('e2e')]
class FrankenPhpSmokeTest extends TestCase
{
    /**
     * @var resource|null The octane:start process.
     */
    protected $serverProcess = null;

    /**
     * @var string Absolute path to the Winter installation the plugin sits in.
     */
    protected string $winterRoot = '';

    /**
     * @var int Port the server was started on.
     */
    protected int $port = 0;

    /**
     * @var string Path to the server's combined output log for this run.
     */
    protected string $serverLog = '';

    public function setUp(): void
    {
        parent::setUp();

        if (getenv('WINTER_OCTANE_E2E') !== '1') {
            $this->markTestSkipped('End-to-end test is opt-in; set WINTER_OCTANE_E2E=1 to run it.');
        }

        $this->winterRoot = realpath(__DIR__ . '/../../../../..');

        if ($this->winterRoot === false || !file_exists($this->winterRoot . '/.env')) {
            $this->markTestSkipped('No configured Winter installation (.env) surrounds the plugin.');
        }
    }

    public function tearDown(): void
    {
        $this->stopServer();

        parent::tearDown();
    }

    public function testStatePlantedByOneRequestIsGoneByTheNext()
    {
        $this->startServer();

        $baseline = $this->get('/_octane-smoke/read');

        $this->assertFalse($baseline['twig_gate_open'], 'the include gate should start closed');
        $this->assertFalse($baseline['view_leak_present'], 'no smoke share should exist yet');
        $this->assertFalse($baseline['code_parser_dirty'], 'the parser cache should start clean');

        $planted = $this->get('/_octane-smoke/plant');
        $this->assertTrue($planted['planted']);

        $after = $this->get('/_octane-smoke/read');

        $this->assertSame(
            $baseline['pid'],
            $after['pid'],
            'the follow-up request was served by a different worker process, so this run proves '
            . 'nothing about cross-request state; with --workers=1 that means the worker crashed'
        );

        $this->assertFalse(
            $after['twig_gate_open'],
            'the Twig include gate planted by the previous request survived into this one'
        );
        $this->assertFalse(
            $after['view_leak_present'],
            'the view share planted by the previous request survived into this one'
        );
        $this->assertFalse(
            $after['code_parser_dirty'],
            'the code parser cache planted by the previous request survived into this one'
        );
    }

    /**
     * Start octane:start with a single FrankenPHP worker and wait until it accepts connections.
     */
    protected function startServer(): void
    {
        $this->ensureWorkerStubSuitsWinter();

        $this->port = $this->findFreePort();
        $this->serverLog = tempnam(sys_get_temp_dir(), 'octane-smoke-') . '.log';

        $command = [
            PHP_BINARY,
            'artisan',
            'octane:start',
            '--server=frankenphp',
            '--host=127.0.0.1',
            '--port=' . $this->port,
            '--workers=1',
            '--no-interaction',
        ];

        /*
         * The PHPUnit process carries test-harness variables (APP_ENV=testing and friends from
         * phpunit.xml) that must not leak into the server: under the testing environment a
         * different configuration loads and the Octane commands are never registered. The server
         * must boot exactly as `php artisan octane:start` from a shell would.
         */
        $environment = getenv();

        unset(
            $environment['APP_ENV'],
            $environment['APP_RUNNING_IN_CONSOLE'],
            $environment['CACHE_DRIVER'],
            $environment['SESSION_DRIVER']
        );

        $environment['WINTER_OCTANE_SMOKE'] = '1';

        $this->serverProcess = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $this->serverLog, 'a'],
                2 => ['file', $this->serverLog, 'a'],
            ],
            $pipes,
            $this->winterRoot,
            $environment
        );

        if (!is_resource($this->serverProcess)) {
            $this->markTestSkipped('Could not start the octane:start process.');
        }

        /*
         * Generous ceiling: the very first start downloads the FrankenPHP binary.
         */
        $deadline = time() + 180;

        while (time() < $deadline) {
            $status = proc_get_status($this->serverProcess);

            if (!$status['running']) {
                $this->markTestSkipped(
                    "octane:start exited before the server came up (needs network on first run "
                    . "to download FrankenPHP). Log tail:\n" . $this->logTail()
                );
            }

            $socket = @fsockopen('127.0.0.1', $this->port, $errorCode, $errorMessage, 1);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(250000);
        }

        $this->markTestSkipped(
            "The server did not accept connections within the timeout. Log tail:\n" . $this->logTail()
        );
    }

    /**
     * Stop the server, politely first, then by port as a backstop for orphaned children.
     */
    protected function stopServer(): void
    {
        if ($this->winterRoot !== '') {
            @exec(sprintf(
                'cd %s && %s artisan octane:stop --server=frankenphp >/dev/null 2>&1',
                escapeshellarg($this->winterRoot),
                escapeshellarg(PHP_BINARY)
            ));
        }

        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
            $this->serverProcess = null;
        }

        if ($this->port > 0) {
            @exec(sprintf('lsof -ti :%d 2>/dev/null | xargs kill 2>/dev/null', $this->port));
        }
    }

    /**
     * Fetch a smoke route and decode its JSON.
     *
     * @param string $path
     * @return array
     */
    protected function get(string $path): array
    {
        $context = stream_context_create(['http' => ['timeout' => 10]]);
        $body = @file_get_contents('http://127.0.0.1:' . $this->port . $path, false, $context);

        $this->assertNotFalse($body, sprintf(
            "GET %s failed. Server log tail:\n%s",
            $path,
            $this->logTail()
        ));

        $decoded = json_decode($body, true);

        $this->assertIsArray($decoded, sprintf(
            "GET %s did not return JSON. Body:\n%s",
            $path,
            substr($body, 0, 500)
        ));

        return $decoded;
    }

    /**
     * Replace Octane's generated FrankenPHP worker stub with one that works from a root docroot.
     *
     * Octane's stock stub resolves the real worker as __DIR__.'/../vendor/...', assuming it was
     * written into a public/ directory one level below the project. Winter serves from the
     * project root by default, so the stub lands in the root and that path escapes the project;
     * the worker then dies before reaching frankenphp_handle_request() and the server reports
     * "too many consecutive failures". Octane only writes the stub when the file is missing, so
     * the corrected version written here persists across restarts. The corrected stub resolves
     * through APP_BASE_PATH, which octane:start always passes, and works in both layouts.
     */
    protected function ensureWorkerStubSuitsWinter(): void
    {
        $stub = $this->winterRoot . '/frankenphp-worker.php';

        if (file_exists($stub) && !str_contains((string) file_get_contents($stub), "__DIR__.'/../vendor")) {
            return;
        }

        file_put_contents($stub, <<<'PHP'
<?php

// Set a default for the application base path and public path if they are missing...
$_SERVER['APP_BASE_PATH'] = $_ENV['APP_BASE_PATH'] ?? $_SERVER['APP_BASE_PATH'] ?? __DIR__;
$_SERVER['APP_PUBLIC_PATH'] = $_ENV['APP_PUBLIC_PATH'] ?? $_SERVER['APP_PUBLIC_PATH'] ?? __DIR__;

// Winter serves from the project root by default, so this stub resolves Octane's worker through
// the application base path rather than assuming it lives in a public/ directory one level down,
// which is what Octane's stock stub assumes.
require $_SERVER['APP_BASE_PATH'].'/vendor/laravel/octane/bin/frankenphp-worker.php';

PHP);
    }

    /**
     * @return int A currently free TCP port.
     */
    protected function findFreePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $name = stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * @return string The last portion of the server log, for diagnostics.
     */
    protected function logTail(): string
    {
        if ($this->serverLog === '' || !file_exists($this->serverLog)) {
            return '(no log)';
        }

        return substr((string) file_get_contents($this->serverLog), -2000);
    }
}
