<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\RecoveryCode;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class RecoveryCodeRegenerationTest extends TestCase
{
    use LogsIn, RefreshDatabase;

    private User $user;

    /**
     * @var list<string>
     */
    private array $oldCodes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeTime();

        $this->user = $this->enrolledUser();
        $this->oldCodes = RecoveryCode::regenerateFor($this->user);
        $this->logIn($this->user);
        $this->confirmIdentity($this->user);
    }

    /**
     * @return array{0: TestResponse, 1: list<string>}
     */
    private function regenerate(): array
    {
        $response = $this->post(route('security.recovery-codes.store'))->assertOk();

        preg_match_all('/<li>([A-Z2-9-]{19})<\/li>/', $response->getContent(), $matches);

        return [$response, $matches[1]];
    }

    public function test_the_page_warns_before_anything_changes(): void
    {
        $this->get(route('security.recovery-codes'))
            ->assertOk()
            ->assertSee('Every existing recovery code stops working at once');

        $this->assertTrue(RecoveryCode::consume($this->user, $this->oldCodes[0]));
    }

    public function test_the_old_codes_die_at_once_and_the_new_ones_work(): void
    {
        [, $codes] = $this->regenerate();

        $this->assertCount(10, $codes);
        $this->assertSame(10, $this->user->recoveryCodes()->count());

        foreach ($this->oldCodes as $old) {
            $this->assertFalse(RecoveryCode::consume($this->user, $old));
        }

        $this->assertTrue(RecoveryCode::consume($this->user, $codes[0]));
    }

    public function test_the_codes_are_shown_once_with_no_store_and_never_enter_the_session(): void
    {
        [$response, $codes] = $this->regenerate();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, json_encode(session()->all()));
        }

        $this->assertStringNotContainsString($codes[0], $this->get(route('security.index'))->getContent());
    }

    public function test_the_codes_are_stored_hashed(): void
    {
        [, $codes] = $this->regenerate();

        $stored = $this->user->recoveryCodes()->get()->pluck('code_hash')->implode(' ');

        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $stored);
        }
    }

    public function test_a_repeated_post_goes_back_to_the_confirmation_and_keeps_the_codes_on_screen(): void
    {
        [, $codes] = $this->regenerate();

        $this->post(route('security.recovery-codes.store'))->assertRedirect(route('security.confirm'));

        $this->assertTrue(RecoveryCode::consume($this->user, $codes[0]));
    }

    public function test_the_audit_entry_holds_no_code(): void
    {
        [, $codes] = $this->regenerate();

        $entry = AuditLog::where('event', AuditEvent::RecoveryCodesRegenerated->value)->sole();

        $this->assertSame($this->user->id, $entry->actor_id);
        $this->assertSame($this->user->id, $entry->subject_id);
        $this->assertNull($entry->metadata);
        $this->assertStringNotContainsString($codes[0], $entry->toJson());
    }

    public function test_it_reaches_only_the_logged_in_user(): void
    {
        $other = $this->enrolledUser();
        $otherCodes = RecoveryCode::regenerateFor($other);

        $this->post(route('security.recovery-codes.store'), ['user_id' => $other->id])->assertOk();

        $this->assertTrue(RecoveryCode::consume($other, $otherCodes[0]));
    }

    public function test_the_overview_counts_the_codes_left(): void
    {
        RecoveryCode::consume($this->user, $this->oldCodes[0]);

        $this->get(route('security.index'))->assertOk()->assertSeeInOrder(['Recovery codes left', '9']);
    }

    public function test_the_overview_warns_when_few_codes_are_left(): void
    {
        foreach (array_slice($this->oldCodes, 0, 8) as $code) {
            RecoveryCode::consume($this->user, $code);
        }

        $this->get(route('security.index'))->assertSee('Few recovery codes are left');
    }

    public function test_the_overview_offers_no_way_to_turn_two_factor_off(): void
    {
        $this->get(route('security.index'))
            ->assertOk()
            ->assertSee('Enabled')
            ->assertDontSee('Disable')
            ->assertDontSee('Turn off');
    }
}
