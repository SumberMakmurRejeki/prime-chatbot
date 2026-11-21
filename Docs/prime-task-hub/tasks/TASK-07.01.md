# TASK-07.01 — Simplify Master PM Checksheet: validation & wizard

**Parent:** [TASK-07](TASK-07.md) · **Scope acceptance terpisah** (child; tidak menyelesaikan parent).

## Tujuan / scope
Validasi nested checksheet/part/standard/assignment, batas numerik, status Nonaktif, pembersihan mapping Action, dan pemulihan input/step error.

## Admin / Operator
- **BEFORE:** wizard belum konsisten menolak payload salah; Action→Number/Range bisa menyisakan state selection.
- **AFTER (implemented):** payload invalid ditolak; error menuju step tepat; Action → Number/Range tidak menyisakan selection state; Admin tetap mengelola master, Operator tidak terdampak sebagai pengelola master.

## Status
**DONE (sesuai scope).** [PR #3](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/3) MERGED 2026-11-18; CI pasca-merge PASS. Bukti historis Q-06 internal, bukan formal GitHub approval; parent tetap IN PROGRESS.

## Acceptance khusus
- Validasi salah ditolak; error ditempatkan pada langkah yang benar; input sebelumnya dipertahankan.
- Konversi tipe standard tidak meninggalkan selection state.
- Kandidat final direview; checks tercatat.

## Source / dependency
Basis `7e90945`, head `a9929fe…`, merge `d2588ce…`; enam file kandidat (request/service/app.js/wizard/test/JS XSS test). Reuse validasi oleh TASK-07.02.

## QA / CI / PR evidence
QA follow-up lama: PHP 52/52, JS 11 PASS, PM Executor 117, Master Action 17 (historis; kandidat tertentu). CI pasca-merge `44224408741` PASS.

## Next action
Tidak ada; task selesai sesuai scope. Perubahan lanjutan bernaung di [TASK-07.02](TASK-07.02.md)/[TASK-07.03](TASK-07.03.md).
