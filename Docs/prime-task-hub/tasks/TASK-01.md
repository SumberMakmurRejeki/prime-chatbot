# TASK-01 — Gemini API Security & Configuration Hardening

**Parent hub:** [Index](../PRIME_TASK_INDEX.md) · **Status source:** Backlog v4 (2026-11-19) · **GitHub facts verified 2026-11-20:** PR #9 merged, main `7f4ef6f1…`; PR #7 draft; PR #8 draft.

## Tujuan bisnis
Mengamankan provider Gemini, credential dan transport sehingga penggunaan dokumen/kredensial operasional tidak membocorkan secret atau mengakses tujuan jaringan yang tidak aman.

## Admin / Operator
- **BEFORE:** keamanan transport Gemini belum konsisten; key berisiko dipakai ulang ketika endpoint berubah; render Markdown berpotensi memuat gambar eksternal (temuan audit).
- **AFTER (target acceptance):** HTTPS/public-host/DNS pinning dan penolakan redirect/IP berbahaya berlaku pada jalur relevan; reuse credential aman; LOW image mitigation ditinjau dan keputusannya dicatat. Operator tetap tidak memperoleh akses administrasi AI.

## Status & scope
- **Status:** BLOCKED. Hardening dan dependency telah diintegrasikan lokal pada M-01; Q-02 code/tests gabungan diterima.
- **Sudah selesai:** secure Text/File/Store transport, endpoint-bound credentials, normalisasi Base URL pada kandidat gabungan. Bukti historis 64/522 dan integrasi 233/1.816 dipertahankan (lihat [arsip M-01](../archive/PRIME_MASTER_BACKLOG.md)).
- **Sisa scope:** acceptance browser/operasional, CI kandidat rilis, keputusan LOW image mitigation, verifikasi foundation pada target rilis. Tidak ada operational activation.

## Acceptance
- HIGH/MEDIUM ditutup dan diverifikasi sebelum Gemini digunakan luas dengan dokumen/credential asli.
- Perubahan lokal/provenance terjaga; LOW ditinjau; keputusan dicatat.

## Source / dependency
History foundation Gemini yang tepat (backend lifecycle/recovery), bukan hanya agent foundation PR #1. Gate [TASK-19](TASK-19.md); koordinasi credential/retrieval dengan [TASK-21](TASK-21.md).

## QA / CI / PR evidence
- Audit HIGH/MEDIUM/LOW dan kondisi rekonsiliasi: [Backlog §TASK-01](../archive/PRIME_MASTER_BACKLOG.md#task-01).
- Kandidat lokal M-01, fingerprint `fc46780e…`; **UI_NOT_VERIFIED**; environment QA **BLOCKED / OWNER INPUT REQUIRED** ([Project State](../archive/PROJECT_STATE.md#current-state)).

## Next action
Gunakan kandidat M-01 frozen dan Q-02 yang diterima; tutup gate tersisa tanpa membuat ulang hardening atau mengulang independent code review pada snapshot identik.
