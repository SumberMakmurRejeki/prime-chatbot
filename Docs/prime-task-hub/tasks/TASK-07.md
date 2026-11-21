# TASK-07 — Simplify Master PM Checksheet & Action

**Parent hub:** [Index](../PRIME_TASK_INDEX.md) · **Status source:** Backlog v4 (2026-11-19) + GitHub facts verified 2026-11-20.

## Tujuan bisnis
Master Checksheet dan Master Action; Admin dapat menyiapkan definisi PM yang konsisten dan mudah dipakai tanpa merusak riwayat.

## Admin / Operator
- **BEFORE:** wizard/validasi belum sederhana; pencarian/filter Master Action belum lengkap.
- **AFTER (target):** Admin membuat/mengubah checksheet dan Action pada alur yang disetujui; Operator menjalankan PM dari master valid, bukan mengelola master. Riwayat PM lama tidak berubah akibat revisi master.

## Status & scope
- **Status parent:** **IN PROGRESS.** Child 07.01 DONE sesuai scope (PR #3 MERGED). Child 07.02 PLANNED. Child 07.03: slice filter status Master Action **MERGED via PR #9** dengan CI post-merge PASS, tetapi **acceptance Master Action yang lebih luas tetap terbuka** — parent tidak otomatis DONE.
- **Sudah selesai:** validasi nested checksheet/part/standard/assignment, batas numerik, status Nonaktif, pembersihan mapping Action, pemulihan input/step error (07.01). Filter status Master Action (07.03 slice).
- **Sisa scope:** keputusan/scope UX 07.02 dan sisa 07.03; review/finalisasi; jangan redesign Master PM dua kali saat migrasi Vue.

## Acceptance (parent)
- Admin dapat membuat dan mengubah checksheet valid; kesalahan input ditampilkan jelas.
- Pengaturan status sesuai keputusan Admin; hasil numerik mempunyai batas dan satuan yang tepat.
- Riwayat PM lama tidak berubah; workflow Operator dan review Admin tidak mengalami regresi.
- Seluruh child diterima, scope bisnis tersisa selesai, gate review/integrasi ditutup.

## Source / dependency
Gate [TASK-19](TASK-19.md); koordinasi identitas/status [TASK-17](TASK-17.md) dan migrasi [TASK-14](TASK-14.md).

## QA / CI / PR evidence
- 07.01: [PR #3](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/3) MERGED 2026-11-18; head `a9929fe…`, merge `d2588ce…`; CI pasca-merge `44224408741` PASS; Q-06 internal.
- 07.03 slice: [PR #9](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/9) **MERGED**; head `929dd6fe89b0fbeb13faafc9fce2d1ed15b915d0`, merge/main `7f4ef6f130c5a37f2f35535d4af9dc39477a4f91`; [Actions 44759296981](https://github.com/SumberMakmurRejeki/prime-chatbot/actions/runs/44759296981) **success** (verified 2026-11-20).

## Next action
Sepakati dan kerjakan sisa scope 07.02/.03; parent selesai hanya setelah seluruh child/acceptance diterima.
