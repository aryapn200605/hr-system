# SPEC: HR Management System (foundation)

Scope: authentication, TOTP 2FA, invitation-based activation, RBAC, user management, dashboard, profile and security settings, audit trail.
Out of scope: email verification, account lockout, notification center, periodic password expiry, any other HR module.
Work one phase at a time. Each phase ends as described in CLAUDE.md. Add audit-log calls and tests alongside each feature, not afterwards.
Tests per phase: success path, validation failure, authorization denial, and throttling where relevant.

---

## Phase 0: Inspect and initialize
- Inspect PHP (including Laragon's), Composer, Node/npm, MySQL, Git and the project directory. Report any mismatch (especially PHP 8.5.12) before continuing.
- The directory already contains CLAUDE.md and SPEC.md. Scaffold Laravel 13 in a temporary folder, then move the files in without overwriting anything.
- Install and configure Livewire 4, Tailwind CSS 4 and Vite.
- .env: MySQL at 127.0.0.1, mail driver `log`, database session driver. Provide .env.example with safe placeholders.
- `git init` if needed, add a proper .gitignore, make the first commit.
- Verify the app boots under Laragon.

## Phase 1: Database, RBAC, admin bootstrap
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

## Phase 2: Login, TOTP, sessions, history
- Stage 1: username or email plus password. Login rate limiting, generic error messages, reject PENDING and SUSPENDED accounts.
- Stage 2: dedicated TOTP page with a six-digit code. Regenerate the session after full authentication, then redirect to the dashboard.
- A user is never fully authenticated before TOTP. Protect all routes and Livewire actions against bypass.
- Also: CSRF, TOTP throttling, secure logout, appropriate cookie configuration.
- TOTP per RFC 6238 (six digits, 30 seconds, small time-window tolerance, replay protection where supported). Evaluate maintained libraries and verify compatibility first. Works with Google and Microsoft Authenticator.
- Recovery codes: stored hashed, single use, usable when the authenticator is unavailable.
- A user whose enrollment is incomplete (including the bootstrapped admin) is forced into 2FA enrollment at first login.
- Login history: date and time, IP, user agent, result. Update last_login_at. Avoid storing unnecessary sensitive data.
- Audit events: successful and failed login, TOTP enrollment, recovery code use.

## Phase 3: Invitation, activation, password reset, emails
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

## Phase 4: UI layout, dashboard, user management
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

## Phase 5: Profile and security settings
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

## Phase 6: Docker runtime, documentation, final verification
- Dual runtime on the same source code and the same local MySQL.
  - Local mode: Laragon PHP 8.5.12, Laragon web server, local Composer and Artisan, DB host 127.0.0.1.
  - Docker mode: PHP 8.5.12 image, built-in dev server on port 8000, Composer and Artisan inside the container, project directory mounted, DB host host.docker.internal.
  - Minimal Dockerfile and docker-compose.yml, required PHP extensions installed, no containers for MySQL, Redis, Nginx or Apache.
  - Handle dependency consistency between the two modes (for example the vendor directory), and use a documented runtime-specific environment mechanism instead of hand-editing config each time.
  - If one runtime is unavailable, the other must still work. Avoid port conflicts and simultaneous runtimes.
- README.md: overview, stack, prerequisites, Laragon setup, Local and Docker setup, environment variables, Composer and npm install, migrations and seeding, first HR Admin creation, running in both modes, Artisan in both modes, running tests, SMTP configuration, security notes, architecture decisions, Git workflow. Use verified commands only.
- Final checks: all migrations, full test suite, frontend build, both runtimes verified, `git status` clean, no sensitive file tracked.
- Final report: summary, structure, installed packages, schema overview, security overview, run instructions for both modes, first-admin instructions, test results, branch and commit summary, known limitations, recommended next steps.

---

## Final acceptance checklist
Laravel 13 on PHP 8.5.12 · Local mode works · Docker mode works · same Laragon MySQL, no MySQL container · secure admin bootstrap · HR Admin can create users and invitations are generated · secure activation with mandatory TOTP · login needs password and TOTP · RBAC correct · User Management HR Admin only · dashboard for all roles · profile, security settings and role badge · password reset · login history and sessions · audit logging · responsive UI · tests pass · README complete · Git clean with no secrets.
