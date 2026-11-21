# TASK-16 — AI Observability & Diagnostics

**Parent hub:** [Index](../PRIME_TASK_INDEX.md) · Backlog v4 (2026-11-19).

## Tujuan bisnis
Diagnosis kegagalan AI agar Admin/support membedakan provider, query dan retrieval tanpa membocorkan credential.

## Admin / Operator
- **BEFORE:** logging aman kegagalan Assistant parsial ada pada commit historis `8d45410`; bukan bukti akar penyebab error Gemini intermiten.
- **AFTER (target):** kegagalan dibedakan benar, diagnosis terlacak, log tidak memuat secret sensitif, dan error tidak disajikan sebagai hasil bisnis kosong/sukses.

## Status & scope
**IN PROGRESS — partial diagnostics; belum diverifikasi menyeluruh.** Sisa: observability query/retrieval/provider yang cukup ditindaklanjuti operasi.

## Acceptance
Bukti diagnosis, aman, failure categories benar.

## Source / dependency
Kontrak error [TASK-01](TASK-01.md)/[TASK-03](TASK-03.md), pembuktian [TASK-05](TASK-05.md), recovery/release [TASK-19](TASK-19.md).

## QA / CI / PR evidence
`8d45410` historis pada history Gemini; kandidat seluruh observability belum diverifikasi.

## Next action
Petakan satu kegagalan end-to-end ke kategori log aman dan pesan Admin yang dapat ditindaklanjuti.
