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
- **Reset on 2026-11-04 (or earlier):** the owner reverted the repository to this stable Phase 5 state. Everything built after it was discarded: the Docker skip and redesign attempt, the "layout fix and visual rework", and the first attempts at Phases for employees, requests and approvals (WIB shift, departments, supervisors, balance, requests, decisions). Their lessons are kept in "Lessons carried over" below and in CLAUDE.md; their business decisions are in DECISIONS.md.
- Planning was redone: the phases after Phase 5 are now Phase 6 to 16, see SPEC.md "Phase map". The old "Phase 6: Docker runtime" no longer exists; Docker moved to the deferred deployment phase (Phase 16).
- Phase 6 (Foundation cleanup): done, see "Phase 6" below.
- Phase 7 (Language mechanism): done, see "Phase 7" below.
- Phase 8 (Organization and leave balance): done, see "Phase 8" below.
- Phase 9 (Requests): done, see "Phase 9" below.
- **Next: Phase 10 (Approvals).** Not started. Every new sentence must be added in English and Indonesian in the same change (README "Language").
- Working rules changed: everything is verified through Laragon at http://hr-system.test, Laragon is started by the owner, and `hr_system` is migrated only at the end of a phase after a verified backup. See CLAUDE.md.

## Lessons carried over from the discarded work (2026-10-31 to 2026-11-04)
Do not repeat these. They were found by real-browser and real-process checks after all unit tests had passed.
- `@js()` is not compiled inside a Blade component attribute (End session and "use account creation date" buttons held literal text).
- `createFromFormat()` without `!` stored the seconds of the current time in an hour-only field.
- Toasts covered the "Keep request" button at 375px, and a dialog removed from the DOM while open left a focus trap that blocked every click. Render dialogs always and hide only the trigger.
- A time input's `step` base follows the current value; `min` keeps it on a full hour.
- A missing icon caused many errors and slowed the whole suite; read the first error first.
- A manual reset that found a late balance period cancelled its own rollover; periods must be computed from the anchor date.
- MySQL `TIMESTAMP` columns follow the session zone; the +7 hour shift is only right when the server and connection are +07:00. The migration must refuse otherwise, and the anchor date must be filled after the shift.
- `php -S` must run from `public/`; this no longer applies because all checks use Laragon's hr-system.test.
- The earlier "menjorok ke kiri" layout bug came from `max-w-*` containers without `mx-auto`, not from the Tailwind theme.

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
- The timing check used the `log` mailer on mod_php. It has not been measured on a Docker runtime (deferred, Phase 16) or behind a buffering proxy, where the client may wait for the deferred work. If so, the agreed fallback is a queue job carrying only the email address.
- A forgot password transport failure reaches the audit log only, because the response must look the same for every address. Nothing shows it to the user or an admin yet.
- A failed resend leaves no working link until the next resend. The result reports it.
- `activation.two-factor` shows recovery codes in the POST response, so a browser refresh loses them. The account is active by then and the authenticator still works, and there is no regeneration page yet.
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
## Phase 6: Foundation cleanup (2026-11-04)

### Step 0 findings
- HEAD was `7f33621` (the revert commit), not `216c571`, but `git diff 7f33621 216c571` was empty, so the tree was the Phase 5 state. The discarded work is on `archive/phase6-hr-modules`.
- `hr_system` had 12 of 12 migrations, matching the code, and no extra tables. MySQL's global zone is SYSTEM (+07:00 on this machine) and its time zone tables are empty.
- The owner's planning documents were committed first, on their own (`415181b`), with `DECISION.md` renamed to `DECISIONS.md` as CLAUDE.md names it.

