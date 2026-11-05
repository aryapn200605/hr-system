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
- Phase 10 (Approvals): done, see "Phase 10" below.
- Phase 11 (History, recap, CSV export): done, see "Phase 11" below.
- Phase 12 (Dashboard): done, see "Phase 12" below.
- Symfony security patch (http-foundation, http-kernel, routing 8.1.10 → 8.1.11): done in its own commit, see "Phase 13" below.
- Phase 13 (Email notifications): done, see "Phase 13" below. Real delivery to a mailbox is still untested (MAIL_FROM_ADDRESS is a placeholder).
- Phase 14 (Full verification and documentation, plus DECISIONS.md section 11): done, see "Phase 14" below.
- Documentation cleanup (2026-11-05): README.md rewritten in Indonesian; technical notes moved to docs/TECHNICAL-DECISIONS.md. See "Documentation cleanup" at the end.
- **Next: Phase 15 (UI redesign) or Phase 16 (deployment).** Both are started by the owner only.
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
Recorded in docs/TECHNICAL-DECISIONS.md under "Project decisions".
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
- The bypass limits above are documented in docs/TECHNICAL-DECISIONS.md: query-builder writes and direct `roles()` pivot calls skip the model guards, and direct database access can change audit rows.
- The lock is covered by a test that checks the `FOR UPDATE` query. Two truly concurrent processes were not tested.
- Hidden password input was not checked in a real terminal, only with piped input and mocked prompts. The prompts use no visible fallback, so they fail instead of showing the password.
- Phase 3's requirements were not read, per CLAUDE.md, so account_invitations may need a further migration.
- Carried over: `vite build` warns about the optional `fontaine` package. Laragon's PHP is not in PATH.

### Decisions
Recorded in docs/TECHNICAL-DECISIONS.md under "Project decisions".
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
- Recovery code HMAC uses a key derived from APP_KEY with HKDF and its own label. Rotation consequences are in docs/TECHNICAL-DECISIONS.md.
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
- Rotating APP_KEY requires re-enrolling all users (docs/TECHNICAL-DECISIONS.md).
- Livewire cannot serve guest pages, because its only update route requires a full login. Phase 3 pages must use controllers.
- The 401 redirect in the layout covers pages using that layout only.
- `resources/views/welcome.blade.php` is now unused.
- Carried over: `vite build` warns about the optional `fontaine` package. Laragon's PHP is not in PATH.

### Decisions
Recorded in docs/TECHNICAL-DECISIONS.md under "Project decisions" and "Recovery codes and APP_KEY".
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
- Web server access logs record a link's first request, including its token. Tokens are single use and expire. See docs/TECHNICAL-DECISIONS.md.
- The timing check used the `log` mailer on mod_php. It has not been measured on a Docker runtime (deferred, Phase 16) or behind a buffering proxy, where the client may wait for the deferred work. If so, the agreed fallback is a queue job carrying only the email address.
- A forgot password transport failure reaches the audit log only, because the response must look the same for every address. Nothing shows it to the user or an admin yet.
- A failed resend leaves no working link until the next resend. The result reports it.
- `activation.two-factor` shows recovery codes in the POST response, so a browser refresh loses them. The account is active by then and the authenticator still works, and there is no regeneration page yet.
- Real SMTP delivery was not tested: the transport failure path used a refused connection, and no message reached a real mail server. No real authenticator app has scanned the QR code yet.
- Expired rows in `password_reset_tokens` and `account_invitations` are not pruned.
- Carried over: `vite build` warns about the optional `fontaine` package. Laragon's PHP is not in PATH.

### Decisions
Recorded in docs/TECHNICAL-DECISIONS.md under "Project decisions" and "Mail".
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
Recorded in docs/TECHNICAL-DECISIONS.md under "Project decisions".
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
- An account suspended before activation stays SUSPENDED for good: it has no password, so Reactivate refuses it, and it cannot be deleted. Inviting that person again needs a different username and email. See docs/TECHNICAL-DECISIONS.md.
- An admin's own username and email cannot change anywhere until Phase 5.
- Editing the email of an ACTIVE user sends nothing to either address, because email verification is out of scope.
- Refused attempts are not audited, only completed actions.
- Mail was tested with the fake mailer and a refused connection, not a real SMTP server.
- The last admin guard is reachable through the UI only in a race, because self-actions are refused first. Both paths are tested.
- On a phone the table scrolls sideways to reach Actions. There is no card layout.
- A real keyboard and a screen reader were not tried on the new pages. Screenshots of the open modals timed out, so modal layout was checked by DOM state and measured width, not by eye.
- Carried over: `vite build` warns about the optional `fontaine` package. Laragon's PHP is not in PATH. Dates show in UTC. Error pages keep Laravel's default look.

### Decisions
Recorded in docs/TECHNICAL-DECISIONS.md under "Project decisions" and "Addition outside SPEC.md: Reactivate".
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
- A mistyped new email is not detected, because nothing is sent. See docs/TECHNICAL-DECISIONS.md.
- Two parallel reconfigurations from two sessions both end in a valid state, but the first one's recovery codes are dead by the time they are read.
- After `app:reset-two-factor`, the password alone reaches enrollment until the user enrolls again. The audit entry does not say who ran it.
- A user who abandons a reconfiguration leaves its secret in that session until cancel or logout. It is unused and encrypted.
- Reset links from a forgot password request that finishes during an email change can survive for an address nobody holds, until they expire. The broker finds no user for them.
- The login history table scrolls sideways on a phone, as User Management does.
- A real keyboard and a screen reader were not tried on the new pages.
- Carried over: `vite build` warns about the optional `fontaine` package. Laragon's PHP is not in PATH. Dates show in UTC. Error pages keep Laravel's default look.

### Decisions
Recorded in docs/TECHNICAL-DECISIONS.md under "Project decisions" and "Addition outside SPEC.md: app:reset-two-factor".
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
Recorded in docs/TECHNICAL-DECISIONS.md under "Project decisions" and "Scheduler".
- Approved by the owner: rename DECISIONS.md and commit the planning documents separately, retention 30 days, the browser account created through the existing action.
- Assumptions: revoked invitations count as consumed, pruning writes no audit entry, and a retention below 1 day is refused.
- Tests changed on purpose: route names and the component namespace in `LoginHistoryPageTest`, `ReauthenticationTest`, `SecuritySensitiveDataTest` and `SessionManagementTest`. `tests/Support/race.php` gained the `prune` mode.

### Owner steps
- Optional: install the scheduler with the `schtasks` command in README.md section 5 (details in docs/TECHNICAL-DECISIONS.md "Scheduler").

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
Recorded in docs/TECHNICAL-DECISIONS.md under "Language".
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
Recorded in docs/TECHNICAL-DECISIONS.md under "Organization and leave balance".
- Approved by the owner: the plan and its six assumptions (losing the Approver role removes links; a new anchor applies from the next period; anchor between 2000-01-01 and today with a full first period; no upper limit and never below 0; the marker for ACTIVE and PENDING users only; the guard on `User::delete()` without a delete button), plus additions a to e: no change to one's own anchor date, anchor date and first period at account creation, the Department column, the README note on Approver role versus suspension, and no marker or refusal for Approvers without a supervisor.
- Assumptions: an adjustment is a whole number from -365 to 365 and not 0; reasons are 10 to 1000 characters (the length DECISIONS.md uses for requests); the join date runs from 1950-01-01 to one year ahead and is informational; HR Admin may manage their own supervisors (the guard still applies); department names are at most 100 characters; a scheduled rollover is audited as `leave.balance_reset` with source `schedule`; an Approver subordinate is guarded like anyone (DECISIONS.md section 9).

### Owner steps
- Review the new Indonesian texts (same way as Phase 7).
- Optional: install the scheduler (README.md section 5; details in docs/TECHNICAL-DECISIONS.md "Scheduler"); it now also runs the daily leave sync.

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
Recorded in docs/TECHNICAL-DECISIONS.md under "Requests".
- From the owner: the projected next period with 12 days and the horizon at its end; available = entitlement − used − pending; Approvers need an active HR Admin, Employee and HR Admin an active supervisor.
- Assumptions: the horizon applies to every leave category; overtime starts at most one year ahead; an overtime touches the dates from its start to its last hour worked (18:00 to 00:00 touches one date); pending allocations count in the period that holds the date their part starts; holidays from 2000-01-01 to the end of the year after next; 10 submissions per minute per user; the pending status is shown as "Pending approval" because "Pending" is the account status; categories are upserted by code and never deleted; a non-deducting request gets no allocation rows; the projected next period is not stored in Phase 9.

