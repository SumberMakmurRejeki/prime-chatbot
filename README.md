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

After the agent-foundation baseline, development continues on feature
branches. At the 2026-11-09 Gemini milestone finalization the working branch
is `feature/gemini-file-search`; the Gemini implementation is committed locally
as `0981c39` (indexing provenance and scoped retrieval) and `37e82cc`
(Assistant binding), followed by the documentation commit that carries this
text. `origin` carries `main`, whose HEAD is the merge commit of PR #1 from
`chore/prime-google-agent-foundation`; the feature branch is not published yet.
Run `git status` and `git log` for the current working-tree and branch state;
historical observations do not describe later changes.

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
- The Admin-only Assistant uses Gemini for answer generation and Gemini File Search for SOP retrieval; it does not fall back to Qdrant. Only the current published SOP revision is eligible, and returned citations must match the trusted registry and import identity.
- Qdrant remains in the legacy SOP upload/index/publish lifecycle and its vector-store implementation. The Gemini Assistant binding does not remove those Qdrant dependencies; see "AI subsystem status".

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

**Implemented in the working tree:** `GroundedAnswerService` is bound to `GeminiGroundedAnswerService`; Assistant conversations use Gemini answer generation with a scoped Gemini File Search tool. Retrieval candidates require the current published, `ready` SOP revision, a completed Gemini index row, matching document snapshot and configured store, and matching credential configuration/revision provenance. Historical revisions and rows lacking required provenance or `import_identity` are excluded (fail closed). There is no implicit Qdrant fallback in the Assistant.

**Citation trust:** PRIME builds the trusted citation map from eligible registry/index records. Gemini citation annotations must match the trusted document code, canonical version, store URI and generation-specific `import_identity`; unverified citations are discarded. A response without a verified citation is not presented as an SOP answer.

**Credential and store configuration:** File Search uses the active Gemini chat-provider revision for both answer generation and indexing operations; credential material is not stored in `config/gemini_file_search.php`. The File Search Store is configured separately through `GEMINI_FILE_SEARCH_STORE_ID`. Indexing is opt-in (`GEMINI_FILE_SEARCH_INDEXING_ENABLED`, default `false`) and queue-based. Do not confuse that indexing switch with the Assistant retrieval binding.

**Verified in QA and tests:** OMP 58 reported 378 tests and 1,738 assertions passing, along with Admin-only authorization, published-SOP eligibility, citation/import-identity checks, no Assistant fallback, synthetic live Gemini verification, and a browser Assistant E2E. On the 2026-11-09 finalization run, `tests/Feature/AI` plus `tests/Feature/KnowledgeDocumentLifecycleTest.php` reproduced 378 tests and 1,738 assertions, and the full suite passed with 1,193 tests and 6,970 assertions (PHP 8.3.36, SQLite `:memory:`). Live and browser checks were not re-run. Gemini has not been approved for operational cutover.

**Operational cutover pending:** The migration adding Gemini indexing credential provenance and `import_identity` is committed on the feature branch but has not been executed against the operational target. Existing index rows without provenance remain ineligible; do not treat historical records as verified. AI Settings currently creates every provider revision with the `openai-compatible` driver, so creating/revising a Gemini provider through that UI is not correct. The UI correction and operational migration/cutover need separate verification and Owner approval.

**Legacy Qdrant:** Qdrant is not the Assistant's active answer/retrieval backend, but remains in the SOP document lifecycle and embedding/vector-store services. Qdrant removal is a separate milestone and is not part of this change. Gemini is the target RAG provider; this working-tree implementation is not a production-readiness or operational-activation claim.

The front end is migrating incrementally from Blade to Laravel + Inertia/Vue
with the NovaSpark Design System as the target. Vue pages and Blade business
pages coexist; migration is not complete. Retain Blade where technically
required, including the Inertia root template, and remove a Blade business page
only after its Vue replacement is verified and accepted.

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

