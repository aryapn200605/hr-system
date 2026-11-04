<?php

namespace Tests\Feature;

use App\Models\AccountInvitation;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PruneExpiredTokensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
        config(['auth.prune.retention_days' => 30]);
        $this->admin = User::factory()->twoFactorEnrolled()->create();
    }

    private function token(string $email, int $minutesAgo): void
    {
        DB::table('password_reset_tokens')->insert([
            'email' => $email,
            'token' => Hash::make('x'),
            'created_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function invitation(array $attributes): AccountInvitation
    {
        return AccountInvitation::create([
            'user_id' => User::factory()->pending()->create()->id,
            'invited_by' => $this->admin->id,
            'token_hash' => hash('sha256', random_bytes(16)),
            'expires_at' => now()->addDay(),
            ...$attributes,
        ]);
    }

    public function test_it_removes_only_reset_tokens_expired_longer_than_the_retention(): void
    {
        $expire = config('auth.passwords.users.expire');
        $this->token('old@example.com', 30 * 1440 + $expire + 1);
        $this->token('edge@example.com', 30 * 1440 + $expire - 1);
        $this->token('fresh@example.com', 1);

        $this->artisan('app:prune-expired-tokens')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['edge@example.com', 'fresh@example.com'],
            DB::table('password_reset_tokens')->pluck('email')->all(),
        );
    }

    public function test_it_removes_finished_invitations_older_than_the_retention_and_keeps_the_rest(): void
    {
        $gone = [
            $this->invitation(['expires_at' => now()->subDays(31)]),
            $this->invitation(['accepted_at' => now()->subDays(31)]),
            $this->invitation(['revoked_at' => now()->subDays(31)]),
        ];
        $kept = [
            $this->invitation([]),
            $this->invitation(['expires_at' => now()->subDays(29)]),
            $this->invitation(['accepted_at' => now()->subDays(29)]),
            $this->invitation(['revoked_at' => now()->subDays(29)]),
            $this->invitation(['created_at' => now()->subDays(400), 'expires_at' => now()->addMinute()]),
        ];

        $this->artisan('app:prune-expired-tokens')
            ->expectsOutputToContain('Removed 0 reset tokens and 3 invitations older than 30 days.')
            ->assertSuccessful();

        foreach ($gone as $invitation) {
            $this->assertModelMissing($invitation);
        }

        foreach ($kept as $invitation) {
            $this->assertModelExists($invitation);
        }

        $this->assertSame(9, User::count(), 'Users are never removed.');
    }

    public function test_an_open_invitation_still_works_after_pruning(): void
    {
        $user = User::factory()->pending()->create();
        [, $token] = AccountInvitation::issueFor($user, $this->admin);

        $this->artisan('app:prune-expired-tokens')->assertSuccessful();

        $this->assertNotNull(AccountInvitation::findOpenByToken($token));
    }

    public function test_a_second_run_removes_nothing_more(): void
    {
        $this->invitation(['expires_at' => now()->subDays(40)]);
        $this->token('old@example.com', 50 * 1440);

        $this->artisan('app:prune-expired-tokens')->expectsOutputToContain('Removed 1 reset tokens and 1 invitations')->assertSuccessful();
        $this->artisan('app:prune-expired-tokens')->expectsOutputToContain('Removed 0 reset tokens and 0 invitations')->assertSuccessful();
    }

    public function test_the_retention_is_configurable(): void
    {
        config(['auth.prune.retention_days' => 7]);
        $old = $this->invitation(['expires_at' => now()->subDays(8)]);
        $recent = $this->invitation(['expires_at' => now()->subDays(6)]);

        $this->artisan('app:prune-expired-tokens')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidRetentions(): array
    {
        return ['zero' => [0], 'negative' => [-5], 'text' => ['30'], 'null' => [null]];
    }

    #[DataProvider('invalidRetentions')]
    public function test_an_invalid_retention_refuses_and_removes_nothing(mixed $days): void
    {
        config(['auth.prune.retention_days' => $days]);
        $invitation = $this->invitation(['expires_at' => now()->subDays(400)]);
        $this->token('old@example.com', 400 * 1440);

        $this->artisan('app:prune-expired-tokens')->assertFailed();

        $this->assertModelExists($invitation);
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
    }

    public function test_it_is_scheduled_daily_at_two_wib_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'app:prune-expired-tokens'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('0 2 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame('Asia/Jakarta', (string) $event->timezone, 'It follows the application timezone, WIB.');
    }
}