### Owner steps
- Review the new Indonesian texts (`database/translations/id.php`, or switch My Profile to Bahasa Indonesia).
- Nothing else: the migration and the permission seeder were run on `hr_system` in this phase.

## Phase 10: Approvals (2026-11-04)

### Plan (Step 0)
Columns `decided_by`, `decided_at`, `decision_note`, `review_locked_by`, `review_locked_until` on both request tables, `leave_request_allocations.charged_at`, `leave_balance_entries.leave_request_id`, ledger type `deducted`; permission `requests.decide` (Approver, HR Admin); services `Deciders`, `Decisions`, `ReviewLock`, `LeaveBalance::chargeApproved()`; routes `/approvals`, `/approvals/{type}/{id}`, `POST /approvals/{type}/{id}/release`. The owner decided the three technical questions in the phase prompt: a projected part maps to the real period with the same start date, opened through the sync service under the user lock if the daily job has not run, and closed periods may be charged by this path only; lock order role lock, request, user, periods by start date, proven by race tests; soft lock storage and expiry left to the agent (about 5 minutes, heartbeat, released on close, no job). Also from the owner: rejection reason at least 5 characters, optional note, authority evaluated at every action, the overtime page shows the day's total.

### Done
- Migration `2026_11_08_100000_add_decisions_to_requests` (decision and soft-lock columns, inbox indexes, `charged_at`, ledger link to the request) and `2026_11_08_100100_load_approval_texts` (texts through `TranslationSeeder`).
- `App\Services\Requests\Deciders`: the one rule (Employee and HR Admin by a linked active Approver, Approver by any active HR Admin, `requests.decide` required), read from the database at every action; also the inbox query and the menu count.
- `App\Services\Requests\Decisions`: approve (optional note, max 1000) and reject (reason 5 to 1000 after trimming) in one transaction with the fixed lock order; "already decided by X on …" and "the requester cancelled …" instead of errors; refusals for no authority or insufficient balance audited after the rollback.
- `LeaveBalance::chargeApproved()`: each part charged to the real period holding its first day, started-but-unopened periods opened by `sync()`, closed periods chargeable, parts in a period that has not started kept and charged by `sync()` when it opens (ledger in the approver's name); insufficient balance keeps the request pending. The projected next period counts those waiting days as used.
- Soft lock (`ReviewLock`, two columns, no job): 5 minutes, heartbeat every 60 s while held, view-only mode with the holder's name for others, "Start reviewing" once free, released by Back, by the decision and by a `sendBeacon` on `pagehide`. The decision refuses while someone else holds a live lock.
- Pages: Approvals inbox (Waiting for me oldest first, Decided by me, type filter, count in the menu), detail (requester, department, dates or hours, allocations with their state, the requester's balance per period, overtime hours per date, evidence, decision), Approve and Reject dialogs. The requester's detail page shows who decided, when, and the note or rejection reason. `RequestPolicy::review()` lets deciders and whoever decided open the request and its evidence; anyone else gets a 404.
- Audit: `leave_request.approved|rejected`, `overtime_request.approved|rejected` (no note or reason text), `request.decision_refused` (`no_authority`, `insufficient_balance`).
- 57 new texts in English and Indonesian; "Leave approved" registered as a dynamic label.
- README: section "Approvals" (who decides, deciding, balance, lock order, soft lock), standard terms, the Phase 8 and 9 notes on locks updated.

### Verified
- `php artisan test`: 1025 passed on `hr_system_test` (940 before). New: `ApprovalTest` (29 tests plus 7 data sets), `LeaveApprovalBalanceTest` (14), `ApprovalPagesTest` (27), `ApprovalConcurrencyTest` (8). Changed on purpose: `RolePermissionSeederTest` (the new permission; the Approver test split from the Employee one), `WibTimezoneTest` (new time columns), `tests/Support/race.php` (modes approve, reject, acquire).
- Real races (separate processes, committed rows, 3 rounds each): four supervisors deciding one request (one wins, one ledger row), approve against cancel (leave and overtime), two requests for the last days, two approvals waiting for the same future period, approve against an HR adjustment (no overspend, ledger matches), approve against the supervisor being suspended and being unlinked (approved only before the change, otherwise refused and audited), takeover of an expired soft lock (one winner), soft lock against decision. No deadlock in any run.
- Mutation check: 85 safeguards removed one at a time, each file restored and confirmed by hash, source tree compared with a hash list afterwards (identical). 74 caught at first. Of the 11 survivors: 2 were redundant code and were removed (the Approver role test in `Deciders::allows()`, since links exist only to Approvers; `abort_if` after `filter_var`, since `find(false)` is already a 404); 9 exposed weak tests and are now caught (permission revoked from the role, suspended supervisor with links, Approver subordinate, a reset after an approval, a part beyond a shortened next period, both pages' `boot()`, the view-only heartbeat, evidence for a demoted former decider). Updated mutations for the changed code are caught. **A check of the one suspicious catch found a real bug** (see Bugs found). Final: every mutation is caught except one kept on purpose: removing the period row lock, which the owner asked for and which is redundant behind the user row lock.
- `npm run build`, Pint, `composer audit`, `npm audit`: clean. No new packages.
- `hr_system`: counts before (users 2, login_histories 5, audit_logs 19 with max id 323, recovery_codes 20, account_invitations 1, model_has_roles 2, departments 2, supervisor_user 1, leave_periods 2, leave_balance_entries 2, requests 0, translations 1219), `migrate:status` with the two migrations pending, backup `C:\laragon\backup\hr-system\hr_system-before-phase10-20261104-215343.sql` (441,221 bytes, "Dump completed", INSERTs for 19 tables), `migrate` ran the two migrations, `db:seed --class=RolePermissionSeeder --force` (permissions 12 to 13, role grants 16 to 18), `app:translations-missing` passes (1333 translations), `view:clear`. The owner's two accounts unchanged (same `updated_at`).
- Puppeteer (`C:\laragon\tmp\browser-check\phase10.mjs`, with `phase10-setup.php`, `phase10-cleanup.php`, `counts.php`) on hr-system.test: four temporary accounts (Employee, two Approvers, HR Admin) created through `CreateUserWithInvitation` with the owner as actor, each activated through its real link and logged in with real TOTP codes in its own browser context; requests seeded through the services. Final run: 66 checks passed, 0 unexpected console errors (one expected: the Employee's deliberate visit to `/approvals`, 403). Menu count, inbox order and filter, lock holder and view-only page with the name, Approve dialog with the deduction sentence, toast, Deducted, the second supervisor told who decided, Reject with a short reason refused then saved, overtime hours per date across midnight, release by Back and by closing the tab (beacon, checked in the database), Decided by me, HR Admin deciding an Approver's request, the requester's view and balance, every page and both dialogs at 375px, the drawer, the pages and a validation message in Indonesian. Screenshots reviewed.
- Cleanup after each of the two runs: the zz-check users with their audit rows, login history, recovery codes, invitations, sessions, role links, supervisor links, requests, allocations, periods and ledger rows, in one transaction that checked each count. Afterwards every count matched the numbers before.

### Bugs found
- **Stale snapshot in decisions** (found by the mutation check): the decision read the decider before locking the requester's row, which fixed MySQL's REPEATABLE READ snapshot too early. Only the period `FOR UPDATE` hid it for real periods; the sum of approved parts waiting for a future period (no row to lock) was exposed, so two approvals could exceed that period's 12 days. The requester's row is now locked right after the request row, before any plain read; a new race test proves it, and removing the lock is caught.
- The browser check showed the inbox table cut off at 1024px (Submitted and the button off-screen; Decided by me had 7 columns). It now has three columns with a second line each and the requester's name as the link; checked to fit at 1024px.
- An old rejection-reason error stayed in the dialog after the reason was fixed (the service validates, not the component). The error bag is now cleared before each attempt.

### Known issues
- Tables on the detail page (overtime hours per date, allocations) and the inbox scroll sideways inside their box at 375px, like the other tables.
- Requests submitted within the same second have no meaningful order between leave and overtime in the inbox (ties broken by ID per table).
- The menu count is computed when a page loads; a Livewire update does not refresh it.
- A beacon sent on reload may arrive after the reloaded page took the lock again and clear it; the next heartbeat takes it back within a minute (a short window for another decider).
- The Indonesian texts are the agent's and await the owner's review.
- Not tested: a real keyboard and a screen reader; a browser tab left in the background for over 5 minutes (polling throttled by the browser).

### Lessons
- Under MySQL's REPEATABLE READ, a lock taken after the transaction's first plain read does not make later plain reads fresh. Lock the rows that guard a check before any plain read, or read the guarded data with `FOR UPDATE`.
- A mutation that is "caught" suspiciously fast deserves the same look as a survivor.
- Livewire turns an `AuthorizationException` in a test call into a 403 response: assert with `assertForbidden()`.
- Check the width of a table at 1024px beside the menu, not only at 375px.

### Decisions
Recorded in docs/TECHNICAL-DECISIONS.md under "Approvals".
- From the owner: the three answers above, rejection reason at least 5 characters, optional note, authority at every action, the deciders per role, the overtime day total.
- Assumptions: a part in a period that has not started yet is approved against the projection and charged when the period opens (periods are never created ahead); after a reset or anchor change a part is charged to the period that now holds its first day; approved deductions do not move on a later reset or anchor change; a suspended requester's pending request can still be decided; whoever decided keeps read access; a view-only page does not take a free lock by itself; lock 5 minutes, heartbeat 60 seconds, view-only refresh 30 seconds; no rate limit on decisions.
- Deviation to note: the owner's order "request, then user" is followed, while the Phase 9 note said "user row first, then request row" for Phase 10; that note is corrected. Both are deadlock-free with the current code.

### Owner steps
- Review the new Indonesian texts.
- Nothing else: the migration and the permission seeder were run on `hr_system` in this phase.

## Phase 11: History, recap, export (2026-11-04)

### Plan (Step 0)
- **Permission:** one new permission `requests.view-all` (HR Admin, through `RoleName::permissions()`): "sees every request in history and recap". Everything else is relation-based and reads no role name: every user with `requests.submit` sees their own requests; a user who also holds `requests.decide` sees the requests of the users linked to them now in `supervisor_user` (links exist only to Approvers). One service, `App\Services\Reports\Visibility`, used by the pages, the exports and the tests. Pages need `requests.submit` or `requests.view-all` (`RequestPolicy::viewHistory`).
- **Tables:** no new table. One migration adds indexes for the recap and the HR Admin history (`leave_requests (status, starts_on)`, `(created_at)`; `overtime_requests (status, start_at)`, `(created_at)`), one migration loads the texts.
- **Routes:** `/history` (Livewire), `/recap` (Livewire, tabs Balance and By department), `GET /history/export`, `GET /recap/balance/export`, `GET /recap/departments/export` (controller, streamed, throttle 10 per minute per user).
- **History:** one UNION query over both request tables with joins (requester, department, category, decider), filters employee, department (current department; "none" too), type, category, status, date range (overlap with the dates a request touches), 25 per page, newest submitted first. Detail links only where the existing pages already allow it: own → My requests detail; may decide now or decided it → Approvals detail; otherwise no link ([ASSUMPTION], existing authorization unchanged).
- **Recap (a):** per visible user (25 per page), every real period (filter: current and next / all) plus the projected next period: entitlement, used, approved-not-yet-deducted, pending, remaining (entitlement − used − approved-not-yet-deducted). Read only: no `sync()`.
- **Recap (b):** per current department and month of a year: request counts per category, overtime count and hours, status Approved by default (filter). A leave counts in the month it starts, an overtime in the month it starts ([ASSUMPTION]).
- **CSV:** `App\Support\Csv`, BOM, delimiter `CSV_DELIMITER` (default `;`), CRLF, formula prefix `'`, WIB times, cursor over the same query as the page, no reason, note or evidence columns. Audit `report.exported` (report, filters, rows) after the stream ends.
- **Tests:** visibility per role (page, Livewire forged filters, export), filters, recap numbers, query counts constant for 2 and 8 users, CSV format and injection, audit, throttle. No new write path, so no new race point; races only if a check shows one.

### Done
- Permission `requests.view-all` (HR Admin). `App\Services\Reports\Visibility`: own rows always, plus the users linked now as subordinates for holders of `requests.decide`, everyone with `requests.view-all`; no role name is read. `RequestPolicy::viewHistory()` guards both pages and the exports.
- Request history (`/history`): one UNION query, filters employee, department (and "No department"), type, category, status, date range by overlap, 25 per page, detail links only where the existing pages already allow them, an export link with the active filters.
- Recap (`/recap`): leave balance per employee and period (entitlement, used, approved not deducted yet, in pending requests, remaining; current and next or all periods; projected next period), and requests per department and month (counts per category, overtime requests and hours; Approved by default, status filter, year). Each tab reads only its own filters.
- CSV exports for all three (`App\Support\Csv`): streamed, BOM, CRLF, `CSV_DELIMITER` (default `;`), formula prefix, WIB times, no reason, note, evidence, username or email; 10 per minute per user; audit `report.exported` (report, filters, row count).
- Migrations `2026_11_09_100000_add_report_indexes_to_requests` and `2026_11_09_100100_load_history_texts`. 37 new texts in English and Indonesian. Menu entries Request history and Recap. README section "History, recap and export"; `CSV_DELIMITER` in `.env.example`.

### Verified
- `php artisan test`: 1075 passed on `hr_system_test` (1025 before). New: `HistoryTest` (22), `RecapTest` (17), `ExportTest` (14), helper `tests/Concerns/SeedsReportData.php`. Changed on purpose: `RolePermissionSeederTest` (the new permission). Both pages and the balance export are checked for a constant query count when users and requests are added.
- Mutation check: 79 safeguards removed one at a time, each file restored and confirmed by hash, source tree compared with a hash list afterwards (identical). 71 caught at first. Of the 8 survivors, 7 exposed weak tests and are now caught (filter fields shown to an Employee, an Approver's employee options, a non-existent date that rolls over, an unknown type, a category without a type in the export, the tie-break of the order, allocations read only for the users on the page). Redundant code found before the run was removed (the `requests.submit` test and `1 = 0` branch inside `Visibility::apply()`, unreachable behind `viewHistory`). **One survivor is kept on purpose:** `ignore_user_abort(true)` in the CSV stream, which only matters when a browser closes mid-download and cannot be shown in-process.
- No real-process race test: this phase adds no write path except the audit row of an export, which has no competing writer.
- `npm run build`, Pint, `composer audit`, `npm audit`: clean. No new packages.
- `hr_system`: counts before (users 2, login_histories 5, audit_logs 19 with max id 323, recovery_codes 20, account_invitations 1, model_has_roles 2, departments 2, supervisor_user 1, leave_periods 2, leave_balance_entries 2, requests 0, permissions 13, role grants 18, translations 1333, sessions 2), `migrate:status` with the two migrations pending, backup `C:\laragon\backup\hr-system\hr_system-before-phase11-20261105-000943.sql` (562,826 bytes, "Dump completed", INSERTs for 19 tables), `migrate` ran both, `db:seed --class=RolePermissionSeeder --force` (permissions 14, role grants 19), `app:translations-missing` passes (1407 translations), `view:clear`.
- Puppeteer (`C:\laragon\tmp\browser-check\phase11.mjs`, with `phase11-setup.php`, `phase11-cleanup.php`, `counts.php`) on hr-system.test: three temporary accounts (Employee, Approver, HR Admin) created through `CreateUserWithInvitation` with the owner as actor, activated through their real links and logged in with real TOTP codes; the Employee assigned to an existing department through `UpdateEmployment`; requests submitted and decided through the services. Final run: 63 checks passed, 0 console errors: menu entries, each role's rows, every filter (overlap range, open end, category switching to Leave, Clear filters), detail links (own to My requests, subordinate to Approvals, none for HR Admin on an Employee's request), balance numbers, department counts by default and with all statuses, the three exports (BOM, separator, filters in the link, no reason text, Indonesian headers), a real download by clicking Export CSV, every table fitting at 1024px, no horizontal scroll at 375px, the drawer, the pages in Indonesian. Screenshots reviewed.
- Cleanup after each of the four runs: the zz-check users with their audit rows (exports included), login history, recovery codes, invitations, sessions, role links, supervisor link, requests, allocations, periods and ledger rows, in one transaction that checked each count. Afterwards every count matched the numbers before except the expected permission, role-grant and translation rows, and `sessions` 2 to 1: the cleanup deleted only the 3 zz-check sessions (its checked count); the other row was most likely an expired session removed by Laravel's session garbage collection (lottery 2/100, lifetime 120 minutes), which cannot be proven because its row was not recorded. The owner's two accounts are unchanged (same `updated_at`).

### Bugs found
- The browser check showed the history table cut off at 1024px (the Submitted column) and the balance table wider than its box. History now has three columns (submission time under the status); the balance table has one heading row per employee with narrow number columns and wrapping headers, the department table likewise; all checked to fit at 1024px.
- The first browser run's CSV assertions were wrong, not the export: `fputcsv` quotes cells that contain spaces, which is valid CSV.

### Known issues
- Rows an HR Admin may see but not decide (an Employee's request) have no detail link, because the detail pages keep their Phase 9 and 10 rules (owner's instruction). The row shows type, dates, days or hours, status, decider and submission time.
- The history export reads through a buffered MySQL cursor, so a very large export holds its result in PHP memory; fine for the expected size, to revisit in the deployment phase.
- The Periods select on an Employee's recap is narrow at 1024px and cuts its Indonesian text.
- The Indonesian texts are the agent's and await the owner's review.
- Not tested: a spreadsheet program opening the CSV (format checked byte by byte), a browser closing mid-download, a real keyboard and a screen reader.

### Decisions
Recorded in docs/TECHNICAL-DECISIONS.md under "History, recap and export".
- From the owner: who sees what, the filters, 25 per page, existing detail pages and evidence rule, both recaps with approved-not-deducted and pending apart, Approved by default, CSV format, formula prefix, audit without data.
- Permission: one new permission `requests.view-all` instead of reading a role; subordinates through the existing `requests.decide` plus the current links.
- Assumptions: an Approver's recap includes their own balance; a request counts in the month it starts; the department is the requester's current one; "remaining" subtracts approved-not-deducted days but not pending ones; a reversed date range is swapped; malformed filters count as no filter; the year filter offers the first request's year to next year; exports are limited to 10 per minute per user; the formula prefix is an apostrophe.

### Owner steps
- Review the new Indonesian texts.
- Optional: set `CSV_DELIMITER` in `.env` for another separator. Nothing else: the migration and the permission seeder were run on `hr_system` in this phase.

## Phase 12: Dashboard (2026-11-05)

### Plan (Step 0)
- **No new table or permission.** One migration only loads the new texts through `TranslationSeeder`.
- **Service** `App\Services\Reports\DashboardSummary`: one query for the counts (own pending; waiting for my decision through `Deciders::requesterIds()`, only with `requests.decide`; pending in the whole organization, only with `requests.view-all`), one UNION query for the own request decided last, one count through `User::lackingSupervisor()` (only with `users.view`), and the balance through `LeaveRequests::slots()`, the same numbers as My requests (only with `requests.submit`). No role name is read.
- **Dashboard:** a new "Requests and leave" section above the existing ones, which stay unchanged. Links: `/history?status=pending`, `/approvals`, `/users?supervisor=none`. Clean empty states, no invented numbers.
- **View-only detail:** route `/history/{type}/{id}` (Livewire `History\Show`) under `viewHistory`; `RequestPolicy::viewDetail()` follows `Visibility` (own, linked subordinates for `requests.decide`, everyone for `requests.view-all`). No decision buttons, no soft lock, no evidence link unless `downloadEvidence` allows it ([ASSUMPTION] from the owner). History links rows that the existing pages do not open to this page.
- **Recap:** the filter grid uses two columns up to `xl`, so the Indonesian "Periode berjalan dan berikutnya" fits at 1024px.
- **Tests:** numbers per role, empty states, no leak between users and roles (page and Livewire), constant query count, view-only page authorization (URL and Livewire), evidence refused for viewers.

### Done
- `App\Services\Reports\DashboardSummary` and a "Requests and leave" section on the dashboard above the existing sections (unchanged): annual leave available with the current period and days held by pending requests, my pending requests (link to history filtered to pending), my last decided request (link to its detail, empty state otherwise), waiting for my decision (`requests.decide`, link to Approvals), pending requests in the organization (`requests.view-all`), Employees and HR Admins without an active supervisor (`users.view`, link to `/users?supervisor=none`). Numbers a user is not entitled to are null and not rendered; zero shows a sentence instead of a link. `x-ui.stat` gained an optional slot.
- Read-only request page `/history/{type}/{id}` (`History\Show`, `RequestPolicy::viewDetail()`, `Visibility::seesUser()`): no decision buttons, no soft lock, no polling; evidence only for the requester and deciders. History links every row that My requests and Approvals do not open to it.
- Recap filters: two columns up to `xl`, so "Periode berjalan dan berikutnya" fits at 1024px.
- 21 new texts in English and Indonesian; migration `2026_11_10_100000_load_dashboard_texts`. README section "Dashboard and read-only request page"; the History note on detail links updated.

### Verified
- `php artisan test`: 1092 passed on `hr_system_test` (1075 before). New: `DashboardTest` (11), `HistoryDetailTest` (6). Changed on purpose: `HistoryTest` (an HR Admin's Employee rows and a view-all-only user's rows now link to the read-only page). The dashboard query count is compared before and after adding users and requests for an HR Admin, an Approver and an Employee.
- Mutation check: 34 safeguards removed one at a time, each file restored and confirmed by hash, and the changed files compared with a hash list afterwards (identical). 30 caught at first. Of the 4 survivors, 3 exposed weak tests and are now caught (the own count for a user with view-all but no submit, no request query without any permission, the newest-first order of the last decision); **1 kept on purpose:** the route's `can:viewHistory` on `/history/{type}/{id}`, which `boot()` repeats, as on every other page route. Redundant code found before the run was removed (`whereNotNull(decided_at)` next to the approved/rejected filter, and the short-circuits in `seesUser()` that `apply()` already covers).
- No real-process race test: this phase adds no write path (reading the balance runs the existing, already race-tested `sync()`, as My requests does).
- `npm run build`, Pint, `npm audit` (0): clean. **`composer audit`: 6 advisories** (see Known issues). No new packages.
- `hr_system`: counts before (users 2, login_histories 9, audit_logs 24 with max id 651, recovery_codes 20, account_invitations 1, model_has_roles 2, departments 2, supervisor_user 1, leave_periods 2, leave_balance_entries 2, requests 0, permissions 14, role grants 19, translations 1407, sessions 1), `migrate:status` with one migration pending, backup `C:\laragon\backup\hr-system\hr_system-before-phase12-20261105-074207.sql` (665,477 bytes, "Dump completed", INSERTs for 19 tables), `migrate` ran it, `app:translations-missing` passes (1449 translations), `view:clear`. No permission change, so no seeder run.
- Puppeteer (`C:\laragon\tmp\browser-check\phase12.mjs`, with `phase12-setup.php`, `phase12-cleanup.php`, `counts.php`) on hr-system.test: four temporary accounts (Employee with supervisor, Approver, HR Admin, Employee without supervisor) created through `CreateUserWithInvitation` with the owner as actor, activated through their real links and logged in with real TOTP codes; requests (one with a real PDF) submitted and decided through the services. 64 checks passed on the first run, 0 unexpected console errors (2 expected: the deliberate 404 fetches). Each role's numbers (the HR Admin's compared with plain SQL), every link clicked, Refresh, the read-only page (banner, no buttons, no poll, evidence restricted, download 404, Back), the empty states, the Periods select measured at 1024, 1280 and 375px in Indonesian for an Employee and an HR Admin (and shown cut with the old grid: 155px room for 196px text), the pages in Indonesian, no horizontal scroll at 1024 and 375px. Screenshots reviewed.
- Cleanup: the zz-check users with their audit rows, login history, recovery codes, invitations, sessions, role links, supervisor link, requests, allocation, periods, ledger rows and the evidence file, in one transaction that checked each count. Afterwards every count matched the numbers after the migration, sessions included; the owner's two accounts are unchanged (same `updated_at`).

### Bugs found
- None in the new code by the browser check. The Phase 11 Periods select cut-off was reproduced in the browser and fixed.

### Known issues
- **`composer audit` reports 6 advisories published on 2026-11-04** in `symfony/http-foundation` (4), `symfony/http-kernel` (1) and `symfony/routing` (1), installed 8.1.10, fixed in 8.1.11 (a patch update of existing packages, no new package). Not applied in this phase: it changes dependencies outside the phase scope; awaiting the owner.
- The dashboard is computed when the page loads (like the menu count); it changes on Refresh or reload.
- The Indonesian texts are the agent's and await the owner's review.
- Not tested: a real keyboard and a screen reader.

### Decisions
Recorded in docs/TECHNICAL-DECISIONS.md under "Dashboard and read-only request page".
- From the owner: the numbers per role and their links, the read-only page for HR Admin and related Approvers, evidence only for the requester and deciders ([ASSUMPTION] from the owner).
- Agent's choices: "remaining" on the dashboard is My requests' "available" (entitlement − used − pending), with the pending days shown under it; "last decided" is approved or rejected by `decided_at`; the "without supervisor" count follows `users.view`, the permission of the list it links to; a user who may view a row but decide it opens the existing Approvals page, not the read-only one; the read-only page shows the reason and decision note, like Approvals, but not the evidence file name to viewers.

### Owner steps
- Decide whether to run the Symfony patch update (`composer update "symfony/*"` to 8.1.11, then the full suite).
- Review the new Indonesian texts. Nothing else: the migration was run on `hr_system` in this phase.

## Phase 13: Email notifications (2026-11-05)

### Plan (Step 0)
- **Step 1, own commit (owner's approval):** `composer update symfony/http-foundation symfony/http-kernel symfony/routing` without `--with-dependencies` (that flag would also have raised two `*-contracts` packages, which the fix does not need).
- **No new table, column or permission.** One migration loads the texts; one new audit event `request.notification_failed`.
- `App\Services\Requests\RequestNotifications` (scoped binding), called by `LeaveRequests::submit()`, `OvertimeRequests::submit()` and `Decisions` after their transaction returns; `Deciders::of()` lists who may decide, through `allows()`. Mailables `RequestSubmittedMail` and `RequestDecidedMail` (shared `RequestMail`), Markdown views, sent with `MailDelivery` in the recipient's language. Livewire pages show a warning toast when a delivery failed.
- `Markdown::withSecuredEncoding()`, because a requester's own name now reaches other users' emails.
- Race processes are forced to the `array` mailer: they read `.env`, which now uses real SMTP.
- **Tests:** recipients per role, permission and status; content without reason, note, rejection reason or evidence; language of subject, HTML and text part; links on APP_URL; after-commit; transport failure and any other error (request kept, audit, class-only log, page warning); cancellation and refusals send nothing; Markdown injection; races (overlapping submissions, concurrent decisions) send exactly one set of emails.

### Done
- Symfony patch: commit `7ca7872`, `composer.lock` only (3 packages, 8.1.10 → 8.1.11), `composer audit` clean, 1092 tests and the build passed before the commit.
- Request emails as described in docs/TECHNICAL-DECISIONS.md "Request email notifications": on submission to every current decider, on approval or rejection to the requester (if ACTIVE), minimal content, recipient's language, links on APP_URL, synchronous after the commit, failures audited and shown as a warning, never undoing the business action.
- `Deciders::of()`; `RequestNotifications::failures()` for the pages; warning toasts in My requests and on the Approvals decision page.
- 13 new texts in English and Indonesian, including "All rights reserved." for the mail footer (it was English in every Indonesian account email). Migration `2026_11_11_100000_load_notification_texts`.
- `tests/Support/race.php` forces the `array` mailer and reports the number of emails per competitor.

### Verified
- `php artisan test`: 1110 passed (7519 assertions) on `hr_system_test` (1092 before). New: `RequestNotificationTest` (18). Changed: `RequestConcurrencyTest` (only the winner of overlapping submissions emails, once) and `ApprovalConcurrencyTest` (four deciders at once: one decision email, from the winner's process), both with real processes.
- Mutation check: 25 safeguards removed one at a time (the runner hashes each file first and restores it; all changed files were compared with a hash list afterwards). 22 caught at first. The 3 survivors were candidate filters in `Deciders::of()`, redundant with `allows()`: the "requester without a path" branch was removed; the linked-supervisor filter is now caught by a query-count test (no extra queries with more unrelated Approvers and HR Admins); **1 kept on purpose:** `activeHrAdmin()` as the candidate query for an Approver's request, which only narrows the rows read while `allows()` decides.
- `hr_system`: counts before (users 2, login_histories 9, audit_logs 24 with max id 651, recovery_codes 20, account_invitations 1, model_has_roles 2, departments 2, supervisor_user 1, leave_periods 2, leave_balance_entries 2, requests 0, permissions 14, role grants 19, translations 1449, sessions 1), `migrate:status` with one migration pending, backup `C:\laragon\backup\hr-system\hr_system-before-phase13-20261105-090602.sql` (765,470 bytes, "Dump completed", INSERTs for 19 tables), `migrate` ran it, `app:translations-missing` passes (1475 translations), `view:clear`. One Indonesian text was reworded after the browser check ("permohonan" → "pengajuan", the glossary term) with `app:translation-set`, which wrote one `translation.changed` audit row (id 720, system actor).
- Puppeteer (`C:\laragon\tmp\browser-check\phase13.mjs`, `phase13-setup.php`, `phase13-cleanup.php`) on hr-system.test: Employee, Approver (address = MAIL_FROM_ADDRESS) and HR Admin created through `CreateUserWithInvitation` with the CLI process on the `log` mailer, activated through their real links, logged in with real TOTP. The Employee submitted a leave through the page (real SMTP email 1, to the Approver, in Indonesian); the HR Admin approved the Approver's own overtime through the page (real SMTP email 2, to the Approver). The four email variants (submitted and decided, en and id) rendered from the real requests and checked at 1024 and 375px (content; no reason, note or decider; button link on APP_URL; no horizontal scroll); the link targets open for the right user and give 404 to another; pages at 375px without horizontal scroll. 0 unexpected console errors. Screenshots reviewed.
- **Real SMTP result:** both deliveries failed with `UnexpectedResponseException`, which proved the failure path for real: request and decision saved, warning toast shown, one `request.notification_failed` audit row each, log lines with class names only. A follow-up probe without DATA (nothing sent) showed connect, login and `MAIL FROM` accepted and `RCPT TO` refused with 550: MAIL_FROM_ADDRESS is still the placeholder `hello@example.com`, a reserved domain that accepts no mail. The 4 browser checks expecting "no warning, no failure audit" therefore failed as expected; the other 41 passed.
- Cleanup: the zz-check users with their audit rows, login history, recovery codes, invitations, sessions, role links, supervisor link, requests, periods and ledger rows, in one transaction that checked each count. Afterwards every count matched the "before" numbers except audit_logs 25 (the translation change above) and translations 1475 (the migration); the owner's two accounts are unchanged (same `updated_at`).
- Evidence leftovers: one new test uploaded evidence without `Storage::fake()`, so every run left a file on the real private disk (39 files from today's runs, referenced by no row in `hr_system` or `hr_system_test`). Fixed in the test, files removed, and the final full run left 0 files.

### Bugs found
- The new test's evidence leftover (above), fixed.
- Older: the Markdown mail footer "All rights reserved." was English in Indonesian emails; now translated.

### Known issues
- **Delivery to a real mailbox is untested:** set MAIL_FROM_ADDRESS to an address the SMTP login may send from and receive at (see README.md section 6 and docs/TECHNICAL-DECISIONS.md "SMTP settings (.env)").
- Emails are synchronous: a slow SMTP server delays the submit or decision page by up to `MAIL_TIMEOUT` per recipient (an Approver's request emails every active HR Admin in turn). Measured with the refused recipient: about 1.4 to 1.8 seconds per page.
- The warning toast disappears after 5 seconds like every toast; the audit row stays.
- The Indonesian texts are the agent's and await the owner's review.

### Decisions
Recorded in docs/TECHNICAL-DECISIONS.md under "Request email notifications".
- From the owner: recipients, minimal content without reason, rejection reason or evidence, recipient's language, synchronous after commit, failure never fails the business action and is audited with class-only logging; [ASSUMPTION] no cancellation email, no opt-out, links on APP_URL.
- Agent's choices: no decision email to a requester who is no longer ACTIVE; no decider name in the decided email; category shown for leave; secured Markdown encoding for every email; warning toasts on the pages.

### Owner steps
- Put real values in `.env`, at least MAIL_FROM_ADDRESS (see README.md section 6 and docs/TECHNICAL-DECISIONS.md "SMTP settings (.env)"), then `php artisan config:clear` if the configuration is cached. Submit one request to see a real email arrive.
- Review the new Indonesian texts. Nothing else: the migration was run on `hr_system` in this phase.

## Phase 14: Full verification and documentation (2026-11-05)

### Plan (Step 0)
- **Step 1, the owner's three decisions**, recorded first as DECISIONS.md section 11. Sick leave: the existing "today or later" rule already covers it; add tests. Final decisions: already enforced; add the "This decision is final…" sentence to both decision dialogs (en/id). Automatic rejection on suspension: new `SuspensionRejections` service called inside `User::suspend()`; one nullable column `system_reason` on both request tables (the reason is a code, translated when shown, so the viewer's language applies); no new permission, route or audit event; counts in the `user.suspended` audit row; count in the suspend dialog; real-process races against approvals, submissions and cancels.
- **Step 2, validation:** full suite, build, Pint, audits, `app:translations-missing`; all migrations from zero, full rollback and up again on `hr_system_test`; a new mutation runner over the critical rules; all race tests together; authorization matrix as a test; read-only integrity checker; load probe on `hr_system_test` (214 users, 6,000 requests); puppeteer walk on hr-system.test as every role at 375/768/1024/1280 px with label, name, focus, contrast (WCAG formula in the page) and "English left in Indonesian pages" probes.
- **Step 3:** compliance tables below; README, SPEC and PROGRESS synced.

### Done
- DECISIONS.md section 11 (the three decisions). docs/TECHNICAL-DECISIONS.md "Phase 14: final decisions and verification" (behavior, lock order, authorization matrix).
- `App\Services\Requests\SuspensionRejections`; `User::suspend()` now returns the counts; `HasDecision::deciderLabel()`, `decisionText()`, `decidedBySystem()`; the history row, CSV and the "already decided" message say "System".
- Suspend dialog count (locked property) and a toast with the number rejected; "This decision is final…" in both decision dialogs. 6 new texts in English and Indonesian.
- Migrations `2026_11_12_100000_add_system_reason_to_requests`, `2026_11_12_100100_load_verification_texts`.
- Fixes from the validation (see "Findings"): shared role lock in `Submission::cancel()`; `down()` of `2026_11_08_100000_add_decisions_to_requests`; the `local` disk is no longer served.
- New tests: `SuspensionRejectionTest` (19), `AuthorizationMatrixTest` (3), `MigrationRollbackTest` (1), `RequestEvidenceTest::test_no_disk_serves_files_by_url`, two real-process races in `ApprovalConcurrencyTest` (the race helper takes a per-competitor start offset so both orders occur). `UserManagementTest` expects the two new audit keys.
- Tools outside the repo, in `C:\laragon\tmp\browser-check`: `phase14-setup.php`, `phase14.mjs`, `phase14-cleanup.php`, `integrity.php` (read-only; `php integrity.php` for hr_system, `php integrity.php hr_system_test`).

### Verified
- `php artisan test`: 1136 passed (7976 assertions) on `hr_system_test` (1110 before this phase). Pint clean, `npm run build` ok, `composer audit` and `npm audit`: no advisories, `app:translations-missing`: every key (1487 translations).
- Migrations: from zero (30), full `migrate:reset` (only the `migrations` table left), up again, on `hr_system_test`. The full rollback failed before the fix; `MigrationRollbackTest` fails with the old `down()` and passes now.
- Mutation check (scratchpad runner; each file hashed and restored, the diff compared afterwards): 48 safeguards — supervisor guard (6), who may decide and the decision recheck and locks (9), balance deduction (4), period allocation (4), overlap and no backdating (7), automatic rejection and its locks (12), dialog and history labels (3), CSV export (6). 47 caught at first; the survivor (clearing the soft lock on automatic rejection) got a test and is caught now. The lock mutations (`cancel-role-lock`, `decision-role-lock`, reading the pending requests before the user row lock) are caught by the real-process races (a deadlock or a request left pending).
- All 8 concurrency test classes together: 34 tests, 919 assertions, real processes, no deadlock.
- Load probe (`hr_system_test`, 214 users, 3,000 leave and 3,000 overtime requests): list, recap, dashboard and export pages answer in 53–166 ms with 2–18 queries on a warm view cache (history export of 6,000 rows: 692 ms, 2 queries; approvals inbox ~100 ms, 13 queries). The first render after `view:clear` took up to 3.5 s (Blade compilation only).
- `hr_system`: counts before (users 3, login_histories 10, audit_logs 36 with max id 731, recovery_codes 30, account_invitations 4, model_has_roles 3, departments 2, supervisor_user 1, leave_periods 3, leave_balance_entries 3, requests 0, translations 1475, sessions 1), `migrate:status` with 2 pending, backup `C:\laragon\backup\hr-system\hr_system-before-phase14-20261105-102405.sql` (965,858 bytes, "Dump completed", INSERTs for 19 tables), `migrate`, `app:translations-missing`, `view:clear`. Integrity check on `hr_system` before and after the browser walk: every check passes except finding 6.
- Browser walk (`phase14.mjs`; final run 130 checks passed and 5 failed, all 5 checker mistakes, confirmed from the state dump and screenshots, and the checker is fixed): 8 zz-check accounts activated through their real links with real TOTP and logged in; HR invited a user in the UI (SMTP refused the .test address and the page said so), the newcomer activated and saw the disabled submit with its reason; department created and assigned; supervisor added, guard refusal naming the employee, bulk move, second supervisor back; anchor change, reset refused because the period started today, adjustment; leave with PDF evidence, leave across the reset split 1 + 1 by period, sick leave today and its cancellation, backdated sick leave blocked by the date field (the server rule is tested), overtime 20:00–02:00 (6 h); two approvers (view-only with the holder's name), both decision dialogs at 375 px with the final sentence, approval, "already decided by" for the second approver, a too-short rejection reason refused then a rejection; deduction 3 days with ledger = remaining, the part after the reset waiting uncharged; the Approver's own request (submitted on the CLI, see Deviations) approved by another HR Admin; the HR Admin's own request: 404 for themself and for another HR Admin, approved by their supervisor; suspension at 375 px naming 1 pending request, a toast with the count, the employee signed out, the overtime rejected by the system, approved requests untouched, counts in the audit, no decision email attempt, the approver seeing "already decided: Rejected by System" with the fixed reason; reactivation restored nothing; history, recap (both tabs), CSV (200, BOM, no reason text, "System") and dashboard as Employee, Approver and HR Admin; profile, sessions, login history and security; Indonesian on 18 pages with no English UI sentence left, "Sistem" and the Indonesian reason, the suspend and approve dialogs in Indonesian at 375 px. 0 unexpected console errors (5 expected 404s from deliberate URL checks). No horizontal scroll on any probed page at 375, 768, 1024 and 1280 px. No unlabeled form control, no nameless button or link, no positive tabindex, no missing focus ring, no text below WCAG AA contrast (colors converted through a canvas, Tailwind's oklch included). Tab order on the login and My requests pages reaches the controls in order. Screenshots reviewed: suspend dialog at 375 px, reject dialog at 375 px, the view-only page, the Indonesian system rejection, the invite dialog, the backdated sick leave.
- Cleanup: the 8 zz-check users, the zz-check department, their audit rows, login history, recovery codes, invitations, sessions, role links, supervisor links, requests, allocations, periods, ledger rows and the evidence file, in one transaction that checked each count, then the 11 anonymous headless-browser sessions (listed first). Afterwards every count equals the "before" numbers except translations 1487 (the migration); the owner's three accounts keep the same `updated_at`.

### Findings
| # | Finding | Severity | Status |
|---|---|---|---|
| 1 | Suspension against cancellation deadlocked (real-process race): a cancellation's status update takes a shared lock on the user row through the foreign-key check, the reverse of the suspension's order | high (a new bug before it shipped) | fixed: cancellation takes the role row shared first; race test and mutation |
| 2 | Locking the pending rows through the `user_id` index deadlocked with a cancellation too | high (same) | fixed: no request row lock is needed under the role and user locks; race test and mutation |
| 3 | A full migration rollback stopped at `2026_11_08_100000` (an index needed by a foreign key) | medium | fixed in `down()`; `MigrationRollbackTest` |
| 4 | Laravel's signed `GET`/`PUT /storage/{path}` routes were on for the `local` disk, whose root holds the evidence folder (a signature was required, so nothing was exposed) | low | fixed: `serve => false`; test |
| 5 | Mutation survivor: clearing the soft lock on automatic rejection was untested | low | fixed: test |
| 6 | `storage/logs/laravel.log` holds 6 lines from 2026-10-30 test runs on `hr_system_test` where a query exception included its bindings: keyed HMAC hashes of test recovery codes (not the codes) | low | unchanged (the owner's local log, not in Git) |
| 7 | "1 pending requests …" and similar counts have one plural form | cosmetic | known, like the existing ":count days" texts |
| 8 | Real email delivery could not be tried: MAIL_FROM_ADDRESS now belongs to one of the owner's accounts, and the request flow would have needed that account | — | not tested |

### DECISIONS.md against code and tests

Status: **met** (implemented and tested), **partly** (implemented, a gap is named), **not yet** (not built), **owner** (outside the code).

| Item | Status | Evidence |
|---|---|---|
| 1 Minimal UI, no new library, no redesign | met | no UI package added since Phase 4; `package.json` unchanged in Phase 14 |
| 1 Docker/VPS/deploy deferred | met | nothing in the repo runs on Docker (docs/TECHNICAL-DECISIONS.md "Local workflow (Laragon)") |
| 1 All checks through hr-system.test | met | puppeteer `phase14.mjs` on hr-system.test; no other server |
| 1 Auth, mandatory 2FA, invitation, recovery codes, self-service username/email kept | met | `LoginTest`, `TwoFactorChallengeTest`, `TwoFactorEnrollmentTest`, `InvitationTest`, `ActivationTest`, `RecoveryCodeTest`, `IdentityChangeTest`; browser: 8 accounts activated with 2FA and logged in |
| 1 2FA reset only by console, cannot be disabled | met | `ResetTwoFactorCommandTest`; no route (authorization matrix) |
| 1 Language in Profile, stored in the database, English default | met | `LanguageTest`; browser: HR2 switched to Indonesian, 18 pages checked |
| 1 Deferred: chatbot, FR25–28, redesign, audit log viewer | met (not built) | no routes (route list) |
| 2 WIB everywhere, "WIB" label, +7 h shift migration, DATE for leave, WIB datetime for overtime | met | `WibTimezoneTest`; integrity check "time"; browser: leave dates and overtime hours stored as entered |
| 3 Three roles, all may submit | met | `LeaveRequestTest::test_every_role_with_an_approval_path_can_submit`; browser: Employee, Approver (CLI, see note), HR Admin submitted |
| 3 Supervisor relation many-to-many, supervisor ACTIVE Approver | met | `SupervisorTest::test_only_an_active_approver_can_supervise`; integrity "supervisors" |
| 3 Right to decide = Approver role + relation, checked on the server | met | `ApprovalTest` (who may decide), `AuthorizationMatrixTest`; mutations decider-* caught |
| 3 Approval flow table (Employee → supervisor, Approver → any HR Admin, HR Admin → assigned supervisor) | met | `ApprovalTest::test_every_requester_role_is_decided_by_the_right_person`; browser steps 4 and 5 |
| 3 No self-approval | met | `ApprovalTest`; `AuthorizationMatrixTest::test_forged_livewire_actions_are_refused_on_the_server`; browser: HR Admin gets 404 on own request |
| 3 No supervisor: cannot submit, marker and filter, disabled button with message | met | `SupervisorTest::test_the_list_marks_and_filters_users_without_a_supervisor`, `RequestPagesTest`; browser: newcomer |
| 3 Global guard in one service, every entry point, names who is affected | met | `SupervisorTest`, `OrganizationConcurrencyTest`; mutations guard-* caught; browser: refusal names the employee |
| 3 Bulk move, one transaction, audited | met | `SupervisorTest::test_moving_all_subordinates_in_one_transaction`; browser step 2 |
| 4 Leave and overtime; categories table seeded idempotently | met | `LeaveRequestTest`, `RolePermissionSeederTest`/`LeaveCategorySeeder` |
| 4 Working days Mon–Fri minus holidays; holidays by HR | met | `LeaveRequestTest::test_working_days_are_monday_to_friday_without_holidays`, `HolidayTest` |
| 4 No backdating (today WIB and later) | met | `LeaveRequestTest`, `OvertimeRequestTest`; mutation leave-not-backdated caught |
| 4 No overlap with pending/approved; rejected and cancelled do not count | met | `LeaveRequestTest`, `OvertimeRequestTest`; mutations overlap-* caught; integrity "no two … overlap" |
| 4 Evidence optional, size limit, private storage | met | `RequestEvidenceTest` (incl. new `test_no_disk_serves_files_by_url`) |
| 4 Overtime whole hours, across midnight, max 12 h, touching ends allowed, daily total shown | met | `OvertimeRequestTest`; browser: 20:00–02:00 (6 h) |
| 4 Overtime refused on a pending/approved leave date | met | `OvertimeRequestTest::test_overtime_touching_a_leave_date_is_refused`; mutation overlap-ot-leave caught |
| 4 Cancel while pending; rejection needs a reason | met | `LeaveRequestTest`, `ApprovalTest`; browser: cancel, reject with a short reason refused |
| 5 12 days per year, reset without carry-over, anchor per employee | met | `LeaveBalanceTest` |
| 5 Periods with an append-only ledger | met | `LeaveBalanceTest::test_the_ledger_is_append_only`; integrity "ledger" |
| 5 Leave across the reset split per period; approval after reset charges the old period | met | `LeaveRequestTest::test_a_request_across_the_reset_is_split…`, `LeaveApprovalBalanceTest`; browser: 1 + 2 split |
| 5 Daily idempotent reset plus manual HR action; manual adjustment with reason | met | `LeaveBalanceTest`; browser step 2 |
| 5 [ASSUMPTION] full 12 days in the first year | met (assumption kept) | `LeaveBalanceTest::test_an_invited_user_gets_an_anchor_date_and_a_first_period_at_once` |
| 6 Soft lock about 5 min with view-only mode; authoritative row-lock check; "already decided by X at Y" | met | `ApprovalTest`, `ApprovalPagesTest`, `ApprovalConcurrencyTest`; browser: two sessions |
| 6 Race tests (two deciders, cancel vs decision) | met | `ApprovalConcurrencyTest` (all rerun together) |
| 7 Reactivation only by HR Admin | met | `UserManagementAccessTest`, matrix |
| 7 Audit log access not built; cancelling an approved request not built | met (not built) | no route; `SuspensionRejectionTest::test_a_decided_request_cannot_be_cancelled_or_decided_again` |
| 7 Sessions and login history under Profile | met | `SessionManagementTest`, `LoginHistoryPageTest`; browser |
| 8 Every validation on the server (list) | met | `LeaveRequestTest`, `OvertimeRequestTest`, `RequestEvidenceTest` |
| 8 [ASSUMPTION] working days frozen at submission | met (assumption kept) | `LeaveRequestTest::test_a_holiday_added_later_does_not_change_the_frozen_days` |
| 8/9 HR Admin cannot adjust their own balance | met | `LeaveBalanceTest::test_an_admin_cannot_adjust_reset_or_re_anchor_their_own_balance`, matrix; browser: no button |
| 9 Manual reset rules, suspended subordinates not counted, marker only for Employee/HR Admin, whole days, closed periods only through approval, anchor-based dates, reset for PENDING/SUSPENDED, inactive departments, audit after rollback, routes in Profile, anchor after the WIB shift | met | `LeaveBalanceTest`, `SupervisorTest`, `DepartmentTest`, `WibTimezoneTest` |
| 10 Laragon and database rules | met | this phase: backup, migrate, zz-check cleanup with counts |
| 11.1 Sick leave not backdated; HR emergency sick leave is backlog | met | `SuspensionRejectionTest::test_sick_leave_cannot_start_before_today`; browser: blocked by the date field; emergency entry not built |
| 11.2 Decisions final; dialogs say so (en/id, 375 px) | met | `SuspensionRejectionTest::test_both_decision_dialogs_say_the_decision_is_final`, `…cannot_be_cancelled_or_decided_again`; browser 375 px en and id |
| 11.3 Automatic rejection on suspension (all sub-items) | met | `SuspensionRejectionTest` (18), `ApprovalConcurrencyTest::test_suspension_against_*` (real processes); mutations auto-reject-* caught; browser step 7 |

### SPEC.md final acceptance checklist
| Item | Status | Evidence |
|---|---|---|
| Laravel 13 on PHP 8.5.12, Laragon at hr-system.test, MySQL only | met | `composer.json`, phpunit.xml, this phase's checks |
| Secure admin bootstrap | met | `CreateAdminCommandTest` |
| Invitation, activation, mandatory TOTP | met | `InvitationTest`, `ActivationTest`, `TwoFactor*Test`; browser |
| RBAC correct; User Management HR Admin only | met | `AuthorizationMatrixTest`, `UserManagementAccessTest` |
| Departments, supervisors with the global guard | met | `DepartmentTest`, `SupervisorTest`; mutations; browser |
| Leave balance with periods and yearly reset | met | `LeaveBalanceTest`; integrity check |
| Leave and overtime requests with all validations | met | `LeaveRequestTest`, `OvertimeRequestTest`, `RequestEvidenceTest`; mutations |
| Approvals with the two-layer lock and correct deduction | met | `ApprovalTest`, `LeaveApprovalBalanceTest`, `ApprovalConcurrencyTest`; browser |
| History, recap and CSV export | met | `HistoryTest`, `RecapTest`, `ExportTest`; browser |
| Dashboard with real data | met | `DashboardTest`; browser |
| English/Indonesian switch | met | `LanguageTest`; browser (18 pages) |
| Profile, sessions, login history | met | `ProfilePageTest`, `SessionManagementTest`, `LoginHistoryPageTest`; browser |
| Audit logging | met | `AuditLogTest` and the audit assertions per module; integrity check |
| WIB everywhere | met | `WibTimezoneTest`; integrity check |
| Responsive UI | met | browser: no horizontal scroll at 375/768/1024/1280 px |
| Tests pass, README complete, Git clean without secrets | met | suite above; README; commit of this phase (`.env` ignored) |
| Real email delivery (Phase 13, optional) | partly | the failure path is proven for real; delivery is not |

### Deviations from DECISIONS.md
- None in behavior. In the browser walk the Approver's own request was submitted on the CLI (log mailer), because an Approver's request emails every active HR Admin, the owner's accounts included; it was decided in the browser.

### Not proven
- Delivery of a real email to a mailbox (finding 8). The SMTP failure path was exercised again for real (refused .test recipients): requests kept, warning toasts, one audit row per refused recipient, class-only log lines.
- The daily job charging the part of a leave after the reset when that period opens (2027-11-05) was not observed live; `LeaveApprovalBalanceTest::test_a_part_in_the_next_period_is_charged_when_that_period_opens` covers it.
- Contrast was measured on the text of the probed pages in their default state, not on hover, focus or toast states. No screen reader was used. Keyboard use was checked through tab order, not by completing whole flows with the keyboard.
- Load was measured in-process on `hr_system_test` (no Apache, no concurrent users).
- The Indonesian texts are the agent's and await the owner's review.

### Known limitations
- Emails are synchronous; a slow SMTP server delays the page (Phase 13).
- Old log lines (finding 6) stay until the owner rotates `storage/logs/laravel.log`. Because `MAIL_MAILER=log` was used in earlier phases, the log also holds activation links of accounts that were removed or have activated already.
- Requests of a suspended supervisor's subordinates keep waiting if no other decider exists (backlog: suspension policy for subordinates).

### Backlog (agreed, not built)
- HR entering an emergency sick leave for past dates.
- Cancelling or changing a request after approval.
- A suspension policy for the suspended user's subordinates (reassign or reject what waits for them).
- Phase 15 UI redesign; Phase 16 deployment (VPS, Nginx, optional Docker, backup and restore, HTTPS cookies, production timing).
- Audit log viewer (DECISIONS.md section 7, still open).

### Lessons
- An UPDATE of a column that sits in the index backing a foreign key makes InnoDB take a shared lock on the parent row. Count that lock when reasoning about lock order; only a real-process race showed it.
- Run a full `migrate:reset` at least once per schema change; a `down()` can fail on an index that a foreign key needs.
- `Schema::getTables()` lists every database on the MySQL server; use `SHOW TABLES` for the current one.
- Browser probes that parse `rgb()` miss Tailwind 4's `oklch()` colors; convert through a canvas.

### Owner steps
- Nothing to migrate: `hr_system` was migrated in this phase (backup above).
- To prove real email: set MAIL_FROM_ADDRESS to an address the SMTP login may send from, give one test decider (not your own account) a mailbox you can read, submit one request, and run `php artisan config:clear` if the configuration is cached.
- Optionally rotate `storage/logs/laravel.log` (finding 6).
- Review the 6 new Indonesian texts and the earlier ones.
- Install the scheduler task if not done yet (README.md section 5; details in docs/TECHNICAL-DECISIONS.md "Scheduler").

## Documentation cleanup (2026-11-05)
Documentation only: no code, test or configuration changed, no command that writes to `hr_system` was run.

### Done
- `README.md` rewritten in Indonesian for the owner and the PT team: summary and roles, versions, prerequisites, numbered installation from a fresh clone to the first 2FA login, daily use and the Windows scheduler, `.env` table (log vs SMTP, a Gmail App Password example without real credentials), permissions and approval flow, modules and rules with links to DECISIONS.md, every `app:*` command, language and translations, testing, backup and safe migration, security and operations notes, troubleshooting, folders and Git flow, other documents, and a final "Belum terverifikasi" list. The Laravel skeleton text (logo, badges, "About Laravel", "Learning Laravel", "Agentic Development", contributing, license) was removed.
- `docs/TECHNICAL-DECISIONS.md`: every section of the old README from "Local workflow (Laragon)" to "First HR Admin" (Project decisions, Organization and leave balance, Requests, Approvals, History, Dashboard, Request email notifications, Phase 14, Language, Mail, Recovery codes and APP_KEY, First HR Admin), moved unchanged (byte-identical, checked with `diff`) under a title, a note and a table of contents.
- References to the old README sections updated in CLAUDE.md (reading order, where to record decisions, end-of-phase step), PROGRESS.md (the "Recorded in" lines, known issues and owner steps) and SPEC.md (Phase 6 docs note). Historical "Done" lines that say what was written into README at the time were left as they were.

### Verified
- Commands checked against `php artisan list app`, `--help` of every `app:*` command, `composer.json`, `package.json`, `routes/console.php`, `.env.example`, `phpunit.xml` and the command code; `php artisan schedule:list` (both jobs); `schtasks /Create /?` and `mysqldump --help` for the options used. `http://hr-system.test/up` answered 200 and MySQL accepted a connection; nothing was written.

### Not verified
- Listed in README.md section 17: a full install on a clean machine, Laragon's automatic virtual host for a new clone, the `schtasks` task itself, real SMTP delivery (Gmail example), the MySQL time zone fix, the `mysqldump` and restore commands as written, `app:create-admin` and `app:reset-two-factor` (help and code only), adding a third language.
