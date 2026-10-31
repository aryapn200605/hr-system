# Progress Log

## Current status
- Phase 0: done and committed (4b6b06e, branch main).
- Phase 0 follow-up (cleanup after Laragon changes): done, see "Phase 0 follow-up" below.
- Phase 1 (Database, RBAC, admin bootstrap): done, see "Phase 1" below.
- Phase 2 (Login, TOTP, sessions, history): done, see "Phase 2" below. Manual test with a real phone still to do by the user.
- `hr_system` reset after the Phase 2 manual test (2026-10-30), see "Local database reset" below. The user creates the admin with `app:create-admin`.
- Phase 3 (Invitation, activation, password reset, emails): done, see "Phase 3" below.
- Phase 4a (UI layout, components, dashboard, restyled public pages): done, see "Phase 4a" below.
- Phase 4b (User Management): done, see "Phase 4b" below.
- Phase 5 (Profile and security settings): done, see "Phase 5" below.
- Phase 6 (tanpa Docker): cleanup, redesign, documentation and final verification done, see "Phase 6 (tanpa Docker)" below. **"Docker mode works" is BELUM DIKERJAKAN / TIDAK DIVERIFIKASI, so Phase 6 is not fully complete.**
- Tahap 1 (WIB, departments, supervisors, leave balances): done and committed locally, see "Tahap 1" at the end. **`hr_system` is not migrated yet: the owner runs that from README.md, "Moving a database to WIB".**
- Next: Tahap 2 (requests) when the owner starts it. The Docker runtime and the manual checks by the owner are still open.

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

## Local database reset (2026-10-30)
- `hr_system` was reset with `migrate:fresh --seed` after the Phase 2 manual test, with user approval. The live connection was confirmed as `hr_system` on 127.0.0.1:3306 first. `hr_system_test`, php.ini and Laragon were not touched.
- Removed: 1 user, 2 sessions, 5 login_histories, 9 audit_logs, 10 recovery_codes, 1 model_has_roles, 1 cache row.
- Verified afterwards: 0 users, 3 roles, 6 permissions, 8 role grants, 11 of 11 migrations applied.
- `app:create-admin` was not run. The user will create the admin.

## Phase 3: Invitation, activation, password reset, emails (2026-10-30)

### Done
- `App\Actions`: `CreateUserWithInvitation`, `ResendInvitation`, `SendInvitation`, `InvitationResult`, for Phase 4 to call.
- `AccountInvitation::issueFor()`, `findOpenByToken()`, `claim()`. `User::completeActivation()` and `resetPassword()`. Additive migration: `account_invitations.sent_at`.
- Activation: `ActivationController`, `PendingActivation`, middleware `EnsurePendingActivation` (`pending.activation`), which rechecks the invitation without a lock on every step. A logged-in browser gets a 409 page and keeps its login.
- Reused from Phase 2 without rewriting: `TwoFactor`, `RecoveryCode::regenerateFor()`, the shared 2FA throttle bucket and the views. The core of `completeTwoFactorEnrollment()` moved to one private method, and the session secret moved to `TwoFactor::sessionSecret()`.
- Password reset: `ForgotPasswordController` (all work in `defer()`), `ResetPasswordController`, Laravel's broker with `status = ACTIVE` as a credential.
- `InvitationMail` and `PasswordResetMail` (responsive Markdown, synchronous), `App\Support\MailDelivery`.
- Throttles with hashed keys: every activation page per IP, including TOTP confirmation, forgot password, password reset, invitation resend.
- Session changes in Phase 2 code: `PendingLogin` compares a keyed hash of the password, `auth.session` guards the full-login routes, Livewire gets a 401 from it.
- `App\Http\Middleware\StartSession`, found by `TokenLeakTest`: the framework stored the token link as the session's previous URL.

