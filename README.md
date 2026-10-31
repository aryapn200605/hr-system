# HR System

An internal HR foundation: authentication with mandatory TOTP two-factor login, invitation-based activation, role-based access, user management, a dashboard, profile and security settings, and an audit trail. On top of it: departments, supervisor relations and yearly leave balances. Requests and approvals come in later stages. Requirements are in SPEC.md, business decisions in DECISIONS.md (which wins over any SRS) and the work log in PROGRESS.md.

## Stack

- PHP 8.5.12, Laravel 13.39, Livewire 4 (Alpine comes with it), Tailwind CSS 4, Vite 8
- MySQL 8.0 for the app and for tests. No SQLite anywhere.
- spatie/laravel-permission 8.3, pragmarx/google2fa 9.1, bacon/bacon-qr-code 3.1
- IBM Plex Sans from `@fontsource-variable/ibm-plex-sans`, shipped in the build

## Prerequisites

Windows with Laragon, providing Apache 2.4, PHP 8.5.12 and MySQL 8.0, plus Composer 2, Node 22 and npm 10. Laragon's PHP is not in PATH for Git Bash, so the commands below are written for Git Bash with the full path, and Composer runs from its phar:

```bash
PHP=/c/laragon/bin/php/php-8.5.12-Win32-vs17-x64/php.exe
```

```bash
COMPOSER=/c/laragon/bin/composer/composer.phar
```

Required PHP extensions, all on in Laragon: bcmath, curl, gd, intl, mbstring, openssl, pdo_mysql, sodium, zip.

## Setup with Laragon

The project lives at `C:\laragon\www\hr-system` and Laragon serves it at `http://hr-system.test`, with Document Root and Auto Virtual Hosts configured in Laragon. Start Apache and MySQL in Laragon first, and create two empty databases, `hr_system` and `hr_system_test`.

```bash
cp .env.example .env
```

```bash
$PHP $COMPOSER install
```

```bash
npm ci
```

```bash
$PHP artisan key:generate
```

Set `DB_USERNAME` and `DB_PASSWORD` in `.env`, then:

```bash
$PHP artisan migrate --seed
```

```bash
npm run build
```

Open `http://hr-system.test`. Apache serves `public/`, so no `artisan serve` is needed. Run `npm run build` again after changing CSS or Blade classes.

Seeders create roles and permissions only, never users.

### First HR Admin

Run this once after migrating. It seeds roles and permissions, asks for the account details with hidden password input, and refuses if an active HR Admin already exists.

```bash
$PHP artisan app:create-admin
```

The account is ACTIVE with 2FA enrollment incomplete, so the first login forces enrollment. There is no public admin registration page.

## Environment variables

`.env.example` has every variable. These are the ones specific to this project or that need attention:

| Variable | Meaning |
| --- | --- |
| `APP_URL` | `http://hr-system.test` locally. Links in emails use it. |
| `APP_DEBUG` | **Must be false in production.** While it is true, a 500 shows Laravel's debug page and not the application's error page. |
| `DB_*` | MySQL at 127.0.0.1. Tests override the database name in phpunit.xml. |
| `SESSION_DRIVER` | Must stay `database`: ending a session deletes its row. |
| `SESSION_ENCRYPT` | `true`. |
| `SESSION_SECURE_COOKIE` | `false` for local HTTP, **`true` wherever HTTPS is used**. |
| `INVITATION_EXPIRE_MINUTES` | Invitation lifetime, default 4320 (3 days). |
| `MAIL_*` | See "SMTP" below. `MAIL_TIMEOUT` limits how long a request waits for the transport. |

## Running tests

Tests use `hr_system_test`, set in phpunit.xml, and never touch `hr_system`. Do not run them while a local server is using `hr_system_test`, because they reset it.

```bash
$PHP artisan test --compact
```

```bash
$PHP artisan test --filter=ErrorPagesTest
```

```bash
$PHP vendor/bin/pint --test
```

