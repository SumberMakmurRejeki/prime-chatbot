# TASK-17 — Data Quality & Machine Identity

**Parent hub:** [Index](../PRIME_TASK_INDEX.md) · Backlog v4 (2026-11-19).

## Tujuan bisnis
Identitas mesin, PM Execution/Review, laporan dan export yang benar; cegah error/data salah sebelum merapikan tampilan.

## Admin / Operator
- **BEFORE:** kandidat PM Review lokal punya filter/sort/pagination dan identity handling, tetapi review/browser/operasional belum lengkap.
- **AFTER (target):** Operator mengisi PM/Breakdown pada mesin benar; Admin mencari, memfilter, mengurutkan dan mereview seluruh hasil. Snapshot historis tidak ditulis ulang oleh perubahan identity live.

## Status & scope
**BLOCKED.** Kandidat PM Review masuk integrasi lokal M-01; Q-02 code/tests gabungan diterima. Parent terbuka karena browser/operasional, rekonsiliasi inventory YAML, temuan di luar scope dan performa MySQL.

## Acceptance
Kode mesin pada URL/QR valid atau ditolak jelas; filter/sort seluruh hasil tanpa SQL ambigu; assignment nonaktif/snapshot/Action `"0"` bermakna; execution vs occurrence tidak disamakan; temuan ditutup per scope. Benchmark SQLite bukan performa MySQL produksi.

## Source / dependency
Kandidat berbasis main dan gate [TASK-19](TASK-19.md); sinkron taxonomy [TASK-20](TASK-20.md). Tidak perlu foundation Gemini untuk patch Review berbasis main.

## QA / CI / PR evidence
M-01 fingerprint `fc46780e…`; Q-02 accepted; browser/operasional/MySQL NOT VERIFIED. Inventory YAML sebelumnya mismatch (5 reported vs 6 paths including `WATCHDOG.yml`); reconciliation open. Lihat [Project State](../archive/PROJECT_STATE.md#current-state).

## Next action
Tutup prerequisite browser M-01 melalui approval environment terpisah; rekonsiliasi inventory/scope luar manifest; verifikasi MySQL hanya setelah otorisasi.