### Verified
- `php artisan test`: 275 passed, on `hr_system_test`, including every Phase 2 test. `npm run build`: succeeded. Pint: clean. Composer and npm audits report no vulnerabilities. No new packages.
- Two Phase 2 tests changed on purpose: the exact key list of the pending login in `LoginTest` gained `password_hash`, and the public route list in `AccessControlTest` gained the new routes.
- Real race: `AuthConcurrencyTest` runs 8 PHP processes on one invitation. Exactly one wins, with 10 recovery codes and one audit entry for each event.
- Timing on the real `hr-system.test` (Laragon Apache, mod_php): 20 active and 20 unknown addresses in random order, with the `log` mailer. Mean 50.4 ms for both, median 47.6 and 44.8 ms, Welch t -0.01. All 20 links were created by deferred work. The response ends well before the broker's 200 ms timebox, so the client does not wait for the deferred work and the queue fallback was not needed. This used 20 temporary accounts in `hr_system`, removed afterwards: 0 users, 0 tokens, 0 audit rows, laravel.log restored to its size.
- Real HTTP run on `hr_system_test` (`php -S`, database sessions, real CSRF), 37 checks passed: full activation, token absent from raw and decrypted session rows, 409 for a logged-in browser, reset ending a second device, a session row written back being refused by `auth.session`, a half-way login cancelled with a valid recovery code left unspent.
- Mutation check: 24 safeguards removed one at a time, each file restored and confirmed by hash. 23 were caught. The survivor showed that storing the session's password hash at login was redundant, because `SessionGuard::login()` already does it, so that line was removed. The test for the behavior stays.
- `app:create-admin` was still not run. `hr_system` has 0 users and 12 of 12 migrations, plus one guest row in `sessions` from a local check of the site.

### Known issues
- Web server access logs record a link's first request, including its token. Tokens are single use and expire. See README.md.
- The timing check used the `log` mailer on mod_php. It has not been measured on the Phase 6 Docker runtime or behind a buffering proxy, where the client may wait for the deferred work. If so, the agreed fallback is a queue job carrying only the email address.
- A forgot password transport failure reaches the audit log only, because the response must look the same for every address. Nothing shows it to the user or an admin yet.
- A failed resend leaves no working link until the next resend. The result reports it.
- `activation.two-factor` shows recovery codes in the POST response, so a browser refresh loses them. The account is active by then and the authenticator still works. (Outdated: Phase 5 added regeneration in Security Settings, and since Phase 6 the page says so.)
- Real SMTP delivery was not tested: the transport failure path used a refused connection, and no message reached a real mail server. No real authenticator app has scanned the QR code yet.
- Expired rows in `password_reset_tokens` and `account_invitations` are not pruned.
- Carried over: `vite build` warns about the optional `fontaine` package. Laragon's PHP is not in PATH.

### Decisions
Recorded in README.md under "Project decisions" and "Mail".
- A logged-in browser is shown a message and not logged out, so a link cannot end someone's session.
- Mail is sent synchronously, never queued. `defer()` is used for forgot password, as the user approved after measuring.
- Reset links hold no email address, so the reset form asks for it.

## Phase 4a: UI layout, components, dashboard, public pages (2026-10-30)

### Done
- Main layout `layouts/app.blade.php`: sidebar, sticky top bar, breadcrumbs, profile dropdown, skip link, toast area, mobile drawer. Public pages moved to a new `layouts/auth.blade.php`.
- Sidebar profile block: name, email and role badge read from the database through `User::roleName()`. Colors come from `RoleName::tone()`. A role name that is not in the enum gets a gray badge.
- Reusable components in `resources/views/components/ui`: button, input, error, alert, badge, role-badge, status-badge, table, modal, dropdown, dropdown-item, toast, spinner, loading, empty-state, card, stat, detail, datetime, breadcrumbs, nav-link, avatar, icon, auth-heading.
- Dashboard for all roles: welcome, full name, role, security status, account information, login summary for the last 30 days, recent logins with an empty state. Holders of `users.view` also get account counts per status. Everything is read from real rows.
- `Dashboard::boot()` now checks `dashboard.view`, because route middleware does not run for Livewire updates. `refresh()` reloads the whole page, because a Livewire update does not render the sidebar.
- Login, TOTP, enrollment, activation, recovery codes, forgot and reset password restyled, still controller + Blade. Wording is unchanged.
- User Management shows only with `users.view` and stays disabled. My Profile and Security Settings are visible but disabled until Phase 5. Neither has a route yet.

### Verified
- `php artisan test`: 300 passed, on `hr_system_test`, including every older test unchanged. `npm run build`: succeeded. Pint: clean.
- Mutation check: 6 safeguards removed one at a time (menu gate, `boot()` authorization, account count gate, role read from the database, role color, toast `x-text`), each file restored. 5 were caught. The toast one survived, so its test was extended, and the same mutation is now caught.
- In a real browser with `php -S` on `hr_system_test`: login with a real TOTP code, HR Admin and Employee dashboards, Refresh with its toast, Logout, a role change from Employee to Approver showing purple on reload, a failed login, reset, activation and enrollment through to recovery codes. Checked at 375px, 1024px and 1280px, with no horizontal scroll.
- Keyboard: the profile menu moves focus in, arrow keys move, Escape returns focus to the trigger. The modal traps focus, locks scrolling and returns focus on close. The drawer moves focus in; closing it with Escape was not tried. The modal was checked with a temporary probe that was removed. The test database was reset afterwards. `hr_system` still has 0 users.
- That browser check exposed two bugs, both fixed: a long email widened the page on mobile, and `x-show` delayed showing elements so moving focus failed. Dropdown, modal and drawer now use a class binding.