### Done
- WIB: `config/app.php` uses `Asia/Jakarta` with no environment variable, and the MySQL connection uses `+07:00`. `APP_TIMEZONE_DISPLAY` did not exist in this tree, so nothing was removed. Dates show "WIB" from the existing `T` format.
- Migration `2026_11_04_100000_shift_timestamps_to_wib`: +7 hours on the 21 TIMESTAMP columns listed from `information_schema`, one transaction, reversible. It refuses unless the app, the connection and the server are all at +07:00. Epoch columns are not shifted.
- Profile: `App\Livewire\Profile\Sessions` and `LoginActivity` at `/profile/sessions` and `/profile/login-history`, with Profile tabs (Details, Active sessions, Login history) and Home > My Profile breadcrumbs. The old `/security/sessions` and `/security/login-history` routes are gone (404, no redirect). Security Settings lost its tab bar, which had only those links. Username and email self-service unchanged.
- `app:prune-expired-tokens`, scheduled daily at 02:00 WIB without overlapping, retention `AUTH_PRUNE_RETENTION_DAYS` (default 30, at least 1). README gives the Task Scheduler commands.
- README: Laragon workflow and database rules, Scheduler section, decisions for WIB, profile pages and pruning, Docker deferred to Phase 16 only. `.env.example` gained `AUTH_PRUNE_RETENTION_DAYS`.

### Verified
- `php artisan test`: 690 passed on `hr_system_test` (660 before). New: `WibTimezoneTest` (13), `PruneExpiredTokensTest` (13), `PruneConcurrencyTest` (1), 7 in `ProfilePageTest`. `npm run build`: succeeded. Pint: clean. `npm audit` and `composer audit`: no vulnerabilities. No new packages.
- WIB tests: RFC 6238 vectors and replay refusal under WIB, invitation and reset-link expiry one minute either side of the limit, the app and MySQL agreeing on a written instant, the inventory against the live schema, real rows shifted forward and back on `hr_system_test` (including a value that crosses midnight, NULLs and epoch columns), rollback of all tables when the last one fails, refusal when the app or the connection is not at WIB.
- Real race (`PruneConcurrencyTest`): 4 pruning processes and 1 activation at the same moment on committed rows. The activation wins, its invitation is kept, and only the old invitations are removed.
- Mutation check: 25 safeguards removed one at a time, each file restored and confirmed by hash, and the source tree compared with a backup afterwards. 23 caught at first. The breadcrumb test passed without its breadcrumb (it matched the profile menu link), and is now caught. The first "no transaction" mutation was faulty (it never ran the closure); the correct one survived, so a rollback test was added and it is now caught. **One survivor is kept on purpose:** the MySQL server zone check, which cannot be tested without changing the server's global zone.
- `hr_system` end-of-phase migration: no row was written in WIB before it (every value was still behind UTC now), backup `C:\laragon\backup\hr-system\hr_system-before-phase6-20261104-123737.sql` (30.5 KB, "Dump completed", INSERTs for 11 tables), `migrate` ran only the new migration, counts unchanged, 04:48 UTC became 11:48 WIB, `two_factor_last_timestep` untouched.
- Real browser on hr-system.test with a temporary account `zz-check-p6`, created through `CreateUserWithInvitation` with the owner's account as actor (owner's choice), activated through the real link with password and a real TOTP code, logged in with TOTP. Checked at 1024px, 1280px and 375px: dashboard, My Profile, Active sessions, Login history, Security Settings, profile menu, every Profile tab clicked, End session and End all other sessions dialogs with a second and third device logged in by curl (both devices were redirected to login afterwards), Log out of this session. No horizontal scroll, "WIB" everywhere and no "UTC", no console errors besides the deliberate visit to the removed `/security/sessions` (404). No bug found.
- Cleanup: the 11 audit rows, 3 login rows, 10 recovery codes, 1 invitation, 1 role link and the user were deleted in one transaction that checked each count. Afterwards users 1, login_histories 2, audit_logs 5 (max id 5), recovery_codes 10, account_invitations 0, password_reset_tokens 0, model_has_roles 1, the same as before.

