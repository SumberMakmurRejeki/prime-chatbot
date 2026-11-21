# TASK-07.02 — Simplify Master PM Checksheet UX

**Parent:** [TASK-07](TASK-07.md) · **Scope acceptance terpisah** (child; parent tetap IN PROGRESS).

## Tujuan / scope
Sederhanakan UX create/edit Master Checksheet tanpa merusak relasi Action/standard/mesin, jadwal atau riwayat PM.

## Admin / Operator
- **BEFORE:** UX simplification belum disepakati; existing wizard validation PR #3 sudah merged.
- **AFTER (target):** Admin menyelesaikan create/edit pada alur yang Owner setujui, memahami error dan mempertahankan input; Operator memakai master valid tanpa regresi jadwal/riwayat.

## Status
**PLANNED — detail perlu disepakati.**

## Acceptance khusus
Satu alur disetujui; error/input terjaga; history/schedule tidak berubah; role Admin/Operator dan keyboard/responsive parity sesuai subplan halaman.

## Source / dependency
Reuse hasil [TASK-07.01](TASK-07.01.md); sinkron ke [TASK-14](TASK-14.md), gate [TASK-19](TASK-19.md). Jangan redesign Blade sebelum keputusan migrasi.

## QA / CI / PR evidence
M-GATE-20 discovery read-only menghasilkan dua alternatif dan parity matrix; persetujuan Owner masih tertunda. Bukan implementasi.

## Next action
Dapatkan keputusan Owner untuk alur/halaman awal dan kontrak route/props sebelum coding.