### Known issues
- Keyboard events in the browser pane were dispatched by script, because the pane was hidden. A pass with a real keyboard and a screen reader has not been done.
- Color contrast was judged from Tailwind's palette values and not measured with a tool.
- The recovery codes page was checked by structure (10 codes, 2 columns at 1024px, 1 column defined for mobile), because its screenshot timed out.
- Dates show in UTC, the app timezone.
- Error pages (403, 404, 419, 500) still use Laravel's default look.
- The modal has no real use yet, Phase 4b will be the first. `resources/views/welcome.blade.php` is still unused.
- Carried over: `vite build` warns about the optional `fontaine` package. Laragon's PHP is not in PATH.

### Decisions
Recorded in README.md under "Project decisions".
- Dropdown, modal and drawer are shown with a class binding, not `x-show`.
- Links to pages that do not exist yet are not links at all, so no route is invented ahead of its phase.

## Phase 4b: User Management (2026-10-30)

### Done
- `App\Livewire\Users\Index` at `/users`: list, search, role and status filters, page size, pagination, and dialogs for create, edit, change role, suspend, reactivate and resend. `App\Livewire\Users\Show` at `/users/{user}`: read only.
- `App\Policies\UserPolicy` is the single source of authorization. `App\Actions\UpdateUser` is new. `CreateUserWithInvitation` and `ResendInvitation` are called unchanged.
- Rule added with the user: in User Management an admin changes only their own name. The role dropdowns list only roles the actor may give.
- `User::suspend()` extended: refuses a self-suspension and an actor without `users.suspend`, locks the user row after the HR Admin lock, refuses an already suspended account, ends sessions, reset links and open invitations. The last HR Admin guard itself was not changed.
- `User::reactivate()` and audit event `user.reactivated`, an addition outside SPEC.md requested by the user. Audit event `user.updated` stores field names only.
- New component `x-ui.select`. `x-ui.dropdown` gained `anchored`. The sidebar entry is now a link. My Profile and Security Settings stay disabled.

### Verified
- `php artisan test`: 475 passed, on `hr_system_test`. `npm run build`: succeeded. Pint: clean. `npm audit`: no vulnerabilities. No new packages or migrations.
- Real races with separate PHP processes (`UserManagementConcurrencyTest`): two admins suspending each other, 3 rounds, exactly one wins and one admin stays active. A ring of 4 admins, suspend against demote, and suspend against activation.
- Mutation check: 61 safeguards removed one at a time, each file restored and confirmed by hash. 52 were caught. Of the 9 survivors:
  - 3 exposed weak tests: `confirm` for suspend and reactivate had no case where the target gains power after the dialog opens, and the named columns test could not fail. Tests added, the same mutations are now caught.
  - 3 were redundant code, now removed: an override of `setPage()` (the framework validates the page), a UTF-8 check (`mb_substr()` already scrubs) and a second authorize in `Show::render()`. Hostile input tests still pass.
  - The route `can:` middleware stands behind `boot()`, so a test now asserts it directly. `createUser` authorize stands behind the action's own check: removing both first survived, which exposed a missing test, and that test now catches it. Without `abort_if` in `findUser()`, `findOrFail(false)` still gives a 404, so that mutant changes no behavior.
- In a real browser with `php -S` on `hr_system_test`: login with a real TOTP code, list, filters, row menu, reactivate, suspend, create with validation errors and with success, own account edit, detail page, at 1024px and 375px, no console errors. Keyboard focus moved into a modal. The test database was reset afterwards. `hr_system` was not touched.
- That check exposed one bug, fixed: the `sr-only` header of the Actions column escaped the table's overflow and made the whole page 2095px wide.

