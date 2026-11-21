# TASK-03 — Reusable Operational Query Tools

**Parent hub:** [Index](../PRIME_TASK_INDEX.md) · **Status source:** Backlog v4 (2026-11-19).

## Tujuan bisnis
Query database operasional reusable sehingga Admin memperoleh fakta maintenance terkontrol dari data nyata.

## Admin / Operator
- **BEFORE:** hanya baseline query terbatas (pending Review, FNC-01) tersedia secara lokal.
- **AFTER (target acceptance):** setiap tahap query menjawab fakta yang benar untuk scope/filter yang diminta; nol dibedakan dari error; sumber/tanggal/status/satuan terlacak. Operator tetap hanya menjalankan alur operasional yang diizinkan.

## Status & scope
- **Status:** IN PROGRESS — baseline terbatas ada; generalized tools belum selesai.
- **Sudah selesai:** DONE-03/DONE-04; source lokal untracked `OperationalPmAssistantAnswerService.php` + binding `AppServiceProvider.php` menangani pending Review dan FNC-01. Bukan bukti QA terbaru/generalisasi.
- **Sisa scope:** generalized PM review count/list/oldest; schedule upcoming/overdue/missed; execution terbaru/detail; Breakdown insiden/mesin/frekuensi; historical measurements dan basic KPI.

## Acceptance
Kontrak bersama AI dipenuhi; tahapan boleh incremental; parent DONE hanya setelah seluruh scope diterima.

## Source / dependency
Rencana [TASK-02](TASK-02.md); kontrak data [TASK-17](TASK-17.md)/[TASK-20](TASK-20.md); pilot [TASK-22](TASK-22.md); security [TASK-01](TASK-01.md); gate [TASK-05](TASK-05.md).

## QA / CI / PR evidence
Implementasi terbatas pada checkout dirty `feature/gemini-file-search`; PR generalized-query belum diverifikasi.

## Next action
Gunakan OMP 112-08 untuk menuntaskan satu query generalized PM Review berikut reference answer, bukan menulis ulang baseline.
