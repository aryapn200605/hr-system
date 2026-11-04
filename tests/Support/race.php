<?php

/*
 * One competitor in AuthConcurrencyTest. It runs in its own PHP process with its own
 * database connection, waits for the agreed moment and prints the outcome.
 *
 * Usage: php race.php <recovery|totp|activation|suspend|demote|regenerate|email|prune|seed-translations|move|attach|detach|adjust|reset|sync-leave>
 *        <user id> <code, invitation id, actor id, email or "actor id:argument"> <start time>
 */

use App\Actions\UpdateOwnIdentity;
use App\Enums\RoleName;
use App\Exceptions\AccountProtectionException;
use App\Models\AccountInvitation;
use App\Models\RecoveryCode;
use App\Models\User;
use App\Services\LeaveBalance;
use App\Services\Supervisors;
use App\Support\TwoFactor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
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

// Organization modes pass "<actor id>:<argument>" as the code.
if (in_array($mode, ['move', 'attach', 'detach', 'adjust', 'reset'], true)) {
    [$actorId, $argument] = explode(':', $code) + [1 => ''];
    $actor = User::findOrFail($actorId);
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
        'prune' => Artisan::call('app:prune-expired-tokens') === 0,
        'seed-translations' => Artisan::call('db:seed', ['--class' => TranslationSeeder::class, '--force' => true]) === 0,
        'move' => app(Supervisors::class)->move($actor, $user, $argument, null) > 0,
        'attach' => app(Supervisors::class)->attach($actor, $user, $argument) === null,
        'detach' => app(Supervisors::class)->detach($actor, $user, $argument) === null,
        'adjust' => app(LeaveBalance::class)->adjust($actor, $user, $argument, 'Concurrent adjustment test.') !== null,
        'reset' => app(LeaveBalance::class)->reset($actor, $user, 'Concurrent reset test.') !== null,
        'sync-leave' => app(LeaveBalance::class)->sync($user) !== null,
    };
} catch (AccountProtectionException) {
    $won = false;
} catch (ValidationException) {
    $won = false;
    $invalid = true;
}

echo json_encode(['won' => $won, 'reached_barrier' => $reachedBarrier, 'invalid' => $invalid, 'codes' => $codes]);
