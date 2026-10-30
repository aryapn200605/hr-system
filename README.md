<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Project decisions

- MySQL is the only database, for the app and for tests. The skeleton's SQLite connection, fallbacks and setup script were removed.
- Tests run on a separate MySQL database, `hr_system_test`, set in phpunit.xml, and must never touch `hr_system`.
- Laragon's php.ini and configuration are changed by the user only.
- The skeleton's AGENTS.md and CLAUDE.md (generic Laravel Boost guidance) were not copied. The project's own CLAUDE.md is the only agent guide.
- Alpine comes from Livewire and is not imported in resources/js/app.js.
- RBAC uses spatie/laravel-permission 8.3 (requires PHP ^8.3 and Laravel 12 or 13), with teams off. Role and permission names live only in `App\Enums\RoleName` and `App\Enums\PermissionName`.
- **All role changes must go through `User::changeRole()`.** It runs in a transaction, locks the HR Admin role row, and enforces the last active HR Admin rule, the ban on changing your own role, and the actor's `users.update` permission. On `User`, Spatie's `assignRole()`, `removeRole()` and `syncRoles()` throw a `LogicException`, because they fire no User model events and would skip those rules. Never call `roles()->attach()`, `detach()` or `sync()` from feature code.
- `User::save()` and `User::delete()` enforce the last active HR Admin rule, so `update()`, `suspend()`, `saveQuietly()` and `saveOrFail()` are covered. Query-builder writes such as `User::query()->update()` skip the model and must not be used for `status`.
- `status` is a string column cast to the `App\Enums\UserStatus` PHP enum, not a MySQL enum, so adding a status needs no column change. It is not mass assignable.
- `password` is nullable because PENDING accounts have none until activation. `email_verified_at` was removed because email verification is out of scope.
- `two_factor_enrolled_at` is the 2FA enrollment flag: null means incomplete.
- Username and email are stored in lowercase. Usernames allow letters, digits, dots, underscores and dashes only, so "@" always identifies an email at login.
- Account validation is defined once in `App\Support\AccountRules`, for commands and forms. The password policy is defined once with `Password::defaults()` in AppServiceProvider: at least 12 characters with uppercase, lowercase and a digit.
- login_histories does not store the identifier typed at login, because it can contain a mistyped password. Attempts for unknown accounts have a null `user_id`.
- `AuditLog` refuses update and delete at model level and no route changes it. Direct database access is not blocked, because no MySQL triggers are used.
- Seeders create roles and permissions only. The skeleton's "Test User" was removed.
- TOTP uses pragmarx/google2fa ^9.1 (`verifyKeyNewer()` returns the matched timestep, which replay protection needs) and bacon/bacon-qr-code ^3.1 (local SVG, no GD, Imagick or external service). spomky-labs/otphp was rejected because `verify()` only returns a bool, laravel/fortify because it brings passkeys and far more than is needed. The library wrapper is `App\Support\TwoFactor`.
- Before the second factor nobody is logged in. `App\Support\PendingLogin` keeps only the user ID and expiry in the session (5 minutes for the challenge, 10 for enrollment), and `PendingLogin::complete()` is the only place that calls `Auth::login()`. There is no remember-me.
- Login failures give one generic message and run exactly one hash check, using a dummy hash for unknown accounts and for PENDING accounts with a null password, because `Hash::check()` returns at once on null.
- Limits are 5 attempts per minute, per identifier and IP for passwords, and per user and IP in one bucket shared by TOTP, recovery codes and enrollment confirmation (including activation). Every activation page shares a limit of 30 requests per minute per IP, forgot password allows 5 per minute per email and IP, password reset 5 per minute per IP, and invitation resends 3 per 10 minutes per user. Keys are hashed, so the cache never stores a typed identifier. The hit comes before verification. There is no limit per IP alone or per account alone, because account lockout is out of scope, so guessing across accounts or across IPs is only limited per pair.
- TOTP accepts one timestep either side of now. A code is refused if its timestep is not newer than `two_factor_last_timestep`, and the conditional update lets only one concurrent request claim a timestep.
- `users.two_factor_secret` is set only when enrollment is confirmed. Until then the secret is kept encrypted in the session. Recovery codes are written straight into the POST response with `no-store` and never enter the session.
- `SESSION_ENCRYPT=true`. `SESSION_SECURE_COOKIE` stays false for local HTTP and **must be true wherever the app is served over HTTPS**.
- **`routes/web.php` replaces Livewire's update route** so that every Livewire action passes `EnsureFullyAuthenticated`. Livewire's own route has only the `web` group and does not reapply custom route middleware to updates. As a result Livewire cannot serve public pages: login and other guest pages use controllers and Blade. `AccessControlTest` fails if a new web route is neither behind this check nor listed as public on purpose.
- `App\Livewire\Dashboard` is a placeholder for the login flow. The real dashboard belongs to Phase 4.
- Users are created and invited through `App\Actions\CreateUserWithInvitation` and `App\Actions\ResendInvitation`, for Phase 4 to call. Both require a logged-in actor and check its permissions themselves. They return an `InvitationResult` whose `$sent` must be shown honestly. It holds no token.
- Invitation tokens are 256 random bits, stored as SHA-256, so they survive `APP_KEY` rotation. Expiry is `INVITATION_EXPIRE_MINUTES`. Opening a link only starts a session state and redirects to a URL without the token, so mail scanners cannot spend it. Nothing reaches the user row until the last step, where `User::completeActivation()` locks the user, claims the invitation with one conditional update and writes password, 2FA and status in one transaction. The chosen password waits in the session, hashed and encrypted. Every step rechecks the invitation without a lock, and the transaction checks again.
- A browser that is logged in gets a 409 page from an invitation link, which neither logs it out nor reads the token. A link must not be able to log someone out, and the two accounts must not share a session.
- `App\Http\Middleware\StartSession` replaces the framework's middleware because the framework stores every GET URL in the session as the previous URL, which would put tokens in the `sessions` table. **Routes that carry a token must name their parameter `token`.**
- Account emails are sent synchronously and never queued, because a job payload would store the plain token, and because the outcome must be known to report it. `App\Support\MailDelivery` logs only class names, because a transport error can quote the message. In production the `log` and `array` mailers count as failed deliveries.
- A resend revokes the old link before sending, so if the transport then fails no link works until the next resend. The result reports `sent: false` and the audit log records it.
- Forgot password does no account lookup before the response: the work runs in `defer()`, so the response and its timing are the same for every address. It therefore cannot report a transport failure, so its wording promises nothing and the failure goes to the audit log. Measured on Laragon's Apache with 20 active and 20 unknown addresses: means of 50.4 ms for both. The explicit `Content-Length` lets the client finish before the deferred work ends on mod_php. **Re-measure on the production web server.**
- Password reset uses Laravel's broker with `status = ACTIVE` as a credential, so other accounts behave as if they do not exist. Reset links hold no email address: the form asks for it. A reset never logs in and never touches 2FA or recovery codes.
- A password reset deletes the user's rows in `sessions`, which needs the database session driver. Two more checks cover what that delete cannot reach: `auth.session` ends a session written back by a request in flight, and `PendingLogin` compares a keyed hash of the password, because pending sessions have no `user_id`.
- `AccessControlTest` lists public routes on purpose. Activation and password reset pages use controllers and Blade.

