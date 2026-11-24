<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.3. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>

## PRIME Google Project Rules

The block above (`<laravel-boost-guidelines>`) is generated and managed by
Laravel Boost. The rules below are project-specific and live outside that
managed block.

- Workspace boundary: `C:\laragon\www\prime-google`. This is an independent Git
  repository; the original PRIME working copy and its databases MUST NOT be
  modified from here.
- Owner approval is REQUIRED before Git commits, pushes, PR creation, merges, destructive actions, production cutover, or runtime database mutations.
- Execution model: one Oh My Pi (OMP) agent, sequential execution, one scoped assignment at a time. Do not create subagents unless explicitly requested.
- Development workflow: PLAN → IMPLEMENT → VERIFY → REVIEW → CLEAN → DONE.
- Correction workflow: FAIL → FIX → VERIFY → REVIEW.
- Environment blocker workflow: ENVIRONMENT BLOCKED → UNBLOCK ENVIRONMENT → VERIFY.
- Apply proportional planning and verification. Small, clearly scoped fixes may skip formal planning.
- After two unsuccessful targeted correction attempts, stop, preserve the checkpoint, and report the blocker.
- Do not let a blocked task prevent independent ready work.
- Use Git CLI, GitHub CLI, or GitHub MCP as appropriate.
- For coding assignments, activate `clean-code` and `ponytail`. Activate other skills only when relevant.
- Project-specific sources:
  - Repository: `C:\laragon\www\prime-google`
  - Project overview: `C:\laragon\www\prime-google\README.md`
  - Active Task Hub: `C:\laragon\www\prime-management\prime-task-hub`
  - Task router: `C:\laragon\www\prime-management\prime-task-hub\PRIME_TASK_INDEX.md`
  - Active assignment: `C:\laragon\www\prime-management\prime-task-hub\PRIME_ACTIVE_WORK.md`
  - Technical state: `C:\laragon\www\prime-management\prime-task-hub\PROJECT_STATE.md`
  - Delivery documents: `C:\laragon\www\prime-management\prime-task-hub\delivery`
  - Product scope: `C:\laragon\www\prime-google\specs\product\SCOPE_LATEST.yaml`
- The ten delivery documents are the canonical source of task scope, business requirements, acceptance criteria, and detailed task status.
- PRIME_TASK_INDEX.md is a lightweight routing and status summary, not a replacement for detailed acceptance criteria.
- Update task status only within an explicitly authorized documentation scope and based on verified evidence.
- If the external Task Hub is unavailable, report **Task Hub unavailable**. Do not use obsolete repository snapshots as the active source of truth.
- Distinguish implemented features from future Gemini File Search plans; do not
  report planned capabilities as done.
- Do not assume the legacy Qdrant code represents the intended final AI backend.
- Do not override MCP tool restrictions or database guards (for example
  `app/Providers/TestingDatabaseServiceProvider.php`).
- Global engineering standards remain external; do not copy them into this
  repository.
- Code Commenting & Human Readability Standard:
  `C:\laragon\www\prime-google\.ai\rules\code-commenting.md`
- Code comments and documentation descriptions must use clear Indonesian. Identifiers remain in English.
- Read the commenting standard before coding or reviewing relevant code comments, subject to Laravel Boost's mandatory project-rule loading requirements.
- Apply the standard incrementally to new and modified code. Do not perform repository-wide commenting.
- Prefer PHPDoc for nontrivial PHP contracts and inline comments only where complex logic requires additional explanation.
- Do not create additional rule files without explicit Owner authorization.
- LOCAL ENVIRONMENT: PRIME Google runs on Windows with Laragon, not Laravel
  Herd. Do not assume Herd services, the Herd CLI, or automatic `*.test`
  domains; use the actual project environment and verified local URL
  configuration. Use the project's PHP 8.3.36 runtime when executing PHP
  commands.
- TINKER AND DATABASE SAFETY: Generic Boost guidance does not constitute
  authorization to execute Tinker or modify database records. Tinker,
  migrations, seeders, database mutations, and destructive commands require
  explicit task scope and Owner approval where applicable. For normal
  verification, prefer source inspection and isolated tests.
- FRONTEND SKILLS: For Inertia/Vue work, use an installed and verified
  relevant skill. If a guideline mentions `inertia-vue-development` but that
  skill is not available, do not claim it has been activated; use existing
  project conventions and version-specific documentation instead. Do not
  install a replacement skill or create a new one without an explicit task.



## PRIME Context Loading & Token Efficiency

Use progressive context loading. Retrieve the smallest sufficient context for the active assignment.

These rules supplement Laravel Boost and MUST NOT bypass its mandatory project-rule or skill-loading requirements.

### Entry point

Use the active assignment and task router:

```
C:\laragon\www\prime-management\prime-task-hub\PRIME_TASK_INDEX.md
```

Use the index to locate the relevant delivery document. Do not automatically load all delivery documents.

### Context by assignment

