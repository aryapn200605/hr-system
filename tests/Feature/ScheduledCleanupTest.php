<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Models\AccountInvitation;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScheduledCleanupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function invitation(array $attributes): AccountInvitation
    {
        return AccountInvitation::create([
            'user_id' => $this->user->getKey(),
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addDay(),
            ...$attributes,
        ]);
    }

    public function test_it_deletes_expired_and_revoked_invitations_past_the_retention(): void
    {
        $expired = $this->invitation(['expires_at' => now()->subDays(91)]);
        $revoked = $this->invitation(['revoked_at' => now()->subDays(91)]);

        $this->artisan('app:prune-invitations')->expectsOutputToContain('Deleted 2')->assertSuccessful();

        $this->assertModelMissing($expired);
        $this->assertModelMissing($revoked);
    }

    public function test_it_keeps_invitations_inside_the_retention(): void
    {
        $expired = $this->invitation(['expires_at' => now()->subDays(89)]);
        $revoked = $this->invitation(['revoked_at' => now()->subDays(89)]);

        $this->artisan('app:prune-invitations')->assertSuccessful();

        $this->assertModelExists($expired);
        $this->assertModelExists($revoked);
    }

    public function test_it_never_deletes_an_open_invitation(): void
    {
        $open = $this->invitation([]);

        $this->artisan('app:prune-invitations', ['--days' => 1])->assertSuccessful();

        $this->assertModelExists($open);
    }

    public function test_it_keeps_accepted_invitations_for_good(): void
    {
        $accepted = $this->invitation([
            'expires_at' => now()->subYears(5),
            'accepted_at' => now()->subYears(5),
            'revoked_at' => now()->subYears(5),
        ]);

        $this->artisan('app:prune-invitations', ['--days' => 1])->assertSuccessful();

        $this->assertModelExists($accepted);
    }

    public function test_it_leaves_the_audit_trail_alone(): void
    {
        $invitation = $this->invitation(['expires_at' => now()->subDays(91)]);
        AuditLog::record(AuditEvent::InvitationCreated, null, $this->user, ['invitation_id' => $invitation->getKey()]);

        $this->artisan('app:prune-invitations')->assertSuccessful();

        $this->assertSame(1, AuditLog::where('event', AuditEvent::InvitationCreated->value)->count());
    }

    public function test_the_retention_can_be_changed(): void
    {
        $expired = $this->invitation(['expires_at' => now()->subDays(11)]);

        $this->artisan('app:prune-invitations', ['--days' => 10])->assertSuccessful();

        $this->assertModelMissing($expired);
    }

    public function test_an_invalid_retention_deletes_nothing(): void
    {
        $expired = $this->invitation(['expires_at' => now()->subDays(91)]);

        foreach (['0', '-5', 'all'] as $days) {
            $this->artisan('app:prune-invitations', ['--days' => $days])->assertFailed();
        }

        $this->assertModelExists($expired);
    }

    public function test_expired_reset_tokens_are_cleared_and_fresh_ones_stay(): void
    {
        DB::table('password_reset_tokens')->insert([
            ['email' => 'old@example.com', 'token' => 'x', 'created_at' => now()->subHours(2)],
            ['email' => 'new@example.com', 'token' => 'y', 'created_at' => now()],
        ]);

        $this->artisan('auth:clear-resets')->assertSuccessful();

        $this->assertSame(['new@example.com'], DB::table('password_reset_tokens')->pluck('email')->all());
    }

    public function test_both_cleanups_are_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach (['auth:clear-resets', 'app:prune-invitations'] as $command) {
            $event = $events->first(fn ($event) => str_contains((string) $event->command, $command));

            $this->assertNotNull($event, $command);
            $this->assertSame('0 0 * * *', $event->expression);
        }
    }
}
