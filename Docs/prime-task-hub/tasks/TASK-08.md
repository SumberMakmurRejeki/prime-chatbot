# TASK-08 — Historical PM CSV/Excel Import

**Parent hub:** [Index](../PRIME_TASK_INDEX.md) · **Source checkpoint:** Backlog v4, 2026-11-19.

## Tujuan bisnis
Import riwayat PM tanpa memalsukan status digital atau jadwal.

## Admin / Operator
- **BEFORE:** belum ada importer yang diverifikasi; data historis berisiko dianggap execution digital.
- **AFTER (target):** Admin preview/import file approved, dapat menelusuri provenance; hasil Operator baru tidak tertimpa atau disamakan dengan legacy.

## Status & scope
**NOT STARTED.** Belum ada implementasi importer terverifikasi. Sisa: template CSV/XLSX, validasi, preview, duplikasi, batch audit, provenance.

## Acceptance
Kode mesin, tanggal, parameter/nilai/satuan tervalidasi; preview sebelum import; duplikasi terdeteksi; provenance terlacak; legacy tidak otomatis approved; jadwal historis tidak dibuat ulang keliru.

## Source / dependency
Mapping pilot [TASK-22](TASK-22.md), identitas/snapshot [TASK-17](TASK-17.md), klasifikasi [TASK-20](TASK-20.md), gate [TASK-19](TASK-19.md).

## QA / CI / PR evidence
Belum ada kandidat importer yang diverifikasi.

## Next action
Gunakan mapping pilot untuk menyepakati template dan aturan duplikasi/status legacy.
