<?php
// Explicit development reset without backup, authorized by the user. No sequence reuse.
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

require '/var/www/app/vendor/autoload.php';
$app = require '/var/www/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('development') || DB::getDriverName() !== 'pgsql' || ($argv[1] ?? '') !== '--reset-and-seed') {
    throw new RuntimeException('Requires development PostgreSQL and explicit --reset-and-seed.');
}
if (! $app->isDownForMaintenance() || Queue::size() !== 0) throw new RuntimeException('Maintenance mode and an empty stopped queue are required.');
if (parse_url(config('app.url'), PHP_URL_HOST) !== 'legal-drop.su') throw new RuntimeException('Expected development domain.');
$protected = ['admins', 'admin_sessions', 'countries', 'cities', 'currencies', 'transport_types', 'block_reasons', 'reference_imports', 'migrations'];
$hash = static function (string $table): string {
    $context = hash_init('sha256');
    foreach (DB::table($table)->orderBy('id')->cursor() as $row) hash_update($context, json_encode($row, JSON_THROW_ON_ERROR));
    return hash_final($context);
};
$report = DB::transaction(function () use ($protected, $hash): array {
    DB::statement("SET LOCAL lock_timeout = '10s'");
    $before = [];
    foreach ($protected as $table) {
        DB::statement('LOCK TABLE '.$table.' IN SHARE MODE');
        $before[$table] = $hash($table);
    }
    $oldUsers = DB::table('users')->get();
    // Reset only development database records, not shared remote S3 objects.
    DB::table('notifications')->where('notifiable_type', (new User)->getMorphClass())->delete();
    DB::table('personal_access_tokens')->where('tokenable_type', (new User)->getMorphClass())->delete();
    DB::table('admin_panel_logs')->whereNotNull('user_id')->orWhereNotNull('order_id')->delete();
    $emails = array_fill_keys($oldUsers->pluck('email')->all(), true);
    foreach (DB::table('email_logs')->select(['id','recipients','cc','bcc'])->cursor() as $log) {
        $matched = false;
        foreach (['recipients','cc','bcc'] as $field) {
            $addresses = json_decode($log->$field, true) ?: [];
            array_walk_recursive($addresses, function ($value) use ($emails, &$matched) {
                if (is_string($value) && isset($emails[$value])) $matched = true;
            });
        }
        if ($matched) DB::table('email_logs')->where('id', $log->id)->delete();
    }
    DB::table('password_reset_tokens')->whereIn('email', array_keys($emails))->delete();
    // These failed jobs refer to financial records being removed by this reset.
    DB::table('failed_jobs')->where('payload', 'like', '%ProcessBankTopup%')->delete();
    DB::table('bank_payments')->update(['entry_id' => null, 'topup_root_id' => null, 'retry_of' => null]);
    DB::table('wallet_entries')->update(['bank_refund_id' => null]);
    $tables = [
        'order_images','order_message_images','order_purchase_receipts',
        'bank_payment_events','bank_refunds','bank_payments',
        'order_messages','order_reward_offers','order_conversations','order_purchase_offers',
        'order_delivery_dates','order_transfer_requests','order_settlements','order_disputes',
        'order_compensations','order_terminations','order_holds','wallet_entries','withdrawals','wallets',
        'order_status_history','order_transport_type','user_logs','user_rating_changes','orders',
        'phone_verifications','email_changes',...(Schema::hasTable('document_acceptances') ? ['document_acceptances'] : []),
        'verification_codes','user_settings','sessions','users',
    ];
    $counts = [];
    foreach ($tables as $table) $counts[$table] = DB::table($table)->delete();
    if (Artisan::call('db:seed', ['--class' => Database\Seeders\DatabaseSeeder::class, '--force' => true, '--no-interaction' => true]) !== 0) {
        throw new RuntimeException('Demo seeding failed; transaction rolled back.');
    }
    $credentialsRestored = 0;
    foreach ($oldUsers as $user) {
        $existing = DB::table('users')->where('email', $user->email)->first(['id']);
        if ($existing) {
            DB::table('users')->where('id', $existing->id)->update(['password' => $user->password, 'username' => $user->username]);
            $credentialsRestored++;
        }
    }
    foreach ($protected as $table) {
        if ($before[$table] !== $hash($table)) throw new RuntimeException('Protected table changed: '.$table);
    }
    return ['deleted' => $counts, 'created' => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all(),
        'credentials_restored' => $credentialsRestored, 'preserved' => $protected, 'remote_media' => 'preserved'];
});
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