## Scheduled cleanup

`routes/console.php` schedules three daily jobs, in WIB. **Nothing runs them until the operating system calls the scheduler.** Add `schedule:run` to Windows Task Scheduler or cron, once a minute. This has not been set up on this machine.

```bash
$PHP artisan schedule:list
```

```bash
$PHP artisan schedule:run
```

- `auth:clear-resets` removes expired rows from `password_reset_tokens`.
- `app:prune-invitations` removes dead invitations, and takes `--days` (default 90).
- `app:reset-leave-balances`, at 00:10, closes leave periods that have ended and opens the next ones. It is safe to run any number of times. If the scheduler misses days, the next run catches up period by period. `{username}` limits it to one user, and `{username} --now` starts a new period for that user today.

## SMTP

Local development uses `MAIL_MAILER=log`. For a real server set `MAIL_MAILER=smtp` with `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`, `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`. **This path has not been tested against a real SMTP server**, see "Manual checks by the owner".

## Moving a database to WIB

The application now runs on WIB: `app.timezone` is `Asia/Jakarta` and the MySQL connection sets `+07:00`, an offset because this MySQL has no named zones loaded. Stored times written before this change are in UTC wall clock, so one migration moves them by +7 hours. It covers 21 columns in 9 tables, listed in the migration. Epoch columns (sessions, cache, jobs) and `users.two_factor_last_timestep` are not touched, as TOTP counts from the epoch.

**`hr_system` has not been migrated. The owner runs this, once.** Until then `http://hr-system.test` fails, because the code expects the new columns and tables.

**Warnings:**

- Take the backup first. The rollback below loses departments, supervisors and balances.
- The shift is right only because MySQL runs on +07:00 here. These are `TIMESTAMP` columns, so on a server in UTC the connection setting alone moves them and a shift would add 7 hours twice. The migration checks this, refuses, and changes nothing. A fresh, empty database needs no shift and passes on any server.
- Keep the site down from before the migration until after the seeder: between the code change and the shift, open invitations and reset links look 7 hours older.
- Never run `migrate:fresh` here. It erases the 2 real users.
- Audit rows change too, on purpose. This is the only write to them that the model does not refuse.

Back up. The file holds password hashes and encrypted secrets, so keep it out of the repository:

```bash
/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysqldump.exe -h 127.0.0.1 -u root -p --single-transaction hr_system > hr_system-before-wib.sql
```

Then, in order:

```bash
$PHP artisan down
```

```bash
$PHP artisan migrate
```

```bash
$PHP artisan db:seed --class=RolePermissionSeeder
```

```bash
$PHP artisan up
```

The seeder adds the four new permissions to HR Admin and touches no user. Logged-in sessions survive, as they are epoch based.

To go back, before returning to older code. This undoes the shift and **drops departments, supervisors and balances**:

```bash
$PHP artisan migrate:rollback --step=5
```

Check afterwards: the last login in User Management should read as the real time in WIB, 7 hours later than it did before.

## Docker runtime: postponed

Not done, and not verified. Docker is not usable on the development machine right now, so at the owner's instruction Phase 6 was finished without it. There is no Dockerfile, no compose file and no runtime-specific environment mechanism yet.

Still to do from SPEC.md Phase 6: a minimal Dockerfile and docker-compose.yml with PHP 8.5.12 and the required extensions, the built-in server on port 8000, the project directory mounted, DB host `host.docker.internal`, no containers for MySQL, Redis, Nginx or Apache, a documented way to switch environment without editing config by hand, a decision on keeping `vendor` consistent between the two runtimes, and README instructions for both modes. The forgot password timing should be measured again on that runtime.

## Security notes

- Passwords, TOTP secrets, authentication codes, invitation tokens and recovery codes are never logged or shown again. Invitation tokens and recovery codes are stored hashed and the TOTP secret encrypted.
- `APP_DEBUG=false` and `SESSION_SECURE_COOKIE=true` are required outside local development.
- Protect `storage/logs` and web server access logs: see "Mail".
- Rotating `APP_KEY` means re-enrolling every user: see "Recovery codes and APP_KEY".
- Authorization is always on the server. Menus and role labels only reflect it.

