<?php

// Local staging belongs to the current release volume: never switch releases while it is needed.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    foreach (['order_images', 'order_message_images', 'order_purchase_receipts'] as $table) {
        if (Schema::hasColumn($table, 'staged_path') && DB::table($table)->whereNotNull('staged_path')->exists()) {
            throw new RuntimeException('Staged attachments must finish or be retried before deployment.');
        }
    }
    if (Schema::hasColumn('reference_imports', 'disk') && DB::table('reference_imports')->where('disk', 'local')->whereIn('status', ['queued', 'processing'])->exists()) {
        throw new RuntimeException('Reference imports must finish before deployment.');
    }
    echo "No pending local staging.\n";
} catch (Throwable) {
    fwrite(STDERR, "Release switch blocked: finish staged uploads/imports and retry failed attachment jobs on the current release.\n");
    exit(1);
}
