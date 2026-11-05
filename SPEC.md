# SPEC: HR Management System

Scope: the foundation (authentication, TOTP 2FA, invitation-based activation, RBAC, user management, profile and security settings, audit trail) plus the HR modules agreed with the owner: employee data and supervisors, annual leave balance, leave/permission and overtime requests, approvals, history, recap, dashboard, language switch, and email notifications.
Business rules live in DECISIONS.md and replace the SRS when they differ. This file says what to build in which phase.
Out of scope for now: email verification, account lockout, periodic password expiry, chatbot, payroll and attendance integration, audit log viewer for users, cancelling an approved request, HR entering a past sick leave, a suspension policy for the suspended user's subordinates, FR25 to FR28 of the SRS.
Work one phase at a time. Each phase ends as described in CLAUDE.md. Add audit-log calls, translations (from Phase 7) and tests alongside each feature, not afterwards.
Tests per phase: success path, validation failure, authorization denial, throttling where relevant, and real-process race tests at critical points.

## Phase map

| Phase | Name | Status |
|---|---|---|
| 0–5 | Foundation: init, RBAC, login/TOTP, invitation, layout and user management, profile and security | Done |
| 6 | Foundation cleanup: WIB, profile pages, pruning, Laragon workflow | Done |
| 7 | Language mechanism (English/Indonesian, database-backed) | Done |
| 8 | Organization: departments, employee data, supervisors, leave balance | Done |
| 9 | Requests: leave/permission and overtime submission, holidays, evidence | Done |
| 10 | Approvals: decisions, balance deduction, soft lock | Done |
| 11 | History, recap, CSV export | Done |
| 12 | Dashboard | Done |
| 13 | Email notifications (optional, small) | Done (real delivery untested) |
| 14 | Full verification and documentation (plus DECISIONS.md section 11) | Done |
| 15 | UI redesign (15a to 15e) | In progress on branch `ui-redesign`: 15a done |
| 16 | Deployment (VPS, Nginx, Docker, backup, performance) | Deferred: owner starts it |

---

