# TASK-04 — Gemini Structured Outputs & Function Calling

**Parent hub:** [Index](../PRIME_TASK_INDEX.md) · **Status source:** Backlog v4 (2026-11-19).

## Tujuan bisnis
Kontrak output/intent terstruktur bila bermanfaat; bukan syarat tambahan untuk menghambat query Laravel terkontrol.

## Admin / Operator
- **BEFORE:** keputusan baseline memakai typed intent/query Laravel terkontrol.
- **AFTER (target acceptance):** bila dipilih, output tervalidasi terhadap kontrak; tool hanya allowlist Laravel read-only; invalid intent tidak menjadi SQL bebas. Tidak ada autonomous agent loop.

## Status & scope
- **Status:** PLANNED — conditional; implementasi belum terbukti.
- **Sisa scope:** buktikan manfaat terukur, sepakati kontrak, implementasikan hanya bila dipilih.

## Acceptance
Manfaat terbukti; keputusan tidak memakai function calling dicatat, bukan diklaim DONE.

## Source / dependency
Evaluasi kebutuhan nyata [TASK-03](TASK-03.md)/[TASK-10](TASK-10.md); keamanan [TASK-01](TASK-01.md); QA [TASK-05](TASK-05.md).

## QA / CI / PR evidence
Belum ada kandidat yang diverifikasi.

## Next action
Bandingkan kebutuhan nyata dengan typed intent existing; minta keputusan manfaat sebelum coding.