## Git workflow

Work happens on `main` in one commit per phase or group of changes. Check `git status` and the staged diff before each commit, and never commit `.env`. No force push, and no push without the owner's permission.

## Known limitations

These are accepted for now and were left unchanged on purpose:

- Session end needs no re-authentication, so a stolen session can log the real user out. They can log back in and change the password.
- After `app:reset-two-factor`, the password alone reaches enrollment until the user enrolls again.
- A forgot password transport failure reaches the audit log only, because the response must look the same for every address.
- The forgot password timing was measured on Laragon's Apache with mod_php and the `log` mailer. It must be measured again on the production web server.
- There is no email verification, so a mistyped new email is not detected.

Also known:

- Between 640px and 1279px the User Management table scrolls inside its own region, with the Actions column stuck to the right edge. At 1280px and up it fits, with long names and emails cut and the full text in a tooltip and on the detail page.
- Below 640px tables become cards using `display: block`, so a screen reader announces each cell's label and value instead of table navigation.
- Password spraying is limited only per identifier and IP pair, as account lockout is out of scope.
- An account suspended before activation stays SUSPENDED for good. See "Addition outside SPEC.md: Reactivate".

## Manual checks by the owner

None of these has passed yet. They need a person or a service this project could not reach:

- [ ] Scan the QR code with the real Google Authenticator and Microsoft Authenticator apps, at first enrollment and at reconfiguration.
- [ ] Send invitation, resend and password reset emails through a real SMTP server, and open them in a real mail client.
- [ ] Use the application with a physical keyboard, mainly User Management, Security Settings and the mobile drawer. Automated key presses passed, see PROGRESS.md.
- [ ] Use it with a real screen reader (NVDA or VoiceOver), including the phone card layout.
- [ ] Use it on a real phone. Phone widths were checked by viewport emulation only.
- [ ] Set up the scheduler in Windows Task Scheduler or cron.

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
- The optional audit trail viewer was skipped on purpose. It would add a seventh permission to the list SPEC.md fixes, and its metadata would need a whitelist. Audit rows are read in the database for now.
- Error pages live in `resources/views/errors`, with `4xx` and `5xx` views for every other status. They read no session, user or database, and never print an exception or `abort()` message. Livewire requests still get JSON. If the page itself cannot render, Laravel falls back to its own default page.
- `resources/views/welcome.blade.php` was deleted: no route used it.
- Fonts are self-hosted through an npm package. The earlier `bunny()` entry in vite.config.js contacted fonts.bunny.net at build time and caused the `fontaine` warning. Removing it fixes both, so `fontaine` was not installed. `DesignTokensTest` fails if an external host returns to the stylesheet.
- `app:prune-invitations` deletes only invitations that were never accepted and stopped working more than 90 days ago, because they prove nothing: the audit log keeps the creation and resend of each one, and audit rows are not touched. **Accepted invitations are kept for good**, as evidence of who invited a person and when they activated. Old audit entries can then hold an `invitation_id` with no row, which is expected, as it is plain metadata.
- Password reset rows use the framework's own `auth:clear-resets`. Both are scheduled daily, and scheduling needs the operating system, see "Scheduled cleanup".
- `app:reset-two-factor` records the operating system account and host as `operator` in the audit metadata. It comes from `USERNAME`, `USER` or `LOGNAME`, so it is a hint and not proof.
- `x-ui.datetime` is the one place dates are formatted, always in WIB. `<time datetime>` keeps the instant. Tables use a compact date with the full time in a tooltip.
- On phones `.table-stack` restyles the same table markup into cards, labelled by `data-label`, so a row's actions and their `@can` checks are never written twice.
- Avatar initials come from a CSS pseudo-element, because they are decorative and otherwise count as visible text that the button's accessible name must contain (WCAG 2.5.3).
- A row menu returns focus to its trigger before Livewire opens a dialog, and "Create user" no longer disables itself while loading. A disabled or hidden opener loses focus, and the dialog then returned it to `body`.

