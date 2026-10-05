<?php

// One-time, explicitly armed deployment reset. Never called by normal migrations.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
umask(0077);

function ensure(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

try {
    ensure(count($argv) === 5, 'Expected mode, environment, tag and request directory.');
    [$script, $mode, $environment, $tag, $directory] = $argv;
    ensure(in_array($mode, ['--plan', '--reset'], true), 'Unknown reset mode.');
    ensure(in_array($environment, ['development', 'production'], true) && app()->environment($environment), 'Environment mismatch.');
    ensure(DB::getDriverName() === 'pgsql', 'Reset requires PostgreSQL.');
    $domain = $environment === 'development' ? 'legal-drop.su' : 'legal-drop.space';
    ensure(parse_url(config('app.url'), PHP_URL_HOST) === $domain, 'Application domain mismatch.');
    $request = json_decode(file_get_contents($directory.'/request.json'), true, flags: JSON_THROW_ON_ERROR);
    ensure(($request['environment'] ?? null) === $environment && ($request['tag'] ?? null) === $tag, 'Reset request does not match release.');
    ensure(($request['action'] ?? null) === 'reset-prelaunch-database', 'Reset is not explicitly armed.');
    $files = glob(database_path('migrations/*.php'));
    sort($files);
    $manifest = '';
    foreach ($files as $file) {
        $manifest .= basename($file).':'.hash_file('sha256', $file)."\n";
    }
    ensure(count($files) === 58 && str_starts_with(basename($files[0]), '2026_10_05_'), 'Unexpected migration set.');
    $fingerprint = hash('sha256', $manifest);
    ensure(hash_equals($request['schema_sha256'] ?? '', $fingerprint), 'Migration fingerprint mismatch.');
    $marker = 'prelaunch-reset:'.$tag.':'.$fingerprint;
    $finished = Schema::hasTable('cache') && DB::table('cache')->where('key', $marker)->exists();
    if (is_file($directory.'/complete.json')) {
        $completion = json_decode(file_get_contents($directory.'/complete.json'), true, flags: JSON_THROW_ON_ERROR);
        ensure(($completion['tag'] ?? null) === $tag && ($completion['schema_sha256'] ?? null) === $fingerprint, 'Completion marker mismatch.');
        $finished = true;
    }
    if ($finished) {
        echo "Reset already completed; database will not be reset again.\n";
        exit(0);
    }
    ensure(filter_var($request['admin_email'] ?? '', FILTER_VALIDATE_EMAIL) !== false && strlen($request['admin_password'] ?? '') > 0, 'Missing administrator credentials.');
    $admin = DB::table('admins')->where('email', $request['admin_email'])->first();
    ensure($admin !== null && $admin->role === 'owner' && $admin->is_active, 'Documented active owner was not found.');
    $catalogs = ['countries', 'cities', 'currencies', 'transport_types', 'block_reasons'];
    $counts = [];
    foreach ($catalogs as $table) {
        $counts[$table] = DB::table($table)->count();
        ensure($counts[$table] > 0, 'Required catalog is empty: '.$table);
    }
    ensure(DB::table('pending_events')->count() === 0 && DB::table('jobs')->count() === 0, 'Drain pending events and database jobs before resetting.');
    foreach (['default', 'cache'] as $connection) {
        ensure(config('database.redis.'.$connection.'.host') === 'redis.internal', 'Redis must belong to this dedicated stack.');
        Redis::connection($connection)->ping();
    }
    foreach (['payments', 'interactive', 'files', 'default'] as $queue) {
        ensure(app('queue')->connection()->size($queue) === 0, 'Drain all queues before resetting.');
    }
    echo json_encode(['environment' => $environment, 'catalogs' => $counts, 'admins' => DB::table('admins')->count(),
        'owner_credentials_match' => Hash::check($request['admin_password'], $admin->password),
        'users_to_remove' => DB::table('users')->count(), 'orders_to_remove' => DB::table('orders')->count(),
        'seed_demo' => $environment === 'development'], JSON_THROW_ON_ERROR)."\n";
    if ($mode === '--plan') {
        exit(0);
    }
    // PostgreSQL DDL is transactional. Temporary catalog copies survive dropping
    // the public tables, and disappear on commit/rollback. No user-data dump.
    DB::transaction(function () use ($catalogs, $counts, $request, $environment, $marker): void {
        DB::statement("SET LOCAL lock_timeout = '15s'");
        DB::select('SELECT pg_advisory_xact_lock(20261005, 1)');
        foreach ([...$catalogs, 'admins'] as $table) {
            DB::statement('CREATE TEMP TABLE reset_'.$table.' ON COMMIT DROP AS TABLE public.'.$table);
        }
        Schema::dropAllTables();
        ensure(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Fresh migrations failed.');
        ensure(DB::table('migrations')->count() === 58, 'Incomplete consolidated schema.');
        foreach ([...$catalogs, 'admins'] as $table) {
            DB::statement('INSERT INTO public.'.$table.' SELECT * FROM pg_temp.reset_'.$table);
            DB::select("SELECT setval(pg_get_serial_sequence(?, 'id'), COALESCE((SELECT MAX(id) FROM public.".$table.'), 1), EXISTS(SELECT 1 FROM public.'.$table.'))', [$table]);
            if (isset($counts[$table])) {
                ensure(DB::table($table)->count() === $counts[$table], 'Catalog restoration failed.');
                ensure(DB::select('SELECT * FROM public.'.$table.' EXCEPT SELECT * FROM pg_temp.reset_'.$table) === [], 'Catalog contents changed during restoration.');
            }
        }
        // Credentials are authoritative in ADMIN.md; preserve all other staff settings.
        $owner = DB::table('admins')->where('email', $request['admin_email'])->first();
        if (! Hash::check($request['admin_password'], $owner->password)) {
            DB::table('admins')->where('id', $owner->id)->update(['password' => Hash::make($request['admin_password'])]);
        }
        DB::table('admins')->update(['remember_token' => null]);
        $reasons = json_decode(file_get_contents(resource_path('js/content/prohibitedActions.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($reasons as $reason) {
            DB::table('block_reasons')->updateOrInsert(['code' => $reason['code']], [
                'name' => json_encode($reason['title'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'description' => json_encode($reason['description'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }
        // Sessions/cache/queue IDs must not refer to the discarded database.
        // Redis instances are dedicated to this environment; writers are stopped.
        foreach (['default', 'cache'] as $connection) {
            Redis::connection($connection)->flushdb();
        }
        if ($environment === 'development') {
            ensure(Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DatabaseSeeder', '--force' => true, '--no-interaction' => true]) === 0, 'Development seeding failed.');
            ensure(DB::table('users')->count() === 139 && DB::table('orders')->count() === 440, 'Unexpected QA dataset.');
        } else {
            foreach (['users', 'orders', 'wallets', 'wallet_entries', 'bank_payments', 'bank_refunds'] as $table) {
                ensure(DB::table($table)->count() === 0, 'Production contains unexpected demo data.');
            }
        }
        ensure(DB::table('document_versions')->count() === 2, 'Unexpected baseline legal documents.');
        ensure(Hash::check($request['admin_password'], DB::table('admins')->where('email', $request['admin_email'])->value('password')), 'Owner credentials verification failed.');
        DB::table('cache')->insert(['key' => $marker, 'value' => 'completed', 'expiration' => 0]);
    });
    file_put_contents($directory.'/complete.json', json_encode(['tag' => $tag, 'schema_sha256' => $fingerprint, 'completed_at' => gmdate('c')], JSON_THROW_ON_ERROR), LOCK_EX);
    echo "Prelaunch reset committed; catalogs and documented owner verified.\n";
} catch (Throwable $exception) {
    // SQL errors may contain credentials or user values. Keep deploy logs private of them.
    fwrite(STDERR, 'Prelaunch reset failed ('.get_class($exception)."). Writers must remain stopped until inspected.\n");
    if (get_class($exception) === RuntimeException::class) {
        fwrite(STDERR, $exception->getMessage()."\n");
    }
    if (isset($directory) && is_dir($directory)) {
        file_put_contents($directory.'/failure-class.txt', get_class($exception)."\n", LOCK_EX);
    }
    exit(1);
}
