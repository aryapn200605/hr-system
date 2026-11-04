<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Local workflow (Laragon)

- The app runs on Laragon's Apache at **http://hr-system.test**. Check things there, not with `php artisan serve` or `php -S`. If Laragon, Apache or MySQL is not running, ask the owner to start it; the agent does not start or configure Laragon.
- `hr_system` is the development database behind hr-system.test and holds the owner's real accounts. `hr_system_test` is only for `php artisan test`. Never run the suite against `hr_system`.
- Migrating `hr_system` happens at the end of a phase, after a verified `mysqldump` backup outside the repository, with `php artisan migrate` only. The steps the owner must run are written at the end of each phase in PROGRESS.md.
- Before any check that needs a service, `http://hr-system.test/up` must answer and MySQL must accept a connection on 127.0.0.1. If either is down, work that needs it stops until the owner starts Laragon.
- Build assets with `npm run build` so Apache serves them. No Vite dev server is left running.
- After changing views, run `php artisan view:clear`. A stale compiled view once kept the plain-text part of an email in English after its template had changed.
- Browser checks run as a puppeteer script outside the project, in `C:\laragon	mp\browser-check` (puppeteer 25.14.0, not in package.json): `node phase7.mjs <file with the activation link and password>`. Screenshots go to its `shots` folder.
- Browser checks run on hr-system.test at 1024px and 375px. Temporary accounts there are created only through the application, with the username prefix `zz-check-`, and removed afterwards with a targeted delete, after which the counts of real accounts and their rows must match the numbers taken before.
- The backup before an end-of-phase migration goes outside the repository, in `C:\laragon\backup\hr-system\`, and is read back before migrating. Never `migrate:fresh`, `migrate:reset`, `migrate:rollback`, `db:wipe` or truncate on `hr_system` without the owner's explicit approval.
- Documents: CLAUDE.md (working rules), DECISIONS.md (business rules), SPEC.md (phases), PROGRESS.md (log and lessons), this README (technical decisions and commands).
- Docker, VPS, Nginx and server deployment are deferred to SPEC.md Phase 16, which the owner starts. Nothing in this repository runs on Docker yet.

## Scheduler

`app:prune-expired-tokens` runs every day at 02:00 WIB through Laravel's scheduler (`routes/console.php`). The scheduler only runs while something calls `schedule:run` every minute. On this Windows machine the owner installs that once in Task Scheduler, from a Command Prompt:

```bat
schtasks /Create /SC MINUTE /MO 1 /TN "hr-system scheduler" /TR "\"C:\laragon\bin\php\php-8.5.12-Win32-vs17-x64\php.exe\" \"C:\laragon\www\hr-system\artisan\" schedule:run"
```

Check and remove it with:

```bat
schtasks /Query /TN "hr-system scheduler"
schtasks /Delete /TN "hr-system scheduler" /F
```

The task needs MySQL, so it only does work while Laragon runs. Verified on this machine: `php artisan schedule:list` lists the command for 02:00 from any working directory, and `schedule:run` and `schedule:test` run it. The `schtasks` command itself was not run, because installing it is the owner's step. To prune by hand:

```bash
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