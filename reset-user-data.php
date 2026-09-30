<?php
// Explicit development reset, authorized 30 September 2026. No schema/sequence/S3 reset.
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentWorkspaceAccounts;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

require '/var/www/app/vendor/autoload.php';
$app = require '/var/www/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$phase = 'preflight';
try {
    if (! $app->environment('development') || DB::getDriverName() !== 'pgsql'
        || parse_url(config('app.url'), PHP_URL_HOST) !== 'legal-drop.su') {
        throw new RuntimeException('Development PostgreSQL and development domain required.');
    }
    $mode = $argv[1] ?? '--plan';
    if (! in_array($mode, ['--plan', '--reset-and-seed'], true)) {
        throw new RuntimeException('Expected --plan or --reset-and-seed.');
    }
    $oldUsers = DB::table('users')->orderBy('id')->get();
    $accounts = DevelopmentWorkspaceAccounts::accounts();
    $queues = [];
    foreach (['default', 'payments', 'interactive', 'files'] as $queue) {
        $queues[$queue] = Queue::size($queue);
    }
    $outbox = DB::table('pending_events')->count();
    $reused = $oldUsers->filter(fn ($user) => isset($accounts[$user->email]));
    $plan = ['environment' => 'development', 'old_users' => $oldUsers->count(), 'old_orders' => DB::table('orders')->count(),
        'new_users' => count($accounts), 'reused_logins' => $reused->count(),
        'reused_avatars' => $reused->whereNotNull('avatar_approved_path')->count(),
        'queues' => $queues, 'pending_events' => $outbox,
        'remote_media' => 'preserved', 'sequences' => 'preserved'];
    if ($mode === '--plan') {
        echo json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    if (! $app->isDownForMaintenance() || array_sum($queues) !== 0 || $outbox !== 0 || DB::table('jobs')->exists()) {
        throw new RuntimeException('Maintenance and empty stopped workers/outbox required.');
    }
    // Call only after the development API (including scheduler/workers) and Reverb have stopped.
    $protected = ['admins', 'admin_sessions', 'countries', 'cities', 'currencies', 'transport_types', 'block_reasons', 'reference_imports', 'migrations', 'failed_jobs',
        ...array_filter(['system_logs', 'legal_documents', 'document_versions'], fn ($table) => Schema::hasTable($table))];
    $hash = static function (string $table): string {
        $context = hash_init('sha256');
        foreach (DB::table($table)->orderBy($table === 'legal_documents' ? 'code' : 'id')->cursor() as $row) {
            hash_update($context, json_encode($row, JSON_THROW_ON_ERROR));
        }
        return hash_final($context);
    };
    $report = DB::transaction(function () use ($oldUsers, $accounts, $protected, $hash, &$phase): array {
        DB::statement("SET LOCAL lock_timeout = '10s'");
        $phase = 'protected tables';
        $before = [];
        foreach ($protected as $table) {
            DB::statement('LOCK TABLE '.$table.' IN SHARE MODE');
            $before[$table] = $hash($table);
        }
        $phase = 'old user records';
        DB::table('notifications')->where('notifiable_type', (new User)->getMorphClass())->delete();
        DB::table('personal_access_tokens')->where('tokenable_type', (new User)->getMorphClass())->delete();
        DB::table('admin_panel_logs')->whereNotNull('user_id')->orWhereNotNull('order_id')->delete();
        $emails = array_fill_keys($oldUsers->pluck('email')->all(), true);
        foreach (DB::table('email_logs')->select(['id', 'recipients', 'cc', 'bcc'])->cursor() as $log) {
            $matched = false;
            foreach (['recipients', 'cc', 'bcc'] as $field) {
                $addresses = json_decode($log->$field, true) ?: [];
                array_walk_recursive($addresses, function ($value) use ($emails, &$matched): void {
                    if (is_string($value) && isset($emails[$value])) {
                        $matched = true;
                    }
                });
            }
            if ($matched) {
                DB::table('email_logs')->where('id', $log->id)->delete();
            }
        }
        DB::table('password_reset_tokens')->whereIn('email', array_keys($emails))->delete();
        // Admin upload authorizations and all remote files remain intact.
        $uploadsDeleted = DB::table('direct_uploads')->where('owner_type', 'user')->whereIn('owner_id', $oldUsers->pluck('id'))->delete();
        DB::table('bank_payments')->update(['entry_id' => null, 'topup_root_id' => null, 'retry_of' => null]);
        DB::table('wallet_entries')->update(['bank_refund_id' => null]);
        DB::table('order_purchase_receipts')->update(['replaces_id' => null]);
        $tables = [
            'order_reviews', 'order_images', 'order_message_images', 'order_purchase_receipts',
            'bank_payment_events', 'bank_refunds', 'bank_payments',
            'order_messages', 'order_reward_offers', 'order_conversations', 'order_purchase_offers',
            'order_delivery_dates', 'order_transfer_requests', 'order_settlements', 'order_disputes',
            'order_compensations', 'order_terminations', 'order_holds', 'wallet_projection_checkpoints',
            'wallet_entries', 'withdrawals', 'wallets',
            'order_status_history', 'order_transport_type', 'user_logs', 'user_rating_changes', 'orders',
            'phone_verifications', 'email_changes', ...(Schema::hasTable('document_acceptances') ? ['document_acceptances'] : []),
            'verification_codes', 'user_settings', 'sessions', 'users',
        ];
        $deleted = ['direct_uploads' => $uploadsDeleted];
        foreach ($tables as $table) {
            $phase = 'delete '.$table;
            $deleted[$table] = DB::table($table)->delete();
        }
        $phase = 'seed current scenarios';
        if (Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true, '--no-interaction' => true]) !== 0) {
            throw new RuntimeException('Demo seeding failed.');
        }
        $phase = 'restore familiar credentials and avatars';
        $credentialsRestored = 0;
        $avatarsRestored = 0;
        $avatarFields = ['avatar_disk', 'avatar_path', 'avatar_status', 'avatar_moderation_status', 'avatar_approved_disk', 'avatar_approved_path'];
        foreach ($oldUsers as $old) {
            $existing = DB::table('users')->where('email', $old->email)->first(['id']);
            if ($existing === null) {
                continue;
            }
            $attributes = ['password' => $old->password, 'username' => $old->username];
            if ($old->avatar_approved_path !== null) {
                foreach ($avatarFields as $field) {
                    $attributes[$field] = $old->$field;
                }
                $avatarsRestored++;
            }
            DB::table('users')->where('id', $existing->id)->update($attributes);
            $actual = DB::table('users')->where('id', $existing->id)->first();
            foreach ($attributes as $field => $value) {
                if ($actual->$field !== $value) {
                    throw new RuntimeException('Identity preservation failed.');
                }
            }
            $credentialsRestored++;
        }
        $phase = 'verify users, money and preserved tables';
        $actualEmails = DB::table('users')->orderBy('email')->pluck('email')->all();
        $expectedEmails = array_keys($accounts);
        sort($expectedEmails);
        if ($actualEmails !== $expectedEmails
            || DB::table('users')->whereNull('avatar_approved_path')->exists()) {
            throw new RuntimeException('Unexpected fixture catalog or avatar state.');
        }
        foreach (DB::table('wallets')->get() as $wallet) {
            $entries = DB::table('wallet_entries')->where('wallet_id', $wallet->id);
            $holds = DB::table('order_holds')->where('wallet_id', $wallet->id)->where('status', 'reserved')->sum('amount');
            $withdrawals = DB::table('withdrawals')->where('wallet_id', $wallet->id)->whereIn('status', ['pending_review', 'processing'])->sum('amount');
            $refunds = DB::table('bank_refunds')->where('wallet_id', $wallet->id)->whereNotIn('status', ['confirmed', 'failed', 'canceled'])->sum('wallet_amount');
            if ((string) $entries->sum('available_delta') !== (string) $wallet->available
                || (string) $entries->sum('reserved_delta') !== (string) $wallet->reserved
                || (string) $wallet->reserved !== bcadd(bcadd((string) $holds, (string) $withdrawals, 0), (string) $refunds, 0)) {
                throw new RuntimeException('Wallet integrity check failed.');
            }
        }
        foreach ($protected as $table) {
            if ($before[$table] !== $hash($table)) {
                throw new RuntimeException('Protected table changed.');
            }
        }
        return ['deleted' => $deleted, 'created' => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all(),
            'credentials_restored' => $credentialsRestored, 'avatars_restored' => $avatarsRestored,
            'preserved' => $protected, 'remote_media' => 'preserved', 'money_integrity' => 'ok'];
    });
    $phase = 'check seeded public avatars after commit';
    $avatars = DB::table('users')->select(['avatar_approved_disk', 'avatar_approved_path'])->distinct()->get();
    foreach ($avatars as $avatar) {
        if (! Storage::disk($avatar->avatar_approved_disk)->exists($avatar->avatar_approved_path)) {
            throw new RuntimeException('An approved avatar is missing from storage after commit.');
        }
    }
    $report['avatar_files_verified'] = $avatars->count();
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    // SQL exceptions can include password hashes in bound values. Never print raw errors.
    fwrite(STDERR, 'Development reset failed at '.$phase.' ('.get_class($error).', code '.$error->getCode().').'.PHP_EOL);
    exit(1);
}
