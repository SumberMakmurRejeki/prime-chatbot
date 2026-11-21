# TASK-15 — Unused Blade Cleanup

**Parent hub:** [Index](../PRIME_TASK_INDEX.md) · Backlog v4 (2026-11-19).

## Tujuan bisnis
Mengurangi template benar-benar mati tanpa memutus halaman aktif.

## Admin / Operator
- **BEFORE:** template Blade coexist dengan migrasi Vue; keberadaan Vue tidak membuktikan Blade tidak dipakai.
- **AFTER (target):** halaman kedua role tetap berfungsi setelah template yang terverifikasi tanpa consumer dihapus.

## Status & scope
**NOT STARTED.** Sisa: inventaris route/controller/include/consumer, pengganti diterima, penghapusan aman per halaman.

## Acceptance
Tidak ada consumer runtime tersisa; route/permission dan fungsi kedua role tetap lulus. Pertahankan `resources/views/spa.blade.php`, PDF/report server render, Blade aktif; hapus per halaman setelah approval scope.

## Source / dependency
Parity/cutover [TASK-14](TASK-14.md), navigation [TASK-06](TASK-06.md), gate [TASK-19](TASK-19.md).

## QA / CI / PR evidence
Belum ada kandidat cleanup yang diverifikasi.

## Next action
Inventaris satu kandidat Blade tanpa consumer setelah pengganti Vue diterima; jangan hapus massal.
