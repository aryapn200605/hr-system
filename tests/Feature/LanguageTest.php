<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\LeaveEntryType;
use App\Enums\LoginResult;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Profile\Show;
use App\Mail\PasswordResetMail;
use App\Models\AuditLog;
use App\Models\Language;
use App\Models\Translation;
use App\Models\User;
use App\Support\MailDelivery;
use App\Support\UserAgentLabel;
use App\Translation\DatabaseLoader;
use App\Translation\DynamicLabels;
use Carbon\CarbonImmutable;
use Database\Seeders\LeaveCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TranslationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\InvitesUsers;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class LanguageTest extends TestCase
{
    use InvitesUsers, LogsIn, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function indonesian(array $attributes = []): User
    {
        $user = $this->enrolledUser($attributes);
        $user->locale = 'id';
        $user->save();

        return $user;
    }

    /**
     * The translator keeps loaded texts for the life of the application, as one real request does.
     * A test that changes rows and then makes another request starts it afresh.
     */
    private function forgetLoadedTexts(): void
    {
        app('translator')->setLoaded([]);
    }

    private function setText(string $locale, string $key, string $value, string $group = '*'): void
    {
        DB::table('translations')->updateOrInsert(['locale' => $locale, 'group' => $group, 'key' => $key], ['value' => $value]);
        DatabaseLoader::flush();
        $this->forgetLoadedTexts();
    }

    // Base data and the missing-keys command

    public function test_the_base_texts_are_loaded_by_migrating(): void
    {
        $this->assertSame(['en', 'id'], Language::activeCodes()->all());
        $this->assertGreaterThan(500, DB::table('translations')->count());
        $this->assertSame('Masuk', DB::table('translations')->where(['locale' => 'id', 'group' => '*', 'key' => 'Log in'])->value('value'));
    }

    public function test_every_active_language_has_every_key(): void
    {
        $this->artisan('app:translations-missing')
            ->expectsOutputToContain('Every active language has every key.')
            ->assertSuccessful();
    }

    public function test_a_key_used_in_the_code_but_missing_in_one_language_fails_the_check(): void
    {
        DB::table('translations')->where(['locale' => 'id', 'group' => '*', 'key' => 'Log in'])->delete();

        $this->artisan('app:translations-missing')
            ->expectsOutputToContain('Log in')
            ->expectsOutputToContain('1 translations are missing.')
            ->assertFailed();
    }

    public function test_a_key_used_in_the_code_but_missing_everywhere_fails_the_check(): void
    {
        DB::table('translations')->where(['group' => '*', 'key' => 'Security Settings'])->delete();

        $this->artisan('app:translations-missing')
            ->expectsOutputToContain('2 translations are missing.')
            ->assertFailed();
    }

    public function test_a_framework_message_missing_in_indonesian_fails_the_check(): void
    {
        DB::table('translations')->where(['locale' => 'id', 'group' => 'validation', 'key' => 'min.string'])->delete();

        $this->artisan('app:translations-missing')->expectsOutputToContain('min.string')->assertFailed();
    }

    public function test_an_inactive_language_is_not_checked(): void
    {
        Language::whereKey('id')->update(['is_active' => false]);
        DB::table('translations')->where(['locale' => 'id', 'group' => '*', 'key' => 'Log in'])->delete();

        $this->artisan('app:translations-missing')->assertSuccessful();
    }

    /**
     * Each dynamic label must be both in the register and translated.
     */
    public function test_every_dynamic_label_is_registered(): void
    {
        App::setLocale('en');

        $labels = [
            ...array_map(fn (UserStatus $s) => $s->label(), UserStatus::cases()),
            ...array_map(fn (RoleName $r) => $r->label(), RoleName::cases()),
            ...array_map(fn (LoginResult $r) => $r->label(), LoginResult::cases()),
            ...array_map(fn (LeaveEntryType $t) => $t->label(), LeaveEntryType::cases()),
            ...array_map(fn (RequestStatus $s) => $s->label(), RequestStatus::cases()),
            ...array_column(LeaveCategorySeeder::CATEGORIES, 'name'),
            UserAgentLabel::for(null),
        ];

        foreach ($labels as $label) {
            $this->assertContains($label, DynamicLabels::KEYS, "Register '$label' in DynamicLabels.");
        }

        $this->assertContains(':browser on :system', DynamicLabels::KEYS);
    }

    public function test_a_dynamic_label_missing_in_indonesian_fails_the_check(): void
    {
        DB::table('translations')->where(['locale' => 'id', 'group' => '*', 'key' => 'Suspended'])->delete();

        $this->artisan('app:translations-missing')->expectsOutputToContain('Suspended')->assertFailed();
    }

    /**
     * Even without any code naming them, and without an English row, the registered labels are required.
     */
    public function test_the_register_alone_makes_dynamic_labels_required(): void
    {
        DB::table('translations')->where(['group' => '*', 'key' => 'Suspended'])->delete();
        DB::table('translations')->where(['group' => '*', 'key' => ':browser on :system'])->delete();

        $this->artisan('app:translations-missing', ['--path' => ['tests/Fixtures/no-keys']])
            ->expectsOutputToContain('Suspended')
            ->expectsOutputToContain(':browser on :system')
            ->expectsOutputToContain('4 translations are missing.')
            ->assertFailed();
    }

    public function test_an_empty_folder_needs_only_the_register_and_the_english_rows(): void
    {
        DB::table('translations')->where(['group' => '*', 'key' => 'Security Settings'])->delete();

        $this->artisan('app:translations-missing', ['--path' => ['tests/Fixtures/no-keys']])->assertSuccessful();
    }

    public function test_dynamic_labels_follow_the_language(): void
    {
        App::setLocale('id');

        $this->assertSame('Ditangguhkan', UserStatus::Suspended->label());
        $this->assertSame('Admin HR', RoleName::HrAdmin->label());
        $this->assertSame('Kode pemulihan salah', LoginResult::FailedRecovery->label());
        $this->assertSame('Firefox di Windows', UserAgentLabel::for('Mozilla/5.0 (Windows NT 10.0; rv:140.0) Firefox/140.0'));
        $this->assertSame('Perangkat tidak dikenal', UserAgentLabel::for(''));
        $this->assertSame(RoleName::HrAdmin->value, 'HR Admin', 'The stored role name never changes.');
    }

    // Keys

    public function test_keys_that_differ_only_in_case_are_separate(): void
    {
        DB::table('translations')->insert([
            ['locale' => 'id', 'group' => '*', 'key' => 'Case Probe', 'value' => 'besar'],
            ['locale' => 'id', 'group' => '*', 'key' => 'case probe', 'value' => 'kecil'],
        ]);
        DatabaseLoader::flush();
        App::setLocale('id');

        $this->assertSame('besar', __('Case Probe'));
        $this->assertSame('kecil', __('case probe'));
        $this->assertSame(2, DB::table('translations')->where('locale', 'id')->whereIn('key', ['Case Probe', 'case probe'])->count());
    }

    public function test_keys_that_differ_only_in_accents_are_separate(): void
    {
        DB::table('translations')->insert([
            ['locale' => 'id', 'group' => '*', 'key' => 'resume', 'value' => 'a'],
            ['locale' => 'id', 'group' => '*', 'key' => 'résumé', 'value' => 'b'],
        ]);

        $this->assertSame(2, DB::table('translations')->whereIn('key', ['resume', 'résumé'])->count());
    }

    public function test_the_same_key_twice_in_one_language_is_refused(): void
    {
        $this->expectException(QueryException::class);

        DB::table('translations')->insert(['locale' => 'id', 'group' => '*', 'key' => 'Log in', 'value' => 'lagi']);
    }

    // Choosing the language

    public function test_guests_get_english(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('Username or email');
    }

    public function test_a_user_without_a_choice_gets_english(): void
    {
        $this->actingAs($this->enrolledUser())->get(route('dashboard'))
            ->assertSee('<html lang="en">', false)
            ->assertSee('Recent logins');
    }

    public function test_a_user_who_chose_indonesian_gets_indonesian_pages(): void
    {
        $user = $this->indonesian(['name' => 'Rina']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<html lang="id">', false)
            ->assertSee('Selamat datang, Rina')
            ->assertSee('Aktivitas masuk terbaru')
            ->assertSee('Karyawan')
            ->assertDontSee('Recent logins');

        $this->actingAs($user)->get(route('profile.sessions'))->assertSee('Sesi aktif')->assertSee('Keluar dari sesi ini');
        $this->actingAs($user)->get(route('security.index'))->assertSee('Pengaturan Keamanan');
    }

    public function test_a_deactivated_language_falls_back_to_english(): void
    {
        $user = $this->indonesian();
        Language::whereKey('id')->update(['is_active' => false]);

        $this->actingAs($user)->get(route('dashboard'))->assertSee('<html lang="en">', false)->assertSee('Recent logins');
    }

    public function test_a_text_missing_in_indonesian_falls_back_to_english(): void
    {
        DB::table('translations')->where(['locale' => 'id', 'group' => '*', 'key' => 'Recent logins'])->delete();
        DatabaseLoader::flush();

        $this->actingAs($this->indonesian())->get(route('dashboard'))
            ->assertSee('Recent logins')
            ->assertSee('Status keamanan');
    }

    public function test_dates_use_the_month_names_of_the_language(): void
    {
        App::setLocale('id');

        $html = Blade::render('<x-ui.datetime :value="$v" />', ['v' => CarbonImmutable::parse('2026-08-17 09:00:00')]);

        $this->assertStringContainsString('17 Agt 2026, 09:00 WIB', $html);
        $this->assertStringContainsString('datetime="2026-08-17T09:00:00+07:00"', $html);
    }

    public function test_validation_messages_and_field_names_are_translated(): void
    {
        $user = $this->indonesian();
        App::setLocale('id');

        Livewire::actingAs($user)->test(Show::class)
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required'])
            ->assertSee('Nama lengkap wajib diisi.');
    }

    public function test_english_validation_messages_are_unchanged(): void
    {
        Livewire::actingAs($this->enrolledUser())->test(Show::class)
            ->set('name', '')
            ->call('save')
            ->assertSee('The name field is required.');
    }

    // The Profile selector

    public function test_a_user_chooses_indonesian_in_my_profile(): void
    {
        $user = $this->enrolledUser();

        Livewire::actingAs($user)->test(Show::class)
            ->assertSet('locale', 'en')
            ->assertSee('Bahasa Indonesia')
            ->set('locale', 'id')
            ->call('saveLocale')
            ->assertHasNoErrors()
            ->assertRedirect(route('profile.show'));

        $this->assertSame('id', $user->fresh()->locale);
        $this->assertSame('Bahasa Anda telah diubah.', session('toast'));
        $this->assertSame(['fields' => ['locale']], AuditLog::where('event', AuditEvent::UserUpdated->value)->sole()->metadata);

        $this->forgetLoadedTexts();
        $this->actingAs($user->fresh())->get(route('profile.show'))->assertSee('Bahasa tampilan');
    }

    public function test_saving_the_same_language_writes_no_audit_entry(): void
    {
        $user = $this->indonesian();

        Livewire::actingAs($user)->test(Show::class)->assertSet('locale', 'id')->call('saveLocale')->assertHasNoErrors();

        $this->assertSame(0, AuditLog::where('event', AuditEvent::UserUpdated->value)->count());
    }

    public function test_an_unknown_or_inactive_language_is_refused(): void
    {
        $user = $this->enrolledUser();
        Language::create(['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'is_active' => false]);

        foreach (['xx', 'fr', 'ID', '', 'en ', '*'] as $value) {
            Livewire::actingAs($user)->test(Show::class)->set('locale', $value)->call('saveLocale')->assertHasErrors('locale');
        }

        $this->assertNull($user->fresh()->locale);
        $this->assertSame(0, AuditLog::where('event', AuditEvent::UserUpdated->value)->count());
    }

    public function test_a_forged_payload_is_refused(): void
    {
        $user = $this->enrolledUser();

        Livewire::actingAs($user)->test(Show::class)->set('locale', ['id'])->assertStatus(422);
        Livewire::actingAs($user)->test(Show::class)->set('locale', 7)->assertStatus(422);

        $this->assertNull($user->fresh()->locale);
    }

    public function test_the_selector_changes_only_the_logged_in_user(): void
    {
        $user = $this->enrolledUser();
        $other = $this->enrolledUser();

        Livewire::actingAs($user)->test(Show::class)->set('locale', 'id')->call('saveLocale');

        $this->assertSame('id', $user->fresh()->locale);
        $this->assertNull($other->fresh()->locale);
    }

    public function test_locale_cannot_be_mass_assigned(): void
    {
        $user = $this->enrolledUser();
        $user->fill(['locale' => 'id'])->save();

        $this->assertNull($user->fresh()->locale);
    }

    public function test_the_selector_lists_only_active_languages(): void
    {
        Language::create(['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'is_active' => false]);

        Livewire::actingAs($this->enrolledUser())->test(Show::class)
            ->assertSee('Bahasa Indonesia')
            ->assertDontSee('Français');
    }

    // Escaping and caching

    public function test_a_translation_is_printed_escaped(): void
    {
        $this->setText('id', 'Recent logins', '<script>alert(1)</script>');

        $this->actingAs($this->indonesian())->get(route('dashboard'))
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_a_change_through_the_model_refreshes_the_cache(): void
    {
        $loader = new DatabaseLoader;
        $this->assertSame('Masuk', $loader->load('id', '*')['Log in']);

        Translation::where(['locale' => 'id', 'group' => '*', 'key' => 'Log in'])->sole()->update(['value' => 'Log masuk']);

        $this->assertSame('Log masuk', $loader->load('id', '*')['Log in']);
    }

    public function test_a_direct_database_edit_needs_the_cache_command(): void
    {
        $loader = new DatabaseLoader;
        $this->assertSame('Masuk', $loader->load('id', '*')['Log in']);

        DB::table('translations')->where(['locale' => 'id', 'group' => '*', 'key' => 'Log in'])->update(['value' => 'Log masuk']);
        $this->assertSame('Masuk', $loader->load('id', '*')['Log in'], 'Served from the cache.');

        $this->artisan('app:translations-cache-clear')->assertSuccessful();

        $this->assertSame('Log masuk', $loader->load('id', '*')['Log in']);
    }

    public function test_a_database_failure_is_not_cached(): void
    {
        $database = DB::getFacadeRoot();
        DB::shouldReceive('table')->once()->andThrow(new \RuntimeException('connection lost'));

        $this->assertSame([], (new DatabaseLoader)->load('id', '*'));

        DB::swap($database);
        $this->assertSame('Masuk', (new DatabaseLoader)->load('id', '*')['Log in']);
    }

    public function test_keyed_groups_load_as_nested_arrays(): void
    {
        $validation = (new DatabaseLoader)->load('id', 'validation');

        $this->assertSame(':Attribute wajib diisi.', $validation['required']);
        $this->assertSame(':Attribute harus memuat setidaknya satu angka.', $validation['password']['numbers']);
        $this->assertSame('nama lengkap', $validation['attributes']['name']);
        $this->assertSame([], (new DatabaseLoader)->load('id', 'validation', 'vendor-package'));
    }

    // Seeding

    public function test_the_seeder_is_idempotent_and_keeps_edited_texts(): void
    {
        $before = DB::table('translations')->count();
        DB::table('translations')->where(['locale' => 'id', 'group' => '*', 'key' => 'Log in'])->update(['value' => 'Log masuk']);
        DB::table('translations')->where(['locale' => 'id', 'group' => '*', 'key' => 'Home'])->delete();
        DatabaseLoader::flush();
        $this->assertArrayNotHasKey('Home', (new DatabaseLoader)->load('id', '*'));

        $this->seed(TranslationSeeder::class);
        $this->seed(TranslationSeeder::class);

        $this->assertSame($before, DB::table('translations')->count());
        $this->assertSame('Log masuk', DB::table('translations')->where(['locale' => 'id', 'key' => 'Log in'])->value('value'));
        $this->assertSame('Beranda', DB::table('translations')->where(['locale' => 'id', 'key' => 'Home'])->value('value'));
        $this->assertSame('Beranda', (new DatabaseLoader)->load('id', '*')['Home'], 'Seeding refreshes the cache.');
    }

    public function test_the_base_files_have_the_same_keys_and_no_markup(): void
    {
        $en = require database_path('translations/en.php');
        $id = require database_path('translations/id.php');

        foreach ($en as $group => $items) {
            $this->assertSame([], array_values(array_diff(array_keys($items), array_keys($id[$group]))), "id lacks keys in $group");
        }

        array_walk_recursive($id, fn ($value) => $this->assertDoesNotMatchRegularExpression('/<[^>]*>|&[#A-Za-z0-9]+;/', $value));
        array_walk_recursive($en, fn ($value) => $this->assertDoesNotMatchRegularExpression('/<[^>]*>|&[#A-Za-z0-9]+;/', $value));
    }

    // Mail

    public function test_a_reset_email_uses_the_users_language(): void
    {
        $sent = MailDelivery::send('rina@example.com', new PasswordResetMail('Rina', str_repeat('a', 64)), 'id');
        $message = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        $this->assertTrue($sent);
        $this->assertSame('Atur ulang kata sandi HR System Anda', $message->getSubject());
        $this->assertStringContainsString('Halo Rina,', $message->getTextBody());
        $this->assertSame('en', App::getLocale(), 'Sending does not change the language of the request.');
    }

    public function test_forgot_password_sends_in_the_users_language(): void
    {
        $this->indonesian(['email' => 'rina@example.com']);

        $this->post(route('password.email'), ['email' => 'rina@example.com']);

        $message = app('mailer')->getSymfonyTransport()->messages()->last()?->getOriginalMessage();
        $this->assertNotNull($message);
        $this->assertSame('Atur ulang kata sandi HR System Anda', $message->getSubject());
    }

    public function test_an_invitation_is_english_even_from_an_indonesian_admin(): void
    {
        $admin = $this->hrAdmin();
        $admin->locale = 'id';
        $admin->save();
        App::setLocale('id');

        $this->invite();

        $message = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('You are invited to HR System', $message->getSubject());
        $this->assertStringContainsString('Activate account', $message->getTextBody());
    }
}