## Phase 0: Inspect and initialize (DONE)
- Inspect PHP (including Laragon's), Composer, Node/npm, MySQL, Git and the project directory. Report any mismatch (especially PHP 8.5.12) before continuing.
- The directory already contains CLAUDE.md and SPEC.md. Scaffold Laravel 13 in a temporary folder, then move the files in without overwriting anything.
- Install and configure Livewire 4, Tailwind CSS 4 and Vite.
- .env: MySQL at 127.0.0.1, mail driver `log`, database session driver. Provide .env.example with safe placeholders.
- `git init` if needed, add a proper .gitignore, make the first commit.
- Verify the app boots under Laragon.

## Phase 1: Database, RBAC, admin bootstrap (DONE)
- Normalized schema with foreign keys, unique constraints, indexes and timestamps. Evaluate and create as needed: users, Spatie roles/permissions tables, account_invitations, TOTP and security fields, recovery_codes, login_histories, audit_logs, password reset tokens, sessions.
- User fields include a status enum: PENDING, ACTIVE, SUSPENDED, plus last_login_at and a 2FA enrollment flag.
- Roles: HR Admin, Approver, Employee. Permissions: dashboard.view, users.view, users.create, users.update, users.suspend, users.invite.
  - HR Admin has all of them. Approver and Employee have dashboard.view only.
- Centralize permission definitions. One primary role per user for now, but do not block multiple roles later. Seeders handle roles and permissions only.
- Prevent privilege escalation. HR Admin cannot change their own role. The last active HR Admin can never be suspended, demoted or deleted.
- Audit records must not be modifiable by users. Do not implement account deletion; suspend instead.
- Command `php artisan app:create-admin` (interactive):
  - Refuse if an active HR Admin already exists. Ensure roles and permissions are seeded first.
  - Ask for full name, username, email, password and confirmation (hidden input). Validate required and unique fields.
  - Create the account as ACTIVE with the HR Admin role and 2FA enrollment incomplete. No dashboard access until 2FA enrollment succeeds.
  - Never hardcode credentials. No public admin registration page.

## Phase 2: Login, TOTP, sessions, history (DONE)
- Stage 1: username or email plus password. Login rate limiting, generic error messages, reject PENDING and SUSPENDED accounts.
- Stage 2: dedicated TOTP page with a six-digit code. Regenerate the session after full authentication, then redirect to the dashboard.
- A user is never fully authenticated before TOTP. Protect all routes and Livewire actions against bypass.
- Also: CSRF, TOTP throttling, secure logout, appropriate cookie configuration.
- TOTP per RFC 6238 (six digits, 30 seconds, small time-window tolerance, replay protection where supported). Evaluate maintained libraries and verify compatibility first. Works with Google and Microsoft Authenticator.
- Recovery codes: stored hashed, single use, usable when the authenticator is unavailable.
- A user whose enrollment is incomplete (including the bootstrapped admin) is forced into 2FA enrollment at first login.
- Login history: date and time, IP, user agent, result. Update last_login_at. Avoid storing unnecessary sensitive data.
- Audit events: successful and failed login, TOTP enrollment, recovery code use.

## Phase 3: Invitation, activation, password reset, emails (DONE)
- HR Admin creates a user (full name, username, email, role) as PENDING. Validate input and use database transactions.
- Generate a cryptographically secure token and store only its hash. Expiry is configurable. The token is invalidated after use. Resend is supported.
- Activation flow:
  1. Validate token, expiry and account status. Reject expired or used invitations.
  2. User sets own password with confirmation and Laravel password rules.
  3. Generate a TOTP secret (encrypted at rest). Render the OTP Auth URI and QR code locally, never via an external QR service. Require a valid six-digit code.
  4. Enable 2FA only after successful verification. Generate recovery codes, store hashed, show once, explain single use.
  5. Mark the invitation consumed, set the account ACTIVE, redirect to login. 2FA enrollment cannot be skipped.
- Forgot password and secure reset (Laravel mechanisms).
- Emails (Laravel Mail or Notifications, responsive HTML, SMTP via .env): invitation, invitation resend, password reset.
  - Never include plaintext passwords, TOTP secrets or recovery codes.
  - Handle delivery failures honestly; never claim success when the transport fails.
  - Local testing via the log mailer or a local mail catcher.
- Audit events: user creation, invitation created, resent and consumed, password changes.

## Phase 4: UI layout, dashboard, user management (DONE)
- Design: modern minimalist enterprise SaaS. Tailwind 4 and Livewire 4. Responsive. Accessible. Few gradients and animations.
- Main layout: sidebar, top bar, profile dropdown (My Profile, Security Settings, Logout), breadcrumbs where appropriate.
- Sidebar profile block shows name, email and a role badge under them. The role is read dynamically from the database, never hardcoded. Colors: HR Admin blue, Approver purple, Employee green. Responsive on mobile.
- Reusable components: buttons, inputs, validation messages, tables, modals, dropdowns, status badges, toasts, loading states, empty states.
- Consistent styling across login, TOTP, activation, password reset, dashboard, user management, profile and security settings.
- Dashboard (all roles): welcome message, full name, role, security status, account information, relevant summary widgets. No fabricated statistics. Use clean empty states.
- Navigation: Home for everyone. User Management for HR Admin only.
- User Management (HR Admin only, also blocked on direct URL and manipulated Livewire requests):
  - List: ID, full name, username, email, role, status, 2FA status, last login, created date, actions.
  - Operations: create, edit, change role, suspend, resend invitation, view details.
  - UI: search, role and status filters, pagination, confirmation dialogs, validation, loading states, responsive table.
  - Never display passwords or TOTP secrets.
- Audit events: role changes, account suspension.

## Phase 5: Profile and security settings (DONE)
- My Profile: full name, username, email, role, status, creation date. Allow permitted personal updates. Username and email changes follow proper validation and verification steps.
- Security Settings:
  - Change password (requires current password and TOTP where appropriate).
  - View 2FA status.
  - Regenerate recovery codes after re-authentication.
  - Reconfigure the authenticator after verifying identity.
  - Disabling 2FA only through an explicitly authorized process.
- Session management: list active sessions, mark the current one, terminate other sessions, log out. Use a reliable invalidation mechanism.
- Login history view for the user.
- Optional, if it stays small: an audit trail viewer for HR Admin.
- Audit events: security configuration changes, session termination.

---

## Phase 6: Foundation cleanup (DONE)
Goal: make the stable Phase 5 base ready for the HR modules, and make the Laragon workflow routine.
- **Step 0 checks (report before changing anything):** `git log` and `git status` confirm the repository is at the Phase 5 state (expected commit 216c571). `php artisan migrate:status` on `hr_system` shows no migration that the code lacks (the database may have been migrated by discarded work). If it does, report and ask; do not roll back on your own. Confirm `http://hr-system.test` and MySQL are up (see CLAUDE.md); if not, ask the owner to start Laragon. Check the layout is centered and not pushed left at 375, 1024 and 1280px.
- **WIB:** application timezone and database connection to Asia/Jakarta (connection as `+07:00` if the MySQL timezone tables are empty). One reversible migration shifts existing time data by +7 hours. Take the column list from an inventory of every `TIMESTAMP`/datetime column, not from memory; as a hint, expect tables such as `account_invitations`, `users`, `recovery_codes`, `roles`, `permissions`, `audit_logs`, `password_reset_tokens`, `login_histories`, `failed_jobs`. Do not shift epoch columns (`sessions.last_activity`, cache, jobs) or `users.two_factor_last_timestep`. The migration refuses to run unless the MySQL server and the connection are +07:00. Prove with a real row before and after on `hr_system_test`, and with tests that TOTP (RFC 6238 vectors), replay protection, invitation expiry and reset-link expiry still work. A test fails if a new TIMESTAMP column is missing from the inventory. Replace the "UTC" label with "WIB"; remove `APP_TIMEZONE_DISPLAY` so the source of truth is one.
- **Profile pages:** move the sessions list and login history under Profile (`/profile/sessions`, `/profile/login-history`). Menu and breadcrumbs consistent. Remove the old routes. Keep username and email self-service exactly as it is. Confirm there is still no UI path to reset or disable 2FA.
- **Pruning:** scheduled idempotent command that removes expired `password_reset_tokens` and expired or consumed `account_invitations` older than a configurable retention. Register it in the scheduler. Document in README how the owner installs the scheduler in Windows Task Scheduler (`php artisan schedule:run` every minute) with verified commands; do not install it.
- **Docs:** remove Docker from the active documentation and mention it as deferred (Phase 16). Document the Laragon workflow and database rules from CLAUDE.md in README.md. (Since 2026-11-05 these notes are in docs/TECHNICAL-DECISIONS.md "Local workflow (Laragon)" and "Scheduler"; README.md summarizes them in Indonesian.)
- Known issues from PROGRESS.md that this phase may fix if small: dates shown in UTC. Leave the others listed.
- End of phase: migrate `hr_system` under the CLAUDE.md rules and verify the whole existing application through hr-system.test.

## Phase 7: Language mechanism (DONE)
Goal: English/Indonesian switching, built before the HR modules so every later sentence is registered once, in both languages.
- Language selector in Profile, stored on the user (`users.locale`). Guests and users without a choice use the configured default (English). [ASSUMPTION: no selector on public pages yet.]
- UI texts are stored in the database (table keyed by locale and key), loaded through Laravel's translator so `__()` and `@lang` work, cached with a safe invalidation, and falling back to English. Adding a language means adding rows, not code.
- Output stays escaped; no HTML in translations.
- An artisan command lists keys that lack a translation in any configured language and exits non-zero if there are any; a test runs it.
- Move the existing UI texts (layout, dashboard, user management, profile, security, auth pages, mail subjects/bodies where applicable) into the mechanism. Wording of English stays identical so existing tests keep their meaning; tests may switch to keys or to a fixed locale.
- Seeding: an idempotent seeder or migration loads the base translations. Provide a documented way to add a language.
- Do not change behavior of auth flows. Validation messages use the same mechanism.
- Authorization: only HR Admin edits translations, and only through database/console in this phase (no editing UI). [ASSUMPTION]

## Phase 8: Organization and leave balance (DONE)
Rules: DECISIONS.md sections 3, 5, 9, 10.
- **Departments:** master table managed by HR Admin (create, edit, deactivate; unique name; cannot be removed while used). Inactive departments stay on existing employees but cannot be assigned anew.
- **Employee data** in User Management: department, join date, supervisors. Marker and filter "No supervisor" for Employee and HR Admin (not for Approver).
- **Supervisor relation (many to many):** pivot table with foreign keys, unique pair, no self reference, no cycles, supervisor must be an ACTIVE Approver. Employees may have several supervisors; HR Admin too.
- **Global guard** in one service, called from every entry point (suspend, role change, delete, detach, bulk move, console): no action may leave a user who currently has an active supervisor with none. SUSPENDED subordinates are not counted. The refusal names who is affected and the way out.
- **Bulk move supervisor** by HR Admin: all or selected employees from one supervisor to another, one transaction, audited.
- **Leave balance:** periods per employee (start, end, entitlement 12, used) and an append-only ledger (who, when, reason, before, after). HR Admin views and adjusts the current period with a mandatory reason; HR Admin cannot adjust their own balance.
- **Yearly reset:** per-employee anchor date (account creation date or a date chosen by HR). Daily idempotent job closes the old period and opens a new one with 12 days on the anniversary; it catches up on missed days. Manual reset closes the current period yesterday and opens a new one today until the next anniversary; refused if the open period starts today. No carry over.
- **Permissions:** e.g. `departments.manage`, `supervisors.manage`, `leave-balance.view`, `leave-balance.adjust`, all for HR Admin only; server-side on direct URL and manipulated Livewire.
- **Audit:** departments, supervisor changes (including bulk), balance adjustments and resets, guard refusals (written after the rollback, not inside the transaction that rolls back).
- Fill `leave_anchor_date` only after the WIB shift has been applied.
- Real-process races: concurrent bulk moves, two resets on the same day, concurrent balance adjustments, two supervisors suspended at the same time.

## Phase 9: Requests (DONE)
Rules: DECISIONS.md sections 4, 8, 9.
- **Leave categories:** reference table (code, name, deducts balance, active), seeded idempotently: `annual` (deducts), `sick`, `other`. No management screen.
- **Public holidays:** table managed by HR Admin (`holidays.manage`). One function counts working days: Monday to Friday minus holidays.
- **Leave/permission request:** category, start and end DATE, working days frozen at submission, reason, status `pending|approved|rejected|cancelled`, optional evidence. Per-period allocation table so a request across a reset is split and validated per period; pending allocations reduce what is available. Periods that do not exist yet are projected from the anchor (12 days); limit how far ahead a request may reach (propose in the plan).
- **Overtime request:** `start_at` and `end_at` in WIB, whole hours only, maximum 12 hours per request, may cross midnight.
- **Validation:** the full list in DECISIONS.md section 8, enforced on the server in a transaction, with specific messages that leak nothing about other people.
- **Evidence:** optional, pdf/jpg/png, configurable size limit, content-type checked from the file, private storage with random names, downloaded through an authorizing controller only.
- **Pages:** "My requests" (list, filters, forms, detail) for all three roles; cancel while pending (safe against double cancel). Users without an approval path see the submit button disabled with the reason.
- **Audit:** creation, cancellation, evidence upload, holiday changes (no full reason text).
- Real-process races: overlapping submissions by one user, two submissions that together exceed the balance, double cancel.

## Phase 10: Approvals (DONE)
Rules: DECISIONS.md sections 3, 5, 6.
- **Who may decide (one service):** Employee requests by a related ACTIVE Approver; Approver requests by any ACTIVE HR Admin; HR Admin requests by an assigned supervisor; never the requester. Evaluated at the moment of each action, not from the state at submission.
- **Pages:** "Approvals" inbox (oldest first, filter by type, count in the menu), detail with requester, dates or hours, allocation per period, reason, evidence, the requester's remaining balance per period, and for overtime the total overtime hours that day. Approve (optional note) and Reject (reason required, at least 5 characters). A simple "Decided by me" tab.
- **Two-layer lock (DECISIONS.md section 6):** soft lock when opened (about 5 minutes, heartbeat, view-only mode for others showing who holds it, expiry checked on access, no background job); authoritative check at submit inside a transaction with row locks.
- **Leave approval deducts balance** in one transaction in a fixed lock order: recheck every allocation, increase used, write ledger entries, set status. Closed periods may be charged through this path only. Projected periods are mapped to real ones. If balance is insufficient at approval, fail with a clear message and keep the request pending. Non-deducting categories and rejections do not touch balance.
- **Overtime approval** has the same locking and rejection rules and does not touch balance.
- **Requester side:** status, who decided, when, rejection reason. Cancel versus decision races end cleanly for the loser.
- **Audit:** approve, reject, refused for insufficient balance, attempts without authority.
- Real-process races: two supervisors at once, approve versus cancel, two requests competing for the last balance, approve versus HR adjustment, decider loses authority mid-flight, soft lock takeover after expiry.

## Phase 11: History, recap, export (DONE)
- **History:** employees see their own requests; supervisors see requests of related employees; HR Admin sees all; filters by employee, department, type/category, status, date range. Authorization on the server.
- **Recap:** per employee balance and usage per period; per department and month, leave counts by category and total overtime hours. Real data only, efficient queries (no N+1).
- **CSV export** without a new library: streamed, protected against formula injection (cells starting with `=`, `+`, `-`, `@`, tab or CR get a prefix), only what the requester may see, no sensitive columns.

## Phase 12: Dashboard (DONE)
Real data only, no invented numbers, clean empty states: own balance and current period, own pending requests, number waiting for my decision (if entitled), and for HR Admin the pending totals and employees without a supervisor. Keep the existing dashboard sections.

## Phase 13: Email notifications (optional, keep it small) (DONE)
Email to the approver(s) when a request is submitted and to the requester when it is decided. Mailer `log` by default, SMTP through .env, translated, no sensitive data, honest handling of delivery failure that never fails the business transaction. Do this phase only if the owner confirms.

## Phase 14: Full verification and documentation (DONE)
- First the three owner decisions of DECISIONS.md section 11: sick leave not backdated (HR emergency sick leave is backlog), decisions are final with a warning in both decision dialogs, and automatic rejection by the system of a user's pending requests on suspension (same transaction, no email, audited with counts, count in the suspend dialog, real-process race against approval).
- Walk the whole application through hr-system.test in a real browser as each role: invitation, activation, 2FA, login, departments, supervisors, balance, requests, approvals, history, recap, export, dashboard, language switch, profile, sessions.
- Run the full suite, mutation checks on the critical services, build, Pint, audits.
- Sync SPEC.md, README.md and PROGRESS.md. List known limitations and the owner's manual steps (migration, scheduler, deployment notes).

## Phase 15: UI redesign (started 2026-11-05)
Started by the owner, with their direction. Work on the branch `ui-redesign`, never merged into `main` without the owner. Design reference: https://github.com/satnaing/shadcn-admin (MIT), cloned outside the project as reading material only; the design is rewritten as Blade, Tailwind CSS 4 and Alpine (no React, TypeScript, Radix or new package). Credit in `THIRD-PARTY-NOTICES.md`. Font: IBM Plex Sans. Light theme only, with tokens structured so a dark theme can be added later. Primary is the blue of PT Peranti Digital Solusindo (placeholder until `design/brand/` holds the brand files). Logo from `design/brand/` (company name as text until then). Every new UI text in English and Indonesian. No database migration.

| Stage | Content | Status |
|---|---|---|
| 15a | Foundation and pilot page: tokens and base styles; layout shell (grouped sidebar foldable to icons, user block with its menu, header with breadcrumbs, phone drawer); `x-ui.page` as the only page container, every authenticated page migrated to it; base components restyled with their names and properties kept; `/ui-kit` gallery (local only); puppeteer layout checker on every authenticated route at 375, 768, 1024, 1280 and 1920 px with before/after screenshots in `design/screens/` (git-ignored); dashboard rebuilt as the pilot with existing data and no chart; WCAG contrast measured by script | Done |
| 15b | Form components after the reference's Select, Command, Popover, Calendar and input OTP: searchable select, multi-select for filters, date picker, six-box OTP input | Pending |
| 15c | Auth pages (login, two-factor, activation, password reset, recovery codes) after the reference's sign-in and OTP pages, with the logo | Pending |
| 15d | Every application page, in groups (requests and approvals; history and recap; users, departments and holidays; profile and security) | Pending |
| 15e | Error pages, empty states, tables as cards on phones, accessibility audit, final verification | Pending |

## Phase 16: Deployment (deferred)
Started by the owner only: VPS, Nginx, optional Docker, backup/restore, performance checks, HTTPS cookie settings, re-measure the forgot-password timing on the production web server.

---

## Final acceptance checklist
Laravel 13 on PHP 8.5.12 · runs on Laragon at hr-system.test · MySQL only · secure admin bootstrap · invitation, activation and mandatory TOTP · RBAC correct · User Management HR Admin only · departments, supervisors with the global guard · leave balance with periods and yearly reset · leave and overtime requests with all validations · approvals with two-layer lock and correct balance deduction · history, recap and CSV export · dashboard with real data · English/Indonesian switch · profile, sessions, login history · audit logging · WIB everywhere · responsive UI · tests pass · README complete · Git clean with no secrets.