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
- Limits are 5 attempts per minute, per identifier and IP for passwords, and per user and IP in one bucket shared by TOTP, recovery codes and enrollment confirmation. Keys are hashed, so the cache never stores a typed identifier. The hit comes before verification. There is no limit per IP alone or per account alone, because account lockout is out of scope, so guessing across accounts or across IPs is only limited per pair.
- TOTP accepts one timestep either side of now. A code is refused if its timestep is not newer than `two_factor_last_timestep`, and the conditional update lets only one concurrent request claim a timestep.
- `users.two_factor_secret` is set only when enrollment is confirmed. Until then the secret is kept encrypted in the session. Recovery codes are written straight into the POST response with `no-store` and never enter the session.
- `SESSION_ENCRYPT=true`. `SESSION_SECURE_COOKIE` stays false for local HTTP and **must be true wherever the app is served over HTTPS**.
- **`routes/web.php` replaces Livewire's update route** so that every Livewire action passes `EnsureFullyAuthenticated`. Livewire's own route has only the `web` group and does not reapply custom route middleware to updates. As a result Livewire cannot serve public pages: login and other guest pages use controllers and Blade. `AccessControlTest` fails if a new web route is neither behind this check nor listed as public on purpose.
- `App\Livewire\Dashboard` is a placeholder for the login flow. The real dashboard belongs to Phase 4.

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
