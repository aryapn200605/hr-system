<?php

/*
 * One competitor in AuthConcurrencyTest. It runs in its own PHP process with its own
 * database connection, waits for the agreed moment and prints the outcome.
 *
 * Usage: php race.php <recovery|totp> <user id> <code> <start time>
 */

use App\Models\RecoveryCode;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $mode, $userId, $code, $startAt] = $argv;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'hr_system_test') {
    fwrite(STDERR, "Refusing to run outside hr_system_test.\n");
    exit(1);
}

$user = User::findOrFail($userId);
DB::connection()->getPdo();

$reachedBarrier = microtime(true) < (float) $startAt;

while (microtime(true) < (float) $startAt) {
    // Spin instead of sleeping, to start as close together as the scheduler allows.
}

$won = $mode === 'recovery'
    ? RecoveryCode::consume($user, $code)
    : TwoFactor::verify($user, $code);

echo json_encode(['won' => $won, 'reached_barrier' => $reachedBarrier]);