## Mail

**The `log` mailer writes whole emails, including activation and reset links with their tokens, to `storage/logs/laravel.log`. It is for local use only.** The application itself never logs a token: `TokenLeakTest` checks this against a real log file, and also proves that only the `log` mailer writes one. Use SMTP everywhere else, with `MAIL_TIMEOUT` (default 10 seconds) limiting how long a request waits for the transport.

Web server access logs record the first request of every link, including its token. The application cannot prevent this. Tokens are single use and expire, but access logs should be protected and rotated like other sensitive logs.

## Recovery codes and APP_KEY

Recovery codes are 80-bit random values, stored as HMAC-SHA256 under a key derived from `APP_KEY` with HKDF and the label `hr-system.recovery-codes.v1`, never under `APP_KEY` itself. The user ID is part of the hashed message. A slow hash is unnecessary at this entropy, and a keyed hash allows an indexed lookup on `(user_id, code_hash)`. Spending a code is one conditional update, so a code works once even with concurrent requests.

**Rotating `APP_KEY` voids every recovery code.** It also makes every TOTP secret undecryptable, because they are encrypted with the same key, and `APP_PREVIOUS_KEYS` does not help recovery codes. Rotation therefore means re-enrolling all users. Until Phase 5, that needs a manual reset of `two_factor_secret`, `two_factor_enrolled_at` and `two_factor_last_timestep`.

## First HR Admin

Run this once after migrating. It seeds roles and permissions, asks for the account details with hidden password input, and refuses if an active HR Admin already exists.

```bash
php artisan app:create-admin
```

The account is ACTIVE with 2FA enrollment incomplete. There is no public admin registration page.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
