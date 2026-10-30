# Progress Log

## Current status
- Phase 0: done.
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
- Tests now use `hr_system_test` via phpunit.xml. Added tests/Feature/FoundationSmokeTest.php.

### Verified
- `php artisan test`: 4 passed.
- `npm run build`: succeeded.
- Composer and npm report no known vulnerabilities.
- Temporary Apache instance built from Laragon's httpd and PHP 8.5.12 module, on 127.0.0.1:8088:
  `/`, `/up`, the built CSS and `livewire.js` all returned 200, a session row was written, laravel.log stayed empty.

### Not verified
- `http://hr-system.test` does not resolve yet (see known issues), so the app has not been opened through Laragon's running Apache.

### Known issues
- Laragon's running Apache does not serve this project. httpd.conf has DocumentRoot `C:/Program-project/php-root-folder`
  while laragon.ini has `C:\laragon\www`, AutoVirtualHosts is 0, and there is no hr-system.test vhost or hosts entry.
  These files are shared with other projects, so they were left unchanged.
  To fix: enable Auto Virtual Hosts in Laragon's preferences, then reload Apache.
- PHP extensions `zip`, `sodium` and `pdo_sqlite` are not enabled in Laragon's php.ini. php.ini was left unchanged.
  Composer needs zip: run it as `php -d extension=zip C:\laragon\bin\composer\composer.phar ...`.
- `vite build` warns that the optional `fontaine` package is missing. Fonts still build; only the optimized fallback is skipped.

### Decisions
Recorded in README.md under "Project decisions".
