# TASK-06 — AI Menu & URL Consolidation

**Parent hub:** [Index](../PRIME_TASK_INDEX.md) · **Status source:** Backlog v4 (2026-11-19).

## Tujuan bisnis
Navigasi Assistant, AI settings dan knowledge yang konsisten bagi Admin.

## Admin / Operator
- **BEFORE:** menu AI belum sepenuhnya konsisten antar shell; sidebar Operator false positive pernah dilaporkan lalu dicabut.
- **AFTER (target acceptance):** menu/URL konsisten dan tidak menduplikasi alur; deep link menuju sumber benar; backend tetap menolak akses tidak sah. Operator hanya melihat menu yang diizinkan.

## Status & scope
- **Status:** IN PROGRESS — paket Pengaturan User dan navigasi sudah masuk M-01 lokal; Q-02 diterima. Browser/UI, kontrak URL/deep link dan acceptance keseluruhan belum selesai; `UI_NOT_VERIFIED` bukan DONE parent.
- **Sudah selesai:** pengelolaan `manage_ai` melalui form izin terpisah di Pengaturan User serta parity sidebar Vue/Blade pada kandidat lokal.
- **Sisa scope:** browser/UI dan deep link/permission acceptance pada M-01; konsolidasi TASK-06 lainnya.

## Acceptance
Workflow Admin/Operator tidak regresi; hidden menu bukan authorization.

## Source / dependency
Role A.2; traceability [TASK-18](TASK-18.md); koordinasi [TASK-14](TASK-14.md). Tidak menghapus workflow registry karena dianggap sekadar retrieval Gemini.

## QA / CI / PR evidence
`feat/ai-navigation-consolidation`, checkpoint `c6dc1be…` dengan perubahan lokal; kandidat bukan clean commit. Detail M-01: [arsip M-01](../archive/PRIME_MASTER_BACKLOG.md).

## Next action
Penuhi prerequisite environment dan approval browser M-01, lalu verifikasi akses/deep link pada kandidat frozen.
