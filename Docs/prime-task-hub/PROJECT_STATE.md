# PRIME Project State & Engineering Checkpoint

**Snapshot:** 2026-11-20. This page is the current-state checkpoint for this hub; older source checkpoints remain byte-identical and explicitly historical under [archive/PROJECT_STATE.md](archive/PROJECT_STATE.md). Product scope/status is in [PRIME_TASK_INDEX](PRIME_TASK_INDEX.md) and [task files](tasks/); ownership and current assignments are in [PRIME_ACTIVE_WORK](PRIME_ACTIVE_WORK.md).

## Current verified GitHub baseline

| Item | Status / evidence (verified read-only 2026-11-20) | Boundary |
|---|---|---|
| `main` | `7f4ef6f130c5a37f2f35535d4af9dc39477a4f91` | This checkpoint does not claim local checkout synchronization. |
| PR #7 | OPEN, DRAFT; head `fecba06f9acfc0ef64cfca4c5bee5902caf0c6f7`, base `main` | Candidate implements temporal Resume, but is not merged/production. Do not repeat stale blanket “not implemented” without qualifying: not in main; present on draft candidate. |
| PR #8 | OPEN, DRAFT; head `b3a562c7410c043c7449497380fad3de47204914`, base `feat/pm-temporal-resume` | Not merged; review/CI/approval incomplete. |
| PR #9 | MERGED; head `929dd6fe89b0fbeb13faafc9fce2d1ed15b915d0`, merge/main `7f4ef6f130c5a37f2f35535d4af9dc39477a4f91` | Master Action status-filter slice only. |
| PR #9 post-merge Actions | run `44759296981`, completed `success` | Evidence for merged PR #9 candidate only; not all TASK-07. |
| Root checkout/worktrees | NOT REFRESHED in documentation assignment | Do not infer live state from archive snapshots. No source or Git changes were made for this hub. |

## Parent status highlights

