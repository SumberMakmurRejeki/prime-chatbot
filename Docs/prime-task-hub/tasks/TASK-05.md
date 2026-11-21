# TASK-05 — AI End-to-End UAT & Regression

**Parent hub:** [Index](../PRIME_TASK_INDEX.md) · **Status source:** Backlog v4 (2026-11-19).

## Tujuan bisnis
Pembuktian jawaban database + SOP end-to-end; penerimaan didasarkan pada jawaban bisnis, bukan hanya request sukses.

## Admin / Operator
- **BEFORE:** UAT baseline FNC-01 dan query terbatas; belum menutup seluruh regresi AI.
- **AFTER (target acceptance):** jawaban, sitasi, abstention dan denial akses cocok dengan reference answers/kontrak; query gagal bukan nol; data tidak lengkap dinyatakan; hasil menyebut SHA/runtime/data scope. Operator ditolak dari endpoint AI dan alur PM-nya tetap berjalan.

## Status & scope
- **Status:** IN PROGRESS — PARTIAL / ONGOING.
- **Sudah selesai:** UAT baseline FNC-01 dan query terbatas; bukti historis dipisahkan (arsip F.3).
- **Sisa scope:** UAT query generalized, gabungan data asli + SOP, negative/abstention, permission, kegagalan provider/query; regression sesuai risiko.

## Acceptance
Full acceptance dan approval tercatat tanpa mengekstrapolasi UAT terbatas.

## Source / dependency
Kandidat [TASK-01](TASK-01.md)/[TASK-03](TASK-03.md)/[TASK-18](TASK-18.md)/[TASK-21](TASK-21.md); data pilot [TASK-22](TASK-22.md); hasil menjadi input release [TASK-19](TASK-19.md).

## QA / CI / PR evidence
Evidence lintas stream; belum ada bukti UAT keseluruhan pada satu SHA kandidat final.

## Next action
Susun cakupan QA terhadap kandidat nyata dan reference answers dari rencana existing, lalu QA independen terisolasi.
