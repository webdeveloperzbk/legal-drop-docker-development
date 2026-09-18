<?php

// Deployment preflight using the selected release volume.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    DB::select('SELECT 1');
    Redis::connection()->ping();
    echo "Database and Redis OK.\n";
} catch (Throwable) {
    // Do not expose credentials in CI logs.
    fwrite(STDERR, "Database/Redis check failed; inspect application logs.\n");
    exit(1);
}
