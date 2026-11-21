# TASK-14 — Blade to Vue/Inertia Migration

**Parent hub:** [Index](../PRIME_TASK_INDEX.md) · Backlog v4 (2026-11-19).

## Tujuan bisnis
Modernisasi UI bertahap dengan design system NovaSpark sambil menjaga parity bisnis.

## Admin / Operator
- **BEFORE:** halaman Blade dan Vue/Inertia coexist; halaman Vue existing tidak membuktikan seluruh migrasi/redesign.
- **AFTER (target):** halaman pilihan memenuhi route/permission, fungsi, responsive, accessibility, regression dan UAT sebelum cutover. Operator tetap dapat Input Breakdown dan PM Executor tanpa menu admin.

## Status & scope
**IN PROGRESS — PARTIAL.** Shell/halaman Vue dan kandidat UI parsial ada; migrasi/redesign seluruhnya belum selesai.

## Acceptance
Blade bisnis tetap sampai pengganti Vue diterima; pertahankan root Inertia/server-rendered Blade yang dibutuhkan. Penggantian per halaman, bukan migrasi massal.

## Source / dependency
Acceptance bisnis [TASK-07](TASK-07.md), navigasi [TASK-06](TASK-06.md), gate [TASK-19](TASK-19.md). Hasil diterima menjadi syarat [TASK-15](TASK-15.md).

## QA / CI / PR evidence
Belum ada kandidat migrasi keseluruhan yang diverifikasi; implementasi bertahap pada history/checkout Gemini.

## Next action
Pilih satu halaman dengan acceptance parity; gunakan keputusan TASK-07 agar Master PM tidak didesain dua kali.