### Known issues
- An account suspended before activation stays SUSPENDED for good: it has no password, so Reactivate refuses it, and it cannot be deleted. Inviting that person again needs a different username and email. See README.md.
- An admin's own username and email cannot change anywhere until Phase 5.
- Editing the email of an ACTIVE user sends nothing to either address, because email verification is out of scope.
- Refused attempts are not audited, only completed actions.
- Mail was tested with the fake mailer and a refused connection, not a real SMTP server.
- The last admin guard is reachable through the UI only in a race, because self-actions are refused first. Both paths are tested.
- On a phone the table scrolls sideways to reach Actions. There is no card layout.
- A real keyboard and a screen reader were not tried on the new pages. Screenshots of the open modals timed out, so modal layout was checked by DOM state and measured width, not by eye.
- Carried over: `vite build` warns about the optional `fontaine` package. Laragon's PHP is not in PATH. Dates show in UTC. Error pages keep Laravel's default look.

### Decisions
Recorded in README.md under "Project decisions" and "Addition outside SPEC.md: Reactivate".
- Two tests from earlier phases changed. `AppLayoutTest`: User Management is now a link, as approved. `ActivationTest`: not in the approved list. `suspend()` now revokes the invitation, so that test sets the status directly to keep covering the status check alone, and a new test covers the link dying on suspend.

## Phase 5: Profile and security settings (2026-10-31)