| Assignment     | Required context                                             |
| -------------- | ------------------------------------------------------------ |
| RECON          | Active scope and relevant evidence                           |
| Master Roadmap | Task index, technical state, dependencies, blockers, and relevant delivery sections |
| Feature PLAN   | Relevant task sections and affected source code              |
| IMPLEMENT      | Approved plan, task scope, acceptance criteria, and applicable rules |
| Bug Fix        | Error evidence, affected code, and relevant business rules   |
| VERIFY         | Acceptance criteria, changed code, and relevant tests        |
| REVIEW         | Relevant diff, acceptance criteria, and verification evidence |
| Git            | Approved scope, Git status, diff, and relevant checkpoint    |

### Roadmap planning

- Cover all 22 parent tasks and 6 child tasks.
- Start with the task index instead of loading every delivery document in full.
- Retrieve task objectives, status, dependencies, blockers, remaining work, and relevant acceptance criteria.
- Verify actual repository checkpoints before making implementation decisions.
- Expand document sections only when additional context is required.
- Do not load detailed code examples or component documentation unless they affect a roadmap decision.
- Do not recreate accepted plans or completed implementations without a concrete reason.

### Source code exploration

- Use targeted Grep, Glob, Read, and symbol searches.
- Use jCodeMunch when available for references, callers, dependencies, and impact analysis.
- Read the actual source before modifying code.
- Avoid full repository scans for narrowly scoped assignments.
- Avoid dumping large files, logs, diffs, or test outputs into context unnecessarily.
- Retrieve version-specific framework documentation only when needed.

### Context reuse

- Reuse verified information already available in the current session.
- Revalidate evidence only when the candidate, environment, scope, or relevant state changes.
- Preserve concise checkpoints for unfinished work.
- Do not repeatedly load the same documents without a concrete reason.
- Do not read historical archives unless required evidence is unavailable in active documentation.

### Safety

Token optimization must never omit mandatory project rules, authorization, database guards, acceptance criteria, or verification required by the risk of the change.

Prefer focused evidence over repeated discovery.

## PRIME Engineering Quality Principles — Mandatory

1. **SCALABLE** — Architecture follows growth in machines, PM schedules,
   breakdown data, history and users. Remove predictable bottlenecks on evidence,
   not prediction.
2. **MODULAR** — Keep UI, controllers, business logic and data access separate.
   Reuse existing components and services before adding coupling.
3. **MAINTAINABLE** — Follow Laravel/Vue conventions, use clear names, and keep
   regression coverage proportional so code stays easy to read, change and ship.
4. **NO OVERENGINEERING** — KISS and YAGNI. No abstraction, internal framework,
   service, interface, dependency or configuration without a current need.
5. **NO UNDERENGINEERING** — Simplicity must not remove validation,
   authorization, transactions, data integrity, concurrency safety, error
   handling or required tests.
6. **MODERN USER-FRIENDLY UI/UX** — Vue/Inertia with Vuetify is the target frontend stack. Prefer existing Vuetify components over equivalent custom implementations. Maintain a consistent, responsive, accessible UI with clear loading, error, and empty states. Migrate Blade incrementally, preserve business parity, and avoid arbitrary rewrites.

7. **ROLE-BASED USABILITY** — PRIME has two business roles: **Admin** (master
   data, checksheets, settings, permitted AI features; not PM Executor) and
   **Operator** (PM Executor and Input Breakdown; not Admin/AI menus).
   Permissions are enforced server-side, not only by hiding UI. `manage_ai`
   stays a separate permission and no new business roles are added.
8. **SECURE, RELIABLE & HEALTHY** — Least privilege, input validation, server
   authorization, credential safety, database guards, transaction integrity, safe
   failure, proportional logging and risk-based rollback/recovery.

### Decision rules

- Reuse before build; one source of truth.
- Preserve backward compatibility where relevant.
- No speculative refactoring.
- Test proportionally to risk.
- Make no unsupported security or scalability claims.
- No large audit for every small change; review findings must be actionable.

## PRIME Vuetify Frontend Rules

- Vue/Inertia and Vuetify are the approved frontend technology direction.
- Verify installed Vue, Inertia, Vite, and Vuetify versions before relying on package APIs.
- Vuetify installation and dependency changes require an approved implementation scope.
- Prefer native Vuetify components before creating equivalent custom components.
- Reuse a centralized Vuetify theme and configuration.
- Preserve existing backend contracts, validation, authorization, routes, and business behavior.
- Migrate existing Blade and Vue pages incrementally.
- Retain active Blade pages until their replacements meet acceptance and cutover is approved.
- Use Vuetify MCP when available and relevant to the current component.
- Retrieve only the component documentation, props, events, slots, and APIs required by the active assignment.
- Do not claim Vuetify or its MCP is installed until verified.
- Do not introduce additional UI libraries without approval.

### Official references

- Documentation: https://vuetifyjs.com/en/
- Repository: https://github.com/vuetifyjs/vuetify
- Vuetify MCP: https://github.com/vuetifyjs/mcp

**Mandatory review:** The Engineering Advisor defines technical direction, scope, acceptance criteria, and priorities. OMP implements and verifies the approved assignment. The Owner approves consequential operations and business decisions.

Apply the relevant engineering principles proportionally. Numeric scoring is required only for architecture reviews or when requested by the Owner.
