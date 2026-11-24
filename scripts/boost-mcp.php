<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

// Local stdio tooling only; the ordinary application keeps BOOST_ENABLED=false.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

putenv('BOOST_ENABLED=true');
$_ENV['BOOST_ENABLED'] = $_SERVER['BOOST_ENABLED'] = 'true';
putenv('BOOST_BROWSER_LOGS_WATCHER=false');
$_ENV['BOOST_BROWSER_LOGS_WATCHER'] = $_SERVER['BOOST_BROWSER_LOGS_WATCHER'] = 'false';

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Read the resolved configuration (including DB_URL) without connecting to the database.
$database = $app->make('db')->connection()->getConfig();
if (! $app->environment('local')
    || ($database['driver'] ?? null) !== 'pgsql'
    || ($database['host'] ?? null) !== 'postgres'
    || ($database['database'] ?? null) !== 'cms'
    || ($database['username'] ?? null) !== 'cms'
    || ! config('boost.enabled')
    || config('boost.browser_logs_watcher')) {
    fwrite(STDERR, "Boost refused: requires the local cms-foundation PostgreSQL database and disabled browser instrumentation.\n");
    exit(1);
}

// Boost clears .env variables in tool subprocesses; enable only those CLI children.
config(['boost.executable_paths.php' => 'env BOOST_ENABLED=true BOOST_BROWSER_LOGS_WATCHER=false '.PHP_BINARY]);

exit($kernel->handle(new ArrayInput(['command' => 'boost:mcp']), new ConsoleOutput));
