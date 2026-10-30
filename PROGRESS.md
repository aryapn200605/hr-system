# Progress Log

## Current status
- Phase 0: done and committed (4b6b06e, branch main).
- Phase 0 follow-up (cleanup after Laragon changes): done, see "Phase 0 follow-up" below.
- Phase 1 (Database, RBAC, admin bootstrap): done, see "Phase 1" below.
- Phase 2 (Login, TOTP, sessions, history): done, see "Phase 2" below. Manual test with a real phone still to do by the user.
- Next: Phase 3 (Invitation, activation, password reset, emails). Not started.

## Phase 0: Inspect and initialize (2026-10-30)

### Done
- Inspected the toolchain:
  - PHP 8.5.12 ZTS (Laragon, not in PATH), also the version loaded by Laragon's Apache 2.4.65.
  - Composer 2.8.12, Node 22.14.0, npm 10.9.2, Git 2.56.0, MySQL 8.0.30.
- Scaffolded Laravel 13 in a temporary folder and moved it in. CLAUDE.md and SPEC.md were confirmed unchanged by hash.
- Installed Laravel 13.39.0 and Livewire 4.4.8. Vite 8.3.5 and Tailwind CSS 4.3.3 come from the Laravel skeleton.
- Configured .env (MySQL at 127.0.0.1, mail `log`, session `database`). .env.example holds placeholders.
- Created databases `hr_system` and `hr_system_test`, ran the default migrations on `hr_system`.
- Tests use `hr_system_test` via phpunit.xml. Added tests/Feature/FoundationSmokeTest.php.

### Verified
- `php artisan test`: 4 passed.
- `npm run build`: succeeded.
- Composer and npm report no known vulnerabilities.
- Earlier check through a temporary Apache instance on 127.0.0.1:8088 (`/`, `/up`, CSS, `livewire.js` all 200, session row written, laravel.log empty).

### Laragon changes made by the user after Phase 0
- Document Root, Auto Virtual Hosts and PHP extensions were changed by the user in Laragon.

## Phase 0 follow-up: cleanup after Laragon changes (2026-10-30)

### Done
- Removed SQLite from the project: `sqlite` connection and fallbacks in config/database.php and config/queue.php (now `mysql`), the setup line in composer.json, and database/.gitignore (it only ignored `*.sqlite*`).
- Removed the Composer zip workaround and the `pdo_sqlite` note from README.md. Added the MySQL-only rule to CLAUDE.md.
- APP_URL was already `http://hr-system.test` in .env and .env.example, the working address. No change needed.

### Verified
- `php -m` (PHP 8.5.12) now includes zip, sodium and pdo_sqlite, plus bcmath, curl, gd, intl, mbstring, mysqli, openssl, pdo_mysql, xsl and OPcache. `exif` and `fileinfo` are also on.
- `http://hr-system.test` through Laragon's real Apache 2.4.65 (no temporary instance): `/`, `/up`, built CSS and `/livewire-52eee8c2/livewire.js` all 200, rechecked after the config changes. Served CSS and livewire.js match the files on disk by SHA-256. Sessions are written to `hr_system`, laravel.log stays absent.
- `composer install` succeeds without `-d extension=zip`.
- `php artisan test`: 4 passed, on `hr_system_test`.
- `npm run build`: succeeded.
- Default connection resolves to `mysql` in config, .env.example and phpunit.xml. `composer validate`: valid. Composer and npm audits report no vulnerabilities.

### Known issues
- `vite build` warns that the optional `fontaine` package is missing. Fonts still build; only the optimized fallback is skipped.
- Laragon's PHP is not in PATH for Git Bash, so the `composer` shim there cannot find PHP. Composer was run as `php composer.phar` with the full PHP path.

### Decisions
Recorded in README.md under "Project decisions".
- Database: MySQL only, for the app and for tests. `pdo_sqlite` is now enabled in Laragon's PHP (a shared setting), but the project does not use it.
- Tests run against the isolated MySQL database `hr_system_test`, never the real `hr_system`.
- Laragon configuration and php.ini are changed by the user only. Claude reports what is needed and does not edit them.

## Phase 1: Database, RBAC, admin bootstrap (2026-10-30)

### Done
- Installed spatie/laravel-permission 8.3.2 after a Composer dry run on PHP 8.5.12 and Laravel 13.39.0. No advisories.
- New additive migrations: account fields on users (`email_verified_at` dropped, `password` nullable), `sessions.user_id` foreign key, Spatie tables, account_invitations, recovery_codes, login_histories, audit_logs.
- Enums `UserStatus`, `RoleName`, `PermissionName`, `AuditEvent`. Models `AccountInvitation`, `RecoveryCode`, `LoginHistory`, `AuditLog`.
- `RolePermissionSeeder`, idempotent, called by `DatabaseSeeder`. Seeders create no users.
- `User::changeRole()` as the only role change path, with a transaction and row lock. `suspend()` and `createFirstHrAdmin()` added. Spatie's role methods throw on `User`. `save()` and `delete()` enforce the last active HR Admin rule.
- `App\Support\AccountRules` for shared validation, `Password::defaults()` in AppServiceProvider.
- Command `app:create-admin`. Audit entries for admin bootstrap, role change and suspension.
- Updated UserFactory, the User model and FoundationSmokeTest after removing `email_verified_at`.

