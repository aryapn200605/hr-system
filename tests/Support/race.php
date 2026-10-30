<?php

/*
 * One competitor in AuthConcurrencyTest. It runs in its own PHP process with its own
 * database connection, waits for the agreed moment and prints the outcome.
 *
 * Usage: php race.php <recovery|totp|activation> <user id> <code or invitation id> <start time>
 */

use App\Models\AccountInvitation;
use App\Models\RecoveryCode;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

if ($mode === 'activation') {
    $invitation = AccountInvitation::findOrFail($code);
    $passwordHash = Hash::make('Correct-Horse-42');
    $secret = TwoFactor::generateSecret();
}

$reachedBarrier = microtime(true) < (float) $startAt;

while (microtime(true) < (float) $startAt) {
    // Spin instead of sleeping, to start as close together as the scheduler allows.
}

$won = match ($mode) {
    'recovery' => RecoveryCode::consume($user, $code),
    'totp' => TwoFactor::verify($user, $code),
    'activation' => $user->completeActivation($invitation, $passwordHash, $secret, 1) !== null,
};

echo json_encode(['won' => $won, 'reached_barrier' => $reachedBarrier]);
