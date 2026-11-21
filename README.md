# PRIME Google

PRIME Google is an independent Laravel application derived from PRIME (preventive
maintenance). It keeps the PRIME domain workflows while running on its own
database and vector-store identities. It is not a deployment target for the
original PRIME project.

This repository is its own Git repository. The original PRIME working copy and
its databases are separate and must not be modified from here.

## Project identity

| Item | Value |
|---|---|
| Project | PRIME Google |
| Local path | `C:\laragon\www\prime-google` |
| Repository | `SumberMakmurRejeki/prime-chatbot` (GitHub remote `origin`) |

Branch and release status change over time; consult Git for the current state.
The hub [Project State](Docs/prime-task-hub/PROJECT_STATE.md) distinguishes
implemented behavior from local candidates, historical verification, and work
that is not released or operationally activated.

## Current task state

Current task status and ownership live in the [PRIME Task Hub](Docs/prime-task-hub/PRIME_TASK_INDEX.md):

- [Task Index](Docs/prime-task-hub/PRIME_TASK_INDEX.md) — parent tasks and children.
- [Active Work](Docs/prime-task-hub/PRIME_ACTIVE_WORK.md) — current owners and handoffs.
- [Project State](Docs/prime-task-hub/PROJECT_STATE.md) — code, PR, and release baseline.

Historical source documents are preserved under the hub's `archive/`; archived
plans and QA results describe their original checkpoints, not current status.

## Current capabilities

The application implements the PRIME preventive-maintenance domain as its own
project:

- Machine and location master data, including QR-based machine access.
- PM checksheets, schedules, generated schedule dates and occurrences.
- PM execution capture with media evidence, review and history.
- Breakdown reporting, maintenance calendar, dashboard and reports with
  Excel/PDF export.
- In-app notifications and application settings. Web Push was removed from the
  runtime; only a legacy service-worker cleanup shim and the `push_subscriptions`
  schema remain (see "Database identities" for the guarded secondary connection).
- Main uses the temporary Qdrant vector store for the AI assistant. Gemini File
  Search is its replacement with local candidate work only; it is not merged
  into `main`. Activation requires separate verification and Owner approval.

## Business roles

PRIME has two business roles:

- **Admin** — administration/master data, checksheets, settings and permitted
  AI features. Admin does not run PM Executor.
- **Operator** — runs PM Executor and Input Breakdown. Operator does not access
  Admin or AI menus.

Permissions are enforced on the backend, not only by hiding UI. The separate
`manage_ai` permission governs AI settings independently of the Admin role.

## Stack and local setup

Laravel 13.35, PHP 8.3.36, Composer 2.x, Vue 3.5/Inertia, Node LTS, and MySQL.
Laragon workspace: `C:\laragon\www\prime-google`; use Laragon's PHP 8.3.36
binary explicitly if multiple PHP versions are installed.

1. Run `composer install`; copy `.env.example` to `.env`, set credentials, then
   run `php artisan key:generate`.
2. Configure only the approved development database and run migrations there.
3. Run `npm ci` and `npm run build`.

## Environment and database safety

`.env.example` is for development (`db_preventive_maintenance_ai`,
`prime_google_knowledge`); `.env.testing.example` is for QA
(`db_preventive_maintenance_ai_test`, `prime_google_qa_knowledge`). The only
approved MySQL databases are those two. `db_preventive_maintenance` and
`db_preventive_maintenance_test` are protected originals. The testing database
is for explicit QA commands only; PHPUnit never uses MySQL.

`TestingDatabaseServiceProvider` rejects protected MySQL connections in every
environment and requires the approved QA database in `testing`; the guard also
covers `webpush.database_connection`. Do not commit `.env` or credentials.

PM business dates use `Asia/Jakarta`; Laravel's global timezone remains `UTC`
unless separately approved. Use PM business-date logic rather than changing the
global timezone.

## Testing

`phpunit.xml` isolates tests to SQLite `:memory:`, array cache/session/mail,
`sync` queue, and `prime_google_qa_knowledge`. Tests must not use MySQL, Qdrant or
the network; keep outbound HTTP fakes. Run with PHP 8.3.36:

```
C:\laragon\bin\php\php-8.3.36-nts-Win32-vs16-x64\php.exe artisan test
```

In `main`, AI retrieval uses the temporary Qdrant vector store. Gemini File
Search replacement work exists only as a local candidate and is not merged into
`main`; separate verification and Owner approval are required before activation.

The frontend migration to Laravel + Inertia/Vue with NovaSpark is incremental;
Blade and Vue business pages coexist. Historical QA results are checkpoint-bound,
not current or production-readiness claims.

## Seeder safety

`database/seeders/DatabaseSeeder.php` does not create users or account
credentials. It seeds location and machine reference data only, and default
seeding is restricted to the approved `local` and `testing` environments.
Automated tests create accounts explicitly with factories or test fixtures.

Development and QA accounts must be provisioned explicitly with unique
credentials; there are no default account credentials. The QA scenario command
`qa:provision-pm-scenario` requires the existing active, authorized
`admin.prime` and `operator.prime` accounts. It refuses execution if either
required account is missing or not ready.

Do not run seeders against any database outside the approved identities.

## Safe command boundaries

- Use PHP 8.3.36 explicitly; do not rely on the ambient `PATH` version.
- Do not connect to Qdrant or run indexing as part of setup or tests.
- PHPUnit stays on SQLite `:memory:`; never repoint tests to MySQL.
- Do not run `composer update` or `npm update` for baseline setup; use the
  committed lockfiles with `composer install` and `npm ci`.

## Known gaps

- `public/favicon.ico` is a zero-byte placeholder and is excluded from the Git
  baseline; it is not referenced by the application.