- **Everything is WIB, and `TIMESTAMP` columns store it through a `+07:00` connection.** DECISIONS.md section 2 asks for WIB in the application, the connection and the display. `APP_TIMEZONE_DISPLAY` was removed, so there is one source. Leave and overtime dates of later stages are `DATE`.
- Sessions and login history moved to My Profile, at `/profile/sessions` and `/profile/login-history`. The old addresses are gone, without a redirect. `ProfileMenuTest` fails if a route, or a Livewire or HTTP class, reaches `resetTwoFactor()`, which stays console only.
- **Every write to `employee_supervisor` goes through `App\Support\SupervisorRelations`.** Direct `attach()` or `detach()` skips the guard.
- **`App\Support\SupervisorGuard` is the one place that decides whether a relation may exist or end.** It runs from `User::save()` on a status change, `changeRole()`, `delete()`, and from assign, remove and move, so any future command is covered and not only the pages. Query-builder writes to `status` skip it, as they skip the last HR Admin rule. No console command suspends or demotes today, so the console path is tested with a command defined in the test.
- All of these share `User::withinHrAdminLock()`: suspensions, role changes and supervisor changes run one at a time. These are rare HR actions, and one lock in one order cannot deadlock.
- A guard refusal must be audited after the rollback, or the transaction takes the entry with it, so `withinHrAdminLock()` writes it after `DB::transaction()` has failed. `DB::afterRollBack()` was not used, because it is unsafe with nested transactions. A second connection was not used either, because the entry's foreign key to the locked user row would wait on our own lock.
- A supervisor relation stays in the table when the supervisor is suspended or loses the role, and counts again when they return. Read relations through `activeSupervisor()`. Loop checks follow every link, active or not, for the same reason.
- A suspended employee does not count as affected by the guard, as agreed with the owner: they cannot submit anything, and someone who has left must not block their supervisor's suspension.
- The "No supervisor" marker and filter cover roles where `RoleName::needsSupervisor()` is true, which leaves out Approver, as agreed with the owner: HR Admin decides for an Approver without any relation. An Approver can still have supervisors, and the guard still applies to them.
- A bulk move runs its guard check with the new links in place. The target was found eligible first, so the check is a second layer and not the rule itself.
- **Leave periods touch end to end for each user**, and dates come from `leave_anchor_date`, never from the last period. A 29 February anchor resets on 28 February in common years and comes back in leap years. All balance changes take the user row lock, in `App\Support\LeaveBalances`.
- Balances are whole days. Adjustments are relative, so two at once both count, and the limits are checked on the values under the lock. Only the open period can be adjusted. An HR Admin can adjust their own balance, as DECISIONS.md does not refuse it, and the ledger and audit log name them.
- Reset now, agreed with the owner: the open period ends yesterday and a new one of 12 days runs from today to the next reset date. The reset date does not move, and it is refused when a period already started today. On an overdue balance it does only the overdue roll over.
- Changing the reset date moves the end of the open period to the day before the next reset. The days in it stay, so no days are granted or taken. A reset date cannot be in the future.
- The daily check also opens a first period for any user without one. Periods keep rolling for SUSPENDED and PENDING users, so the history has no gaps.
- `leave_balance_entries` refuses update and delete at model level, like `audit_logs`.
- A department in use can be deactivated. Its users keep it, and it cannot be chosen for anyone else.

### Design direction

A quiet document or ledger for an internal HR tool: dense, left aligned, read top to bottom. The skill `frontend-design` was not available, so this comes from the owner's brief. Tokens live in the `@theme` block of `resources/css/app.css`. Tailwind's default palette, radii and shadows are switched off there, and `DesignTokensTest` fails if a view reaches for one.

