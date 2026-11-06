<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Mail\PasswordResetMail;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Concerns\InvitesUsers;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

/**
 * Phase 15c: the auth pages after the redesign. Field names, attributes and actions stay what the controllers
 * read; every form is sent here the way a browser without JavaScript sends it (the fields parsed from the
 * rendered page, nothing added); no Livewire or Alpine on the public pages; no token in a page or its referrer.
 */
class AuthPagesTest extends TestCase
{
    use InvitesUsers, LogsIn, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeTime();
        $this->withoutDefer();
        Mail::fake();

        // Livewire keeps "a component was rendered in this request" in a static flag that an earlier test in the
        // same PHP process can leave set (in production every request starts clean). Reset it, so the public
        // pages are judged on what they render themselves.
        Livewire::flushState();

        // The base TestCase turns Vite off; here each entry point is written as a marker, so the tests can see
        // which scripts a page loads without depending on a build.
        $this->swap(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null)
            {
                return new HtmlString(collect($entrypoints)->map(fn ($entry) => '<script type="module" data-entry="'.$entry.'"></script>')->implode(''));
            }
        });
    }

    // --- helpers ------------------------------------------------------------------------------------------

    private function dom(string|TestResponse $html): HTMLDocument
    {
        return HTMLDocument::createFromString(is_string($html) ? $html : $html->getContent(), LIBXML_NOERROR);
    }

    private function form(string|TestResponse $html, string $action): Element
    {
        $form = $this->dom($html)->querySelector('form[action="'.$action.'"]');
        $this->assertNotNull($form, "No form posts to $action.");
        $this->assertSame('post', strtolower($form->getAttribute('method')));

        return $form;
    }

    /**
     * @return array<string, string|null>
     */
    private function attributes(Element $form, string $name): array
    {
        $fields = $form->querySelectorAll('[name="'.$name.'"]');
        $this->assertCount(1, $fields, "One field named $name.");
        $attributes = [];
        foreach ($fields[0]->attributes as $attribute) {
            $attributes[$attribute->name] = $attribute->value;
        }

        return $attributes;
    }

    /**
     * Send a form as a browser without JavaScript would: every enabled named field of the rendered form with its
     * rendered value, and the typed values only for fields that exist on the page.
     *
     * @param  array<string, string>  $typed
     */
    private function submit(string|TestResponse $page, string $action, array $typed, string $from): TestResponse
    {
        $form = $this->form($page, $action);
        $data = [];
        foreach ($form->querySelectorAll('input[name], select[name], textarea[name]') as $field) {
            if ($field->hasAttribute('disabled') || ($field->getAttribute('type') === 'checkbox' && ! $field->hasAttribute('checked'))) {
                continue;
            }
            $data[$field->getAttribute('name')] = $field->getAttribute('value') ?? '';
        }
        foreach ($typed as $name => $value) {
            $this->assertArrayHasKey($name, $data, "The page has no field named $name.");
            $data[$name] = $value;
        }
        $this->assertArrayHasKey('_token', $data);
        $this->assertNotNull($form->querySelector('button[type="submit"]'), 'The form has a submit button.');

        return $this->from($from)->post($action, $data);
    }

    private function assertOneTimeCodeField(Element $form, bool $required = true): void
    {
        $code = $this->attributes($form, 'code');
        $this->assertSame('text', $code['type']);
        $this->assertSame('numeric', $code['inputmode']);
        $this->assertSame('[0-9]{6}', $code['pattern']);
        $this->assertSame('6', $code['maxlength']);
        $this->assertSame('one-time-code', $code['autocomplete']);
        $this->assertSame($required, array_key_exists('required', $code));
        $this->assertArrayNotHasKey('disabled', $code);
        $this->assertArrayNotHasKey('x-model', $code);
        $this->assertSame(6, $form->querySelectorAll('[data-slot]')->length);
    }

    /**
     * A public page: the auth layout, no Livewire, no Alpine, only the auth script, and no referrer.
     */
    private function assertPublicPage(string|TestResponse $page): void
    {
        $html = is_string($page) ? $page : $page->getContent();

        foreach (['wire:', 'x-data', 'x-on:', 'x-bind', 'x-model', 'x-show', '@click'] as $marker) {
            $this->assertStringNotContainsString($marker, $html);
        }
        $this->assertDoesNotMatchRegularExpression('/livewire|alpine/i', $html);
        $this->assertStringContainsString('data-entry="resources/js/auth.js"', $html);
        $this->assertStringNotContainsString('data-entry="resources/js/app.js"', $html);
        $this->assertStringContainsString('<meta name="referrer" content="no-referrer">', $html);
        $this->assertStringNotContainsString('Skip to content', $html);
    }

    private function secretOn(TestResponse $page): string
    {
        return str_replace(' ', '', $this->dom($page)->querySelector('[data-manual-key]')->textContent);
    }

    /**
     * @return list<string>
     */
    private function codesOn(TestResponse $page): array
    {
        $codes = [];
        foreach ($this->dom($page)->querySelectorAll('[data-recovery-list] li') as $li) {
            $codes[] = trim($li->textContent);
        }

        return $codes;
    }

    // --- fields and attributes ----------------------------------------------------------------------------

    public function test_the_login_form_keeps_its_fields_and_autocomplete(): void
    {
        $page = $this->get(route('login'))->assertOk();
        $form = $this->form($page, route('login.store'));
        $this->assertPublicPage($page);

        $identifier = $this->attributes($form, 'identifier');
        $this->assertSame(['text', 'username'], [$identifier['type'], $identifier['autocomplete']]);
        $this->assertArrayHasKey('required', $identifier);
        $this->assertArrayNotHasKey('value', $identifier);

        $password = $this->attributes($form, 'password');
        $this->assertSame(['password', 'current-password'], [$password['type'], $password['autocomplete']]);

        // The show/hide button: hidden until the script runs, never a submit button, tied to the field.
        $toggle = $form->querySelector('[data-password-toggle]');
        $this->assertSame('button', $toggle->getAttribute('type'));
        $this->assertTrue($toggle->hasAttribute('hidden'));
        $this->assertSame('password', $toggle->getAttribute('aria-controls'));
        $this->assertNotNull($this->dom($page)->querySelector('a[href="'.route('password.request').'"]'));
    }

    public function test_a_failed_login_does_not_fill_the_identifier_back(): void
    {
        $this->enrolledUser(['username' => 'rina.private']);

        $this->submit($this->get(route('login')), route('login.store'), ['identifier' => 'rina.private', 'password' => 'wrong'], route('login'))
            ->assertRedirect(route('login'));
        $page = $this->get(route('login'))->assertSee(__('auth.failed'));

        $this->assertArrayNotHasKey('value', $this->attributes($this->form($page, route('login.store')), 'identifier'));
        $this->assertStringNotContainsString('rina.private', $page->getContent());
    }

    public function test_the_challenge_has_one_code_field_and_a_separate_recovery_form(): void
    {
        $this->submitPassword($this->enrolledUser());
        $page = $this->get(route('two-factor.challenge'))->assertOk();
        $this->assertPublicPage($page);

        $this->assertOneTimeCodeField($this->form($page, route('two-factor.challenge.store')));

        $recovery = $this->form($page, route('two-factor.recovery.store'));
        $this->assertSame('off', $this->attributes($recovery, 'recovery_code')['autocomplete']);
        $this->assertNull($recovery->querySelector('[name="code"]'));

        // Without JavaScript the switch is a link to the recovery form, which is on the page.
        $dom = $this->dom($page);
        $this->assertSame('#recovery-code-form', $dom->querySelector('[data-switch-to="recovery"]')->getAttribute('href'));
        $this->assertNotNull($dom->getElementById('recovery-code-form'));
        $this->assertFalse($dom->getElementById('recovery-code-form')->hasAttribute('hidden'));
        $this->assertSame('code', $dom->querySelector('[data-auth-switch]')->getAttribute('data-switch-initial'));
        $this->assertNotNull($this->form($page, route('two-factor.cancel')));
    }

    public function test_a_wrong_recovery_code_opens_the_recovery_form_first(): void
    {
        $this->submitPassword($this->enrolledUser());
        $this->submitRecoveryCode('AAAA-BBBB-CCCC-DDDD');

        $page = $this->get(route('two-factor.challenge'));
        $this->assertSame('recovery', $this->dom($page)->querySelector('[data-auth-switch]')->getAttribute('data-switch-initial'));
    }

    public function test_the_enrollment_page_shows_steps_qr_key_and_one_code_field(): void
    {
        $this->submitPassword(User::factory()->withRole(RoleName::Employee)->create());
        $page = $this->get(route('two-factor.enrollment'))->assertOk()->assertSee('<svg', false)->assertSee(__('Step :current of :total', ['current' => 1, 'total' => 2]));
        $this->assertPublicPage($page);

        $this->assertOneTimeCodeField($this->form($page, route('two-factor.enrollment.store')));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{16,}$/', $this->secretOn($page));
        $this->assertSame('step', $this->dom($page)->querySelector('[aria-current]')->getAttribute('aria-current'));
    }

    public function test_the_activation_and_reset_forms_ask_for_new_passwords_with_the_rules(): void
    {
        [, $token] = $this->invitedUser();
        $this->openLink($token);
        $page = $this->get(route('activation.password'))->assertOk()->assertSee(__('Step :current of :total', ['current' => 1, 'total' => 3]));
        $this->assertPublicPage($page);
        $form = $this->form($page, route('activation.password.store'));

        foreach (['password', 'password_confirmation'] as $name) {
            $field = $this->attributes($form, $name);
            $this->assertSame(['password', 'new-password'], [$field['type'], $field['autocomplete']]);
        }
        $this->assertStringContainsString('password-rules', $this->attributes($form, 'password')['aria-describedby']);
        $this->assertNotNull($this->dom($page)->getElementById('password-rules'));
        $this->assertSame(2, $form->querySelectorAll('[data-password-toggle][hidden]')->length);

        $this->enrolledUser(['email' => 'budi@example.com']);
        $this->post(route('password.email'), ['email' => 'budi@example.com']);
        $this->get(route('password.reset.start', Str::afterLast(Mail::sent(PasswordResetMail::class)->last()->url, '/')));
        $reset = $this->get(route('password.reset'))->assertOk();
        $this->assertPublicPage($reset);
        $form = $this->form($reset, route('password.update'));
        $this->assertSame(['email', 'email'], [$this->attributes($form, 'email')['type'], $this->attributes($form, 'email')['autocomplete']]);
        foreach (['password', 'password_confirmation'] as $name) {
            $this->assertSame('new-password', $this->attributes($form, $name)['autocomplete']);
        }

        $forgot = $this->get(route('password.request'));
        $this->assertPublicPage($forgot);
        $this->assertSame('email', $this->attributes($this->form($forgot, route('password.email')), 'email')['autocomplete']);
    }

    public function test_the_confirmation_page_uses_the_same_components_in_the_app_layout(): void
    {
        $user = $this->enrolledUser();
        $this->logIn($user);

        $page = $this->get(route('security.confirm'))->assertOk()->assertSee('Skip to content');
        $form = $this->form($page, route('security.confirm.store'));

        $password = $this->attributes($form, 'password');
        $this->assertSame(['password', 'current-password'], [$password['type'], $password['autocomplete']]);
        // The code is optional here: a recovery code may replace it.
        $this->assertOneTimeCodeField($form, required: false);
        $this->assertSame('off', $this->attributes($form, 'recovery_code')['autocomplete']);
        $this->assertSame('true', $form->querySelector('[data-auth-switch]')->getAttribute('data-disable-hidden'));
        $this->assertStringContainsString('data-entry="resources/js/auth.js"', $page->getContent());

        // Sent as rendered, without JavaScript (both fields present, the recovery code left empty).
        $this->travel(30)->seconds();
        $this->submit($page, route('security.confirm.store'), ['password' => 'password', 'code' => $this->totp($user->two_factor_secret)], route('security.confirm'))
            ->assertRedirect(route('security.index'))
            ->assertSessionHasNoErrors();
    }

    // --- every form without JavaScript --------------------------------------------------------------------

    public function test_activation_then_both_logins_work_with_the_rendered_forms_only(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->openLink($token)->assertRedirect(route('activation.password'));
        $page = $this->get(route('activation.password'));
        $this->submit($page, route('activation.password.store'), ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD], route('activation.password'))
            ->assertRedirect(route('activation.two-factor'));

        $page = $this->get(route('activation.two-factor'))->assertOk()->assertSee(__('Step :current of :total', ['current' => 2, 'total' => 3]));
        $this->assertPublicPage($page);
        $this->assertOneTimeCodeField($this->form($page, route('activation.two-factor.store')));
        $secret = $this->secretOn($page);

        $codesPage = $this->submit($page, route('activation.two-factor.store'), ['code' => $this->totp($secret)], route('activation.two-factor'))
            ->assertOk()
            ->assertSee(__('Step :current of :total', ['current' => 3, 'total' => 3]));
        $this->assertPublicPage($codesPage);
        $this->assertStringContainsString('no-store', $codesPage->headers->get('Cache-Control'));
        $codes = $this->codesOn($codesPage);
        $this->assertCount(10, $codes);
        // The continue link works without the checkbox (the checkbox is only a reminder, enforced by the script).
        $this->assertSame(route('login'), $this->dom($codesPage)->querySelector('[data-recovery-continue]')->getAttribute('href'));
        $this->assertNull($this->dom($codesPage)->querySelector('[data-recovery-continue]')->getAttribute('aria-disabled'));
        $this->assertTrue($this->dom($codesPage)->querySelector('[data-recovery-tools]')->hasAttribute('hidden'));

        // Two-step login.
        $this->travel(30)->seconds();
        $this->submit($this->get(route('login')), route('login.store'), ['identifier' => 'rina.w', 'password' => self::NEW_PASSWORD], route('login'))
            ->assertRedirect(route('two-factor.challenge'));
        $this->submit($this->get(route('two-factor.challenge')), route('two-factor.challenge.store'), ['code' => $this->totp($secret)], route('two-factor.challenge'))
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        // Log out, then log in with a recovery code from the page.
        $this->post(route('logout'));
        $this->startNextRequest();
        $this->submit($this->get(route('login')), route('login.store'), ['identifier' => 'rina@example.com', 'password' => self::NEW_PASSWORD], route('login'));
        $this->submit($this->get(route('two-factor.challenge')), route('two-factor.recovery.store'), ['recovery_code' => $codes[0]], route('two-factor.challenge'))
            ->assertRedirect(route('dashboard'));
        $this->assertSame(9, $user->recoveryCodes()->whereNull('used_at')->count());
    }

    public function test_forced_enrollment_works_with_the_rendered_form_and_answers_no_store(): void
    {
        $user = User::factory()->withRole(RoleName::Employee)->create();

        $this->submit($this->get(route('login')), route('login.store'), ['identifier' => $user->username, 'password' => 'password'], route('login'))
            ->assertRedirect(route('two-factor.enrollment'));
        $page = $this->get(route('two-factor.enrollment'));

        $codesPage = $this->submit($page, route('two-factor.enrollment.store'), ['code' => $this->totp($this->secretOn($page))], route('two-factor.enrollment'))
            ->assertOk()
            ->assertSee(__('Step :current of :total', ['current' => 2, 'total' => 2]));
        $this->assertPublicPage($codesPage);
        $this->assertStringContainsString('no-store', $codesPage->headers->get('Cache-Control'));
        $this->assertCount(10, $this->codesOn($codesPage));
        $this->assertSame(route('dashboard'), $this->dom($codesPage)->querySelector('[data-recovery-continue]')->getAttribute('href'));
    }

    public function test_new_codes_from_security_use_the_same_page_with_no_store(): void
    {
        $user = $this->enrolledUser();
        $this->logIn($user);
        $this->confirmIdentity($user);

        $codesPage = $this->post(route('security.recovery-codes.store'))->assertOk();
        $this->assertPublicPage($codesPage);
        $this->assertStringContainsString('no-store', $codesPage->headers->get('Cache-Control'));
        $this->assertCount(10, $this->codesOn($codesPage));
        $this->assertNull($this->dom($codesPage)->querySelector('[aria-current="step"]'));
    }

    public function test_forgot_and_reset_password_work_with_the_rendered_forms_only(): void
    {
        $this->enrolledUser(['email' => 'budi@example.com']);

        $this->submit($this->get(route('password.request')), route('password.email'), ['email' => 'budi@example.com'], route('password.request'))
            ->assertRedirect(route('password.request'));
        $this->get(route('password.request'))->assertSee(__('If this address can reset a password, a link is on its way. It may take a few minutes.'));

        $token = Str::afterLast(Mail::sent(PasswordResetMail::class)->last()->url, '/');
        $this->get(route('password.reset.start', $token))->assertRedirect(route('password.reset'));
        $page = $this->get(route('password.reset'));
        $this->assertStringNotContainsString($token, $page->getContent());

        $this->submit($page, route('password.update'), ['email' => 'budi@example.com', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD], route('password.reset'))
            ->assertRedirect(route('login'));
        $this->get(route('login'))->assertSee(__('Your password has been changed. Please log in.'));
    }

    // --- tokens, scripts, components ----------------------------------------------------------------------

    public function test_pages_shown_at_a_token_url_hold_no_token_and_send_no_referrer(): void
    {
        $unknown = str_repeat('ab', 32);
        $gone = $this->get(route('activation.start', $unknown))->assertStatus(410);
        $this->assertPublicPage($gone);
        $this->assertStringNotContainsString($unknown, $gone->getContent());

        [, $token] = $this->invitedUser();
        $this->actingAs($this->enrolledUser());
        $conflict = $this->get(route('activation.start', $token))->assertStatus(409);
        $this->assertPublicPage($conflict);
        $this->assertStringNotContainsString($token, $conflict->getContent());
        $this->assertNotNull($this->form($conflict, route('logout')));
    }

    public function test_the_auth_script_stores_sends_and_logs_nothing(): void
    {
        $source = file_get_contents(resource_path('js/auth.js'));

        foreach (['localStorage', 'sessionStorage', 'indexedDB', 'document.cookie', 'console.', 'fetch(', 'XMLHttpRequest', 'sendBeacon', 'WebSocket', 'import('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
        // The download is a Blob made in the browser.
        $this->assertStringContainsString('new Blob(', $source);
    }

    public function test_the_vanilla_code_input_renders_a_plain_field_without_alpine(): void
    {
        View::share('errors', new ViewErrorBag);
        $html = Blade::render('<x-ui.otp-input script="vanilla" label="Code" id="code" name="code" required />');

        $this->assertDoesNotMatchRegularExpression('/\sx-[a-z]+|\s@[a-z]+=/', $html);
        $this->assertSame(1, substr_count($html, '<input'));
        $this->assertSame(6, substr_count($html, 'data-slot='));
        $this->assertStringContainsString('data-otp="code"', $html);
        $this->assertStringNotContainsString('data-enhanced', str_replace('group-data-enhanced/otp:', '', $html));
        // Boxes hidden until the script marks the field enhanced (CSS .otp-boxes), two groups of three.
        $this->assertStringContainsString('class="otp-boxes" aria-hidden="true" data-otp-boxes', $html);
        $this->assertSame(2, substr_count($html, 'class="otp-group"'));
        $this->assertSame(1, substr_count($html, 'class="otp-sep"'));

        // The Alpine variant (Livewire pages) is unchanged in behaviour and starts enhanced.
        $alpine = Blade::render('<x-ui.otp-input label="Code" id="code" name="code" />');
        $this->assertStringContainsString('x-data="uiOtp(', $alpine);
        $this->assertStringContainsString('data-enhanced', str_replace('group-data-enhanced/otp:', '', $alpine));
    }

    public function test_the_recovery_page_and_confirmation_are_translated(): void
    {
        $user = $this->enrolledUser(['locale' => 'id']);
        $this->logIn($user);

        $this->get(route('security.confirm'))->assertSee('Tampilkan kata sandi')->assertSee('Gunakan kode dari aplikasi saja');

        $this->confirmIdentity($user);
        $this->post(route('security.recovery-codes.store'))
            ->assertSee('<html lang="id">', false)
            ->assertSee('Salin')
            ->assertSee('Cetak')
            ->assertSee('Ditampilkan sekali')
            ->assertSee('Saya sudah menyimpan kode pemulihan saya di tempat yang aman.');
    }
}
