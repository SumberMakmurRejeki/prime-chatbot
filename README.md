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
| Repository | Independent Git repository with no commits yet; no remote configured |

No Git remote exists yet and the private remote has not been created. Do not add
or invent a clone URL; remote configuration is the owner's decision.

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
- An AI assistant over the knowledge-document registry, backed by the
  temporary Qdrant vector store (see "AI subsystem status").

## Stack and prerequisites

| Component | Requirement |
|---|---|
| Laravel | 13.35.0 (`composer.lock`) |
| PHP | 8.3.36 (verified runtime; `composer.json` requires `^8.3`) |
| Composer | 2.x, matching the existing `composer.lock` |
| Node.js | Any current LTS that satisfies the existing `package-lock.json` (Vite 6, Vue 3.5, Tailwind 4) |
| MySQL | One server hosting the development database and the QA database |
| Qdrant | Temporary legacy dependency for AI retrieval/indexing; not needed for setup, tests or non-AI web pages |

## Independent local setup

1. Install PHP dependencies with `composer install` (lockfile unchanged).
2. Copy `.env.example` to `.env`, then run `php artisan key:generate`.
3. Set `DB_USERNAME` and `DB_PASSWORD` for the development database.
4. Create the development database `db_preventive_maintenance_ai` and run
   `php artisan migrate` only against this approved development database.
   Never target either protected original PRIME database or an unapproved one.
5. Install frontend dependencies with `npm ci`, then `npm run build`.

Setup assumes PHP, Composer and Node are on `PATH`. Select the verified PHP
8.3.36 binary explicitly when several versions are installed, for example:

```
C:\laragon\bin\php\php-8.3.36-nts-Win32-vs16-x64\php.exe artisan --version
```

## Business dates and application timezone

Business dates use `Asia/Jakarta` in the PM date logic. The global Laravel
application timezone is `UTC` (`config/app.php`, overridable through
`APP_TIMEZONE`) and changes only with separate approval. Do not set the global
timezone to `Asia/Jakarta` as a setup shortcut; use the PM business-date helper
for business-day logic instead.

## Environment templates

- `.env.example` — development template; `APP_ENV=local`, MySQL
  `db_preventive_maintenance_ai`, collection `prime_google_knowledge`.
- `.env.testing.example` — QA/testing template; `APP_ENV=testing`, MySQL
  `db_preventive_maintenance_ai_test`, collection `prime_google_qa_knowledge`.

Both templates are part of the baseline. Local `.env` files are ignored and must
never be committed. Fill secrets per machine; no real credential belongs here.

## Database identities

| Purpose | Database |
|---|---|
| Development | `db_preventive_maintenance_ai` |
| QA/testing | `db_preventive_maintenance_ai_test` |
| Protected original | `db_preventive_maintenance` |
| Protected original | `db_preventive_maintenance_test` |

`app/Providers/TestingDatabaseServiceProvider.php` enforces this contract at
boot: any effective MySQL connection pointing at a protected original database
raises `RuntimeException` in every environment, and the `testing` environment
must point at the approved QA database. The guard also covers the
`webpush.database_connection` secondary connection.

## Testing isolation contract

`phpunit.xml` pins the test environment so a local `.env` cannot leak into
tests: SQLite `:memory:`, array cache/session, array mail, `sync` queue, and
`QDRANT_COLLECTION=prime_google_qa_knowledge`. Tests must not require MySQL,
Qdrant or network access.

Run tests with the pinned PHP 8.3.36 binary; `phpunit.xml` forces SQLite
`:memory:` even when local environment variables are set:

```
C:\laragon\bin\php\php-8.3.36-nts-Win32-vs16-x64\php.exe artisan test
```

or:

```
C:\laragon\bin\php\php-8.3.36-nts-Win32-vs16-x64\php.exe vendor\bin\phpunit
```

The tests use SQLite `:memory:` and must not require MySQL, Qdrant or network
access. Keep outbound HTTP fakes in place.

## AI subsystem status

The AI assistant, provider settings, knowledge-document ingestion and retrieval
services are inherited from PRIME. Retrieval uses a vector-store abstraction;
Qdrant is its only currently bound implementation.

Qdrant is temporary legacy code, not a supported setup dependency. Do not enable
or connect to it during ordinary setup or baseline verification, and never let
it access original PRIME data or collections. Collection configuration has no
shared default; an empty value is rejected when the vector-store configuration
is resolved. The environment templates name isolated PRIME Google collections.

Gemini File Search is the planned replacement, not an implemented capability.
`GEMINI_API_KEY` in the environment templates is an empty placeholder; no Gemini
File Search integration exists yet.

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

- Use PHP 8.3.36 explicitly; do not rely on the ambient PHP version on `PATH`.
- The only approved MySQL databases are `db_preventive_maintenance_ai` for
  development and `db_preventive_maintenance_ai_test` for explicit QA work.
  Never target the protected original databases.
- Do not connect to Qdrant or run indexing as part of setup or tests.
- Do not run `composer update` or `npm update` for baseline setup; use the
  committed lockfiles with `composer install` and `npm ci`.
- PHPUnit is isolated to SQLite `:memory:`. Do not repoint tests to MySQL.
- The QA MySQL database is only for explicit QA commands that validate their
  database target; it is not the PHPUnit database.

## Known gaps

- `public/favicon.ico` is a zero-byte placeholder and is excluded from the Git
  baseline; it is not referenced by the application.
- Gemini File Search integration, its documentation and its tests do not exist
  yet.