- **Color**: warm neutrals (`paper` page, `surface` for inputs and overlays, `wash` for hover, `rule` for hairlines, `rule-strong` for control borders, `ink`, `ink-soft`, `muted` for text). One accent, `accent` teal, for primary buttons, links, focus and the current page. `tone-*` colors are muted, with blue, purple and green for roles as SPEC.md asks. Status and result badges are only a small square and neutral text, and only role labels are filled.
- **Type**: IBM Plex Sans in weights 400, 500 and 600. Page titles 24px semibold, section titles 14px semibold, body 14px, table headers and hints 12px, figures 30px medium. `tabular-nums` on tables, dates, details and counts. Recovery codes and setup keys use the system monospace stack, and no second web font.
- **Space**: Tailwind's 4px scale. Sections are 32px apart, table cells 8px by 8px.
- **Shape**: one radius, `rounded-sm` (3px). Sections are set off by a rule, not a box. One shadow, `shadow-overlay`, only on menus, dialogs and toasts.
- **Motion**: no transitions. Only the loading spinner moves, and not under `prefers-reduced-motion`.
- **Copy**: says what is true and what to do next. Empty states are text without an icon. Security messages were not changed.

### Addition outside SPEC.md: Reactivate

SPEC.md has no way back from SUSPENDED. At the user's request, User Management has a minimal Reactivate action: permission `users.suspend`, a confirmation dialog, audit event `user.reactivated`, status back to ACTIVE, password, 2FA and role unchanged. It ends any session left over from the suspension.

It never activates an account that has not finished activation. A PENDING account is refused because it is not suspended, and an account suspended before activation has no password, so it is refused too. **Such an account stays SUSPENDED for good: the invitation was revoked and nothing can restore it.** To invite that person again, create a new user with a different username and email, since accounts are never deleted.

### Addition outside SPEC.md: app:reset-two-factor

A user who lost the authenticator and every recovery code cannot pass a re-authentication, so at the user's request there is a console-only way back. It has no route or UI.

```bash
$PHP artisan app:reset-two-factor {username}
```

After a confirmation it removes the secret, enrollment date, last timestep and recovery codes, ends all sessions of that user, and writes the audit event `two_factor.reset` with a null actor and the operator. Password, status and role stay. The next login forces a new enrollment.

**Until they enroll again, the password alone reaches the enrollment page**, the same exposure as a first login. Check who is asking before running it, out of band. Anyone who can run this command already has the server, so it adds no new access. The audit entry has a null actor and names the operating system account that ran the command.

## Mail

**The `log` mailer writes whole emails, including activation and reset links with their tokens, to `storage/logs/laravel.log`. It is for local use only.** The application itself never logs a token: `TokenLeakTest` checks this against a real log file, and also proves that only the `log` mailer writes one. Use SMTP everywhere else, with `MAIL_TIMEOUT` (default 10 seconds) limiting how long a request waits for the transport.

Web server access logs record the first request of every link, including its token. The application cannot prevent this. Tokens are single use and expire, but access logs should be protected and rotated like other sensitive logs.

## Recovery codes and APP_KEY

Recovery codes are 80-bit random values, stored as HMAC-SHA256 under a key derived from `APP_KEY` with HKDF and the label `hr-system.recovery-codes.v1`, never under `APP_KEY` itself. The user ID is part of the hashed message. A slow hash is unnecessary at this entropy, and a keyed hash allows an indexed lookup on `(user_id, code_hash)`. Spending a code is one conditional update, so a code works once even with concurrent requests.

**Rotating `APP_KEY` voids every recovery code.** It also makes every TOTP secret undecryptable, because they are encrypted with the same key, and `APP_PREVIOUS_KEYS` does not help recovery codes. Rotation therefore means re-enrolling all users, with `app:reset-two-factor` for each of them. It clears the secret without reading it, so it works after a rotation too.