### Done
- My Profile at `/profile` (Livewire): details, name only. `/profile/identity` (controller) for username and email, with `App\Actions\UpdateOwnIdentity`.
- Security Settings at `/security`: 2FA status, recovery codes left, change password, regenerate recovery codes, reconfigure authenticator. `/security/sessions` and `/security/login-history` (Livewire). The profile menu entries are now links.
- `App\Support\Reauthentication`, middleware `reauthenticated:{route}`, `/security/confirm`: password plus TOTP or a recovery code, 10 minutes fixed, spent by one action, tied to the password hash, in the TOTP throttle bucket.
- `User::changePassword()`, `reconfigureTwoFactor()`, `regenerateRecoveryCodes()`, `resetTwoFactor()`, `endOtherSessions()`. `enableTwoFactor()` now takes the audit event. `PendingLogin::passwordHash()` is public.
- `App\Support\ActiveSessions` (HMAC handles, only the user's own other sessions), `App\Support\UserAgentLabel`.
- Command `app:reset-two-factor {username}`, an addition outside SPEC.md requested by the user.
- Six audit events: `two_factor.reconfigured`, `two_factor.reset`, `recovery_codes.regenerated`, `reauthentication.succeeded`, `reauthentication.failed`, `session.terminated`.
- `layouts/app.blade.php` now has `@livewireStyles` and `@livewireScripts`, because pages without a Livewire component get no auto-injected Alpine, which the menus need.
- No new packages or migrations.

### Verified
- `php artisan test`: 660 passed, on `hr_system_test`. `npm run build`: succeeded. Pint: clean. `npm audit`: no vulnerabilities.
- Real races with separate PHP processes (`SecurityConcurrencyTest`): 8 concurrent regenerations leave exactly one valid set of 10 codes and 7 fully dead sets. Two users taking one email, 3 rounds: one success and one validation error, never a 500. The unique index path is also forced deterministically in `IdentityChangeTest`, because a race does not always reach it.
- Mutation check: 93 safeguards removed one at a time, each file restored and confirmed by hash, and the whole tree compared with a backup. 83 were caught. Of the 10 survivors:
  - 7 exposed weak tests, which were fixed, and the same mutations are now caught. Two of them are worth knowing: the guard's cached user hid the old timestep bug, and a static Livewire flag left by an earlier test hid the missing `@livewireScripts`.
  - 2 were redundant code, now removed: spending the proof after a password change (the proof dies with the old hash) and lowercasing the username in the command (the collation ignores case).
  - 1 is a deliberate second layer, kept: the `user_id` filter on the delete in `ActiveSessions::endOne()`. The lookup before it has the same filter, and removing that one is caught.
  - The full set was not repeated after these fixes, only the 7 mutations above.
- Real HTTP run on `hr_system_test` (`php -S`, database sessions, real CSRF, separate cookie jars as devices), 47 checks passed: ending a session cuts that device at once, another user's raw session ID ends nothing, password change and reconfiguration keep the current session and end the others, the reset command with real piped answers, and neither the audit table nor laravel.log holds a password, code or secret.
- That run exposed one bug that every test had missed: `@js()` is not compiled inside a Blade component attribute, so the End session button held the literal text. Fixed, and a test now checks the handle in the button.
- In a real browser with a real TOTP code: profile, overview, login history, sessions, the end-session dialog with a second device, profile menu, confirmation, password, authenticator, identity and recovery code pages. Checked at 1024px and 375px with no horizontal scroll and no console errors. That check also exposed `GMT+0000` in the session list, now `UTC` like every other page.
- The test database was reset afterwards. No check account reached `hr_system`.

### Known issues
- The authenticator QR code has not been scanned by a real app. The URI comes from the same code as first enrollment.
- A request in flight could revive an ended session only by regenerating its ID during the delete. Confirming identity does not regenerate. Login cannot apply, and a password change on the ended session itself is the one remaining case. The same-ID case is tested at handler level, not with two real processes in flight.
- Ending a session needs no re-authentication, so a stolen session can log the real user out. They can log back in and change the password.
- A mistyped new email is not detected, because nothing is sent. See README.md.
- Two parallel reconfigurations from two sessions both end in a valid state, but the first one's recovery codes are dead by the time they are read.
- After `app:reset-two-factor`, the password alone reaches enrollment until the user enrolls again. The audit entry does not say who ran it.
- A user who abandons a reconfiguration leaves its secret in that session until cancel or logout. It is unused and encrypted.
- Reset links from a forgot password request that finishes during an email change can survive for an address nobody holds, until they expire. The broker finds no user for them.
- The login history table scrolls sideways on a phone, as User Management does.
- A real keyboard and a screen reader were not tried on the new pages.
- Carried over: `vite build` warns about the optional `fontaine` package. Laragon's PHP is not in PATH. Dates show in UTC. Error pages keep Laravel's default look.

### Decisions
Recorded in README.md under "Project decisions" and "Addition outside SPEC.md: app:reset-two-factor".
- Approved by the user: recovery code as an alternative at re-authentication, reconfiguration ends other sessions, no notice email on an email change, the audit viewer skipped, no way to disable 2FA in the application.
- Pages touching a password, secret or code are controllers, not Livewire, so snapshots never carry them.
- No older test changed. `AppLayoutTest` gained two tests, and `tests/Support/race.php` gained two modes and two output fields.

## Phase 6 (tanpa Docker) (2026-10-31)

The Docker part of SPEC.md Phase 6 was skipped at the owner's instruction, because Docker is not working on this machine.

### Not done
- **Docker mode works: BELUM DIKERJAKAN / TIDAK DIVERIFIKASI.** No Dockerfile, compose file, runtime-specific environment mechanism or Docker script exists. What remains is listed in README.md, "Docker runtime: postponed".

### Done: cleanup
- Error pages for 403, 404, 419, 429, 500, 503, plus `4xx` and `5xx` for anything else, in `layouts/error.blade.php`, with no session, user, database or exception detail.
- `welcome.blade.php` deleted: no route used it.
- `fontaine` warning gone. The cause was `bunny()` in vite.config.js, which also contacted fonts.bunny.net at build time. Replaced by `@fontsource-variable/ibm-plex-sans` 5.3.0 (OFL-1.1), a dev dependency, after an npm dry run. `npm audit` is clean. No Composer package was added.
- `app:prune-invitations {--days=90}` and `auth:clear-resets`, scheduled daily. Accepted invitations are kept for good.
- Recovery codes page says codes can be regenerated in Security Settings.
- `app:reset-two-factor` writes `operator` (OS account and host) into the audit metadata.
- `APP_TIMEZONE_DISPLAY`, default UTC, with `compact` dates in User Management.
- Tables become labelled cards below 640px from one markup, so authorization is not duplicated. The session list was already a list.

### Done: design
- Tokens in `@theme`, with Tailwind's palette, radii and shadows switched off. Components first, then pages. Direction and tokens are in README.md, "Design direction". The `frontend-design` skill was not available.
- Text changed only where it said nothing: the dashboard subtitle and the "No users found" description. Security messages unchanged. A footer line was added to public pages.
- Baseline screenshots of the 7 requested pages plus login history and user detail at 1280, 1024 and 375, and the same set afterwards plus the 404 page, 30 in all, taken with headless Chrome and viewed by eye for the key pages. They are in the session scratchpad, not the repository.

### Verified
- `php artisan test`: 690 passed, 3708 assertions, on `hr_system_test`, after a real `composer install` and `npm ci`. `npm run build`: no warnings, and the output has no `fontaine` line. Pint: clean. `composer audit` and `npm audit`: nothing found. `composer validate`: valid.
- `migrate:fresh --seed` on `hr_system_test` only, after confirming the resolved database name: 12 migrations, 3 roles, 6 permissions, 0 users. `hr_system` still has 2 users and 12 migrations, before and after.
- `http://hr-system.test` on Laragon's Apache 2.4.65 with PHP 8.5.12: `/` (redirect to login), `/login`, `/forgot-password`, `/up`, CSS, JS and the font all 200. `/no-such-page` gives the 404 page, and a real POST without a CSRF token gives the 419 page.
- `.env` and other secrets are not tracked (`git ls-files`), and `git status` is clean after the last commit.
- axe-core 4.14 (WCAG 2.0, 2.1 and 2.2 A and AA) on login, forgot password, the 404 page, TOTP, and all 7 logged-in pages at 1280 and 375, plus the create user dialog: 0 violations in the end. Color contrast passed from the start. The tool found one real failure on every logged-in page, `label-content-name-mismatch` on the profile button, now fixed. It leaves `aria-valid-attr-value` as "needs review" on the `aria-controls` of closed menus, which was not reviewed further.
- axe only measures colors on screen, so every text and background token pair was also computed: lowest 5.75:1, and control borders 3.73:1 at their lowest. Hover states and disabled buttons were not measured.
- Keyboard through Chrome's input pipeline (trusted key events from puppeteer, not a physical keyboard), 16 of 16 passed: skip link, Tab order on User Management, row menu arrows and Escape, Home and End in the profile menu, focus trapped in a dialog, Escape on dialogs, Escape on the mobile drawer with focus returning to its button.
- That check exposed one bug, fixed: focus fell to `body` after a dialog closed, because its opener was disabled or hidden.
- No horizontal page scroll at any of the three widths, and no console errors. Zero requests to other hosts, and one woff2 loaded.
- IBM Plex Sans digits are all the same width with or without `tabular-nums`. Under `prefers-reduced-motion` the spinner has no animation.
- Mutation check on the new safeguards only, 4 of 4 caught: the accepted guard, both cutoffs and the schedule entry.
- Scheduled commands ran by hand on `hr_system_test`. `schedule:run` ran with nothing due. `schedule:list` shows both.

### Not verified
- Commands in the README not run as written: `cp .env.example .env` and `key:generate` (only `--show`, because both write to the real `.env`), `app:create-admin` and `app:reset-two-factor` in this phase (both verified in earlier phases), and `migrate --seed` only on `hr_system_test`.
- Maintenance mode itself was not switched on. The 503 page was tested with `abort(503)`.
- The screen reader, physical keyboard and real phone checks, see below.

### Tests changed
- `ResetTwoFactorCommandTest`: the exact metadata array became a check of its keys, because `operator` depends on the machine. A new test covers the value.
- `ActivationTest`: one extra `assertSee` for the regeneration note.
- `AppLayoutTest`, `UserListTest`: new tests only. `ErrorPagesTest`, `ScheduledCleanupTest` and `DesignTokensTest` are new.

### Known issues
- The items accepted as they are: README.md, "Known limitations".
- The scheduler is defined but no operating system task calls it yet.
- `.env` here does not set `APP_TIMEZONE_DISPLAY`, so dates still show in UTC until the owner sets it.
- Between 640px and 1279px the User Management table scrolls inside its region, with Actions stuck to the right.
- Superseded: the `fontaine` warning, default error pages, unused welcome view, UTC-only dates, unpruned expired rows, sideways tables on phones, the audit entry not naming who ran the reset, and unmeasured contrast.

### Manual checks by the owner (none passed yet)
Also in README.md: real Google and Microsoft Authenticator scans, real SMTP delivery, physical keyboard, a real screen reader, a real phone, and setting up the scheduler.

### Decisions
Recorded in README.md under "Project decisions" and "Design direction".

## Layout fix and visual rework (2026-10-31)

Outside the SPEC.md phases, at the owner's request. Only `resources/views`, `resources/css`, tests and this file changed. No package, PHP logic, authorization, route or security message changed.

### Root cause of "pushed to the left"
- `main` had `max-w-6xl` and the public pages had `max-w-sm`, both without `mx-auto`. At 1920px the content stopped at x=1344 and the login form sat in the left half of its shell.
- `@theme` was not the cause: it only resets colors, radii and shadows. `max-w-*`, `mx-auto` and `gap-*` were all in the built CSS. `DesignTokensTest` now fails if another group is reset.

### Problems seen in the "before" screenshots (35 viewed)
- Layout: content and auth form left aligned on wide screens, with the top bar edges unrelated to the content edges. Narrow in-app forms had the same fault.
- Bugs: at 1920px the sticky Actions column cut off Created, and at 1024px it cut off 2FA with Last login and Created hidden and no sign of more columns. Emails broke mid-word and dates wrapped at 1024px. Status badges sat lower than their row's text.
- Hierarchy: every section had the same black rule, section titles matched body text, and field labels outweighed section titles. Phone user cards were long, with Actions last. The sidebar email wrapped at its hyphen.

### Done
- Page column: one centered `max-w-7xl` shared by the top bar and the page, 208px either side of the content at 1920px. Auth pages are a centered `max-w-md` block, 736px either side at 1920px. Narrow in-app forms are centered too. Text stays left aligned. The sidebar stays 224px.
- Components: white panels on the paper page, type levels of 24, 16, 14 and 12px, one accent, role labels as a hairline with tinted text, no side stripes, `tabular-nums` on IDs, dates and counts. Short color transitions on controls, off under `prefers-reduced-motion`. New `x-ui.page-header` and `x-ui.pagination`. `security/partials/page-header` was removed.
- Tables: they become labelled cards from the room they have, through a container query, and not from the screen width. User Management stacks below 960px of room, others below 544px. The sticky column is gone, so nothing is clipped. Row actions are an icon button named "Actions for {name}".
- Pages: the dashboard has a main and a side column from 1280px. The empty user list has a "Clear search and filters" button, which works in the browser and adds no PHP.

### Verification
- 692 tests passed (3714 assertions). `npm run build` and Pint are clean. `git diff --stat` is empty for composer.json, composer.lock, package.json and package-lock.json. No CDN or external font. `hr_system` still has 2 users; demo data was only on `hr_system_test`.
- "After" screenshots at 1920, 1440, 1280, 1024 and 375 for login, TOTP, dashboard, User Management, profile, security and sessions: all 35 viewed, plus login history at 1024, the row menu, the create dialog, the empty state and the phone drawer. Login history and user details at the other widths were checked for overflow only. No horizontal scroll, and no console errors or failed or external requests.
- axe-core 4.14 found 0 violations on login, forgot password, 404, TOTP, every app page at 1920, 1280, 1024 and 375, and the create dialog. It ran from the scratchpad through puppeteer-core, not through `npx`, so nothing entered the project. axe leaves `aria-valid-attr-value` as "incomplete" for the menus' `aria-controls`, which was not checked by hand.
- 16 of 16 keyboard checks passed with trusted key events from puppeteer, not a physical keyboard. Under `prefers-reduced-motion` the spinner has no animation.

### Not verified
- Token contrast was not recomputed for the new role label borders or hover states. axe measured the text on screen.
- Activation, enrollment, recovery code and error pages were not screenshotted in this pass. They use the same public block, and the tests pass.
- The optional audit in step 5 was skipped: neither the Impeccable nor the Hallmark skill is installed, so there is no `docs/design-audit.md`.

### Tests changed
- `UserListTest`: the `table-stack` class check became a check of the table region and the row menu's accessible name, because a class name proves nothing about behavior. The test is renamed. One new test covers the clear filters button.
- `DesignTokensTest`: one new test on the `@theme` resets.
- No behavior test changed. The `font-mono` lookups that read the setup key in test helpers are unchanged, and the markup they need was kept.

### Known issues
- At 1280px and 1440px long names are cut, and at 1280px emails too, with the full text in a tooltip and on the detail page.
- Stacked tables use `display: block`, so a screen reader reads labels and values and not table navigation.
- README.md was outside this task's allowed files, so "Design direction" and the table notes under "Also known" still describe the earlier ledger design. This section is the current record until they are updated.
- This supersedes the Phase 6 known issue about the table scrolling between 640px and 1279px.

## Tahap 1: WIB, departments, supervisors, leave balances (2026-10-31)

DECISIONS.md is now in the repository and wins over the SRS. The plan was approved by the owner before any code, with three choices: what Reset now does, suspended employees not counting for the guard, and the "No supervisor" marker leaving out Approvers.

### Done
- WIB: `app.timezone` is `Asia/Jakarta`, the connection sets `+07:00`, `APP_TIMEZONE_DISPLAY` is gone. One reversible migration shifts 21 columns in 9 tables, and refuses unless the server and the connection are both on +07:00.
- Sessions and login history moved to My Profile: `App\Livewire\Profile\Sessions` and `LoginActivity`, with their own tab menu. Security Settings is one page now.
- Departments at `/departments`: add, rename, deactivate, activate, delete when unused.
- Employment at `/users/{id}/employment`: department, join date, supervisors. "No supervisor" marker and filter in User Management.
- `employee_supervisor`, `App\Support\SupervisorGuard`, `App\Support\SupervisorRelations`, `App\Exceptions\SupervisorGuardException`. Bulk move at `/supervisors/move`.
- `leave_balance_periods`, `leave_balance_entries`, `App\Support\LeaveBalances`, page `/users/{id}/leave-balance`, command `app:reset-leave-balances`, scheduled at 00:10 WIB. Existing users get a first period from the migration.
- Four permissions, twelve audit events. No new package.

### Verified
- `php artisan test`: 858 passed, 4686 assertions, on `hr_system_test`. `npm run build`: succeeded. Pint: clean. `composer audit` and `npm audit`: nothing found. No change to composer or npm files.
- Migration up and down on `hr_system_test` with rows written as the old code wrote them: +7 hours going up, identical to the original going down, epoch values and the TOTP timestep unchanged, then up again, the seeder (10 permissions) and the daily check. `hr_system` still has 2 users and 12 migrations.
- Real races with separate PHP processes (`LeaveAndSupervisorConcurrencyTest`): two supervisors suspended at once (3 rounds), suspend against demote, two bulk moves (3 rounds), a move against the suspension of its target, 6 daily checks at once (3 rounds), 6 manual resets (3 rounds), a reset against the daily check, 6 adjustments with the ledger staying a chain, adjustments pushing against the entitlement.
- Mutation check on the guard, its entry points, period logic, the migration and page checks: 59 removed one at a time, each file restored and confirmed by hash. 52 caught at once. Of the 7 survivors:
  - 4 exposed weak tests (the anchor end, and `boot()` on three pages, which the route's `can:` stood behind). `RouteAndBootChecksTest` and one leave test were added, and the same mutations are now caught.
  - 1 was redundant code: the check before deleting a department. The foreign key is the rule now, and removing its catch is caught.
  - 1 was two locks covering each other on balances. Removing both is caught in the race test.
  - 1 stays as a second layer on purpose: the guard call inside the bulk move, which cannot refuse once the target is found eligible.
  - The full set was not repeated after these fixes, only these mutations.
- Real HTTP run on `hr_system_test` (`php -S`, database sessions, real CSRF, separate cookie jars), 34 checks passed: login with TOTP, departments, employment, both guard refusals with names, bulk move, adjustment with and without a reason, reset refused, the moved pages, every direct URL refused for an Employee, and replayed admin snapshots refused.
- In a real browser with a real TOTP code: no horizontal page scroll on 11 pages at 1024px and 375px, measured in same-origin frames of that width, with nothing in `main` past the edge. Bulk move of chosen people through the real controls.
- That browser check exposed one bug every test missed, the same one as in Phase 5: `@js()` is not compiled inside a Blade component attribute, so the reset date shortcuts were broken. Fixed, with a test on the rendered value.
- The tests also exposed a real bug: a manual reset on an overdue balance rolled over and then refused, which undid the roll over.

### Not verified
- `hr_system` was not migrated, and the `mysqldump` command in the README was not run.
- Screenshots timed out in the browser pane, so the new pages were checked by measurement and not viewed by eye. Open dialogs were not measured at 375px.
- After the `@js()` fix, the shortcut's rendered attribute was checked in the browser, but a click was not shown to change the date.
- The console error list keeps old entries, so a clean console after that fix was not confirmed.
- The scheduler still has no operating system task, so the daily reset does not run on its own yet.
- A real keyboard, a screen reader and a real phone were not tried on the new pages.

### Tests changed
- `AppLayoutTest`: 4 UTC tests replaced by 3 on WIB. `RolePermissionSeederTest`: the permission list. `LoginHistoryPageTest`, `ReauthenticationTest`, `SecuritySensitiveDataTest`, `SessionManagementTest`: the new routes and class. `tests/Support/race.php` gained five modes.
- New: `WibMigrationTest`, `DepartmentTest`, `EmploymentTest`, `SupervisorGuardTest`, `SupervisorMoveTest`, `LeaveBalanceTest`, `LeaveAndSupervisorConcurrencyTest`, `ProfileMenuTest`, `RouteAndBootChecksTest`, and the trait `ReplaysLivewire`.

### Known issues
- `http://hr-system.test` fails until the owner migrates `hr_system`.
- No console command suspends or demotes today, so that path of the guard is tested with a command defined in the test.
- An HR Admin can adjust their own balance. It is recorded, and DECISIONS.md does not refuse it.
- Superseded: "Dates show in UTC".

### Decisions
Recorded in README.md under "Project decisions" and "Moving a database to WIB".
- Differs from the approved plan: guard refusals are audited in `withinHrAdminLock()` after the rollback and not with `DB::afterRollBack()`, which is unsafe with nested transactions.