- **TASK-07 remains IN PROGRESS.** 07.01 is DONE for its validation/wizard scope (PR #3 merged). 07.02 remains planned; 07.03 status-filter slice is merged PR #9 with post-merge CI success, but broader Action acceptance stays open. See [TASK-07](tasks/TASK-07.md) and child files.
- **TASK-19 remains IN PROGRESS.** 19.01 (PR #4) and 19.02 (PR #2) are done for their scoped deliverables; recovery/UAT/docs/release gates remain. Do not infer parent completion from merged children.
- **TASK-17 is BLOCKED** on browser/operational acceptance, YAML/inventory reconciliation, out-of-manifest scope and MySQL performance evidence. M-01 Q-02 accepted does not close parent or imply production readiness.
- **TASK-01/06** M-01 code/tests Q-02 accepted, but UI/operational acceptance and release gates remain open; environment QA blocked. Full details retained in [historical backlog archive](archive/PRIME_MASTER_BACKLOG.md).
- **TASK-19.03** still has role checks, relevant DB scenarios, migration/rollback, recovery, hosting, accepted docs, cutover approvals open.


## PR #7 candidate contract — D1–D5

**Provenance:** [PR #7 description](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/7), independently retrieved read-only by Manager. These are the precise business contract recorded in the **open draft PR #7**, not a merged/main contract or production approval. The locally supplied six source documents did not contain the explicit D1–D5 labels; this authoritative candidate description supersedes that local-source absence. PR #7 is based on `main` at head `fecba06f…`; its implementation is not in `main`. The real InnoDB serialization gate remains **OPEN / NOT VERIFIED**; SQLite tests do not prove it.

- **D1:** Jakarta business dates define half-open pause intervals `[P,R)`; protected execution/history takes precedence.
- **D2:** Same-day Pause/Resume has an empty gap; invalid repeated transitions do not add duplicate intervals.
- **D3:** Legacy `PAUSED` schedules without trustworthy structured interval provenance fail closed on Resume; no guessed historical boundary/backfill.
- **D4:** Pause days do not age overdue work. Pre-pause debt remains actionable; active age subtracts the union of pause intervals. Mutable gap obligations are excluded from current projections/new execution eligibility without rewriting historical occurrences.
- **D5:** Interval, lifecycle and audit writes share a transaction; the lifecycle write path rejects inconsistent/overlapping intervals. Real InnoDB serialization is an OPEN gate, not proven by SQLite.

## Owner decisions and cross-task invariants

The D1–D5 contract above comes from the PR #7 candidate description; the following existing owner decisions/invariants are preserved from source sections (particularly archived `PROJECT_STATE.md` §6):
1. **Two business roles:** Admin and Operator only. Assistant remains Admin-only/read-only. Admin does not get PM Executor. Operator does not get admin modules, PM Review or AI administration. `manage_ai` is a permission for Admin, not a third role; legacy `guest` labels do not change this contract.
2. **Gemini is target RAG backend; Qdrant retirement is conditional:** Gemini File Search is the target; Qdrant is not fallback. Retire only after all runtime consumers are inventoried, cutover verified, and Owner approves.
3. **Incremental Blade → Inertia/Vue + NovaSpark:** migration is gradual. Keep business Blade until a page replacement is verified/accepted; preserve Inertia root and needed server-rendered views.
4. **Time:** global application timezone remains UTC; PM business dates use Asia/Jakarta helper. Do not alter global timezone as shortcut.
5. **SOP authority/publication:** registry metadata (MySQL/private storage) is source of truth; Gemini index is derived. Indexing is not publication; Admin publication is explicit; only current eligible/published revision with verifiable identity/provenance/citation is retrievable.
6. **AI query contract:** Laravel allowlisted read-only queries, no SQL generated/executed freely by Gemini; query failure is not zero; incomplete data is stated; PM approval is not proof machine safety; no autonomous maintenance writes.
7. **Database and secrets:** test isolation SQLite `:memory:` and existing guard; no protected PRIME database, runtime mutation or real credential without separate approval. Never copy credentials/secrets into docs.

## Cross-task risks / release gates

- M-01 candidate remains a local integration checkpoint, not `main`: fingerprint `fc46780e…`, Q-02 accepted, **UI_NOT_VERIFIED**. Browser role/deep link/responsive, relevant MySQL/performance, operational acceptance/full release CI remain open.
- Environment QA for M-01 remains **BLOCKED / OWNER/ENV ADMIN INPUT REQUIRED** in historical checkpoint; this assignment did not probe or change database/runtime.
- Slice 1 MySQL concurrency is not verified by SQLite/sequential tests. Slice 2 PR #6 currently-paused aging/notification merge evidence does not prove temporal Resume, global cron head-of-line/load or unrelated races. Temporal Resume implementation is on draft PR #7, not on main; PR #8 remains draft dependent on PR #7.
- YAML / scope discrepancy for TASK-17 and MySQL production performance are open; synthetic SQLite benchmark is not production performance evidence.
- Historical RUNNING/assignment statements are not current dispatch. See [Active Work](PRIME_ACTIVE_WORK.md).

## Evidence and status policy

`SOURCE-VERIFIED` means source inspected, not a test run. `TEST-VERIFIED` is only a run with command/scope/candidate stated. `TEST-SUPPORTED` means regression exists, not that it passed now. `HISTORICAL` results are not rerun in this documentation update. `NOT VERIFIED` is unknown, not assumed false/true. `MERGED` is not `DEPLOYED`, `release READY`, or parent completion. Owner-approved planning does not equal coding/publication approval.

### Evidence preserved, not rerun

The historical source docs contain candidate-scoped reports, test counts, QA reviews, hash manifests, CI runs, M-01 merge/lock details, dependency/exclusion decisions and earlier worktree inventories. They remain byte-identical at:
- [Master backlog historical archive](archive/PRIME_MASTER_BACKLOG.md)
- [Parallel execution plan historical archive](archive/PRIME_PARALLEL_EXECUTION_PLAN.md)
- [Project state historical archive](archive/PROJECT_STATE.md)

These archives are superseded for current dashboard/dispatch but remain evidence and provenance. No old test or QA claim is represented as rerun or newly approved here.