### Verified
- `php artisan test`: 77 passed, on `hr_system_test`. Covers the seeder, the command (success, refusal when an active admin exists, invalid input, incomplete 2FA), the last active HR Admin rule through every application path, shared validation and audit immutability.
- Disabling the last-admin guard made 13 of the 26 tests in LastActiveHrAdminTest fail. The guard was restored.
- `migrate:fresh` succeeded on both `hr_system` and `hr_system_test`. `db:seed` gives 3 roles, 6 permissions and 8 role grants, 0 users. Rolling back all 7 new migrations and migrating again succeeded on `hr_system_test`.
- `app:create-admin` ran for real on `hr_system_test` with piped input, created the admin and printed no password. `hr_system` has no users yet.
- `npm run build`: succeeded. Pint: clean.

### Known issues
- The bypass limits above are documented in README.md: query-builder writes and direct `roles()` pivot calls skip the model guards, and direct database access can change audit rows.
- The lock is covered by a test that checks the `FOR UPDATE` query. Two truly concurrent processes were not tested.
- Hidden password input was not checked in a real terminal, only with piped input and mocked prompts. The prompts use no visible fallback, so they fail instead of showing the password.
- Phase 3's requirements were not read, per CLAUDE.md, so account_invitations may need a further migration.
- Carried over: `vite build` warns about the optional `fontaine` package. Laragon's PHP is not in PATH.

### Decisions
Recorded in README.md under "Project decisions".
- `status` is a string column cast to a PHP enum, as the user suggested, instead of the MySQL enum in the approved plan.
- Role changes require a `users.update` actor unless the actor is null, which means the system (bootstrap command only).
## Phase 2: Login, TOTP, sessions, history (2026-10-30)

### Done
- Installed pragmarx/google2fa 9.1.0 and bacon/bacon-qr-code 3.1.1 after a Composer dry run on PHP 8.5.12, with user approval. No advisories.
- Stage 1 (`LoginController`), stage 2 (`TwoFactorChallengeController`), forced enrollment (`TwoFactorEnrollmentController`), logout and cancel.
- `App\Support\TwoFactor`, `PendingLogin`, `AuthThrottle`. Enum `LoginResult`, four new `AuditEvent` cases. `LoginHistory::recordSuccess()` and `recordFailure()`. `RecoveryCode::regenerateFor()` and `consume()`. `User::completeTwoFactorEnrollment()` and `canAccessApplication()`.
- Middleware `EnsureFullyAuthenticated` (`fully.authenticated`) and `EnsurePendingLogin` (`pending.login`).
- Livewire's update route replaced so every action passes the full-login check. A 401 on a Livewire request sends the browser to the login page.
- Additive migration: unique index on `recovery_codes (user_id, code_hash)`.
- Recovery code HMAC uses a key derived from APP_KEY with HKDF and its own label. Rotation consequences are in README.md.
- `SESSION_ENCRYPT=true` in .env and .env.example, `SESSION_SECURE_COOKIE` added to .env.example. `identifier`, `code` and `recovery_code` are never flashed.
- Functional Blade pages and a placeholder Livewire dashboard, with the `dashboard.view` permission on its route.

### Verified
- `php artisan test`: 176 passed, on `hr_system_test`. `npm run build`: succeeded. Pint: clean. Composer and npm audits report no vulnerabilities.
- Migrations applied to `hr_system`, which is ready for `app:create-admin`.
- Real races: `AuthConcurrencyTest` runs 8 separate PHP processes with separate connections on committed rows, for a recovery code and a TOTP code. Exactly one wins, and the test fails if a process misses the start barrier.
- Mutation check: 11 safeguards removed one at a time, each with the file restored and confirmed by hash. 8 were caught at once. 3 survived, which exposed two weak tests: MySQL reports 0 changed rows for an update to the same value, so frozen time hid the bug. The tests now move time forward and a stale-model test was added. The third was one of two redundant replay layers, and removing both is caught.
- Real HTTP run on `hr_system_test` (`php -S`, not the test client), 30 checks passed: real CSRF (419 without a token), HttpOnly and SameSite cookies, session cookie changes at both stages, replay refused, recovery login, suspension from another process cutting Livewire (401) and the dashboard at once. Session payloads were unreadable when base64-decoded, and no identifier appeared in cache keys.
- In a real browser: the QR renders, enrollment and recovery pages work, and a suspended user clicking a Livewire button lands on the login page.
- `hr_system` still has no users, ready for the manual test.

### Known issues
- The QR code has not been scanned by a real Google or Microsoft Authenticator. The otpauth URI format and RFC 6238 vectors (6 of 6) were checked, and the manual test covers the rest.
- Password spraying across accounts from one IP, and guessing one account from many IPs, are limited only per identifier and IP pair, as specified. Account lockout is out of scope.
- The first enrollment is protected by the password alone. This follows from forced enrollment at first login.
- Used recovery codes are not replaced and there is no regeneration page yet. Phase 5 is the likely place, but it has not been read.
- Rotating APP_KEY requires re-enrolling all users (README.md).
- Livewire cannot serve guest pages, because its only update route requires a full login. Phase 3 pages must use controllers.
- The 401 redirect in the layout covers pages using that layout only.
- `resources/views/welcome.blade.php` is now unused.
- Carried over: `vite build` warns about the optional `fontaine` package. Laragon's PHP is not in PATH.

### Decisions
Recorded in README.md under "Project decisions" and "Recovery codes and APP_KEY".
- HMAC-SHA256 with a derived key for recovery codes, approved by the user instead of bcrypt.
- The typed identifier is not refilled after a failed login, because it can contain a mistyped password.
- Tests use a separate `tests/Support/race.php` process and `AuthConcurrencyTest` does not use RefreshDatabase, whose open transaction would hide rows from other processes.
