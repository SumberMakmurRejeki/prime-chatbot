# TASK-21 — Real SOP/WI Governance & Machine Applicability

**Parent hub:** [Index](../PRIME_TASK_INDEX.md) · Backlog v4 (2026-11-19).

## Tujuan bisnis
Registry, versi/publikasi SOP/WI dan machine applicability memastikan jawaban memakai dokumen asli yang memang berlaku.

## Admin / Operator
- **BEFORE:** retrieval/citation baseline dan backend lifecycle ada, tetapi governance SOP/WI asli belum selesai.
- **AFTER (target):** Admin/pemilik menyetujui versi/mesin sasaran; Operator tetap tidak masuk menu knowledge/administrasi AI. Retrieval hanya published/eligible dengan citation terverifikasi.

## Status & scope
**NOT STARTED** untuk governance SOP/WI asli. Baseline retrieval/publikasi teknis bukan bukti governance data asli.

## Acceptance
Pemilik menyetujui SOP/WI asli; keamanan Gemini diverifikasi; identity/versi/status/applicability jelas; SOP tidak dipilih hanya dari kemiripan nama mesin; dokumen aktif dibedakan dari arsip.

## Source / dependency
Security [TASK-01](TASK-01.md), release [TASK-19](TASK-19.md); koordinasi [TASK-22](TASK-22.md), [TASK-18](TASK-18.md) boleh paralel, bukan blocker dua arah.

## QA / CI / PR evidence
Baseline pada Gemini history; perubahan knowledge lokal `feature/gemini-file-search` belum dianggap terintegrasi.

## Next action
Pilih SOP/WI asli, pemilik dokumen dan bukti applicability setelah gate keamanan jelas.

## Business / technical decisions to preserve
Indexing bukan publication; registry MySQL/private storage adalah source of truth, Gemini index derived state. Recovery dengan bukti remote ambigu tidak mengizinkan upload ulang buta atau penghapusan otomatis resource Google.
