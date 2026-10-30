# HR Management System: Project Context

Laravel 13 HR foundation app (authentication, mandatory TOTP 2FA, RBAC, user management).
Full requirements: SPEC.md (read only the phase you are working on). Progress log: PROGRESS.md.

## Environment
- Windows + Laragon. Project path: C:\laragon\www\hr-system (never move it).
- PHP 8.5.12 (Laragon's PHP may not be in PATH; look under C:\laragon\bin\php), Composer, Node/npm, Vite, Git.
- MySQL 8.0.30 local (Laragon), host 127.0.0.1. Never run tests against the real database; use an isolated test database.
- Stack: Laravel 13, Livewire 4 (Alpine via Livewire), Tailwind CSS 4, Spatie Laravel Permission (only if compatible).
- Verify every package's compatibility with Laravel 13 and PHP 8.5 via Composer before installing. Never silently use a different PHP version.
- Docker is for the PHP runtime only (no MySQL, Redis, Nginx or Apache containers). It reaches MySQL via host.docker.internal and serves on port 8000.

## Working rules
- Do only the current phase. Do not build features outside SPEC.md or add unrelated HR modules.
- Laravel-native first. Minimal dependencies. No needless service or repository layers.
- Authorization is always enforced server-side (middleware, policies, gates), including Livewire actions. Role labels in the UI are informational only.
- Never hardcode role names in feature code; use central permission definitions.
- Never log or expose: passwords, TOTP secrets, authentication codes, invitation tokens, recovery codes.
- Storage: invitation tokens and recovery codes hashed; TOTP secret encrypted; never show a stored secret again after enrollment.
- Never overwrite existing files or Git history. Check `git status` and the staged diff before each commit. Never commit .env or secrets. No force push, no reset, no push without permission.
- Do not claim a feature is complete unless it is implemented and verified (tests, build, or manual check). Never fabricate results.
- Ask only when blocked (missing credentials, destructive operation, unavailable infrastructure). Otherwise decide, and record significant decisions in README.md.

## End of every phase
1. Run the tests and the frontend build for the work done so far.
2. Update PROGRESS.md: what is done, what is next, known issues, decisions.
3. Commit with a descriptive message.
4. Stop and report in 10 lines or fewer. Do not start the next phase.
