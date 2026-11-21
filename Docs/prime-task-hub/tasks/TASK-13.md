# TASK-13 — Qdrant Legacy Dependency Audit & Cut-off

**Parent hub:** [Index](../PRIME_TASK_INDEX.md) · Backlog v4 (2026-11-19).

## Tujuan bisnis
Retire backend/konfigurasi legacy hanya setelah Gemini terbukti; jangan memelihara dua backend AI sebagai target permanen.

## Admin / Operator
- **BEFORE:** Qdrant bukan backend Assistant dan tidak diperlukan SOP `process()`/`publish()`, tetapi vector store/embedding/readiness/consumer legacy masih ada.
- **AFTER (target):** Admin tetap mengelola/mencari SOP melalui Gemini; retirement tidak mengganggu PM/Breakdown Operator. Tidak ada fallback Qdrant tak terdokumentasi atau consumer runtime tertinggal dalam scope.

## Status & scope
**NOT STARTED** untuk audit/cut-off menyeluruh. Dokumentasi dependency parsial bukan bukti retirement.

## Acceptance
Petakan seluruh consumer runtime/test/config/schema, cutover/recovery, pengganti dan keputusan Owner; removal disetujui dan regresi diuji. Tidak mengakses/menghapus layanan Qdrant project asal.

## Source / dependency
Foundation/security [TASK-01](TASK-01.md), workflow dokumen [TASK-21](TASK-21.md), cutover/approval [TASK-19](TASK-19.md). TASK-06 wave order bukan blocker teknis otomatis.

Source current-state caveat: [Project State archive](../archive/PROJECT_STATE.md#61-keputusan-dependency-dan-retirement).

## QA / CI / PR evidence
Belum ada kandidat cut-off menyeluruh yang diverifikasi.

## Next action
Buat inventaris consumer read-only dan bukti pengganti sebelum meminta keputusan retirement Owner.
