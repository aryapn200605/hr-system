# Progress Log

## Current status
- Phase 0: done and committed (4b6b06e, branch main).
- Phase 0 follow-up (cleanup after Laragon changes): done, see "Phase 0 follow-up" below.
- Next: Phase 1 (Database, RBAC, admin bootstrap). Not started.

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