### Known issues
- The browser check used the desktop app's built-in browser pane. During this phase the owner changed CLAUDE.md to require a puppeteer script instead (no permission prompts) and to stop waiting for Step 0 approval. That CLAUDE.md change is the owner's and was left uncommitted. From Phase 7 on, browser checks use puppeteer outside the project.
- Guest rows in `sessions` (6 now, no user) come from the checks and expire with the session lifetime; they are not tied to any account.
- `storage/logs/laravel.log` holds the check account's spent invitation link, as the `log` mailer always does. The account no longer exists.
- The `schtasks` command was not run; installing it is the owner's step. `schedule:list`, `schedule:run` and `schedule:test` were run.
- The test suite writes its expected mail-failure lines into the same `storage/logs/laravel.log` (unchanged from earlier phases).
- Carried over: `vite build` warns about the optional `fontaine` package. Laragon's PHP is not in PATH. Error pages keep Laravel's default look. The login history table scrolls sideways on a phone. "Dates shown in UTC" is fixed.

### Decisions
Recorded in README.md under "Project decisions" and "Scheduler".
- Approved by the owner: rename DECISIONS.md and commit the planning documents separately, retention 30 days, the browser account created through the existing action.
- Assumptions: revoked invitations count as consumed, pruning writes no audit entry, and a retention below 1 day is refused.
- Tests changed on purpose: route names and the component namespace in `LoginHistoryPageTest`, `ReauthenticationTest`, `SecuritySensitiveDataTest` and `SessionManagementTest`. `tests/Support/race.php` gained the `prune` mode.

### Owner steps
- Optional: install the scheduler with the `schtasks` command in README "Scheduler".

## Phase 7: Language mechanism (2026-11-04)

### Done
- Tables `languages` (en, id) and `translations` (locale, group, key with `utf8mb4_bin`, value), `users.locale`. Models `Language` and `Translation`.
- `App\Translation\DatabaseLoader` replaces the file loader: `__()`, `@lang` and validation messages read the database, cached per language and group under a version, with English as fallback. A database error is not cached.
- Middleware `SetLocale` in the web group (including the Livewire update route): the user's active language, otherwise `APP_FALLBACK_LOCALE` (English). Guests always get English; no selector on public pages.
- All 243 interface sentences in views, Livewire components, controllers, exceptions and mail templates go through `__()` with unchanged English wording. Framework messages (`validation`, `auth`, `passwords`) and Indonesian field names (`validation.attributes.*`) are in the database. Dates use `translatedFormat` (Carbon follows the app locale on its own).
- Dynamic labels (statuses, role names, login results, device names) are literal `__()` calls in a match and listed in `App\Translation\DynamicLabels`.
- My Profile: a Language card with the active languages. Forged values get a 422, unknown or inactive ones a validation error, the change is audited (`user.updated`, fields `[locale]`), and the page reloads in the new language.
- Emails: `MailDelivery::send()` takes a locale. Invitations use the default language; password reset uses the user's choice (`User::preferredLocale()`, `HasLocalePreference`).
- Commands: `app:translations-missing` (with `--path` for tests), `app:translation-set` (audit event `translation.changed`, no HTML, same placeholders as English), `app:translations-cache-clear`.
- Base data in `database/translations/en.php` and `id.php`, loaded by `TranslationSeeder` (insert-only, retries a chunk on deadlock) through a migration, and by `DatabaseSeeder`.
- README: section "Language" with commands, how to add a sentence or a language, and the standard Indonesian terms.

