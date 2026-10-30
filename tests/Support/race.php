<?php

/*
 * One competitor in AuthConcurrencyTest. It runs in its own PHP process with its own
 * database connection, waits for the agreed moment and prints the outcome.
 *
 * Usage: php race.php <recovery|totp|activation|suspend|demote|regenerate|email> <user id> <code, invitation id, actor id or email> <start time>
 */

use App\Actions\UpdateOwnIdentity;
use App\Enums\RoleName;
use App\Exceptions\AccountProtectionException;
use App\Models\AccountInvitation;
use App\Models\RecoveryCode;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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

if (in_array($mode, ['suspend', 'demote'], true)) {
    $actor = User::findOrFail($code);
    $actor->getAllPermissions();
}

$reachedBarrier = microtime(true) < (float) $startAt;

while (microtime(true) < (float) $startAt) {
    // Spin instead of sleeping, to start as close together as the scheduler allows.
}

$invalid = false;
$codes = [];

try {
    $won = match ($mode) {
        'recovery' => RecoveryCode::consume($user, $code),
        'totp' => TwoFactor::verify($user, $code),
        'activation' => $user->completeActivation($invitation, $passwordHash, $secret, 1) !== null,
        'suspend' => (bool) $user->suspend($actor) || true,
        'demote' => (bool) $user->changeRole(RoleName::Employee, $actor) || true,
        'regenerate' => ($codes = $user->regenerateRecoveryCodes()) !== [],
        'email' => (new UpdateOwnIdentity)->handle($user, [
            'username' => $user->username,
            'email' => $code,
            'email_confirmation' => $code,
        ]) === ['email'],
    };
} catch (AccountProtectionException) {
    $won = false;
} catch (ValidationException) {
    $won = false;
    $invalid = true;
}

echo json_encode(['won' => $won, 'reached_barrier' => $reachedBarrier, 'invalid' => $invalid, 'codes' => $codes]);
