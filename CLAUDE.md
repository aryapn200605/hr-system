# HR Management System: Project Context

Laravel 13 HR app. Foundation (authentication, mandatory TOTP 2FA, RBAC, user management, profile and security) is done. HR modules (employees, leave/permission and overtime requests, approvals, history and recap) are built phase by phase.

Read at the start of every phase, in this order:
1. This file.
2. DECISIONS.md: business rules agreed with the owner. They replace the SRS when they differ. Items marked [ASSUMPTION] may be used but must be reported.
3. PROGRESS.md: what is done, known issues, lessons learned.
4. SPEC.md: read **only the phase you are working on**.
5. docs/TECHNICAL-DECISIONS.md "Project decisions" and the sections after it: technical decisions already made. Do not undo them silently. README.md (Indonesian) is the setup and daily-use guide for the owner and the team.

## Environment
- Windows + Laragon. Project path: C:\laragon\www\hr-system (never move it).
- PHP 8.5.12 (Laragon's PHP may not be in PATH; look under C:\laragon\bin\php), Composer, Node/npm, Vite, Git.
- MySQL 8.0.30 local (Laragon), host 127.0.0.1. MySQL only, for the app and for tests. No SQLite anywhere.
- Stack: Laravel 13, Livewire 4 (Alpine via Livewire), Tailwind CSS 4, spatie/laravel-permission, pragmarx/google2fa, bacon/bacon-qr-code.
- Verify every package's compatibility with Laravel 13 and PHP 8.5 via Composer before installing. Never silently use a different PHP version. New packages need the owner's approval.
- Docker, VPS, Nginx and deployment are **out of scope** until the owner starts the deployment phase.

## Laragon workflow (mandatory)
- The app is served by Laragon's Apache at **http://hr-system.test**. Every manual, browser and HTTP check goes through that address. Never use `php artisan serve`, `php -S`, a temporary Apache, or any other server.
- Before any step that needs a service, check it without changing it: `http://hr-system.test/up` answers, and MySQL accepts a connection on 127.0.0.1 (use the .env credentials, never print them). **If either is down, stop and ask the owner to start Laragon, then wait.** Never start, stop or restart Laragon, Apache or MySQL, and never edit Laragon configuration or php.ini. Work that needs no service (reading, writing code) can continue.
- Use `npm run build` so Apache serves built assets. Do not leave a Vite dev server running.
- Browser checks use a **puppeteer script** (headless Chrome with its own profile) against http://hr-system.test at 1024px and 375px. Click every new button and dialog and record console errors, and look at screenshots yourself. A rendered-HTML test is not a substitute. Put puppeteer outside the project (for example C:\laragon\tmp\browser-check) unless it is already installed; do not add it to package.json without approval.
- **Do not use the Claude in Chrome extension or the built-in browser pane/tools** for these checks: they ask the owner for permission on every action. If a browser tool shows a permission prompt, stop using it and switch to the puppeteer script. Batch many checks into one script so a run needs one command.

## Databases
- `hr_system` is the development database behind hr-system.test. It holds the owner's real accounts. **Never modify, delete or lock those accounts or their rows.** Record their count before and after any work there.
- `hr_system_test` is for automated tests (`php artisan test`, race processes, mutation checks). **Never run the test suite against `hr_system`.**
- Browser checks on hr-system.test may create temporary accounts, through the application's own actions (a console command, tinker calling the same action classes, or the UI), with the username prefix `zz-check-`. Remove them afterwards with a targeted delete after listing the rows first, and show that the counts of real accounts and their rows match the "before" numbers. If cleanup cannot be proven clean, say so.
- Migrating `hr_system` is allowed **only at the end of a phase**, after: tests green, `php artisan migrate:status` shown, and a `mysqldump` backup written outside the repository (for example C:\laragon\backup\hr-system\) and read back to prove it is not empty. Use `php artisan migrate` and the idempotent seeders only. Never `migrate:fresh`, `migrate:reset`, `migrate:rollback`, `db:wipe` or truncate on `hr_system` without the owner's explicit approval in that conversation.

## Working rules
- Do only the current phase. Do not build features outside SPEC.md and DECISIONS.md, and do not change auth, 2FA, invitation or recovery code behavior unless a phase says so or a verified defect requires it (report first).
- UI: follow the existing layout, components and tokens. **No redesign and no new UI library** until the owner starts the redesign phase. Keep pages minimal and consistent with the current ones.
- Laravel-native first. Minimal dependencies. No needless service or repository layers.
- Authorization is always enforced server-side (middleware, policies, gates), including Livewire actions. Role labels in the UI are informational only.
- Never hardcode role names in feature code; use central permission definitions.
- Public pages (login, activation, password reset) use controllers + Blade, not Livewire: the Livewire update route is only for fully logged-in users.
- `changeRole()` with a null actor is only for CLI bootstrap. In HTTP/Livewire code, the actor is always the logged-in user.
- Never log or expose: passwords, TOTP secrets, authentication codes, invitation tokens, recovery codes.
- Storage: invitation tokens and recovery codes hashed; TOTP secret encrypted; never show a stored secret again after enrollment.
- All times are WIB (Asia/Jakarta) from Phase 6 on. Calendar dates (leave) are stored as DATE.
- From Phase 7 on, every user-facing sentence goes through the translation mechanism, in both English and Indonesian, in the same change that adds it.
- Never overwrite existing files or Git history. Check `git status` and the staged diff before each commit. Never commit .env or secrets. No force push, no reset, no push without permission.
- Do not claim a feature is complete unless it is implemented and verified (tests, build, browser). Never fabricate results. Report what was not tested.
- Ask only when blocked (missing credentials, destructive operation, Laragon down, a business rule that DECISIONS.md does not cover and cannot be assumed safely). Otherwise decide, record the decision in docs/TECHNICAL-DECISIONS.md, and list assumptions in PROGRESS.md.

## How every phase runs
1. **Step 0, plan first, then keep going.** Read the code, check the state, and write a short plan (tables and columns, permissions, routes, technical decisions, assumptions) at the top of your work. **Do not wait for approval and do not ask for confirmation of routine choices.** Stop and ask only when blocked: Laragon is down, a business rule is missing from DECISIONS.md that cannot be assumed safely, a destructive operation outside the rules below, or the plan would deviate from SPEC.md or DECISIONS.md. Choose sensible defaults (for example retention periods), record them as assumptions, and mention them in the final report.
   Do not open any port or extra server. Run and check everything through http://hr-system.test.
2. Build with tests alongside: success, validation failure, authorization denial (direct URL and manipulated Livewire requests), throttling where relevant, and real-process race tests (separate PHP processes, committed rows) at every critical point.
3. Mutation check on the new safeguards: remove them one at a time, restore each file and confirm by hash. Survivors mean weak tests or redundant code: fix or remove, and re-run those mutations.
4. Real verification on **hr-system.test** (after the end-of-phase migration) and in a real browser, at 1024px and 375px.
5. Run the full test suite, `npm run build`, Pint, `composer audit`, `npm audit`.
6. Update PROGRESS.md (done, next, known issues, decisions, assumptions), docs/TECHNICAL-DECISIONS.md (technical decisions and notes, in English) and README.md (setup, commands and steps the owner must run, in Indonesian). Commit locally with a descriptive message. No push.
7. Stop and report in 12 lines or fewer: result, test numbers, bugs found, what was not tested, deviations from DECISIONS.md, and steps the owner must do. Do not start the next phase.

## Lessons learned (do not repeat)
- `@js()` is not compiled inside a Blade component attribute: the button then holds literal text. Test every interactive button in a real browser.
- `Carbon::createFromFormat()` without a leading `!` keeps the current time's seconds. Use `!` for date/time input.
- Toasts can cover buttons on small screens, and a modal removed from the DOM while open leaves its focus trap active. Render dialogs always and hide only the trigger.
- `<input type="time|datetime-local" step>` takes its step base from the current value; set `min` so the base stays on a full hour.
- A `position: absolute` element escapes `overflow` unless a parent is positioned (the `sr-only` table header once widened the whole page).
- `max-w-*` containers need `mx-auto`; check that content is centered at 375, 1024 and 1280px.
- Pages without a Livewire component need `@livewireScripts` for Alpine.
- A guard that caches the user object can hide bugs that a real request shows; test with real processes.
- MySQL reports 0 changed rows when an UPDATE writes the same value; tests with frozen time can hide this.
- A missing icon or view can make a whole suite slow and noisy; fix the first error before reading the rest.
- Run the dev tests against `hr_system_test` only, and race processes without `RefreshDatabase`.
- Livewire 4 reserves `$slots` in views; a view variable with that name renders nothing.
- MySQL limits index names to 64 characters; name long composite indexes yourself.
- `x-anchor` without `.fixed` places a menu absolutely: inside an `overflow` box (a table) it is clipped and its items cannot be clicked. Use `x-anchor.fixed`, and click a menu item in a short list in the browser.