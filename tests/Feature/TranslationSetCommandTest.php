<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Translation\DatabaseLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TranslationSetCommandTest extends TestCase
{
    use RefreshDatabase;

    private function value(string $locale, string $key, string $group = '*'): ?string
    {
        return DB::table('translations')->where(compact('locale', 'group', 'key'))->value('value');
    }

    public function test_it_changes_a_text_audits_it_and_refreshes_the_cache(): void
    {
        $loader = new DatabaseLoader;
        $this->assertSame('Masuk', $loader->load('id', '*')['Log in']);

        $this->artisan('app:translation-set', ['locale' => 'id', 'key' => 'Log in', 'value' => 'Log masuk'])
            ->expectsOutputToContain('Translation updated.')
            ->assertSuccessful();

        $this->assertSame('Log masuk', $this->value('id', 'Log in'));
        $this->assertSame('Log masuk', $loader->load('id', '*')['Log in']);

        $audit = AuditLog::where('event', AuditEvent::TranslationChanged->value)->sole();
        $this->assertNull($audit->actor_id);
        $this->assertEqualsCanonicalizing(['locale' => 'id', 'group' => '*', 'key' => 'Log in', 'created' => false], $audit->metadata);
    }

    public function test_it_adds_a_new_key_and_a_keyed_group_text(): void
    {
        $this->artisan('app:translation-set', ['locale' => 'id', 'key' => 'Brand new text', 'value' => 'Teks baru'])
            ->expectsOutputToContain('Translation added.')
            ->assertSuccessful();

        $this->artisan('app:translation-set', ['locale' => 'id', 'key' => 'required', 'value' => ':Attribute harus diisi.', '--group' => 'validation'])
            ->assertSuccessful();

        $this->assertSame('Teks baru', $this->value('id', 'Brand new text'));
        $this->assertSame(':Attribute harus diisi.', $this->value('id', 'required', 'validation'));
        $this->assertTrue(AuditLog::where('event', AuditEvent::TranslationChanged->value)->get()->contains(fn ($a) => $a->metadata['created'] === true));
    }

    public function test_a_key_with_different_case_is_a_new_key(): void
    {
        $this->artisan('app:translation-set', ['locale' => 'id', 'key' => 'log in', 'value' => 'masuk kecil'])->assertSuccessful();

        $this->assertSame('Masuk', $this->value('id', 'Log in'));
        $this->assertSame('masuk kecil', $this->value('id', 'log in'));
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function refused(): array
    {
        return [
            'unknown language' => [['locale' => 'xx', 'key' => 'Log in', 'value' => 'Masuk']],
            'unknown group' => [['locale' => 'id', 'key' => 'Log in', 'value' => 'Masuk', '--group' => 'mail']],
            'html tag' => [['locale' => 'id', 'key' => 'Log in', 'value' => '<b>Masuk</b>']],
            'html entity' => [['locale' => 'id', 'key' => 'Log in', 'value' => 'Masuk &raquo;']],
            'missing placeholder' => [['locale' => 'id', 'key' => 'Welcome, :name', 'value' => 'Selamat datang']],
            'extra placeholder' => [['locale' => 'id', 'key' => 'Log in', 'value' => 'Masuk :name']],
            'renamed placeholder' => [['locale' => 'id', 'key' => 'Welcome, :name', 'value' => 'Selamat datang, :nama']],
            'too long key' => [['locale' => 'id', 'key' => str_repeat('k', 501), 'value' => 'x']],
        ];
    }

    /**
     * @param  array<string, string>  $arguments
     */
    #[DataProvider('refused')]
    public function test_invalid_input_is_refused_and_changes_nothing(array $arguments): void
    {
        $before = DB::table('translations')->orderBy('id')->get()->toArray();

        $this->artisan('app:translation-set', $arguments)->assertFailed();

        $this->assertEquals($before, DB::table('translations')->orderBy('id')->get()->toArray());
        $this->assertSame(0, AuditLog::where('event', AuditEvent::TranslationChanged->value)->count());
    }

    public function test_placeholder_case_does_not_matter(): void
    {
        $this->artisan('app:translation-set', ['locale' => 'id', 'key' => 'required', 'value' => ':attribute wajib.', '--group' => 'validation'])
            ->assertSuccessful();
    }
}
