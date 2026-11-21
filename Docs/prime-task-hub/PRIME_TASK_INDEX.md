# PRIME Task Index

**Dashboard snapshot:** 2026-11-20. Business scope/status references: [TASK-01…22](tasks/); current code/PR/release baseline: [Project State](PROJECT_STATE.md); active engineering ownership: [Active Work](PRIME_ACTIVE_WORK.md). Full source docs preserved byte-identically as **historical** files under [archive/](archive/); they are superseded as task-dispatch dashboards, not erased evidence. No percent complete is inferred.

## Status terms
`NOT STARTED`, `PLANNED`, `IN PROGRESS`, `BLOCKED`, `MERGED / DONE (scope only)`. A child/slice completion does not complete its parent. `HISTORICAL`, `SOURCE-VERIFIED`, `TEST-VERIFIED`, `NOT VERIFIED` retain the evidence distinctions defined in [current project state](PROJECT_STATE.md#evidence-and-status-policy).

## Parent tasks (exactly 22)

| TASK | Title | Parent status | Priority | Main blocker | Next action | Detail |
|---|---|---|---|---|---|---|
| 01 | Gemini API Security & Configuration Hardening | BLOCKED | P1 | UI/operational acceptance, release CI, LOW image decision | Reuse frozen M-01; close remaining gates | [TASK-01](tasks/TASK-01.md) |
| 02 | Operational Query Capability Plan OMP 112-08 | PLANNED | Planning prerequisite | External approved handoff location unverified | Retrieve approved handoff for implementor | [TASK-02](tasks/TASK-02.md) |
| 03 | Reusable Operational Query Tools | IN PROGRESS | P2 | Generalized queries/reference answers | Implement first generalized Review query using OMP plan | [TASK-03](tasks/TASK-03.md) |
| 04 | Gemini Structured Outputs & Function Calling | PLANNED (conditional) | P3 | Benefit/contract decision | Compare need to typed intent; seek decision | [TASK-04](tasks/TASK-04.md) |
| 05 | AI End-to-End UAT & Regression | IN PROGRESS (partial) | P2 | No final candidate/reference-answer UAT | Plan isolated QA on real candidate | [TASK-05](tasks/TASK-05.md) |
| 06 | AI Menu & URL Consolidation | IN PROGRESS | P3 | Browser/deep-link acceptance | Approved browser verification on frozen candidate | [TASK-06](tasks/TASK-06.md) |
| 07 | Simplify Master PM Checksheet & Action | **IN PROGRESS** | P1 | 07.02 and wider 07.03 acceptance remain open | Agree/execute remaining child scope | [TASK-07](tasks/TASK-07.md) |
| 08 | Historical PM CSV/Excel Import | NOT STARTED | P2 | Pilot mapping and import contract | Agree template/legacy rules using pilot | [TASK-08](tasks/TASK-08.md) |
| 09 | AI Multi-turn Context | NOT STARTED | P3 | Real follow-up acceptance | Select ambiguous-scope scenario | [TASK-09](tasks/TASK-09.md) |
| 10 | Maintenance Advice & Prioritization | IN PROGRESS (partial historical) | P3 | Generalized benefit/evidence not accepted | Validate one need with business owner | [TASK-10](tasks/TASK-10.md) |
| 11 | AI Vision for Maintenance Photos | NOT STARTED | P3 | Approved need/data/evaluation set | Agree permitted photo scenario | [TASK-11](tasks/TASK-11.md) |
| 12 | SOP/WI Revision Comparison | NOT STARTED | P3 | User need/acceptance unknown | Collect one owner-backed case | [TASK-12](tasks/TASK-12.md) |
| 13 | Qdrant Legacy Dependency Audit & Cut-off | NOT STARTED | P3 | Full consumer inventory + Owner retirement decision | Read-only inventory before decision | [TASK-13](tasks/TASK-13.md) |
| 14 | Blade to Vue/Inertia Migration | IN PROGRESS (partial) | P3 | Whole migration remains incomplete | Pick one parity-accepted page | [TASK-14](tasks/TASK-14.md) |
| 15 | Unused Blade Cleanup | NOT STARTED | P3 | Accepted Vue replacement per page | Inventory one candidate after replacement accepted | [TASK-15](tasks/TASK-15.md) |
| 16 | AI Observability & Diagnostics | IN PROGRESS (partial) | P2 | Query/retrieval/provider coverage | Trace one failure end-to-end safely | [TASK-16](tasks/TASK-16.md) |
| 17 | Data Quality & Machine Identity | BLOCKED | P2 | Browser, inventory/YAML, MySQL performance | Close browser prerequisite; reconcile scope | [TASK-17](tasks/TASK-17.md) |
| 18 | AI Source Traceability & Deep Links | IN PROGRESS (partial) | P2 | Business deep-link acceptance | Verify one Admin source navigation | [TASK-18](tasks/TASK-18.md) |
| 19 | Release Readiness, Recovery & Documentation | **IN PROGRESS** | P1 continuous | Recovery/UAT/roles/MySQL/migration/hosting/release gates | Scope next gate against accepted candidate | [TASK-19](tasks/TASK-19.md) |
| 20 | Operational Data Classification & Taxonomy | PLANNED (audit done) | P2 | Impactful mapping decision | Select one real inconsistency | [TASK-20](tasks/TASK-20.md) |
| 21 | Real SOP/WI Governance & Machine Applicability | NOT STARTED | P2 | Real approved documents/security/applicability | Select Owner-approved SOP/WI | [TASK-21](tasks/TASK-21.md) |
| 22 | Real Operational Data Pilot & Mapping | NOT STARTED | P2 | Data source/authorized sample not selected | Agree pilot source/sample/privacy | [TASK-22](tasks/TASK-22.md) |

## Separate acceptance children

- TASK-07: [07.01 validation/wizard (DONE scope)](tasks/TASK-07.01.md), [07.02 Checksheet UX (PLANNED)](tasks/TASK-07.02.md), [07.03 Action UX (filter slice MERGED; broader acceptance open)](tasks/TASK-07.03.md).
- TASK-19: [19.01 CI gates (DONE scope)](tasks/TASK-19.01.md), [19.02 XLSX safety (DONE scope)](tasks/TASK-19.02.md), [19.03 recovery/UAT/docs (IN PROGRESS)](tasks/TASK-19.03.md).

- Parent status never advances because one child/slice merged.
- Admin/Operator are the two business roles. Assistant is Admin-only/read-only; Operator retains operational PM/Breakdown only; Admin has no PM Executor. Legacy `guest` labels do not add a third business role.
- **D1–D5:** the authoritative business contract labels exist only in the open draft [PR #7](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/7) description (candidate, not merged); see [Project State](PROJECT_STATE.md#pr-7-candidate-contract--d1d5). The six local source docs do not contain those labels; do not invent additional numbered decisions.