### Verified
- `php artisan test`: 742 passed on `hr_system_test` (690 before). New: `LanguageTest` (40), `TranslationSetCommandTest` (13), `TranslationSeederConcurrencyTest` (1). `WibTimezoneTest` gained the two new tables in `ADDED_AFTER_WIB`; no other older test changed. `npm run build`, Pint, `npm audit`, `composer audit`: clean. No new project packages.
- Real race: four processes seed the texts at once on committed rows. It first failed with a MySQL deadlock in `INSERT IGNORE`; each chunk now retries in a transaction, and the run inserts every row once.
- Mutation check: 38 mutations, file restored and confirmed by hash each time, source tree compared with a backup. 33 caught at first. Of the 5 survivors: `Carbon::setLocale` in the middleware was redundant (Carbon's own listener does it) and was removed; the "skip existing rows" filter in the seeder was redundant with `insertOrIgnore` and was removed, which also exposed the "seeder overwrites" mutation, now caught; tests were added for an uncached database error and for the cache refresh after seeding. Those mutations were re-run and are caught.
- `hr_system`: backup `C:\laragon\backup\hr-system\hr_system-before-phase7-20261104-140615.sql` (32.4 KB, "Dump completed", INSERTs for 11 tables), `migrate` ran the two new migrations, real-row counts unchanged, 787 translations, `app:translations-missing` passes, `view:clear` run. The owner's `users.locale` is null (English).
- Puppeteer 25.14.0 (outside the project, `C:\laragon\tmp\browser-check\phase7.mjs`) on hr-system.test with a temporary HR Admin `zz-check-p7`, created through `CreateUserWithInvitation` with the owner as actor, activated through the real link with a real TOTP code: 55 checks passed, 0 console errors. Language switch and toast, Indonesian validation message, dashboard, profile, sessions, login history, security and user management in Indonesian at 1024px and 375px with no horizontal scroll, profile menu, profile tabs, mobile drawer, create-user dialog and row menu, the End session dialog and its Livewire toast with a second device (which was logged out), switching back to English, and the login page in English for a guest. The invitation email in the log was English. Screenshots were reviewed.
- Cleanup: 11 audit rows, 2 login rows, 10 recovery codes, 1 invitation, 1 role link and the user deleted in one transaction that checked each count. Afterwards users 1, login_histories 2, audit_logs 5 (max id 5), recovery_codes 10, account_invitations 0, password_reset_tokens 0, model_has_roles 1, the same as before.

### Known issues
- The Indonesian texts were written by the agent in a formal register and still need the owner's review.
- Console command output (artisan messages) stays English; only the web interface and emails are translated.
- An edit made directly in the `translations` table shows only after `app:translations-cache-clear`.
- Guest rows in `sessions` (8 now) come from the checks and expire on their own.
- The sessions dialog now switches its two sentences with `x-show` instead of an `x-text` string, so both texts come from the server; checked in the browser.
- Carried over: `vite build` warns about the optional `fontaine` package. Laragon's PHP is not in PATH. Error pages keep Laravel's default look. Tables scroll sideways on a phone.

### Lessons
- `App::setLocale()` also rewrites `config('app.locale')`, so after the middleware runs that value is the request's language. The default language is read from `app.fallback_locale`.
- `{--group=*}` in a command signature declares an array option, not a default of `*`.
- A stale compiled view kept the text part of an email in English while its HTML part was translated. Run `php artisan view:clear` after changing views.
- MySQL can deadlock two concurrent `INSERT IGNORE` statements on the same unique index; retry the transaction.

### Decisions
Recorded in README.md under "Language".
- Approved by the owner: `app:translation-set` as the console editing tool, puppeteer in `C:\laragon\tmp\browser-check`, formal Indonesian with a list of standard terms, invitations in English and password reset in the user's language, `utf8mb4_bin` keys with a case test, dynamic labels registered and checked.
- Assumptions: sentence keys are the English text; the framework `pagination` group is not loaded (HTML entities, unused); the seeder never overwrites an existing text; stored role names stay English and only their display is translated; `users.locale` is not mass assignable.

### Owner steps
- Review the Indonesian wording (`database/translations/id.php`, or switch My Profile to Bahasa Indonesia). Corrections: `php artisan app:translation-set id "<English key>" "<Indonesian text>"`, and the same change in `id.php`.

## Phase 8: Organization and leave balance (2026-11-04)

### Done
- Departments: table `departments` (unique name ignoring case), page `/departments` (add, rename, activate or deactivate, delete only while unused), `users.department_id` (restricting foreign key) and `users.join_date`. User Management shows a Department column.
- Employment card on `/users/{id}`: department (inactive ones stay but cannot be given anew) and join date, action `App\Actions\UpdateEmployment`, audit `employment.updated`.
- Supervisors: table `supervisor_user`, cards Supervisors and Subordinates on `/users/{id}`, service `App\Services\Supervisors` (attach, detach, Move subordinates), active Approver only, no self link, no duplicate, no cycle (recursive query).
- Global guard `App\Services\SupervisorGuard`, called from `User::save()` (every status change), `changeRole()`, `delete()`, detach and move. Refusals name up to five users and are audited (`supervisor.guard_refused`) after the rollback. Losing the Approver role removes the user's links; suspension keeps them.
- "No supervisor" marker and filter for Employee and HR Admin who are not suspended (`User::lacksSupervisor()`, scope `lackingSupervisor`). Approvers never get it.
- Leave balance: tables `leave_periods` and `leave_balance_entries` (append-only), `users.leave_anchor_date`, service `App\Services\LeaveBalance` (sync, adjust, reset, change anchor), page `/users/{id}/leave-balance`, command `app:leave-periods-sync` scheduled daily at 00:05 WIB. A new account gets its anchor date and first period in the transaction that creates it (owner's addition b).
- Permissions `departments.manage`, `supervisors.manage`, `leave-balance.view`, `leave-balance.adjust` (HR Admin), checked by route, component `boot()` and service. Nobody adjusts, resets or re-anchors their own balance (owner's addition a).
- `User::withinHrAdminLock()` became `withinAccountLock()` (the same single lock on the HR Admin role row) and now also serializes supervisor changes.
- All new sentences in English and Indonesian. The role-change toast in User Management, which Phase 7 had left as a concatenated English string, now goes through `__()`. New components `x-ui.date` and `x-ui.textarea`, icon `building`.
- README: section "Organization and leave balance", scheduler, standard terms. The browser-check path in README held a tab character instead of `\t` and is fixed.

### Verified
- `php artisan test`: 813 passed on `hr_system_test` (742 before). New: `DepartmentTest` (12), `SupervisorTest` (27), `LeaveBalanceTest` (24), `OrganizationConcurrencyTest` (8). Changed on purpose: `RolePermissionSeederTest` (the four permissions), `WibTimezoneTest` (`ADDED_AFTER_WIB` gained the new tables), `LanguageTest` (leave ledger labels in the dynamic-label check), `tests/Support/race.php` (modes move, attach, detach, adjust, reset, sync-leave). `npm run build`, Pint, `composer audit`, `npm audit`: clean. No new packages.
- Real races (separate processes, committed rows, 3 rounds each): two supervisors of one employee suspended at once (one wins, one active supervisor left), detach against suspend, two opposite links at once (no loop), two bulk moves from the same supervisor (one wins, everyone moved once), crossing bulk moves, two resets on the same day (one period opened), concurrent adjustments (no overspend, no lost update), concurrent daily syncs (one period).
- Mutation check: 53 safeguards removed one at a time, each file restored and confirmed by hash, and the source tree compared with a hash list afterwards. 47 caught at first. Two mutations were faulty (they never ran the closure) and were redone correctly: caught. Of the 6 survivors, 5 were redundant code and were removed: the Approver role filter of the guard, the depth counter for nested refusal audits, the separate Approver role lock, a second per-subordinate authorization in move, and the period row lock in the leave service. One exposed a weak test (the leave balance component's own permission check), now covered. A new race test covers the lock around attach. Final code: 51 of 51 mutations caught.
- `hr_system`: counts before (users 1, login_histories 3, audit_logs 8 with max id 30, recovery_codes 10, account_invitations 0, password_reset_tokens 0, model_has_roles 1, translations 787), `migrate:status` with the four migrations pending, backup `C:\laragon\backup\hr-system\hr_system-before-phase8-20261104-163722.sql` (201,871 bytes, "Dump completed", INSERTs for 13 tables), `migrate` ran the four migrations, `db:seed --class=RolePermissionSeeder --force` gave HR Admin the four permissions, `app:translations-missing` passes (1017 translations), `schedule:list` shows both jobs. The owner's account got anchor date 2026-11-04 (its WIB creation date) and one period 2026-11-04 to 2027-11-03 with 12 days, plus its ledger row; nothing else of it changed. (Its `locale` is `en`: the owner set it at 14:38, audit rows 29 and 30, before this phase.)
- Puppeteer (`C:\laragon\tmp\browser-check\phase8.mjs`, with `phase8-setup.php` and `phase8-cleanup.php`) on hr-system.test: a temporary HR Admin `zz-check-p8` created through `CreateUserWithInvitation` with the owner as actor and activated through its real link with real TOTP codes; two Approvers created the same way and activated with `User::completeActivation()`; one pending Employee. 67 checks passed, 0 console errors: departments (add, duplicate in other case refused, deactivate), employment dialog (inactive department not offered), add supervisor, guard refusal with the name, removing one of two supervisors, Move subordinates with a selection, adjust (overspend refused, then saved), reset refused on the first day, anchor with the creation-date button, own balance read only, Department column and No supervisor filter, every new page and dialog at 375px (no horizontal scroll, dialogs fit and close), drawer, and the new pages in Indonesian. Screenshots were reviewed; one fix came from them ("Back to user" wrapped at 1024px).
- Cleanup after each run: the zz-check users with their audit rows (35 in the last run), login history, recovery codes, invitations, session, role links, supervisor link, periods, ledger rows, and the two zz-check departments, deleted in one transaction that checked each count. Afterwards users 1, login_histories 3, audit_logs 8 (max id 30), recovery_codes 10, account_invitations 0, password_reset_tokens 0, model_has_roles 1, departments 0, supervisor_user 0, leave_periods 1 and leave_balance_entries 1 (the owner's, from the migration).

### Bugs found
- A refused `save()` or `delete()` left the in-memory model believing the change was saved (`exists` false, or the original status replaced). Both now restore the instance; tested.
- `TranslationSeeder`: with the new texts, four concurrent runs deadlocked more often than the per-chunk retries could absorb. A run now holds a lock on the `languages` rows for its whole transaction.
- The role-change toast was not translated (Phase 7 leftover).

### Known issues
- Toasts (bottom right, 5 seconds) can cover a button at the bottom of a 1024px screen, as CLAUDE.md's lessons warn: in the browser check two stacked toasts covered "Add supervisor" until they vanished. The toast component is shared UI and was not changed (no redesign); a user can close the toast or wait.
- The first leave period of a new user and of every existing user is the one that contains today, with the full 12 days ([ASSUMPTION] of DECISIONS.md section 5).
- `used` stays 0 until Phase 10 deducts approved leave.
- The Indonesian texts are the agent's and await the owner's review, as in Phase 7.
- A real keyboard and a screen reader were not tried. The scheduler task (`schtasks`) is still the owner's step.
- Carried over: `vite build` warns about the optional `fontaine` package. Laragon's PHP is not in PATH. Error pages keep Laravel's default look. Tables scroll sideways on a phone.

### Lessons
- A refusal thrown after `parent::save()` or `parent::delete()` rolls the row back but not the model instance: restore the instance in the catch.
- A refusal audit written inside a nested transaction is rolled back with the outer one; only the outermost write survives, so no depth bookkeeping is needed.
- Headless Chrome throttles a tab that is not in front: bring it to the front before driving it again.
- In this shell, heredocs and inline scripts can turn a double backslash into a single one. Edit PHP and JS that contain backslashes with the editor tools.

### Decisions
Recorded in README.md under "Organization and leave balance".
- Approved by the owner: the plan and its six assumptions (losing the Approver role removes links; a new anchor applies from the next period; anchor between 2000-01-01 and today with a full first period; no upper limit and never below 0; the marker for ACTIVE and PENDING users only; the guard on `User::delete()` without a delete button), plus additions a to e: no change to one's own anchor date, anchor date and first period at account creation, the Department column, the README note on Approver role versus suspension, and no marker or refusal for Approvers without a supervisor.
- Assumptions: an adjustment is a whole number from -365 to 365 and not 0; reasons are 10 to 1000 characters (the length DECISIONS.md uses for requests); the join date runs from 1950-01-01 to one year ahead and is informational; HR Admin may manage their own supervisors (the guard still applies); department names are at most 100 characters; a scheduled rollover is audited as `leave.balance_reset` with source `schedule`; an Approver subordinate is guarded like anyone (DECISIONS.md section 9).

### Owner steps
- Review the new Indonesian texts (same way as Phase 7).
- Optional: install the scheduler (README "Scheduler"); it now also runs the daily leave sync.

## Phase 9: Requests (2026-11-04)

### Plan (Step 0)
Tables `leave_categories`, `holidays`, `leave_requests`, `leave_request_allocations`, `overtime_requests`; permissions `requests.submit` (all three roles) and `holidays.manage` (HR Admin); routes `/requests`, `/requests/{leave|overtime}/{id}`, `/requests/{type}/{id}/evidence`, `/holidays`; lock order user row then request row; private `evidence` disk. The owner decided the two open questions in the phase prompt: project the next period from the anchor with 12 days and stop at the end of that next period; available = entitlement − used − pending allocations, with `used` untouched until Phase 10. Also from the owner: Approvers need an active HR Admin, Employee and HR Admin need an active supervisor.

### Done
- Leave categories (reference table, `LeaveCategorySeeder` upserts `annual`, `sick`, `other` by code), holidays page with audit, `WorkingDays` (Monday to Friday minus holidays).
- Leave requests: every rule of DECISIONS.md section 8 on the server in one transaction, working days and the deducting flag frozen, allocation per period with the next period projected, horizon at the end of the next period.
- Overtime requests: whole WIB hours, at most 12, across midnight, overlap only on hours that really overlap, refused on dates of pending or approved leave (and the other way round).
- Evidence: pdf/jpg/png checked from the content with `finfo`, `EVIDENCE_MAX_KB` (default 5120, also the Livewire upload limit), random names on the private `evidence` disk, download through `EvidenceController` only, a file removed again when its request is refused.
- Pages: My requests (balance card with the current and the projected next period, Leave/Overtime tabs, status filter, both forms in dialogs, buttons disabled with the reason when there is no approval path), detail page with cancel, Holidays. Menu entries My requests and Holidays. All texts in English and Indonesian (101 new keys; 7 dynamic labels registered).
- Audit: request created and cancelled, evidence uploaded (type and size only), holiday created, updated and deleted. No reason text, no file name.
- Submissions limited to 10 per minute per user.
- README: section "Requests", new standard terms, `EVIDENCE_MAX_KB` in `.env.example`.

### Verified
- `php artisan test`: 940 passed on `hr_system_test` (813 before). New: `LeaveRequestTest`, `OvertimeRequestTest`, `RequestEvidenceTest`, `HolidayTest`, `RequestPagesTest`, `RequestConcurrencyTest`. Changed on purpose: `RolePermissionSeederTest` (two permissions; Approver and Employee also get `requests.submit`), `WibTimezoneTest` (`ADDED_AFTER_WIB` gained the five tables), `LanguageTest` (request statuses and categories in the dynamic-label check), `tests/Support/race.php` (modes leave, overtime, cancel-leave, cancel-overtime).
- Real races (separate processes, committed rows, 3 rounds where it applies): four overlapping leave submissions by one user (one wins), two submissions that together exceed the balance (one wins, 8 days allocated), leave against overtime on the same date (one wins), three overlapping overtime submissions (one wins), double cancel of a leave and an overtime request (each cancelled once, one audit row each).
- Mutation check: 77 safeguards removed one at a time, each file restored and confirmed by hash, and the source tree compared with a hash list afterwards (only the four files changed on purpose differed). 69 caught at first. Of the 8 survivors, 3 exposed weak tests (cancel without the permission, a user without a role, the end-before-start message), now caught; 3 were redundant code, now removed (the user row lock in cancel, `Rule::unique` on holiday dates that the unique index answers with the same message, `abort_if` in the holidays page's `find()`); the safeguard added after the browser check (`resetErrorBag` for a new file) was mutated too and is caught. **One survivor is kept on purpose:** removing `\` from an uploaded file name, because PHP on Windows already treats it as a separator, while a Linux server does not.
- `npm run build`, Pint, `composer audit`, `npm audit`: clean. No new packages.
- `hr_system`: counts before (users 2, login_histories 5, audit_logs 19 with max id 323, recovery_codes 20, account_invitations 1, model_has_roles 2, departments 2, supervisor_user 1, leave_periods 2, leave_balance_entries 2, translations 1017), `migrate:status` with the three migrations pending, backup `C:\laragon\backup\hr-system\hr_system-before-phase9-20261104-183037.sql` (309,057 bytes, "Dump completed", INSERTs for 18 tables), `migrate` ran the three migrations, `db:seed --class=RolePermissionSeeder --force` (role grants 12 to 16), `app:translations-missing` passes (1219 translations), `view:clear`.
- Puppeteer (`C:\laragon\tmp\browser-check\phase9.mjs`, with `phase9-setup.php` and `phase9-cleanup.php`) on hr-system.test: a temporary HR Admin `zz-check-p9` created through `CreateUserWithInvitation` with the owner as actor and activated through its real link with real TOTP codes, and an Approver `zz-check-p9-a1` activated with `User::completeActivation()` and linked as its supervisor through `Supervisors::attach()` mid-run. 66 checks passed, 0 console errors: disabled buttons with the reason before the link, holiday add (empty name refused), edit and delete, leave across that holiday counted as 4 working days, short reason refused, a text file named .pdf refused, a real PDF uploaded and downloaded as an attachment, balance card 4 pending and 8 available, overlap refused, overtime across midnight (13 hours refused, 8 accepted), overtime on a leave date refused, detail, Keep request, cancel, a stale second tab told "no longer pending", frozen days after deleting the holiday, every new page and dialog at 375px without horizontal scroll, the drawer, the pages and a validation message in Indonesian. Screenshots were reviewed.
- Cleanup after each of the three runs: the zz-check users with their audit rows, login history, recovery codes, invitations, sessions, role links, supervisor link, requests, allocations, periods, ledger rows and evidence files, in one transaction that checked each count. Afterwards every count matched the numbers before; holidays, requests and evidence files 0. The 9 temporary upload files of the runs in `storage/app/private/livewire-tmp` were removed by hand.

### Bugs found
- The unique index name of `leave_request_allocations` was over MySQL's 64 characters, so the first full run failed in every test (fixed with a short name).
- Livewire 4 uses `$slots` in views: the balance card rendered empty while other assertions passed. Found by a page test; the variable is now `$periods`.
- The browser check showed the old "must be a PDF" error after a valid file replaced a rejected one. A new file now clears its field's error.

### Known issues
- Requests are visible only to their requester; deciders see nothing until Phase 10.
- `used` stays 0; approval deducts in Phase 10, which must also map projected allocations to real periods and recheck them.
- A rejected temporary upload and Livewire's `.json` metadata of uploads stay in `livewire-tmp` until Livewire's own 24-hour cleanup.
- The Indonesian texts are the agent's and await the owner's review.
- Not tested: an evidence file at the real 5 MB limit through the browser (only through tests with a smaller limit), and the `\` cleaning on Windows (the kept survivor). A real keyboard and a screen reader were not tried. Request tables scroll sideways on a phone, like other tables.

### Lessons
- Read the first error of a failed run first: one bad index name failed 811 tests and took 27 minutes.
- Do not name a Livewire view variable `$slots`.
- MySQL JSON columns return keys sorted: compare audit metadata with `assertEquals`, not `assertSame`.

### Decisions
Recorded in README.md under "Requests".
- From the owner: the projected next period with 12 days and the horizon at its end; available = entitlement − used − pending; Approvers need an active HR Admin, Employee and HR Admin an active supervisor.
- Assumptions: the horizon applies to every leave category; overtime starts at most one year ahead; an overtime touches the dates from its start to its last hour worked (18:00 to 00:00 touches one date); pending allocations count in the period that holds the date their part starts; holidays from 2000-01-01 to the end of the year after next; 10 submissions per minute per user; the pending status is shown as "Pending approval" because "Pending" is the account status; categories are upserted by code and never deleted; a non-deducting request gets no allocation rows; the projected next period is not stored in Phase 9.

### Owner steps
- Review the new Indonesian texts (`database/translations/id.php`, or switch My Profile to Bahasa Indonesia).
- Nothing else: the migration and the permission seeder were run on `hr_system` in this phase.
