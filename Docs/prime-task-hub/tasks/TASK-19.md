# TASK-19 — Release Readiness, Recovery & Documentation

**Parent hub:** [Index](../PRIME_TASK_INDEX.md) · Backlog v4 (2026-11-19) + GitHub facts verified 2026-11-20.

## Tujuan bisnis
Release yang dapat direcovery, terdokumentasi, dan dioperasikan.

## Admin / Operator
- **BEFORE:** quality gates PR #4 diterima; CI dapat jalan; dokumentasi/operasional belum lengkap.
- **AFTER (target):** Admin hanya mempublikasikan SOP lewat aksi eksplisit; kegagalan job tidak menghilangkan permintaan bisnis; Operator tetap dapat alur kerja; runbook/dokumentasi tersedia.

## Status & scope
**IN PROGRESS — parent terbuka.** 19.01 dan 19.02 DONE sesuai scope. 19.03 IN PROGRESS (gate belum lengkap). Parent tetap terbuka (contohnya `SCOPE_LATEST.yaml` hardening dan scope lain belum diselesaikan). DONE-05 Runbook **NOT STARTED**.

## Acceptance (parent)
- Recovery job/spool aman; kegagalan dapat didiagnosis admin.
- Admin mempublikasikan SOP hanya lewat aksi eksplisit; kegagalan indexing tidak menimpa revisi terbit.
- Kedua role tetap bekerja; runbook/dokumentasi ICA dan environment matrix ada.
- Seluruh child/acceptance ditutup; site tidak dibiarkan rusak bila gate gagal.

## Source / dependency
Kandidat [TASK-01](TASK-01.md), [TASK-17](TASK-17.md); bukti rilis [TASK-05](TASK-05.md); quality gates PR #4, dependency CI PR #7/PR #8 (draft, terverifikasi 2026-11-20).

## QA / CI / PR evidence
- 19.01: [PR #4](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/4) MERGED; head `393a505…`, merge/main `f048f74…`; CI main `44094015983` PASS.
- 19.02: [PR #2](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/2) MERGED; head `28ce9f2…`, merge/main `7e90945…`; CI main `44118241952` PASS.
- 19.03: IN PROGRESS — gate belum lengkap; progres/checklist historis ada sebagai bukti persiapan, bukan implementasi.

## Next action
Tutup gate TASK-19.03 sesuai kandidat/target yang disepakati — Role Check, MySQL yang relevan, migration/rollback, recovery, hosting, dokumentasi accepted dan approval cutover — tanpa menyatakan release READY tanpa bukti tersebut; jangan membuka ulang Q-03/Q-04 atau CI PR #4 tanpa perubahan konkret. Jangan mulai redaksi runbook tanpa approval.
