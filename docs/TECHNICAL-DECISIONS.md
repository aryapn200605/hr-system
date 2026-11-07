# Technical decisions and notes

Technical decisions, commands and notes of the HR Management System, phase by phase. This text was moved unchanged from the old README.md on 2026-11-05, when README.md was rewritten in Indonesian for the owner and the team. In the text below, "README", "this README" and section names in quotes (for example README "Scheduler") refer to the sections of **this file**. Business rules live in [DECISIONS.md](../DECISIONS.md); the setup and daily-use guide is [README.md](../README.md).

## Contents

- [Local workflow (Laragon)](#local-workflow-laragon)
- [Scheduler](#scheduler)
- [Project decisions](#project-decisions)
  - [Addition outside SPEC.md: Reactivate](#addition-outside-specmd-reactivate)
  - [Addition outside SPEC.md: app:reset-two-factor](#addition-outside-specmd-appreset-two-factor)
- [Organization and leave balance](#organization-and-leave-balance)
  - [Permissions and pages](#permissions-and-pages)
  - [Departments](#departments)
  - [Supervisors](#supervisors)
  - [Leave balance](#leave-balance)
  - [Translation seeder lock](#translation-seeder-lock)
- [Requests](#requests)
  - [Permissions and pages](#permissions-and-pages-1)
  - [Approval path](#approval-path)
  - [Leave requests](#leave-requests)
  - [Overtime requests](#overtime-requests)
  - [Evidence](#evidence)
  - [Cancel, locking and limits](#cancel-locking-and-limits)
  - [Holidays](#holidays)
- [Approvals](#approvals)
  - [Permission and pages](#permission-and-pages)
  - [Who decides](#who-decides)
  - [Deciding](#deciding)
  - [Leave balance on approval](#leave-balance-on-approval)
  - [Lock order](#lock-order)
  - [Soft lock](#soft-lock)
- [History, recap and export](#history-recap-and-export)
  - [Permission and pages](#permission-and-pages-1)
  - [Who sees what](#who-sees-what)
  - [History](#history)
  - [Recap](#recap)
  - [CSV export](#csv-export)
- [Dashboard and read-only request page](#dashboard-and-read-only-request-page)
  - [Dashboard numbers](#dashboard-numbers)
  - [Read-only request page](#read-only-request-page)
  - [Recap filter width](#recap-filter-width)
- [Request email notifications](#request-email-notifications)
  - [SMTP settings (.env)](#smtp-settings-env)
- [Phase 14: final decisions and verification](#phase-14-final-decisions-and-verification)
  - [Final decisions](#final-decisions)
  - [Automatic rejection on suspension](#automatic-rejection-on-suspension)
  - [Hardening found in Phase 14](#hardening-found-in-phase-14)
  - [Authorization matrix](#authorization-matrix)
- [Phase 15a: redesign foundation](#phase-15a-redesign-foundation)
  - [Reference and licence](#reference-and-licence)
  - [Tokens and font](#tokens-and-font)
  - [Layout shell](#layout-shell)
  - [Page container](#page-container)
  - [Components](#components)
  - [Palette for roles and statuses](#palette-for-roles-and-statuses)
  - [Component gallery](#component-gallery)
  - [Checks](#checks)
- [Phase 15b: form components](#phase-15b-form-components)
- [Phase 15c: auth pages](#phase-15c-auth-pages)
- [Language](#language)
  - [How it works](#how-it-works)
  - [Commands](#commands)
  - [Adding a sentence (every later phase)](#adding-a-sentence-every-later-phase)
  - [Adding a language](#adding-a-language)
  - [Standard Indonesian terms](#standard-indonesian-terms)
- [Mail](#mail)
- [Recovery codes and APP_KEY](#recovery-codes-and-app_key)
- [First HR Admin](#first-hr-admin)

## Local workflow (Laragon)

- The app runs on Laragon's Apache at **http://hr-system.test**. Check things there, not with `php artisan serve` or `php -S`. If Laragon, Apache or MySQL is not running, ask the owner to start it; the agent does not start or configure Laragon.
- `hr_system` is the development database behind hr-system.test and holds the owner's real accounts. `hr_system_test` is only for `php artisan test`. Never run the suite against `hr_system`.
- Migrating `hr_system` happens at the end of a phase, after a verified `mysqldump` backup outside the repository, with `php artisan migrate` only. The steps the owner must run are written at the end of each phase in PROGRESS.md.
- Before any check that needs a service, `http://hr-system.test/up` must answer and MySQL must accept a connection on 127.0.0.1. If either is down, work that needs it stops until the owner starts Laragon.
- Build assets with `npm run build` so Apache serves them. No Vite dev server is left running.
- After changing views, run `php artisan view:clear`. A stale compiled view once kept the plain-text part of an email in English after its template had changed.
- Browser checks run as a puppeteer script outside the project, in `C:\laragon\tmp\browser-check` (puppeteer 25.14.0, not in package.json): `node phase7.mjs <file with the activation link and password>`, `node phase8.mjs <setup.json>`; from Phase 10 on, from the project folder, `php phaseN-setup.php <setup.json>`, `node phaseN.mjs <setup.json>` and `php phaseN-cleanup.php`. Screenshots go to its `shots` folder.
- Browser checks run on hr-system.test at 1024px and 375px. Temporary accounts there are created only through the application, with the username prefix `zz-check-`, and removed afterwards with a targeted delete, after which the counts of real accounts and their rows must match the numbers taken before.
- The backup before an end-of-phase migration goes outside the repository, in `C:\laragon\backup\hr-system\`, and is read back before migrating. Never `migrate:fresh`, `migrate:reset`, `migrate:rollback`, `db:wipe` or truncate on `hr_system` without the owner's explicit approval.
- Documents: CLAUDE.md (working rules), DECISIONS.md (business rules), SPEC.md (phases), PROGRESS.md (log and lessons), this README (technical decisions and commands).
- Docker, VPS, Nginx and server deployment are deferred to SPEC.md Phase 16, which the owner starts. Nothing in this repository runs on Docker yet.

## Scheduler

Two commands run every day through Laravel's scheduler (`routes/console.php`), at WIB times: `app:leave-periods-sync` at 00:05 (the yearly leave reset, see "Organization and leave balance") and `app:prune-expired-tokens` at 02:00. The scheduler only runs while something calls `schedule:run` every minute. On this Windows machine the owner installs that once in Task Scheduler, from a Command Prompt:

```bat
schtasks /Create /SC MINUTE /MO 1 /TN "hr-system scheduler" /TR "\"C:\laragon\bin\php\php-8.5.12-Win32-vs17-x64\php.exe\" \"C:\laragon\www\hr-system\artisan\" schedule:run"
```

Check and remove it with:

```bat
schtasks /Query /TN "hr-system scheduler"
schtasks /Delete /TN "hr-system scheduler" /F
```

The task needs MySQL, so it only does work while Laragon runs. Verified on this machine: `php artisan schedule:list` lists both commands from any working directory, and `schedule:run` and `schedule:test` run them. The `schtasks` command itself was not run, because installing it is the owner's step. A day the machine was off is caught up by the next leave sync. To run either by hand:

```bash
php artisan app:leave-periods-sync
php artisan app:prune-expired-tokens
```

## Project decisions

- MySQL is the only database, for the app and for tests. The skeleton's SQLite connection, fallbacks and setup script were removed.
- Tests run on a separate MySQL database, `hr_system_test`, set in phpunit.xml, and must never touch `hr_system`.
- Laragon's php.ini and configuration are changed by the user only. The agent never starts, stops or reconfigures Laragon.
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
- Logged-in pages use `layouts/app.blade.php` and public pages use `layouts/auth.blade.php`, which loads no Livewire or Alpine. Shared UI lives in `resources/views/components/ui`.
- Dropdowns, modals and the mobile drawer are shown with a class binding, not `x-show`, because `x-show` waits for an animation frame and focus moves before the element is visible.
- Menu entries for pages that do not exist yet are disabled text, not links. The menu is only a marker: every page checks its permission on the server.
- Badge colors are defined on the enums (`RoleName::tone()`, `UserStatus::tone()`), so views never test a role name.
- User Management authorization lives in `App\Policies\UserPolicy`. Every Livewire action calls it on the server, `boot()` checks `users.view` on every request, and the views ask the same policy, so the screen cannot promise what the server refuses. A dialog stores its target in a `#[Locked]` property, and the action reloads that account from the database and checks again.
- Nobody can give a role with permissions they lack, or act on an account holding permissions they lack. The comparison reads permissions from the database, so it needs no role names. HR Admin holds every permission, so this limits only roles granted part of User Management later.
- An HR Admin cannot suspend their own account, even when another admin exists: `User::suspend()` refuses it, as `changeRole()` does for their own role.
- In User Management an admin can change only their own name. Their own username and email are refused on the server and belong to My Profile.
- `User::suspend()` also ends the user's sessions, password reset links and open invitations. It takes the HR Admin lock before the user row, the same order as `changeRole()`, so the two cannot deadlock. Requests of a suspended user are refused anyway by the status check on every request.
- Changing the username or email of a PENDING account revokes its open invitation, and the admin is told to resend. There is no automatic resend, because it would spend the resend limit unseen. Changing only the name keeps the link. Changing an email deletes reset links for the old address.
- `#[Url]` filters are strings and are whitelisted before use, and search text has its LIKE wildcards escaped. A forged update with anything but a plain string gets a 422.
- Dropdowns inside a scrolling table use the `anchored` option, placed from the trigger. Elements with `position: absolute` escape `overflow` unless a parent is positioned, which is why the table's Actions header is plain text and not `sr-only`.
- Profile and security pages act only on the logged-in user, so they take no user ID and need no permission. Anything that touches a password, secret or code is a controller with a Blade form, not Livewire, because a Livewire snapshot would carry the value back and forth. `/profile` (Livewire) changes the name only and has no property or action that could reach anything else.
- Sensitive actions (password, username or email, recovery codes, authenticator) need a re-authentication proof from `/security/confirm`: password plus an authenticator code or a recovery code, in one POST. `App\Support\Reauthentication` keeps it in the session for 10 minutes from the moment it was granted, never extended by activity. One completed action spends it, a form refused by validation keeps it, and it dies when the password hash changes. The `reauthenticated:{route}` middleware names its return route in the route definition, so the client cannot choose where the page returns to.
- The password is checked first, so a wrong one spends no timestep and no recovery code, and the message is the same for a wrong password and a wrong code. Attempts share the TOTP bucket. `TwoFactor::verify()` claims the timestep, so the code that finished a login is refused here and the user waits for the next one. A recovery code is spent even if the action is then abandoned. Failed attempts go to the audit log only: they are not logins, so they stay out of the login history.
- Confirming identity keeps the session ID. A request in flight on a session the user just ended could otherwise regenerate it, and that writes a new row instead of updating the deleted one.
- Changing the password ends every other session, deletes the user's reset links and rotates `remember_token`. The session that made the change stays logged in with a new ID. The new password must differ from the current one.
- **There is no email verification, so changing an email sends no email to either address, and neither does any other change in My Profile or Security Settings.** The re-authentication and typing the address twice stand in for it. A mistyped address is not detected: reset links then go to it, though the username still logs in and the address can be corrected. Reset links for both the old and the new address are deleted, so a vacated address passes on no working link. Changing only the username keeps reset links.
- The message that an email or username is taken lets a logged-in user test whether an address is registered. The risk is low: it needs a full login plus a re-authentication, and the same list is known to HR Admin anyway. The unique index is the last check, and a collision there becomes the same validation message, not a 500.
- Reconfiguring the authenticator keeps the new secret encrypted in the session until a code from it is valid, then swaps secret, timestep and recovery codes in one transaction, so a failed attempt changes nothing and the old authenticator works until the swap. The code is checked without the old `two_factor_last_timestep`, which belongs to the old secret, and its own timestep is stored. Old recovery codes die with the old device, and every other session ends. `two_factor_enrolled_at` becomes the date of the latest setup. The QR code's label is the email at that moment and does not follow a later change.
- A session ID is a credential, so pages only hold an HMAC handle under a key derived from `APP_KEY`, and `App\Support\ActiveSessions` looks a handle up among the user's own other sessions. Ending a session needs no re-authentication, because it protects the user. Devices are named from the user agent with `App\Support\UserAgentLabel`, a hint with no new dependency, as a client can send anything.
- **Sessions end by deleting the row, which needs the database session driver.** A request in flight on that session read its row at the start, so `DatabaseSessionHandler::write()` only updates, which changes nothing once the row is gone. After a password change, `auth.session` is a second check.
- **There is no way to turn 2FA off in the application**: no button and no route. SPEC.md allows it only through an explicitly authorized process, and the only such process is `app:reset-two-factor` below, which forces a new enrollment.
- **Every time is WIB (Asia/Jakarta)** from Phase 6 on. `config/app.php` sets the zone with no environment variable, so there is one source of truth, and the MySQL connection is set to `+07:00` as an offset, because Laragon's MySQL has empty time zone tables. Dates show with the label "WIB" from the `T` format in `x-ui.datetime`.
- Times written before Phase 6 were UTC strings. The migration `2026_11_04_100000_shift_timestamps_to_wib` moves 21 TIMESTAMP columns forward seven hours in one transaction, and back on rollback. It refuses to run unless the application, the connection and the MySQL server are all at +07:00. Its column list came from `information_schema`, and `WibTimezoneTest` fails when a TIMESTAMP or DATETIME column exists that is in neither that list nor the test's list of columns added after the switch. **A new time column goes in that test's `ADDED_AFTER_WIB`.** Epoch columns are not shifted: `sessions.last_activity`, cache and job times, and `users.two_factor_last_timestep`, because TOTP counts from the Unix epoch.
- The sessions list and login history belong to My Profile at `/profile/sessions` and `/profile/login-history`. The old `/security/...` routes were removed without a redirect. Security Settings keeps 2FA status, password, recovery codes and the authenticator.
- `app:prune-expired-tokens` deletes reset tokens that expired more than `AUTH_PRUNE_RETENTION_DAYS` days ago (default 30, at least 1), and invitations that expired, were accepted or were revoked more than that long ago. An open invitation never matches, and users are never deleted. It writes no audit entry, because it removes only rows that can no longer be used. A retention below 1 day is refused, so a just-accepted invitation is never pruned while its activation finishes.
- The optional audit trail viewer was skipped on purpose. It would add a seventh permission to the list SPEC.md fixes, and its metadata would need a whitelist. Audit rows are read in the database for now.

### Addition outside SPEC.md: Reactivate

SPEC.md has no way back from SUSPENDED. At the user's request, User Management has a minimal Reactivate action: permission `users.suspend`, a confirmation dialog, audit event `user.reactivated`, status back to ACTIVE, password, 2FA and role unchanged. It ends any session left over from the suspension.

It never activates an account that has not finished activation. A PENDING account is refused because it is not suspended, and an account suspended before activation has no password, so it is refused too. **Such an account stays SUSPENDED for good: the invitation was revoked and nothing can restore it.** To invite that person again, create a new user with a different username and email, since accounts are never deleted.

### Addition outside SPEC.md: app:reset-two-factor

A user who lost the authenticator and every recovery code cannot pass a re-authentication, so at the user's request there is a console-only way back. It has no route or UI.

```bash
php artisan app:reset-two-factor {username}
```

After a confirmation it removes the secret, enrollment date, last timestep and recovery codes, ends all sessions of that user, and writes the audit event `two_factor.reset` with a null actor. Password, status and role stay. The next login forces a new enrollment.

**Until they enroll again, the password alone reaches the enrollment page**, the same exposure as a first login. Check who is asking before running it, out of band. Anyone who can run this command already has the server, so it adds no new access. The audit entry cannot say who ran it, only that the system did.

## Organization and leave balance

Built in Phase 8. Business rules: DECISIONS.md sections 3, 5 and 9.

### Permissions and pages

| Permission | Allows | HR Admin |
|---|---|---|
| `departments.manage` | `/departments`: add, rename, deactivate and delete departments | yes |
| `supervisors.manage` | Add and remove supervisors, Move subordinates, on `/users/{id}` | yes |
| `leave-balance.view` | `/users/{id}/leave-balance`: periods and ledger | yes |
| `leave-balance.adjust` | Adjust, Reset period and Change anchor date on that page | yes |

Department and join date use the existing `users.update`. Every permission is checked by the route, the Livewire `boot()` and the service the action calls, so a replayed or forged Livewire request is refused too. After migrating, the owner runs `php artisan db:seed --class=RolePermissionSeeder --force` once to give HR Admin the four new permissions (idempotent).

### Departments

- Names are unique ignoring case, at most 100 characters, with spaces collapsed.
- A department can be deleted only while no user has it. The restricting foreign key on `users.department_id` is the check, and its refusal becomes the message "Deactivate it instead". A used department can only be deactivated: it stays on its employees, is marked inactive, and is not offered for a new assignment. Saving an employee who keeps an inactive department is allowed.
- The join date is for information only (1950-01-01 to one year ahead). Leave follows the anchor date.

### Supervisors

- `supervisor_user` holds the links (unique pair, foreign keys that cascade when a user row is deleted). A supervisor must be an ACTIVE Approver when the link is made. No self link, no duplicate, and no cycle of any length: a recursive query walks up from the new supervisor and refuses if it meets the subordinate.
- **An active supervisor** is a linked user who is ACTIVE. Every linked supervisor is an Approver: a link is made only to an active Approver, and losing the role removes the user's links. `App\Services\SupervisorGuard::activeSupervisors()` is the one definition, used by the guard, the marker and the filter.
- **The guard** (`SupervisorGuard::check()`): no action may leave a user who has an active supervisor now with none. SUSPENDED subordinates are not counted; PENDING ones are. It runs inside `User::save()` (every status change, so suspension), `User::changeRole()`, `User::delete()`, removing a supervisor and Move subordinates. Console and tinker code goes through the same model methods. The refusal names up to five affected users ("and N more") and says the way out: give them another supervisor, or move them.
- **Refusals are audited** as `supervisor.guard_refused` (action, supervisor IDs, affected IDs) by `SupervisorGuard::auditing()`, after the transaction that held the change has rolled back. A nested entry point (suspend() saving the user) writes a row too, but inside the outer transaction, which rolls it back: one row stays. An entry point must not be called inside a caller's own transaction, or that transaction also decides the fate of the audit row.
- **Locking:** `User::withinAccountLock()` (formerly `withinHrAdminLock()`) locks the HR Admin role row. Every change to supervisor links, statuses, roles and deletes runs under it, which serializes them; the guard therefore reads a state nobody else can change meanwhile.
- **Losing the Approver role removes that user's links as a supervisor**, in the same transaction, after the guard has checked that nobody is left without an active supervisor (audited in `user.role_changed` as `supervisor_links_removed`). Becoming an Approver again does not bring them back. **A suspension keeps the links**: they stop counting while the supervisor is suspended and count again after Reactivate. A role change of a subordinate keeps their own supervisors.
- **Approvers do not need a supervisor**: their requests will go to any HR Admin (Phase 9). They never get the "No supervisor" marker, nothing refuses them for having none, and they can still have supervisors, who are guarded like anyone's (DECISIONS.md section 9).
- **"No supervisor" marker and filter** (User Management and the detail page): Employee and HR Admin, not SUSPENDED, without an active supervisor.
- **Move subordinates** (on an Approver's detail page): all or the chosen subordinates move from this supervisor to another active Approver in one transaction. A subordinate who already has the new supervisor only loses the old one. Refused as a whole if the list changed meanwhile, the target is the same supervisor or one of the subordinates, or the move would close a loop. Audited as `supervisors.moved` with every subordinate ID.

### Leave balance

- Every user has periods (`leave_periods`: start, end, entitlement, used) and an append-only ledger (`leave_balance_entries`: period, actor or null for the system, type, change, remaining days before and after, reason). The ledger model refuses update and delete, and no code deletes a period; only deleting a user row (which no screen offers) removes its periods and ledger by foreign key cascade.
- **A new account gets its anchor date (today in WIB) and its first period with 12 days at once**, in the transaction that creates it, through the `User` model's `creating` and `created` events. This covers the invitation flow, `app:create-admin` and every other way a user row is created, without the daily job. Existing users got their anchor date from `DATE(created_at)` (already WIB after the Phase 6 shift) and their current period from the migration `2026_11_06_100300`.
- **A period** runs from an anniversary of the anchor date to the day before the next one. Anniversaries are counted from the anchor with `addYearsNoOverflow`, never from the previous period: an anchor on 29 February gives 28 February in other years and the 29th again in leap years. The first period is the one that contains today, with the full 12 days ([ASSUMPTION] of DECISIONS.md). No carry over: remaining days stay on the old period, which simply ends.
- **`app:leave-periods-sync`** opens every period that should have started by today, for every user including PENDING and SUSPENDED. It is idempotent and fills each missed period in turn, so a machine that was off loses no year. The leave balance page runs the same sync for the user it shows. A rollover is audited as `leave.balance_reset` with source `schedule`. Opening a period also charges the approved leave that was waiting for it (Phase 10, "Approvals").
- **Adjust**: a whole number of days between -365 and 365 (not 0) and a reason of 10 to 1000 characters, on the current period only. The remaining days can never go below 0. Audited as `leave.balance_adjusted`.
- **Reset period**: the current period ends yesterday and a new one with 12 days starts today, until the day before the next anniversary. The anchor does not move. Refused when the current period started today. Reason required. Audited with source `manual`.
- **Change anchor date** (2000-01-01 to today): the current period keeps its dates. The new anchor sets the end of every period from the next one on, so that next period can be shorter (or longer) than a year. The dialog has a "use the account creation date" button. Audited as `leave.anchor_changed` with both dates.
- **Nobody adjusts, resets or re-anchors their own balance**, an HR Admin included: another HR Admin must do it. Refused by `UserPolicy::adjustLeaveBalance()`, which the service and the page both ask.
- **Locking:** every change locks the user's row first, so the daily job, a reset, adjustments and approvals of one user run one after another. An approval also locks the periods it charges, by start date (see "Approvals", Lock order).

### Translation seeder lock

`TranslationSeeder` now runs in one transaction that first locks the `languages` rows, so concurrent runs wait for each other. With the Phase 8 texts, four concurrent runs deadlocked on `INSERT IGNORE` more often than per-chunk retries could absorb (found by `TranslationSeederConcurrencyTest`).

## Requests

Built in Phase 9. Business rules: DECISIONS.md sections 4, 8 and 9, plus the owner's Phase 9 decisions on projected periods and pending balance.

### Permissions and pages

| Permission | Allows | Roles |
|---|---|---|
| `requests.submit` | `/requests` (My requests), `/requests/{leave\|overtime}/{id}` (detail and cancel), evidence download | HR Admin, Approver, Employee |
| `holidays.manage` | `/holidays`: add, edit and delete holidays | HR Admin |

Checked by the route, the Livewire `boot()` and the service. After migrating, run `php artisan db:seed --class=RolePermissionSeeder --force` once (idempotent) so every role gets its new permission. On My requests a request is visible only to its requester (`App\Policies\RequestPolicy`); someone else's request, by URL or by forged Livewire call, is a 404. The people who may decide it see it under Approvals (below).

### Approval path

`App\Services\Requests\ApprovalPath` is the one check, used by the page (buttons disabled with the reason) and by the services (inside the transaction, from the database): the requester is ACTIVE; an Employee or HR Admin has at least one active supervisor; an Approver needs at least one active HR Admin. The message names nobody.

### Leave requests

- Categories come from `leave_categories` (`annual` deducts balance, `sick` and `other` do not), kept by `Database\Seeders\LeaveCategorySeeder`, which upserts by code and never deletes. There is no screen. A request freezes `deducts_balance` and `working_days` at submission, so a later category or holiday change does not alter it.
- Working days are Monday to Friday minus `holidays` (`App\Services\Requests\WorkingDays`, the one definition).
- Rules (all on the server, in one transaction): start not before today (WIB), end not before start, at least one working day, active category, reason 10 to 1000 characters, no overlap with the user's pending or approved leave, no date touched by the user's pending or approved overtime.
- **Balance:** a deducting request is split over the current period and the next one (`leave_request_allocations`, one row per period part). The next period never exists in advance (`LeaveBalance::sync()` opens a period only once it starts), so it is always **projected from the anchor date with 12 days** (`leave_period_id` null; Phase 10 maps it to the real row). Each part must fit in that period's **entitlement − used − pending allocations**. `used` does not change in Phase 9.
- **Horizon:** the end date may not be after the last day of the next period. This applies to every category ([ASSUMPTION]).
- Pending allocations are counted by the date their part starts, not by the stored period start, so a manual reset or anchor change after submission still counts them in the period that now holds those dates.

### Overtime requests

- `start_at` and `end_at` are DATETIME in WIB, whole hours (the form offers 00:00 to 23:00; a forged :30 is refused), end after start, at most 12 hours, may cross midnight. The start date is not before today and not more than one year ahead ([ASSUMPTION]).
- No overlap with the user's pending or approved overtime; touching ends are allowed.
- **Touched dates** (`first_date`, `last_date`) run from the start to the last hour worked, so 18:00 to 00:00 touches one date ([ASSUMPTION]). Overtime on a date of pending or approved leave is refused, and leave on a date touched by such overtime is refused.

### Evidence

- Optional on both types: pdf, jpg or png, at most `EVIDENCE_MAX_KB` (default 5120, also Livewire's temporary upload limit in `config/livewire.php`). **The type is read from the file's content with `finfo`**, never from the name or the browser's claim, and the stored extension follows the real type.
- Stored on the `evidence` disk (`storage/app/private/evidence`, not served, no URL) under 40 random characters. The uploaded name is kept, cleaned of path, control and quote characters, only as the download name. A file whose request is then refused is deleted again.
- Downloaded only through `EvidenceController` (`requests.evidence`), with `Content-Disposition: attachment`, `nosniff`, a sandbox CSP and `no-store`. Livewire's temporary uploads stay in `storage/app/private/livewire-tmp` and are cleaned after 24 hours by Livewire; the form deletes its own when it resets.

### Cancel, locking and limits

- The requester cancels a pending request from its detail page. A page opened before the request was cancelled or decided shows the reason instead of the dialog, and a second cancel is refused with the status.
- **Locks:** a submission locks the requester's user row as its first statement, so every submission of one user runs one after another and the overlap and balance checks read a state nobody can change meanwhile. A cancellation locks only the request row. Decisions take more locks in a fixed order: see "Approvals", Lock order.
- Submissions are limited to 10 per minute per user (`config/requests.php`).
- Audit: `leave_request.created` (category, dates, days, allocations), `overtime_request.created` (times, hours), `leave_request.cancelled`, `overtime_request.cancelled`, `request.evidence_uploaded` (type and size), `holiday.created`, `holiday.updated`, `holiday.deleted`. No reason text and no file name.

### Holidays

`/holidays`, permission `holidays.manage`: one holiday per date, name up to 100 characters, dates from 2000-01-01 to the end of the year after next ([ASSUMPTION]). A change affects only requests submitted afterwards.

## Approvals

Built in Phase 10. Business rules: DECISIONS.md sections 3, 5 and 6, plus the owner's Phase 10 decisions on projected periods, lock order and the soft lock.

### Permission and pages

| Permission | Allows | Roles |
|---|---|---|
| `requests.decide` | `/approvals` (inbox: Waiting for me, Decided by me), `/approvals/{leave\|overtime}/{id}` (detail, Approve, Reject), `POST /approvals/{type}/{id}/release` | HR Admin, Approver |

Checked by the route, the Livewire `boot()` and `App\Services\Requests\Decisions`. After migrating, run `php artisan db:seed --class=RolePermissionSeeder --force` once (idempotent). The menu entry "Approvals" shows how many pending requests wait for the user. The inbox lists both request types together, oldest first, with a type filter; "Decided by me" lists the user's decisions, newest first.

### Who decides

`App\Services\Requests\Deciders` is the one rule, read from the database at the moment of every action, never from the state at submission:

| Requester | Decided by |
|---|---|
| Employee | an ACTIVE Approver linked to them as supervisor |
| HR Admin | an ACTIVE Approver linked to them as supervisor |
| Approver | any ACTIVE HR Admin (their own supervisors do not decide) |

The decider also needs `requests.decide`. Nobody decides their own request: the role that decides is never the requester's role, and a user has one role. A user without a role has nobody. If a link is removed, a supervisor is suspended or a role changes while a page is open, the next action says "You cannot decide this request." and is audited. The requester's own status does not matter: a pending request of a suspended user can still be decided ([ASSUMPTION]).

Who may open a request under Approvals: anyone who may decide it now (pending or not, so a second supervisor sees who decided first), and whoever decided it, even after losing the link (`RequestPolicy::review()`). Everyone else gets a 404. The same people may download its evidence.

### Deciding

- **Approve** with an optional note (up to 1000 characters); **Reject** with a reason of 5 to 1000 characters after trimming. The requester sees both on their request, with who decided and when (WIB).
- A request that is no longer pending is not an error: "This request was already decided: Approved by X on 4 Nov 2026, 13:45 WIB." or "The requester cancelled this request on …". A cancel that loses against a decision gets "This request is no longer pending: it is Approved."
- Overtime approval touches no balance. The overtime detail shows, for each date the request touches, the requester's approved and pending overtime hours on that date (hours across midnight count on the date they are worked). There is no daily limit (DECISIONS.md section 4).
- Audit: `leave_request.approved`, `leave_request.rejected`, `overtime_request.approved`, `overtime_request.rejected` (requester, whether a note was given, the charged parts; never the note or reason text), and `request.decision_refused` with reason `no_authority` or `insufficient_balance`, written after the transaction has rolled back.

### Leave balance on approval

Approving a leave request of a deducting category (`LeaveBalance::chargeApproved()`), in the decision's transaction:

- Each allocation part is charged to the **real period that holds the part's first day**. Normally that is the period with the same start date as the projection at submission (the owner's rule). After a manual reset or an anchor change it is the period that now holds those dates, which is also how pending days are counted (Phase 9). A period that has started but was not opened yet (the daily job did not run) is opened first by `LeaveBalance::sync()` under the user row lock. **A closed period can be charged, through this path only.**
- **A part in a period that has not started yet** ([ASSUMPTION]: the owner's rule covers a period that has started but was not opened): it is checked against that projected period (12 days minus parts already approved there), stays with `charged_at` null, and is charged by `sync()` the moment it opens the period, with a ledger row in the approver's name. Periods are never created ahead of time, so reset, re-anchor and the daily job keep working as in Phase 8. Until then the projected next period on My requests counts those days as used.
- Every part must fit in its period's remaining days (entitlement − used) at the moment of approval; other pending requests do not count against it. If one does not fit, nothing is charged, the request stays pending, the decider sees "Not enough leave balance in the period …: this request needs N working days, M remain. The request stays pending.", and the refusal is audited.
- Each charge adds to `used`, writes a ledger row of type `deducted` ("Leave approved") naming the request (`leave_balance_entries.leave_request_id`) and the approver, and sets the allocation's `leave_period_id` and `charged_at`. The detail pages show each part as Reserved, Deducted, Deducted when the period opens, or Not deducted.
- Non-deducting categories and rejections never touch the balance.
- Approved deductions do not move when HR later resets a period or changes the anchor ([ASSUMPTION]); cancelling an approved request is not built (DECISIONS.md section 7).

### Lock order

Every decision runs in one transaction and takes its locks in this order (the owner's Phase 10 decision):

1. the HR Admin role row, **shared** (`User::shareAccountLock()`): roles, statuses and supervisor links change only under its exclusive lock (`User::withinAccountLock()`), so who may decide cannot change while a decision runs, and decisions do not wait for each other;
2. the request row (`FOR UPDATE`); its status and soft lock are then read again;
3. the requester's user row, **before any plain read**: MySQL's REPEATABLE READ fixes a transaction's snapshot at its first plain read, so taking it after this lock makes every balance change of the user that committed before visible;
4. the leave periods to charge, ordered by start date.

The other writers fit this order: a cancellation takes the role row shared and then the request row (since Phase 14; its status update also takes a shared lock on the requester's user row, because the `user_id` foreign key is backed by the `(user_id, status, …)` index and InnoDB checks the parent row whenever that index changes); a submission, the daily job, adjustments and resets lock only the user row and then periods; account changes lock the role row exclusively and then user rows, and a suspension then reads and rejects the user's pending requests (see "Automatic rejection on suspension"). No two of them can wait for each other in a circle. `ApprovalConcurrencyTest` runs the critical pairs in separate processes on committed rows (two to four supervisors at once, approve against cancel, two requests for the last days, approve against an HR adjustment, approve against a supervisor being suspended or unlinked, soft-lock takeover, soft lock against decision); a deadlock would fail the process and the test. The mutation check showed why step 3 must come before any plain read: with the snapshot taken earlier (reading the decider first), two approvals for the last days both went through when the period lock was removed, and the sum of approved parts waiting for a future period, which has no row to lock, was exposed. The period row lock (step 4) is kept as the owner asked, although with step 3 in place it is redundant (its removal is the one mutation that survives).

### Soft lock

The first layer of DECISIONS.md section 6 (`App\Services\Requests\ReviewLock`):

- Two columns on each request, `review_locked_by` and `review_locked_until`. No table and no background job: expiry is checked whenever the lock is read or taken.
- Opening a pending request takes the lock for 5 minutes (`config/requests.php`, `review_lock_minutes`). The page renews it every 60 seconds (`wire:poll`, `review_heartbeat_seconds`) while it holds it. Browsers slow down polling in background tabs, so a forgotten tab lets the lock lapse.
- Anyone else who may decide sees **view-only mode**: "X is reviewing this request until 10:05 WIB. You can view it, but not decide it now.", without Approve and Reject. The page refreshes every 30 seconds; once the lock is free it offers "Start reviewing".
- **Released** by "Back to approvals", by a decision, and when the tab is closed or left: the page sends `navigator.sendBeacon()` to `approvals.release` on `pagehide`, because a Livewire call does not survive the unload. The release only clears a lock the user holds.
- Taking, renewing and releasing are each one conditional `UPDATE` (free, mine, or expired), read back afterwards because MySQL reports 0 changed rows when a renewal in the same second writes the same values. It does not change `updated_at`.
- **The soft lock never replaces the check at submit:** the decision re-reads the request under its row lock and refuses while someone else holds a live soft lock, so view-only mode also holds against a forged Livewire call. A lock that has expired does not stop anyone.

## History, recap and export

Built in Phase 11. The owner's Phase 11 decisions: who sees what, the filters, the two recaps and the CSV format.

### Permission and pages

| Permission | Allows | Roles |
|---|---|---|
| `requests.view-all` | every user's requests and balance in history, recap and exports | HR Admin |

| Page | URL | Who |
|---|---|---|
| Request history | `/history` | anyone with `requests.submit` or `requests.view-all` |
| Recap (tabs Leave balance, By department and month) | `/recap`, `/recap?tab=departments` | the same |
| CSV exports | `GET /history/export`, `/recap/balance/export`, `/recap/departments/export` | the same, 10 per minute per user (`config/requests.php`) |

Checked by the route (`can:viewHistory`, `RequestPolicy::viewHistory()`) and the Livewire `boot()`. After migrating, run `php artisan db:seed --class=RolePermissionSeeder --force` once (idempotent).

### Who sees what

`App\Services\Reports\Visibility` is the one rule, used by both pages and all three exports. It reads permissions, never a role name, and the links as they are now:

- `requests.view-all`: everyone;
- otherwise the user's own requests and balance;
- with `requests.decide`, also those of the users linked to them now as supervisor (`supervisor_user`; links exist only to Approvers). A removed link hides that user at once. An Approver therefore sees their own requests and their subordinates' (including an HR Admin they supervise), and in the recap their own balance too ([ASSUMPTION]: "bawahannya" plus their own).

Filters can only narrow this: a forged employee or department in a Livewire call or an export URL returns fewer rows, never more. Filters are plain strings checked by shape in `App\Services\Reports\ReportFilters`; a value of the wrong shape counts as no filter, and anything but a string in a Livewire update is a 422.

### History

- Both request types in one UNION query with the requester, current department, category and decider joined (`App\Services\Reports\RequestHistory`), newest submitted first, 25 per page.
- Filters: employee and department (only offered to users who may see others; "No department" too), type, category (selects Leave; Overtime clears it), status, and a date range. **A request is listed when the dates it touches overlap the range** (leave: start to end; overtime: `first_date` to `last_date`, so overtime across midnight counts on both dates). Either end may stay open; a reversed range is read the right way round.
- The department is the requester's department now, like the supervisor relation, not the one at submission.
- **Detail links:** the user's own request opens My requests; a request the user may decide now, or decided, opens Approvals (`RequestPolicy::review()`); every other row opens the read-only page `/history/{type}/{id}` (Phase 12, see "Dashboard and read-only request page"). The history list itself never shows a reason, note or rejection reason.

### Recap

- **Leave balance** (`App\Services\Reports\BalanceRecap`): per visible user, 25 users per page, the latest real period (or every period with "All periods") plus the next period projected from the anchor date. Columns: entitlement, used, approved but not deducted yet (approved parts waiting for their period to open), in pending requests, and remaining = entitlement − used − approved not deducted yet. Pending days are shown apart and not subtracted. Parts are matched to a period by their first day, as on My requests. The recap only reads: it never opens a period.
- **By department and month** (`App\Services\Reports\DepartmentRecap`): for one year, per current department and month, the number of leave requests per category, overtime requests and overtime hours. **Approved only by default**; the status filter offers the other statuses and "All statuses". A request counts in the month it starts (leave by its start date, overtime by its start time) ([ASSUMPTION]), so overtime from 31 December 20:00 counts in December. Only months with a request are listed.
- Each tab reads only its own filters, so the employee filter of the balance tab does not narrow the department tab.
- **Query counts are fixed:** the balance runs three queries for any page (users with department, periods, open allocations), the department recap two grouped queries, history one UNION plus a count and one query for the detail links. `HistoryTest`, `RecapTest` and `ExportTest` compare the query count before and after adding users and requests.

### CSV export

- `App\Support\Csv`, no library: `response()->streamDownload()`, rows written as they are read (history through a cursor, the balance in batches of 200 users), UTF-8 with a byte order mark, CRLF line ends, `Content-Disposition: attachment`, `no-store`.
- **Separator:** `CSV_DELIMITER` in `.env` (default `;`, which Excel expects in Indonesian). Anything but one character other than a quote or a line break falls back to `;`.
- **Formula injection:** a cell starting with `=`, `+`, `-`, `@`, a tab or a carriage return gets a leading `'`.
- The rows are those the page shows for the same filters, read through the same services. Columns: type, request ID, employee name, department, category, start, end, working days, hours, status, submitted, decided by, decided; the balance and department recaps have the page's columns. Times are WIB as `Y-m-d H:i`, with "(WIB)" in the header; headers and labels follow the user's language. **Never exported:** reason, decision note, rejection reason, evidence, username, email.
- **Audit:** `report.exported` with the report (`history`, `leave_balance`, `requests_by_department`), the filters in effect and the number of rows, written after the last row; no row data. The stream ignores a closed connection (`ignore_user_abort`) so the count is always written.

## Dashboard and read-only request page

Built in Phase 12. No new table and no new permission; the migration `2026_11_10_100000_load_dashboard_texts` only loads the texts.

### Dashboard numbers

`App\Services\Reports\DashboardSummary` computes each number only for a user entitled to it, by permission, never by role name. A number the user is not entitled to is `null` and its card is not rendered. The existing dashboard sections (login summary, user accounts, security status, account information, recent logins) are unchanged; the new "Requests and leave" section sits above them.

| Card | Shown with | Source | Link |
|---|---|---|---|
| Annual leave available, current period (start and end), days held by pending requests | `requests.submit` | `LeaveRequests::slots()`, the same numbers as My requests (available = entitlement − used − pending) | none |
| My pending requests | `requests.submit` | own pending leave and overtime | `/history?status=pending` |
| My last decided request | `requests.submit` | own request approved or rejected most recently (`decided_at`) | My requests detail |
| Waiting for my decision | `requests.decide` | `Deciders::requesterIds()`, the inbox and menu rule of Phase 10, as the links are now | `/approvals` |
| Pending requests in the organization | `requests.view-all` | every pending leave and overtime request | `/history?status=pending` |
| Employees without a supervisor | `users.view` | `User::lackingSupervisor()`, the "No supervisor" filter of User Management (Employee and HR Admin, not suspended, no active supervisor) | `/users?supervisor=none` |

- A link is shown only when its number is above zero; zero shows a short sentence instead. A user with no decided request sees an empty state. No number is estimated or invented.
- **Fixed query count:** the pending counts are one query over a UNION of the pending rows of both tables (own, waiting and total as conditional sums), the last decision one UNION query, the "without supervisor" count one query, and the balance the same queries as My requests. `DashboardTest` compares the query count before and after adding users and requests, for an HR Admin, an Approver and an Employee.
- Like My requests, reading the balance runs `LeaveBalance::sync()`, which may open the user's due period (idempotent, as before).

### Read-only request page

| Page | URL | Who |
|---|---|---|
| Request (read-only) | `/history/{type}/{id}` | whoever sees the row in history (`RequestPolicy::viewDetail()`, the `Visibility` rule): their own, the users linked to them now when they hold `requests.decide`, everyone with `requests.view-all` |

- History links here only rows that My requests and Approvals do not open: for example an HR Admin looking at an Employee's request, or an Approver looking at a linked subordinate who is an Approver (decided by HR). A request the user may decide still opens in Approvals.
- The page shows the requester, department, role, status, dates or hours, reason, decision and allocations. It has **no decision buttons, takes no soft lock and does not poll**. Anyone else gets a 404, by URL and by Livewire; the route and `boot()` need `viewHistory`.
- **Evidence ([ASSUMPTION] from the owner):** only the requester and those who may decide the request (`RequestPolicy::downloadEvidence()`, unchanged) may download it. Someone who may only view sees "Attached" without the file name or a link, and the download URL answers 404.

### Recap filter width

The recap filters use two columns up to 1280px (`xl`), three above, so "Periode berjalan dan berikutnya" fits at 1024px.

## Request email notifications

Built in Phase 13. No new table and no new permission; the migration `2026_11_11_100000_load_notification_texts` only loads the texts. New audit event `request.notification_failed`.

| When | To | Email | Link |
|---|---|---|---|
| A leave or overtime request is submitted | everyone who may decide it now (`Deciders::of()`, filtered through `Deciders::allows()`): the active Approvers linked to an Employee or HR Admin, every active HR Admin for an Approver | `RequestSubmittedMail` | `/approvals/{type}/{id}` |
| A request is approved or rejected | the requester, if still ACTIVE | `RequestDecidedMail` | `/requests/{type}/{id}` |

- **Content:** type (Leave or Overtime), category for leave, dates or WIB hours with working days or hours, the requester's name (submitted email only), the outcome (decided email only) and a button. **Never:** the reason, the decision note, the rejection reason, the evidence or its file name, the decider's name. The request is a protected property of the mailable, so the view gets only these values.
- **Language:** the recipient's `users.locale` (`User::preferredLocale()`), for subject, HTML and text part; dates are formatted inside that language. All texts are in English and Indonesian. The mail footer "All rights reserved." is now translated too (it also appears in the account emails).
- **Links use `APP_URL`**, never the host of the current request ([ASSUMPTION] from the owner).
- **Markdown injection:** `Markdown::withSecuredEncoding()` is on (AppServiceProvider), so a value echoed into a Markdown email (for example a requester's own name such as `[x](https://…)`) cannot become a link or HTML. This applies to the account emails as well; their text is unchanged.
- **Timing:** synchronous, never queued (the account email decision), called after the business transaction returned, so after its commit. A slow SMTP server makes that request wait up to `MAIL_TIMEOUT` per recipient; an Approver's request with many HR Admins waits for each in turn.
- **Failure:** never undoes or fails the request or the decision. `MailDelivery` logs the mailable and exception class only; any other error while notifying (for example a database error while finding the deciders) is caught and logged by class only. Each failure is audited as `request.notification_failed` with `notification` (`submitted` or `decided`) and `recipient_id` (null when no recipient was reached), the requester or decider as actor and the request as subject. The page shows a warning toast next to the success toast ("…but an email to a decider could not be sent…", "…but the email to the requester could not be sent."), never a claim that the email was sent. `RequestNotifications` is bound as scoped so the page reads the failures of its own HTTP request.
- **Not sent ([ASSUMPTION] from the owner):** no email for a cancellation, no opt-out setting. Agent's choice: no decision email to a requester who is no longer ACTIVE (they cannot sign in to open the link).
- **Tests and race helper:** the suite uses `Mail::fake()` and the `array` mailer (phpunit.xml). `tests/Support/race.php` forces the `array` mailer in every competitor process, because those processes read `.env`, and reports how many emails each sent. The browser-check setup scripts force the `log` mailer in their CLI process for the same reason.

### SMTP settings (.env)

| Variable | Meaning |
|---|---|
| `MAIL_MAILER` | `smtp` for real delivery (`log` writes whole emails to `storage/logs/laravel.log`, local only) |
| `MAIL_SCHEME` | `smtps` for implicit TLS (port 465), empty or `null` for STARTTLS/plain (587, 25) |
| `MAIL_HOST`, `MAIL_PORT` | the provider's SMTP server and port |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | the SMTP login (often an app password) |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | the sender; must be an address the provider lets this login send from |
| `MAIL_TIMEOUT` | seconds a page waits for the server per email (default 10) |
| `APP_URL` | the address used in every email link (`http://hr-system.test` locally) |

Run `php artisan config:clear` after changing `.env` if the configuration was cached.

## Phase 14: final decisions and verification

Built and verified in Phase 14 (DECISIONS.md section 11). Migrations `2026_11_12_100000_add_system_reason_to_requests` (nullable `system_reason` on both request tables) and `2026_11_12_100100_load_verification_texts`. No new permission, route or audit event.

### Final decisions
- Sick leave follows the same "today (WIB) or later" rule as every category (it already did; now tested). HR entering a past sick leave is backlog.
- An approved or rejected request cannot be cancelled, changed or decided again (already enforced; now tested together). Both decision dialogs say: "This decision is final. It cannot be undone or changed later." (English and Indonesian).

### Automatic rejection on suspension
- `User::suspend()` rejects every pending leave and overtime request of the user in the same transaction (`App\Services\Requests\SuspensionRejections`). A refused suspension (guard, last HR Admin, already suspended) rejects nothing.
- Each rejected row: `status = rejected`, `decided_by = null`, `decided_at = now`, `decision_note = null`, `system_reason = 'account_suspended'`, soft lock cleared. Shown everywhere as decided by **"System"** with the fixed reason "Rejected automatically because the account was suspended." in the viewer's language (`HasDecision::deciderLabel()` / `decisionText()`); history and its CSV say "System" too.
- Approved, rejected and cancelled requests are untouched; requests of the user's subordinates are untouched (backlog). The status change releases a leave request's pending allocation (only pending requests count against a period); `used` and the ledger do not change. Reactivation restores nothing. No email is sent.
- Audit: `user.suspended` now carries `leave_requests_rejected` and `overtime_requests_rejected`; each request gets a `leave_request.rejected` / `overtime_request.rejected` row with the suspending HR Admin as actor and `system_reason` in the metadata.
- The suspend dialog says how many pending requests will be rejected ("This user has no pending requests." when none); the success toast gives the number actually rejected. The count is a locked Livewire property.
- **Lock order.** Suspension: HR Admin role row exclusive → user row → plain read of the pending requests (no row lock needed). Decision and cancellation: role row **shared** → request row → user row. Submission: user row → new request row. The role row keeps suspensions apart from decisions and cancellations; the user row keeps them apart from submissions. Two real deadlocks were found by the race test and fixed on the way: locking the pending rows through the `user_id` index deadlocked with a cancellation, and so did locking them by primary key, because a cancellation's status update takes a shared lock on the user row (foreign-key check, see "Lock order" under Approvals). The fix is the shared role lock in `Submission::cancel()`.
- Race tests (`ApprovalConcurrencyTest::test_suspension_against_*`, separate processes, committed rows): suspension against two approvals (both orders occur through start offsets; the winner's outcome is consistent, no balance charged for a system rejection, the loser sees "already decided … by System") and suspension against a submission, a second submission and a cancellation (nothing left pending, no deadlock).

### Hardening found in Phase 14
- `config/filesystems.php`: the `local` disk is no longer served (`serve => false`). Laravel registered signed `GET`/`PUT /storage/{path}` routes for it, unused, and its root holds the evidence folder. Signed URLs were required (403), so nothing was exposed; the routes are now gone (`RequestEvidenceTest::test_no_disk_serves_files_by_url`).

### Authorization matrix
`tests/Feature/AuthorizationMatrixTest.php` tries every page route by URL and mounts every Livewire page component directly (what a manipulated Livewire request reaches), as a guest and as each role, and fails on any difference from this table. 302 = redirect to login, 403 = forbidden, 404 = not found (used where the existence of a record must not leak).

| Route | Guest | Employee | Approver | HR Admin |
|---|---|---|---|---|
| /dashboard, /requests, /history, /recap, the three CSV exports, /profile, /profile/sessions, /profile/login-history, /security | 302 | 200 | 200 | 200 |
| /users, /users/{id}, /users/{id}/leave-balance, /departments, /holidays | 302 | 403 | 403 | 200 |
| /approvals | 302 | 403 | 200 | 200 |
| /approvals/{type}/{id} of a subordinate's request (Approver supervises it) | 302 | 403 | 200 | 404 |
| /approvals/{type}/{id} of an unrelated request | 302 | 403 | 404 | 404 |
| /requests/{type}/{id} own request (others: 404) | 302 | 200 | 404 | 404 |
| /requests/{type}/{id}/evidence own (decider may too) / unrelated | 302 | 200 / 404 | 200 / 404 | 404 / 404 |
| /history/{type}/{id} own or subordinate / unrelated | 302 | 200 / 404 | 200 / 404 | 200 / 200 |
| /login | 200 | 302 | 302 | 302 |

Representative forged actions are refused on the server: HR Admin adjusting their own balance (403), deciding an unrelated or own request by ID (404), opening another user's request (404). An Approver's request is decided by any HR Admin; an HR Admin's request only by their supervisor. This matches DECISIONS.md sections 3, 7 and 9.

## Phase 15a: redesign foundation

Branch `ui-redesign` only. No database migration, no new package.

### Reference and licence
- Design reference: [satnaing/shadcn-admin](https://github.com/satnaing/shadcn-admin) (MIT), read at commit `e16c87f` from a clone outside the project (`C:\laragon\tmp\ref\shadcn-admin`); nothing in it was installed, built or run, and no file was copied in. Files read: `src/styles/theme.css` and `index.css` (tokens), `components/ui/*` (button, card, badge, input, table, alert, dialog, dropdown-menu, avatar, sidebar, sonner), `components/layout/*` (sidebar, nav group, nav user, header, main) and the dashboard page.
- Credit and the licence text (copyright line read from the repository's `LICENSE`, compared with `diff`) are in `THIRD-PARTY-NOTICES.md`.
- Icons stay the project's own `x-ui.icon` paths. Nine were added (panel-left, chevrons-up-down, log-out, user, shield, info, alert-triangle, check-circle, x-circle), drawn for this project, not taken from the reference or Lucide.

### Tokens and font
- `resources/css/app.css` defines the shadcn token set as CSS variables on `:root` (`background`, `foreground`, `card`, `popover`, `primary`, `secondary`, `muted`, `accent`, `destructive`, `border`, `input`, `ring`, `sidebar-*`, `--radius` and its sm/md/lg/xl scale) and maps them to Tailwind colours with `@theme inline`, as the reference does. Light theme only; a dark theme is one more block of the same variables under a selector.
- Primary: Tailwind blue-700, a **placeholder** until the brand colour is supplied (`design/brand/` does not exist yet). (Superseded: the brand blue `#2370B5`, see "Brand, dialogs and forms".)
- Deliberate differences from the reference, each measured (see Checks): `--muted-foreground` slate-600 instead of slate-500 (4.35:1 on muted), `--input` slate-500 instead of the border colour (field borders at 1.23:1), `--ring` blue-600 used as a solid 2 px outline instead of a 50% halo (2.20:1). Added `--success` and `--warning` for status dots.
- Font: IBM Plex Sans (400, 500, 600, 700) through the existing `bunny()` font option of `laravel-vite-plugin`, which downloads the files at build time and serves them from `public/build`; no package was added. Before this phase `main` used Instrument Sans the same way. The reference uses Inter and Manrope.

### Layout shell
- `layouts/app.blade.php` follows the reference's "inset" sidebar variant: the page sits on the sidebar colour and the content is a raised panel (`md:m-2 rounded-xl shadow-sm`).
- Desktop (from 768 px, the reference's `md`): a 16 rem sidebar that folds to icons (3 rem plus padding). The state is the attribute `data-sidebar-state` on `<html>`, set by an inline script from `localStorage['sidebar-collapsed']` before the first paint, inside `try/catch`; without storage the sidebar starts open and folding still works for the page. A Tailwind custom variant `sidebar-collapsed:` (only from 48 rem) styles the folded state, and an Alpine store `sidebar` toggles it (header button, the rail along the edge, Ctrl/Cmd+B). Folded labels become `sr-only` (screen readers keep the names) and a tooltip anchored with Alpine's `x-anchor` names the link on hover and focus.
- Below 768 px the menu is the existing drawer (same dialog, focus trap and close button), now 18 rem wide.
- Menu groups follow the existing menu: General (Home, My requests, Approvals), Reports (Request history, Recap), Administration (User Management, Departments, Holidays). A group is rendered only when one of its entries is allowed; every page still checks its permission on the server.
- The user block at the bottom of the sidebar (NavUser) opens a menu with name, email, role badge, My Profile, Security Settings and Logout. The header keeps a profile menu (avatar) on the right, as on the reference dashboard. Breadcrumbs show only the current page below 640 px.
- Logo: `x-ui.logo` shows `public/brand/logo.svg` or `logo-icon.svg` when they exist (with the company name as alternative text) and the company name as text otherwise; the favicon link is added only when `logo-icon.svg` exists. The company name is `config('app.company')`. No logo was drawn.

### Page container
- `x-ui.page` (`title`, `subtitle`, `actions`, `width` = default 80 rem, narrow 32 rem, full) is the only place that sets a page's width, centring (`mx-auto`) and padding. `<main>` in the layout has none. Every authenticated page was migrated: the 16 Livewire pages and the 5 controller pages under Security (narrow). The old `security/partials/page-header` was removed. The page contents were not changed.
- Livewire needs one root element: `x-ui.page` renders one `<div data-page>`; the approval page keeps its own root `<div>` because its root carries the conditional `wire:poll` and the release beacon.

### Components
- Restyled with the same names and properties, so every view keeps working: button (variants primary, secondary, danger, ghost, link, plus muted; sizes sm, md, plus lg and icon), input, select, textarea, error, card (plus `description`), badge (plus tones primary, teal, solid, outline), table, alert (now a block with an icon and an optional `title`; `info` is neutral), modal (plus `description`, `max-w-lg`), dropdown (plus `align`, `width`), dropdown-item (plus `icon`, `variant="destructive"`), toast, empty-state, stat (plus `icon`), detail, breadcrumbs, avatar, nav-link (plus `tooltip` and a `badge` slot).
- CLAUDE.md lessons kept: dialogs are always rendered and only shown or hidden; no `@js()` inside a component attribute (the gallery passes toast texts through `data-*` attributes); toasts no longer block buttons: on phones they sit under the header (dialogs open from the bottom there), from 640 px at the bottom right, and a toast's body has `pointer-events: none` so a click reaches the button under it; only its close button takes clicks.

### Palette for roles and statuses
- Statuses keep green (success), amber (warning) and red (danger); "info" is neutral; the primary blue is for actions and links only.
- Roles move away from both: HR Admin violet (`purple` tone, was blue), Approver teal (was purple), Employee slate (`gray`, was green, which matched "Active"). This is a proposal for the owner. Every badge text measures 6.7:1 or more on its background.

### Component gallery
- `GET /ui-kit` (`UiKitController`, inside the logged-in group) shows every base component. The controller aborts with 404 unless `app()->isLocal()`, so a cached route list cannot expose it. It is in no menu. Tests: `UiKitTest`.

### Checks
- `C:\laragon\tmp\browser-check\layout.mjs`: every authenticated GET route as the zz-check HR Admin at 375, 768, 1024, 1280 and 1920 px (and folded at 1024 to 1920): no horizontal scroll; the container's left and right margins equal within 1 px when the content area is wider than its maximum; no visible element past the screen edge unless a scrolling parent clips it; no console error; every control in the page, scrolled to the middle, is the top element at its centre (not covered by a fixed or sticky element); at 1280 px the WCAG contrast of every visible text (colours read through a canvas, so oklch is handled). Screenshots in `design/screens/before` and `after` (git-ignored).
- `contrast.mjs`: WCAG ratios computed from the oklch token and palette values for buttons, links, badges, alerts, status dots, field borders and the focus outline.
- `p15-interactions.mjs`: clicks every new control (fold, tooltip, reload, Ctrl+B, both menus, the drawer and its menu, a user row menu, a decision dialog at 375 px, the gallery dialog, toasts and their click-through), and runs once with `localStorage` throwing.

## Phase 15b: form components

Branch `ui-redesign`. No new package. One migration, `2026_11_13_100000_load_redesign_texts`, runs the idempotent `TranslationSeeder` (the 24 texts of 15a, which had no migration, and the 15b texts); no schema change.

### Reference and Alpine plugins
- Read again from the same clone (`e16c87f`): `components/ui/select.tsx`, `popover.tsx`, `command.tsx`, `calendar.tsx`, `input-otp.tsx`, `components/date-picker.tsx` and `data-table/faceted-filter.tsx` (the multi-select filter: checkbox items, "N selected" badge, clear). Rewritten as Blade and Alpine; nothing copied.
- Livewire 4.4.8 bundles Alpine with the anchor (`x-anchor`, Floating UI with flip and shift), focus (`$focus`, `x-trap`), collapse, intersect, mask, sort and resize plugins. The components use `x-anchor` with the `.fixed` strategy and `$focus`; no plugin was added.
- Icons added to `x-ui.icon`: `search`, `check`, `chevron-left` (own paths).

### Components (`resources/js/ui.js`, `resources/views/components/ui/`)
- `x-ui.select`: one component for the plain select, the searchable select (combobox) and the multi-select. Properties: `label`, `id`, `name`, `options` (`value => label`, or a list of `value`, `label`, `hint`, `disabled`, `lang`), `multiple`, `searchable` (default: more than 8 options), `remote` (a Livewire method name for a server search), `labels` (names of chosen values a remote list does not hold), `value`, `placeholder`, `hint`, `disabled`, `summaryAfter` (default 2: then the button says "N selected"), `chips` (default 3, then "+N more"), `selectAll`. The old `<option>` slot API is gone; every caller passes `options`.
- ARIA: without a search box and single, the APG "select-only combobox" (the button has `role=combobox`, `aria-activedescendant`, focus stays on it). With a search box or several values, the button opens a `role=dialog` popover holding a `role=combobox` input (`aria-autocomplete=list`) and the `role=listbox` (`aria-multiselectable` for several values; without a search box the listbox itself takes focus). Keys: arrows, Home/End, PageUp/PageDown, Enter, Space (choose or toggle), Escape (close, focus back on the button, the event stops so a surrounding dialog stays open), Tab (close), type-ahead on lists without a search box (700 ms buffer, so "17" reaches 17:00). The label is a `<label for>` on the button and also its `aria-labelledby`; hint and error are its `aria-describedby`.
- Chips under a multi-select each have a remove button ("Remove :name") and there is a "Clear" link; the panel has "Select all" (local lists only) and "Clear". A live region announces the count.
- `x-ui.date-picker`: a button and a calendar in a `role=dialog` popover. Monday first; month, weekday and button texts from `Intl.DateTimeFormat` in `<html lang>`; values are `yyyy-mm-dd`, and all date arithmetic is done on UTC dates built from those strings, so the browser's zone never shifts a day. **"Today" is rendered by the server** (`today()` in WIB, `data-today`) and used for the first month shown and the today mark; the browser clock is never read. `min`, `max`, `disable-weekends` and `disabled-dates` (for example holidays) make days unavailable (`aria-disabled`, still focusable, not choosable). Grid keys as in the APG date picker dialog: arrows by day and week, Home/End to Monday/Sunday, PageUp/PageDown by month, with Shift by year, Enter/Space to choose, Escape to close. Range mode (`range`, `start-name`/`end-name` for a form, `wire-start`/`wire-end` plus `live` for Livewire): the first click sets the start, the second the end (swapped if earlier); closing after one date keeps an open end; one Livewire request sets both properties (`$wire.$set(a, x, false)` then `$wire.$set(b, y, true)`).
- `x-ui.hour-select`: `x-ui.select` with the 24 values `00:00` to `23:00`; minutes are never offered. `x-ui.date-hour` puts a date picker and an hour select side by side as one fieldset (overtime start and end). Server validation of overtime is unchanged.
- `x-ui.otp-input`: one real `<input>` (`autocomplete=one-time-code`, `inputmode=numeric`, `pattern=[0-9]{6}`, `maxlength=6`) laid transparently over six boxes that only display its digits, the active box outlined and a caret shown in it. Typing, paste, delete and SMS autofill are the browser's own; Alpine strips non-digits, keeps the caret at the end and dispatches `otp-complete` when six digits are in. Error (`aria-invalid`, `aria-describedby`) and disabled states. Pure Alpine. In 15b it is only in the gallery. (15c: the auth pages use a plain-JavaScript variant with the same markup, `script="vanilla"`, because public pages load no Alpine.)
- Two modes for every component: `name` renders hidden inputs (a list as `name[]`), and `wire:model` on the component binds the Alpine value through `x-modelable` (modifiers such as `.live` work, arrays included). Option lists and labels are JSON in `data-options` / `data-labels` on the component's root; a `MutationObserver` copies them into Alpine when a Livewire update morphs them, so server changes reach an open page.
- Placement: panels use `x-anchor.fixed.bottom-start.offset.4` (flip and shift with 5 px padding), stay inside the component's DOM (so a dialog's focus trap still contains them) and, being `position: fixed`, escape any `overflow` box. Their width is at least the button's and at most the screen minus 16 px. Not teleported to `<body>`: a teleported panel would sit outside a dialog's trap.

### Lists built on the server
- Small lists (roles, statuses, types, categories, departments, years, languages) are rendered in full and filtered in the browser.
- Large lists are never rendered: the employee filter of history and recap calls `searchEmployees` (Visibility::search: only people the user may see, by name or username, 20 at most, `LIKE` with escaped wildcards, nobody for a user who sees only themself), and the supervisor dialogs on a user's page call `searchCandidates` / `searchTargets` (active Approvers, 20 at most, `manageSupervisors` authorized in the method, so a forged call is 403). They are `#[Renderless]` Livewire actions whose return value reaches Alpine (`$wire.method(term)`), called 300 ms after the last keystroke; out-of-order answers are dropped. Not `#[Json]`: Livewire's Json methods catch every exception and answer 200 with `null`, so a refused authorization would not be a 403. The page itself only holds the names of the people already chosen (`Visibility::names`, visibility applied). `Visibility::users()` (every visible user, for the old `<select>`) was removed.

### Filters with several values
- Users (role, status), history (employee, department, type, category, status), recap (employee, department) and approvals (type) hold lists, kept in the URL with `#[Url]` (`?status[0]=approved&status[1]=pending`). Values inside one filter are combined with OR (`whereIn`), filters with AND. Single choices stay single (users: supervisor, rows per page; recap: periods, year, status; my requests: status; holidays: year).
- Shared trait `App\Livewire\Concerns\HasArrayFilters`: an update must be a list of at most 50 strings of at most 100 bytes, or one such string at a numeric index (Livewire sends `status.0` when a list keeps its length); anything else is 422, like every other forged property. Values the page does not offer are dropped before the query (`keepOffered`), which also cleans the URL. Employee IDs are only checked for their shape (`keepIds`): an ID the user may not see stays and matches no row, so a forged value narrows the list and never widens it (the rule of `ReportFilters` since Phase 11).
- Old links with one value (`?role=employee`, `?status=approved`) keep working: Livewire cannot put a string into an array property and silently drops it, so the trait's `mount` hook reads such a value and makes it a list of one. Exports accept the list or the old single string.
- `ReportFilters` now holds lists (`employees`, `departments`, `types`, `categories`, `statuses`) with `applyEmployees`, `applyDepartment` (IDs or "No department"), `applyStatuses` and `wantsType`; at most 50 values per filter are read. The department recap counts Approved when no status was chosen, as before (`recapStatuses()`). Export links and the export audit row carry the lists.
- History keeps its rule that a category belongs to leave: choosing a category sets the type to leave, and choosing a type other than leave alone drops the categories.

### Pages changed
- Users (filters; role in the create and change-role dialogs), user detail (department, join date, add supervisor, move subordinates), leave balance (anchor date), my requests (status filter; leave category, start and end dates with weekends and holidays from the server greyed out; overtime start and end as date + hour), approvals (type), history (all filters, one date-range picker instead of From/To), recap (all filters), holidays (year; date), profile (language). No native `<select>`, date or time input is left in the views.
- Holidays: the year property is set to the year shown, so the select shows it when none was chosen.

### Gallery
- `/ui-kit` gained "Form components" (a plain GET form whose submitted values are printed back), "Form components with Livewire" (`App\Livewire\UiKitDemo`, local only like the page: 404 elsewhere, also for its Livewire updates; its server search runs over 100 made-up names, never users), a select inside an `overflow: hidden` box, and a select and a date picker inside the gallery dialog.

## Phase 15c: auth pages

Branch `ui-redesign`. No new package, no schema change. One migration, `2026_11_14_100000_load_auth_page_texts`, runs the idempotent `TranslationSeeder` for the 40 new texts. Controllers, routes, validation, throttles, error messages, sessions and field names are unchanged.

### Reference
- Read from the same clone (`e16c87f`): `features/auth/auth-layout.tsx`, `sign-in/index.tsx`, `sign-in/sign-in-2.tsx` (two panels), `sign-in/components/user-auth-form.tsx`, `otp/`, `forgot-password/` and `components/password-input.tsx`. Rewritten as Blade and plain JavaScript; nothing copied. The reference's screenshot image on the second panel is replaced by the logo and one translated sentence on the primary blue, with a dot grid drawn in CSS (no image file).
- `design/brand/` still does not exist: the logo is the company name as text (`x-ui.logo`), on a white plate so a future coloured logo file stays readable on the blue; the primary colour is the 15a placeholder. (Superseded: see "Brand, dialogs and forms".)

### Layout (`layouts/auth.blade.php`)
- From `lg`: a two-column grid. Left (`#main`, light muted background): the form in a card (`[data-page]` `max-w-md mx-auto`, inner `[data-card]` rounded-2xl, border, soft `shadow-xl shadow-slate-900/5`), a help line ("Need help? Contact your HR Admin.") and a small footer (© year, company, app name). Right: the brand panel (`data-auth-panel="brand"`): a soft gradient from `--brand-from` (primary) to `--brand-to` (a deeper blue, tokens in `app.css`), a line grid fading downwards and four thin concentric arcs drawn in inline SVG (no image file), the logo on a white plate, the app name, one sentence, three product points with icons (leave and permission, overtime, approvals) and a line on two-factor protection. Below `lg`: one column, the logo above the card (`data-auth-panel="logo"`), the card top-aligned on phones and centred from `sm`. Print hides everything but the card.
- Headings (`x-ui.auth-heading`): centred, an icon badge (primary tint; warning/danger tones for the 410 page), `text-2xl` title, muted subtitle. Inputs take an optional start icon (`x-ui.input icon=…`: user, mail, key; `x-ui.password-input` has a lock by default). The main button is `x-ui.submit-button`: soft hover shadow, pressed offset, and a loading state (spinner, `aria-busy`) while the form is sent.
- Motion: only the card enters (opacity and 0.5 rem rise, 0.3 s), with Tailwind `motion-safe:`; nothing moves under `prefers-reduced-motion`. The brand panel is not animated: in headless Chrome its text stayed faded in screenshots after an entrance animation (computed opacity 1; a compositor artefact), and a static panel avoids the risk.
- Loads `resources/css/app.css` and **`resources/js/auth.js` only**: no `app.js`, no Livewire, no Alpine on the public pages (`AppLayoutTest` and `AuthPagesTest` check it). `auth.js` is a separate Vite entry (about 4.6 kB, 1.7 kB gzip).
- `<meta name="referrer" content="no-referrer">`: the 409 and 410 pages are rendered at the token URL (`/activation/{token}`); before this phase their CSS, script and link requests carried that URL in `Referer` (same origin, so Chrome's default policy sends the full URL), which ends up in the web server's access log. The request line itself is still logged by the web server (outside the application; see Known issues in PROGRESS.md).

### One-time code layout (`.otp-*` in `resources/css/app.css`)
- Two groups of three boxes with a short separator, after the reference's input OTP. The field is a size container: the box width is `clamp(2.25rem, (100cqw - 1.5rem) / 6, 2.75rem)` and the height 1.15 × that, so the row fits the card at 320 px (37 px boxes) and is 44 px from 375 px; the row is `width: max-content; margin-inline: auto`, so it is centred in the card. The label, hint and error are centred with it.
- Each digit is a flex-centred span with `line-height: 1` and tabular figures. The native input lies over the row, transparent, in a monospace font with `letter-spacing: box - 1ch` and `text-indent: half of that`, so its own characters fall on the same pitch; its caret and selection are transparent (the boxes draw a caret). In forced-colours mode the boxes are hidden and the input is shown as a plain field.
- States: active box (ring colour border and 2 px ring, on top), filled (`data-filled`: darker border, muted fill), error (`data-otp-invalid` on the field: destructive borders and ring), disabled (`data-otp-disabled`: 50 % and not-allowed). Without the script the boxes stay hidden and a plain centred field is shown.
- Measured by `layout.mjs` (`otpMeasureHere`) on the challenge, the forced enrollment and `/security/confirm` at 320, 375, 768 and 1280 px: every digit's glyph box (a `Range` over its text) against its box, and the row's left and right gaps inside the card.

### Plain-JavaScript components (`resources/js/auth.js`)
- Started on `DOMContentLoaded`, by data attributes; texts come translated from Blade in `data-*` attributes. It stores nothing (no `localStorage`, `sessionStorage`, cookies), sends nothing (no `fetch`, beacon, XHR) and logs nothing; `AuthPagesTest` checks the source.
- **One-time code**: `x-ui.otp-input script="vanilla"`. Same markup and classes as the Alpine variant of 15b (which now renders through the same template, with `data-enhanced` from the start). Without the script, the boxes stay `hidden` and the native input is an ordinary visible field (`pattern`, `maxlength`, `inputmode=numeric`, `autocomplete=one-time-code`), so the form works without JavaScript. The script sets `data-enhanced` on the root, after which the `.otp-*` styles lay the input transparently over the boxes. It cleans non-digits on `input`, cleans a pasted code before `maxlength` cuts it (`123 456`, `12-34-56`), keeps the caret at the end, marks the active box (`data-active`) and dispatches `otp-complete`.
- **Show/hide password**: `x-ui.password-input` (label, optional `action` slot for the "Forgot your password?" link, hint, error, `described-by`). The button is `type=button`, `hidden` until the script runs, `aria-controls` the field, and changes its `aria-label` (Show password / Hide password); focus returns to the field. A shown password is turned back to `type=password` on submit.
- **Code / recovery code switch** (`data-auth-switch`): two panels, one shown at a time; the links are anchors to the other panel, so without JavaScript both panels are simply visible. After a recovery-code error the server sets `data-switch-initial="recovery"`. On `/security/confirm` (one form with both fields, where the server refuses both at once) `data-disable-hidden="true"` disables the hidden panel's field so only one is sent.
- **Submit state**: every form with an `x-ui.submit-button` gets `data-submitting` on its first submit (a second one is cancelled) and the button `data-loading` / `aria-busy`; a page restored from the back/forward cache is reset (`pageshow`).
- **Recovery codes** (`data-recovery-codes`): copy, download and print buttons, `hidden` until the script runs. Copy uses the Clipboard API only in a secure context (HTTPS or localhost); on `http://hr-system.test` it is not available, so the codes are selected and the page says to press Ctrl+C (or Cmd+C). Download builds a `.txt` (title, note, the ten codes, CRLF) as a `Blob` in the browser, `<app>-recovery-codes.txt`; no request is made. Print calls `window.print()`; print styles show the heading, the codes and the warning only. The checkbox "I have saved my recovery codes" sets `aria-disabled` on the continue link until ticked (a click before is cancelled and focuses the box); without JavaScript the link works as before.

### Pages
- Login: identifier (`autocomplete=username`, never filled back after a failure, as decided in Phase 2) and password (`current-password`) with show/hide and the "Forgot your password?" link beside its label.
- Two-factor challenge: the six-box code (required), then "Use a recovery code" to the separate recovery form (`recovery_code`, unchanged route); "Cancel and go back to login".
- Forced enrollment and the activation's second step: step indicator (`x-ui.steps`, "Step x of y", `aria-current=step`), the local QR code (server-rendered SVG, shown at 176–192 px), the manual key (`data-manual-key`, as before), the six-box code.
- Activation: steps Password → Authenticator → Recovery codes; password and confirmation with show/hide (`new-password`) and the rules of `Password::defaults()` as a hint linked by `aria-describedby` (text only; the server decides). The recovery code page knows its step from the route (`activation.two-factor.store` 3 of 3, `two-factor.enrollment.store` 2 of 2; none after regeneration in Security).
- Recovery codes: the codes stay plain `<li>` items (tests read them), the warning "Shown once" / "Single use", the tools and the checkbox. `Cache-Control: no-store, private` is still set by the three controllers that render the page.
- Forgot password, reset password (`email`, `new-password` ×2, rules hint), invitation not valid (410) and logged-in browser (409), with an icon badge in the heading (`x-ui.auth-heading icon=…`; it also takes `level` for a second heading on a page).
- `/security/confirm` stays in the app layout and loads `auth.js` itself (`@vite('resources/js/auth.js')`): password with show/hide, the six-box code (not required there), and the switch to the recovery code. Alpine on that page is untouched.
- Icons added (own paths): `eye`, `eye-off`, `copy`, `printer`, `log-in`, `lock`, `mail`, `key`, `link-off`.

### Checks
- `AuthPagesTest` parses the rendered pages (PHP 8.5 `Dom\HTMLDocument`) and sends every form as a browser without JavaScript would (the rendered fields and values only): activation → login with a code → login with a recovery code, forced enrollment, forgot → reset, and the confirmation page. It swaps Vite for a fake that writes the entry names, so the "only `auth.js`" check needs no build.
- Browser: `C:\laragon\tmp\browser-check\p15c.mjs` (flows and component behaviour, at 1024 and 375 px, also with JavaScript turned off) and `layout.mjs … public <prefix>` (every public page and the confirmation page at 375, 768, 1024, 1280, 1920 px: no horizontal scroll, nothing outside the screen, no covered control, no empty panel, symmetric margins of the form column, contrast of every visible text, against every colour stop of a gradient background, the worst one counted; plus the code row measurements). Test accounts come from `p15c-setup.php` (log mailer only).

## Brand, dialogs and forms (after 15c, before 15d)

Branch `ui-redesign`. No new package, no migration, no new text. DECISIONS.md section 1 now names the style: brand blue from `design/brand/`, light theme only, shadcn-admin (MIT) as the reference.

### Brand files
- `design/brand/` holds one file, `logo.jpg` (851 × 851 px): the round blue mark with a white "p" on a patterned grey square, with the curved lettering "Solusi Peranti Digital". **No colour code and no SVG** were supplied.
- The mark is cropped into `public/brand/logo-icon.png` (256 px) and `favicon-32.png` (32 px): a circle of radius 245 px around (427, 382), just inside the blue edge, with an anti-aliased transparent outside (4 × 4 samples per pixel), by `C:\laragon\tmp\browser-check\brand-crop.php <logo.jpg> <out.png> <size>` (PHP GD; outside the repository like the other check tools). No logo was drawn or traced.
- The curved lettering is not used: at 32 px it cannot be read. `x-ui.logo` (full) is the mark plus `config('app.company')` as text; the mark there has `alt=""` (the text names it). `x-ui.logo variant="icon"` is the mark alone with the company name as `alt`, or `alt=""` with `decorative` (the sidebar, where the link text names it). Without the file both fall back to the text of 15a.
- The lettering reads "Solusi Peranti Digital", the configured company name "PT Peranti Digital Solusindo"; the name was not changed.
- Favicon: `layouts/partials/favicon.blade.php` (PNG 32 px and the 256 px mark as `apple-touch-icon`), included by both layouts, only when the file exists. In the auth layout it comes **after** the `no-referrer` meta, so the icon request from a token URL carries no `Referer` (tested: the policy must precede the first `link`, `script` or `img`).

### Colour
- Primary `#2370B5` = `oklch(0.534 0.131 249.907)`: the average of the 68,402 pixels of the mark's flat blue (within 8 of `#2070B0` per channel), measured by script (`brand-color.php` converts and measures). White on it 5.18:1, the light foreground 4.95:1.
- `--primary-hover` `#1D5C96` (`oklch(0.466 0.113 250.28)`, 6.95:1 on white), a deeper step of the same hue. Hovered and loading buttons use it instead of `primary/90`, which lightens the blue under 4.5:1 for the white label. Blue text on a light tint (badges, notices) uses it too.
- `--ring` is the primary (5.18:1 on white, above the 3:1 for a focus indicator). `--brand-to` `#15406A` (`oklch(0.365 0.086 251.05)`).
- The 15a pages still had literal Tailwind blues (links `text-blue-700`, tabs `bg-blue-700`, notices `bg-blue-50`, checkboxes, the profile menu): all moved to the tokens (`text-primary`, `hover:text-primary-hover`, `bg-primary`, `border-primary/20 bg-primary/5 text-primary-hover`, `accent-primary`). No `blue-*` class is left in the views.
- Brand panel of the auth pages: its secondary texts were `text-white/85`, which measures 4.23:1 on the lighter end of the new gradient (the layout checker found it); now `text-white` (5.18:1).

### Dialogs and forms after the auth pages
- `x-ui.modal`: the auth card's look (rounded-2xl, soft `shadow-xl shadow-slate-900/10`, the same 0.3 s entrance with `motion-safe:` only), a footer band (`border-t bg-muted/40`), and two optional properties: `icon` (an `x-ui.icon` name) and `tone` (`default` primary tint, `danger`, `warning`), drawn as the auth heading's badge beside the title. The badge is `aria-hidden`; the dialog is still named by its title. The dialog stays always rendered (CLAUDE.md lesson). Every dialog got an icon: confirmations of something final or destructive use `danger` (reject, delete, suspend), reversible warnings `warning` (cancel a request, reset a period, end sessions), forms the default tone.
- `x-ui.button`: the filled variants (primary, danger) take the submit button's hover shadow and 1 px pressed offset (no offset under reduced motion). The `loading` property (Livewire: disabled plus a spinner while the action runs) is unchanged and now verified in a browser with a delayed request.
- `x-ui.card`: the auth card's soft shadow, lighter (`shadow-md shadow-slate-900/[0.04]`) since a page holds several.
- Input icons (`x-ui.input icon=`) on the app's text fields: user (names, usernames), mail (emails), search (users search), building (department name).
- Security → Change password and Profile → Change username or email are controller pages like the confirmation page: they now load `auth.js` and use `x-ui.password-input` (show/hide) and `x-ui.submit-button` (loading state, one submit). Field names, `autocomplete` values and the controllers are unchanged; without JavaScript the forms are as before (tested by sending the rendered form).

### Row menus clipped by the table (defect found)
- An anchored `x-ui.dropdown` (the row menus of User Management) was placed by `x-anchor` with the default absolute strategy inside the table's `overflow-x-auto` box. With few rows the menu reached below the table and was **clipped**: its lower items could not be clicked (the click landed on the "Rows per page" select under it). This existed since 15a; it shows with one or two rows (the owner's list has three). Fixed with `x-anchor.fixed`, as the 15b panels already do; tested in a test and in the browser.

### Tests
- `Livewire::flushState()` moved from `AuthPagesTest::setUp` to the base `TestCase::setUp`, so Livewire's static per-request state never leaks from one test to the next. `AppLayoutTest` now asserts the flag is clear instead of clearing it itself. A full run with `--order-by=random` passed.

### Checks
- `C:\laragon\tmp\browser-check\p15-brand.mjs` (puppeteer, 1024 and 375 px): logo mark and favicon load on the auth and app layouts, the primary colour, the referrer policy first; every dialog opened by its real trigger (row menus opened first), its badge and tone, radius, inside the screen, focus inside, no covered button, Escape; input icons inside their fields; the password page's show/hide and submit state; a Livewire dialog button disabled with a spinner while a delayed request runs. It saves nothing: the forms it sends are refused by validation (no mail is sent; `.env` has a real SMTP server).

## Phase 15d: application pages

Branch `ui-redesign`. No new package. One migration, `2026_11_15_100000_load_application_page_texts` (idempotent `TranslationSeeder`, texts only). Reference re-read from the clone at `C:\laragon\tmp\ref\shadcn-admin` (`e16c87f`): `components/data-table/*` (toolbar, faceted filter, pagination, row actions), `features/users/*`, `features/settings/*` (content section, sidebar nav), `features/dashboard/*`, `components/ui/tabs.tsx`, `skeleton.tsx`. Rewritten as Blade; nothing copied.

### Company name
- Owner's decision: `config('app.company')` = "Peranti Digital Solusindo" (beside the logo in the sidebar and on the auth pages, in every page title as `<page> - <APP_NAME> - <company>`, in the email header as `<company> <APP_NAME>`), `config('app.company_legal')` = "PT Peranti Digital Solusindo" (copyright line of the auth footer and of every email). The curved lettering of `design/brand/logo.jpg` is not used. `APP_NAME` stays "HR System" (the product); the authenticator issuer (`config('app.name')` in the TOTP URI) is unchanged, so enrolled authenticators keep their label. The recovery-codes file title reads `<company> <APP_NAME>: Recovery codes`.
- Laravel's mail layout is published only for `html/message` and `text/message` (`resources/views/vendor/mail/`), the two files that print the name; every other mail component still comes from the framework.
- CSV exports carry no company name (file names and columns unchanged).

### Shared pieces
- `x-ui.page`: `icon` (a tinted chip beside the title, hidden below 640 px), `back` (`['href', 'label']`, a small link above the title on detail pages; the old "Back to ..." buttons became this link, same texts; the decision page keeps its button because it releases the review lock) and a dotted backdrop (`.page-backdrop` in `app.css`: brand-blue dots at 22% and a 7% glow, masked to fade out, 14 rem high). The backdrop is the container's first child, absolutely placed, and every other child except fixed ones is `relative`, so content paints over it. The container creates **no stacking context**: an earlier `isolate` kept the page's dialogs (fixed, z-50) under the sidebar and the sticky header. Breadcrumbs stay in the header bar (one trail per page, from the layout data); the page heading does not repeat them.
- `x-ui.card`: `icon` + `tone` (chip in the header), `actions` (header slot) and `footer` (muted bar, `mt-auto`, so cards of unequal height in a grid keep their footer at the bottom). The card is a flex column.
- `x-ui.icon-chip` (tones primary, green, amber, red, violet, teal, gray; icon at 3:1 or more on its tint), used by `x-ui.stat` (new `tone`), cards, the page heading, table cells and the progress card.
- `x-ui.badge dot`: a small circle in the text colour before the label; `x-ui.status-badge` passes it on.
- `x-ui.toolbar`: filters in a grid (1, 2, 4 columns) that becomes a wrapping flex row from `xl`; actions (Clear filters) on the right. `x-ui.segmented`: shadcn's Tabs list as a toggle group (`wire:click="$set(...)"`, `aria-pressed`, a label above like a field's); it spans two toolbar columns and keeps its own width in the flex row (at 1024 px it overflowed one column and the next filter covered it).
- `x-ui.row-actions`: a three-dot icon button opening `x-ui.dropdown` with `anchored` (fixed strategy). Every row menu in a table is anchored fixed; the select and date-picker panels use `float()` (15b).
- `x-ui.pagination`: range, "Page X of Y", first / previous / next / last icon buttons with screen-reader names (`gotoPage`, `previousPage`, `nextPage`); a slot for rows per page (Users). Replaces the six hand-made footers.
- `x-ui.table-skeleton`: placeholder rows shown with `wire:loading.delay.block` for the same targets as the table, which is wrapped in `wire:loading.delay.remove`; pulsing only under `motion-safe`. Quick answers show no skeleton.
- `x-ui.empty-state`: a drawing made for the project (rings, a dotted orbit, two soft cards; inline SVG in the theme colour, decorative) behind the icon, and a slot for the call to action (Add department, Add holiday, New leave/overtime request when an approval path exists, Clear filters).
- 25 icons drawn for the project (more-horizontal, plus, pencil, trash, user-plus/check/x, arrows, chevrons, briefcase, refresh, globe, monitor, smartphone, history, file-text, send, wallet, flag, hourglass, list).

### Pages
- Users (toolbar, Clear filters in one request: three `$wire.$set(..., false)` and a last one that sends, avatars, status dots, icon row menu with a separator before Suspend, rows per page in the footer), user detail (back link, card icons, actions in footers, avatars in the supervisor and subordinate lists), departments (figure cards computed from the list already loaded, building chips, row menu), leave balance (figure cards with a bar, footers, current period highlighted, coloured ledger changes), holidays (calendar leaves, row menu).
- My requests and the decision page share `requests/partials/balance-slots` (tiles per period; the requester sees Available, the decider Remaining after used days; the data attributes are unchanged). Request detail and history detail show `requests/partials/progress` (submitted, then cancelled, decided with the decider, or waiting), built from the record's own dates. The final warning of both decision dialogs is an amber notice with an icon (same sentence).
- History and recap: toolbar, skeleton, pagination; recap tabs as the segmented control. Dashboard: tones on the figure cards and icons on the cards and the login list. Profile tabs as the Tabs track (links with `aria-current`), profile forms submitted from the card footers through the `form` attribute; security pages with icons.

### Defects found
- **Fixed panels inside dialogs:** the dialog's entrance animation filled `both`, so its transform stayed in effect and Chrome made the dialog the containing block of the `position: fixed` select and date-picker panels: they were shifted by the dialog's offset and fell off the screen (since "Brand, dialogs and forms"). The animation now fills `backwards`; a test refuses `both`/`forwards`.
- **Dialogs under the sidebar:** see the page container above (found in a screenshot of the overtime dialog).
- **Segmented switch covered** at 1024 px (see above; found by a real click that landed on the next filter).
- **Blade:** a view that mixes an inline `@php(...)` with an `@php ... @endphp` block compiles to a broken file; `balance-slots` uses blocks only.

### Checks
- `layout.mjs <setup> <label> roles [routes]`: logs in as the zz-check HR Admin, Approver and Employee of `p15d-setup.php`, records each role's sidebar links, measures and photographs the phone drawer, and checks every route the role reaches (sidebar pages, detail pages of the seeded requests, profile, security and the four pages behind the confirmation) at 375, 768, 1024, 1280 and 1920 px with the contrast scan at every width.
- `p15d-g1.mjs` to `p15d-g5.mjs` drive each group with real clicks (helpers in `p15d-lib.mjs`; a row menu is clicked on its visible copy, and the last item of a short and a long list is checked to be the element on top at its centre). No decision is confirmed and no request is submitted in the browser: both email real addresses through the SMTP server of `.env`.
- `p15d-mutate.py`: the safeguards touched in this phase, one at a time, restored and confirmed by SHA-256.

## Phase 15e: error pages, phone cards, empty states, accessibility

Branch `ui-redesign`. No new package. One migration, `2026_11_16_100000_load_finishing_texts` (idempotent `TranslationSeeder`, texts only). One new route: the local-only gallery preview `/ui-kit/errors/{code}`.

### Error pages (`resources/views/errors/`)
- One standalone layout, `errors/layout.blade.php`, used by `403`, `404`, `419`, `429`, `500`, `503` and the generic `4xx` / `5xx` (405, 410, 502 and the like). Each view passes only a fixed English key for the title and the explanation, an icon name and the code; **the exception's message is never printed** (an `abort(403, '...')` reason, a thrown exception's text, a trace). No Livewire, no Alpine, no application script; one small inline script shows "Go back" only when there is a page to go back to, and "Try again" (a reload) on 500 and 503 only (reloading after a 419 or 429 would repeat the refused request).
- The way on: a fully logged-in user (`canAccessApplication()`) gets "Go to dashboard", anyone else "Go to login". The language follows the user (set again in the layout, because a failed CSRF check stops the request before `SetLocale`).
- **Robust against the failure itself:** the translations and the user read the database and the stylesheet needs the Vite manifest; each goes through `rescue()`, so with the database down the 500 page still renders, in English, unstyled at worst. Tested by making the translator throw.
- `<meta name="referrer" content="no-referrer">` before every request of the page (an error can be shown at a URL with a token), and `noindex`.
- An address that matches no route is answered before the web middleware runs, so no session has started and the page cannot know who asks: it links to `/` ("Go to the home page"), which sends a guest to the login page and a user to the dashboard, and it is in the default language. A `Route::fallback` was tried to get the session there, and dropped: it also answered GET on POST-only routes (`GET /logout` became 404 instead of 405), a behaviour change caught by the suite.
- With `APP_DEBUG=true` (this local `.env`) a real exception still shows Laravel's debug page; the 500 view is what production (`APP_DEBUG=false`) shows. `abort(500)` and other HTTP exceptions show the views in both modes.
- Gallery preview `GET /ui-kit/errors/{code}` (local only; 403, 404, 419, 429, 500, 503): aborts with that code, so the real exception handler renders the page with its real status.

### Failed Livewire actions
- The interceptor in `layouts/app.blade.php` already sent a 401 to the login page. Every other status from 400 up now calls `preventDefault()` and dispatches `request-failed`; `layouts/partials/request-failed.blade.php` opens one of four always-rendered dialogs: **419 session expired** (Reload page; with the session gone the reload lands on the login page), 403/404 not available (Close, Reload page), 429 too many attempts (Close), anything else something went wrong (Close, Reload page). This replaces Livewire's untranslated `confirm()` on 419 and its frame that showed the raw response (in local debug mode a full trace) for other failures. Server behaviour is unchanged.

### Tables as cards on phones
- `x-ui.table` (`cards`, default true) adds `table-cards`. Below 1024 px on screens (`@media screen and (width < 64rem)`, unlayered CSS in `app.css`, so it beats the table's utility classes without `!important`) the header row is hidden and each body row becomes a card (flex column). Every cell carries `data-label` with its column header text, shown by `::before`, so a card holds exactly what the row holds. `data-card="title"` (the row's name, moved first), `data-card="menu"` (the row menu, top right, 44 × 44 px) and `data-card="actions"` (buttons at the bottom, 44 px high, full width) mark the cells without a label; a `th[colspan]` row (the recap's employee rows) becomes a plain heading.
- The component wraps each cell's content in one `<div data-cell>` (a regex over the rendered slot that touches only `<td>` tags), so a value made of several pieces (two dates and a dash) stays one grid item beside its label. No table is nested in a cell, so the lazy match is safe.
- Print keeps the plain table (the media query is screen only).
- Every table was labelled: users, departments, holidays, leave periods and ledger, my requests (leave, overtime), allocations, approvals (waiting, decided), overtime per date, history, recap (balance, departments), login history, and the gallery's. The lists that were never tables (supervisors, subordinates, active sessions, dashboard lists) already stacked on phones.
- The recap's `px-3!` cell padding became `lg:px-3!` (an important utility would beat the card's padding).

### Empty states
- Filtered against really empty, marked `data-empty="filtered|none"`: My requests (a status filter: "No requests with this status" + "Show all statuses"; without an approval path an explanation and no button), Approvals (a type filter: "Clear filters"; the empty waiting list offers "Decided by me"), Recap (both tabs: "Clear filters" only when a filter narrows the list; otherwise what the list would show), Users and History (search icon when filtered). The ledger explains itself. Calls to action appear only on pages the role reaches (departments and holidays are HR Admin pages; Employee pages offer only their own actions).

### Accessibility
- Live regions: one polite `role=status` region in the app layout (`data-announcer`, present from the first paint). A Livewire `morphed` hook reads the `[data-announce]` texts of the updated component (the pagination range, an empty state's title and description) and announces them when they changed, so a filter's result is heard. Toasts already sat in a polite status region. Field errors (`x-ui.error`, the OTP error) carry `role=alert`; `x-ui.alert tone=danger` is `role=alert`, the other tones `role=status`.
- Dialog focus: the trap's own return would land on `<body>` when the opening button was disabled by its Livewire request (`loading`). `ui.js` remembers the last control focused outside a dialog (`window.uiLastFocus`); `x-ui.modal` stores it on opening, uses `x-trap.noreturn`, and on closing calls `window.uiRestoreFocus`, which takes the menu's button for an item of a closed menu, finds a re-rendered control again by its id, and waits while it is still disabled.
- Reduced motion: one rule in the base layer sets every animation and transition to 0.01 ms under `prefers-reduced-motion: reduce` (components, Alpine transitions, the skeleton pulse), on top of the `motion-safe:` entrances.
- Segmented control: its options may wrap below 640 px (at 320 px the recap's switch pushed the page sideways).

### Gallery and clean-up
- `/ui-kit` now also shows table rows with a row menu and pagination (cards on phones), the toolbar with the segmented switch and Clear filters, icon chips in every tone, steps, password input, submit button, auth heading, role badge, a section card with icon and footer, the skeleton, `x-ui.date-hour` (in the Livewire demo), the error pages (links with their real status) and the four request-failure dialogs.
- Removed: `x-ui.loading` (used only by the gallery since `x-ui.table-skeleton`) and `resources/views/welcome.blade.php` (Laravel's stock page, never routed).

### Checks
- In `C:\laragon\tmp\browser-check\`: `p15e-a11y.mjs` (labels, accessible names, headings, landmarks, `lang`, `alt`, ARIA roles, attributes, values and references, `aria-hidden` without focusables, unique ids, skip link), `p15e-keyboard.mjs` (skip link, tab order and focus rings, dialog trap and focus return, menus, selects, date picker, OTP, toasts, announcer, request-failure dialogs, a real 419 on a Livewire action and on a plain form, reduced motion, phone drawer), `p15e-states.mjs` (contrast hovered, focused, disabled and in error, three roles), `p15e-cards.mjs` (cards at 320/375/768 px, tables at 1024 px), `p15e-print.mjs` (print view and A4 PDFs), `p15e-errors.mjs` (every error page at 375 and 1280 px, English and Indonesian, each way-on link followed), `p15e-e2e.mjs` (flows per role with the log mailer) and `p15e-export.mjs` (the CSV exports, fetched with the page's session because headless Chrome refuses downloads over http).

## Language

The interface is in English or Indonesian. Each user picks a language in My Profile; guests, users who never chose, and users whose language was deactivated get the default, `APP_FALLBACK_LOCALE` (English). Account emails go out in the recipient's language: a password reset follows the user's choice, and an invitation is always in the default language, because the invitee has not chosen yet.

### How it works

- Texts live in two tables. `languages` lists the languages (`code`, `name`, `native_name`, `is_active`, `sort`). `translations` holds one row per language, group and key. `key` uses the `utf8mb4_bin` collation, so `Save` and `save` are different keys. `users.locale` holds the user's choice (null means the default), and it is not mass assignable.
- `App\Translation\DatabaseLoader` replaces Laravel's file loader, so `__()`, `@lang` and validation messages all read the database. A key missing in one language falls back to English. There is no `lang/` folder.
- Keys: a sentence is keyed by its English text in the `*` group, for example `__('Log in')`. The framework's messages keep their keyed groups, `validation`, `auth` and `passwords`, with dotted keys such as `validation.min.string`. `validation.attributes.*` gives field names in Indonesian messages. The framework's `pagination` group is not loaded: it holds HTML entities, and the app does not use it.
- Labels picked at runtime are written out as literals, so the checker sees them: account statuses, role names, login results and device names (`:browser on :system`). They are also listed in `App\Translation\DynamicLabels`, which the checker always requires. A new dynamic label goes in both places, and `LanguageTest` fails if an enum case is missing from the list.
- Every text is printed escaped, and translations hold no HTML. `app:translation-set` refuses tags and entities, and a test checks the base files.
- Each language and group is cached under a version number. Changes through `app:translation-set`, the `Translation` model or the seeder start a new version. **After editing the table directly in the database, run `php artisan app:translations-cache-clear`.**
- The base texts are in `database/translations/en.php` and `id.php`. The migration `2026_11_05_100100_load_base_translations` loads them through `TranslationSeeder`, which only inserts rows that do not exist yet, so it never overwrites a text changed later. Two seeders running at once can deadlock on the unique index; each chunk retries, and `TranslationSeederConcurrencyTest` runs four at once.
- The role names stored in the database stay English (`HR Admin`, `Approver`, `Employee`). Only their display is translated.

### Commands

```bash
php artisan app:translations-missing
php artisan app:translation-set id "Log in" "Masuk"
php artisan app:translation-set id required ":Attribute wajib diisi." --group=validation
php artisan app:translations-cache-clear
```

`app:translations-missing` lists every key that an active language lacks and exits with 1 if there is one. A key is required when the code names it as a literal (`__()`, `trans()`, `trans_choice()`, `@lang`), when it is in `DynamicLabels`, or when English has it in the database. `LanguageTest` runs it, so a sentence added without both languages fails the suite.

`app:translation-set` adds or changes one text. It is the only editing tool in this phase: there is no editing screen. It runs on the server console only, so it has no role check of its own (whoever runs it already controls the server). It refuses an unknown language or group, HTML, and placeholders that differ from the English text, writes the audit event `translation.changed` with a null actor, and refreshes the cache.

### Adding a sentence (every later phase)

Write `__('English sentence')` in the code, add the same key to `database/translations/en.php` and its Indonesian text to `id.php`, and add a migration that runs `(new TranslationSeeder)->run()`. The suite fails until both languages have it.

### Adding a language

1. Insert a row in `languages`, with `is_active = 0` until the texts are complete. For example, in tinker: `App\Models\Language::create(['code' => 'ms', 'name' => 'Malay', 'native_name' => 'Bahasa Melayu', 'is_active' => false, 'sort' => 3]);`
2. Add its texts: copy `database/translations/id.php` to `ms.php`, translate it, then run `php artisan db:seed --class=TranslationSeeder --force`. Single texts can also be added with `app:translation-set`.
3. Activate it: `App\Models\Language::whereKey('ms')->update(['is_active' => true]);`, then run `php artisan app:translations-missing`, which must report nothing.

No code changes. Carbon's month names come from Carbon itself and need no rows.

### Standard Indonesian terms

The formal register uses "Anda". The same English term always gets the same Indonesian term:

| English | Indonesian |
|---|---|
| account | akun |
| user | pengguna |
| username | nama pengguna |
| password | kata sandi |
| email | email |
| log in / login | masuk |
| log out / logout | keluar |
| session | sesi |
| two-factor authentication | autentikasi dua faktor |
| authenticator app | aplikasi autentikator |
| recovery code | kode pemulihan |
| invitation | undangan |
| activate / reactivate | aktifkan / aktifkan kembali |
| suspend / suspended | tangguhkan / ditangguhkan |
| pending (account status) | menunggu aktivasi |
| role | peran |
| HR Admin / Approver / Employee | Admin HR / Penyetuju / Karyawan |
| Home | Beranda |
| My Profile | Profil Saya |
| Security Settings | Pengaturan Keamanan |
| User Management | Manajemen Pengguna |
| reset (a password) | atur ulang |
| link | tautan |
| device | perangkat |
| confirm | konfirmasi |
| cancel | batal |
| save | simpan |
| field (validation) | named by its label, for example "Nama lengkap wajib diisi." |
| department | departemen |
| supervisor / subordinate | atasan / bawahan |
| No supervisor (marker) | Belum punya atasan |
| employment (card) | data kepegawaian |
| join date | tanggal masuk kerja |
| leave balance | saldo cuti |
| period / current period | periode / periode berjalan |
| anchor date | tanggal acuan |
| entitlement / used / remaining | jatah / terpakai / sisa |
| adjust (a balance) | sesuaikan |
| ledger | buku besar |
| inactive | nonaktif |
| request / my requests | pengajuan / pengajuan saya |
| leave (request type) / overtime | izin / lembur |
| annual leave / sick leave / other leave | cuti tahunan / sakit / izin lainnya |
| pending approval / approved / rejected / cancelled | menunggu persetujuan / disetujui / ditolak / dibatalkan |
| working days | hari kerja |
| holiday | hari libur |
| evidence | bukti |
| projected (period) | proyeksi |
| approvals / approve / reject | persetujuan / setujui / tolak |
| decision / decided by | keputusan / diputuskan oleh |
| requester | pemohon |
| review (a request) | tinjau |
| reason for rejection / note | alasan penolakan / catatan |
| deducted / reserved | sudah dipotong / dicadangkan |
| request history / recap / export | riwayat pengajuan / rekap / ekspor |
| filter / date range (from, to) | filter / rentang tanggal (dari, sampai) |

## Mail

**The `log` mailer writes whole emails, including activation and reset links with their tokens, to `storage/logs/laravel.log`. It is for local use only.** The application itself never logs a token: `TokenLeakTest` checks this against a real log file, and also proves that only the `log` mailer writes one. Use SMTP everywhere else, with `MAIL_TIMEOUT` (default 10 seconds) limiting how long a request waits for the transport.

Web server access logs record the first request of every link, including its token. The application cannot prevent this. Tokens are single use and expire, but access logs should be protected and rotated like other sensitive logs.

## Recovery codes and APP_KEY

Recovery codes are 80-bit random values, stored as HMAC-SHA256 under a key derived from `APP_KEY` with HKDF and the label `hr-system.recovery-codes.v1`, never under `APP_KEY` itself. The user ID is part of the hashed message. A slow hash is unnecessary at this entropy, and a keyed hash allows an indexed lookup on `(user_id, code_hash)`. Spending a code is one conditional update, so a code works once even with concurrent requests.

**Rotating `APP_KEY` voids every recovery code.** It also makes every TOTP secret undecryptable, because they are encrypted with the same key, and `APP_PREVIOUS_KEYS` does not help recovery codes. Rotation therefore means re-enrolling all users, with `app:reset-two-factor` for each of them. It clears the secret without reading it, so it works after a rotation too.

## First HR Admin

Run this once after migrating. It seeds roles and permissions, asks for the account details with hidden password input, and refuses if an active HR Admin already exists.

```bash
php artisan app:create-admin
```

The account is ACTIVE with 2FA enrollment incomplete. There is no public admin registration page.
