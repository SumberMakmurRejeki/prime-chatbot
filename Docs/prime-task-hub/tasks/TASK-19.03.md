# TASK-19.03 — Recovery, UAT and Release Documentation

**Parent:** [TASK-19](TASK-19.md) · **Scope acceptance terpisah**.

## Tujuan / scope
Menutup gate rilis yang tersisa: final review/checks kandidat lain, regression/UAT sesuai risiko, role checks, MySQL yang relevan, migration/rollback, recovery, hosting, dokumentasi rilis dan approval cutover.

## Admin / Operator
- **BEFORE:** gate otomatis (19.01) dan XLSX text safety (19.02) sudah merged; gate recovery/UAT, role checks, database relevan, migration/rollback, recovery, hosting, dokumentasi accepted dan approval cutover belum seluruhnya ditutup.
- **AFTER (target):** kedua role menjalankan workflow yang diizinkan setelah integrasi; Admin tidak mendapat PM Executor, Operator tidak mendapat menu admin; release dapat diverifikasi, dipulihkan dan didokumentasikan.

## Status
**IN PROGRESS — gate belum lengkap** menurut Backlog v4 (2026-11-19). Checklist/handoff historis ada sebagai bukti persiapan, bukan implementasi. Parent [TASK-19](TASK-19.md) tetap terbuka.

## Acceptance khusus
Permission, skenario database relevan, migration/rollback, recovery, hosting, dokumentasi accepted dan approval cutover memiliki bukti sesuai target yang disetujui. Jangan menyatakan release READY tanpa bukti tersebut; jangan menyembunyikan pemeriksaan agar CI tampak PASS.

## Source / dependency
Kandidat tiap fitur yang akan dirilis; gate [TASK-19](TASK-19.md) berjalan bersama implementasi dan berlaku pada seluruh Wave. Tidak membuka ulang Q-03/Q-04 atau CI PR #4 tanpa perubahan konkret.

## QA / CI / PR evidence
Bukti CI historis: [PR #4](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/4) merge/main `f048f74…` dan [PR #2](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/2) merge/main `7e90945…`; CI pasca-merge PASS. Slice 1 PR #5 merge `6e79fd46`; Slice 2 aging/notification PR #6 merged. **Temporal Resume: tidak ada di `main`; implementasi hanya pada kandidat draft [PR #7](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/7) (head `fecba06f…`, belum merged) — bukan produksi dan tidak menutup TASK-19.**

## Next action
Tutup gate TASK-19.03 sesuai kandidat/target yang disetujui — Role Check, MySQL yang relevan, migration/rollback, recovery, hosting, dokumentasi accepted dan approval cutover — tanpa menyatakan release READY sebelum bukti tersedia; jangan membuka ulang Q-03/Q-04 atau CI PR #4 tanpa perubahan konkret.
