<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

$mode = $argv[1] ?? 'check';
if (! in_array($mode, ['wait', 'check'], true)) {
    fwrite(STDERR, "Usage: php docker/database.php [wait|check]\n");
    exit(1);
}

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = $app->make('db');
$deadline = microtime(true) + ($mode === 'wait' ? 120 : 0);

do {
    try {
        $database->connection()->select('SELECT 1');
        exit(0);
    } catch (Throwable) {
        $database->purge();
        if (microtime(true) >= $deadline) {
            fwrite(STDERR, "Database connection failed. Check the Laravel connection settings and database availability.\n");
            exit(1);
        }
        usleep(500_000);
    }
} while (true);
