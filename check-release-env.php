<?php

// Deployment preflight: no Laravel boot, database or network access.
require getcwd().'/vendor/autoload.php';

$expected = $argv[1] ?? '';
if (! in_array($expected, ['development', 'production'], true)) {
    fwrite(STDERR, "APP_ENV must be development or production.\n");
    exit(1);
}

try {
    $env = Dotenv\Dotenv::createArrayBacked(getcwd(), '.env')->load();
} catch (Throwable) {
    fwrite(STDERR, "Unable to parse the prepared .env file.\n");
    exit(1);
}

$errors = [];
if (($env['APP_ENV'] ?? '') !== $expected) {
    $errors[] = 'APP_ENV does not match the build environment';
}
foreach (['APP_KEY', 'DB_PASSWORD', 'REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET',
    'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'AWS_BUCKET',
    'AWS_PUBLIC_ACCESS_KEY_ID', 'AWS_PUBLIC_SECRET_ACCESS_KEY', 'AWS_PUBLIC_DEFAULT_REGION', 'AWS_PUBLIC_BUCKET',
    'APP_URL', 'ADMIN_DOMAIN', 'VITE_REVERB_HOST'] as $name) {
    if (in_array(strtolower(trim($env[$name] ?? '')), ['', 'null', '(null)'], true)) {
        $errors[] = $name.' is required';
    }
}
foreach (['FILESYSTEM_DISK' => 's3', 'SESSION_DRIVER' => 'redis', 'CACHE_STORE' => 'redis',
    'QUEUE_CONNECTION' => 'redis', 'VITE_REVERB_SCHEME' => 'https', 'VITE_REVERB_PORT' => '443'] as $name => $value) {
    if (($env[$name] ?? '') !== $value) {
        $errors[] = $name.' must be '.$value;
    }
}
if (($env['VITE_REVERB_APP_KEY'] ?? '') !== ($env['REVERB_APP_KEY'] ?? '')) {
    $errors[] = 'VITE_REVERB_APP_KEY must match REVERB_APP_KEY';
}
$key = $env['APP_KEY'] ?? '';
if (! str_starts_with($key, 'base64:') || strlen((string) base64_decode(substr($key, 7), true)) !== 32) {
    $errors[] = 'APP_KEY must contain a base64-encoded 32-byte key';
}
if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors)."\n");
    exit(1);
}
echo "Release environment validated (no secret values printed).\n";
