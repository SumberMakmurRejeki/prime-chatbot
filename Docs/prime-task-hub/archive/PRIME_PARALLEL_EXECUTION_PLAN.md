# PRIME Parallel Engineering Implementation Plan

**Fungsi:** sumber resmi assignment aktif, owner, worktree/branch, dependency, blocker, handoff dan approval boundary. Kebutuhan/TASK/acceptance dimiliki [Master Backlog](PRIME_MASTER_BACKLOG.md); baseline terintegrasi, CI/QA dan risiko dimiliki [Project State](PROJECT_STATE.md#current-state). Jangan menyalin laporan agent mentah atau ledger status panjang ke bagian aktif.

**Checkpoint saat ini:** 19 November 2026 — M-NEXT-17C Canonical Documentation Sync lanjutan. Scope terbatas: DocsWriter17C sebagai satu-satunya penulis pengganti tiga dokumen canonical setelah penulis lama tidak tersedia, dan independent replacement QA meninjau kandidat frozen. Inventaris worktree COMPLETED (frozen 2026-11-19T14:47:29.834Z; 12 worktree, 8 PRESERVE, 4 UNVERIFIED, 0 CLEANUP_CANDIDATE; seluruh active ownership UNVERIFIED; root dan M-01 selalu PRESERVE) — reuse snapshot, tanpa rerun/cleanup. M-DOC-01/SLICE-01-LOW-DOCFIX dan M-GATE-24 adalah riwayat; bukan assignment atau izin aktif.

<a id="active-workflow"></a>
## Workflow aktif M-NEXT-17C — DocsWriter17C, Manager, independent replacement QA

<a id="mnext17-workflow"></a>
### Peran dan batas ownership

| Peran internal | Peran tetap | Assignment gate ini | Allowed writes |
|---|---|---|---|
| Manager | Planner/Reviewer/Integrator | Orkestrasi, koordinasi dan review final; tanpa penulisan dokumen di gate ini | Tidak ada pada tiga dokumen; bukti hanya pada artefak lokal Manager |
| DocsWriter17C | Fullstack implementor pengganti | Canonical Documentation Sync lanjutan: tiga dokumen canonical saja | `Docs/PROJECT_STATE.md`, `Docs/PRIME_MASTER_BACKLOG.md`, `Docs/PRIME_PARALLEL_EXECUTION_PLAN.md` di root |
| Independent replacement QA | Independent review | QA M-NEXT-17C setelah kandidat frozen | Tidak ada; review read-only pada snapshot frozen |

- DocsWriter17C adalah satu-satunya penulis tiga dokumen pada gate ini; Manager mengoordinasikan dan melakukan review final tanpa repo writes. QA pengganti mereview read-only setelah freeze.
- Inventaris 12 worktree telah COMPLETED pada snapshot frozen di atas; hanya digunakan kembali. Jangan rerun inventory atau melakukan cleanup; status owner tetap UNVERIFIED.
- QA review hanya pada kandidat frozen; hasilnya bukan authorisasi slice baru dan tidak menutup keputusan bisnis yang masih OPEN.
- Corrections dari QA diarahkan kepada DocsWriter17C untuk satu focused fix loop yang diwajibkan Manager, lalu snapshot baru dan focused QA read-only. Tidak ada terminal/worktree baru atau rencana feature baru yang diotorisasi.

### Target dan batas gate

| Paket | Target | Acceptance dan batas |
|---|---|---|
| DocsWriter17C — Documentation Sync | `C:\laragon\www\prime-google\Docs\{PROJECT_STATE.md,PRIME_MASTER_BACKLOG.md,PRIME_PARALLEL_EXECUTION_PLAN.md}` | Menggantikan assignment A yang tidak tersedia pada sesi ini; ownership historis tetap dipertahankan. Update selektif; pertahankan TASK/child/evidence dan encoding. Arsip historis ditandai superseded tanpa dihapus. Freeze byte snapshot sebelum QA. |
| B — Worktree Inventory COMPLETED; REUSE ONLY | Snapshot frozen 2026-11-19T14:47:29.834Z: 12 worktree, 8 PRESERVE, 4 UNVERIFIED, 0 CLEANUP_CANDIDATE | Seluruh active ownership UNVERIFIED; root dan M-01 selalu PRESERVE. Gunakan kembali snapshot; jangan rerun inventory. Bukan authorisasi cleanup. |
| Independent replacement QA — frozen read-only | Kandidat tiga dokumen frozen DocsWriter17C | Nonauthorship, scope/hash, semantic accuracy, no lost history, hanya tiga file approved; tanpa klaim Slice 2 DONE atau deployment. |

**Tidak diizinkan:** stage/commit/push/PR/merge, source/test edit, cleanup/worktree, migration/schema, Git mutation termasuk fetch, DB/runtime/deploy, README edit, atau penulisan hasil QA sebelum laporan frozen QA. Koreksi findings kembali ke DocsWriter17C sebagai satu-satunya penulis dan memerlukan freeze serta fingerprint baru sebelum focused QA read-only.

**Next gate (rekomendasi, bukan authorisasi):** sesudah laporan QA frozen diterima Owner, keputusan bisnis yang masih OPEN ialah interval [P,R), pause/resume hari sama, boundary legacy PAUSED, actionability overdue pra-pause dan provenance temporal existing vs new. Baru kemudian dapat direncanakan temporal Resume (Slice 2 lanjutan) atau Slice 3/4/5; tidak ada implementasi yang disahkan di sini.
---


<details>
<summary>Arsip historis checkpoint M-DOC-01 dan SLICE-01-LOW-DOCFIX</summary>

- **Checkpoint lama:** 19 November 2026 — M-DOC-01 dan SLICE-01-LOW-DOCFIX diotorisasi untuk perubahan lokal terbatas. M-GATE-24 selesai untuk recovery, independent review dan readiness analysis; bukan izin implementasi Slice 2–5. Bagian di bawah dipertahankan untuk traceability; status "aktif" pada header lama telah SUPERSEDED oleh [Workflow aktif M-NEXT-17](#mnext17-workflow).

### Peran dan batas ownership (lama)

| Peran internal | Peran tetap | Assignment gate lama | Allowed writes |
|---|---|---|---|
| Manager | Planner, Architect, Orchestrator, Reviewer, Integrator; owner canonical documentation | M-DOC-01, review evidence/dependency dan handoff | Hanya tiga dokumen canonical pada root |
| Fullstack A | Fullstack implementor sesuai assignment | SLICE-01-LOW-DOCFIX | Hanya PHPDoc Start pada `app/Services/PM/PmExecutionService.php` di kandidat Slice 1 |
| Fullstack B | Fullstack implementor sesuai assignment | SCHEDULING-DEPENDENCY-ALIGNMENT | Tidak ada; read-only lock/dependency analysis |
| QA | Independent testing/review, bukan penulis hasil yang direview | QA-1 delta A; QA-2 dokumen Manager | Tidak ada; review setelah freeze masing-masing |
- A dan B bekerja paralel pada scope terpisah; internal OMP subagents boleh menjalankan assignment yang sudah ditentukan. Ini role/work item mapping, bukan bukti physical terminal creation; tidak ada worker baru atau terminal baru yang dibuat oleh gate ini. Inventory runtime/active-owner state dilaporkan hanya jika benar-benar diamati.
- Manager boleh coding untuk kebutuhan khusus, tetapi bukan implementor default ketika A/B dapat menangani pekerjaan dengan aman. Pada gate lama Manager tidak mengedit source Slice 1.
- Satu writer per file/scope. Manager memilih paralelisme berdasarkan dependency nyata dan reservasi file; reviewer tidak memperbaiki hasil yang sedang dinilai.
- Gunakan worker existing dan identitas live. Default cwd pane A/QA adalah M-01 dan B adalah kandidat CI: **bukan** source target Slice 1. Semua command/read memakai path target eksplisit; ID pane historis tidak boleh ditebak atau dipakai untuk membuat worker pengganti.

### Target, dependency dan koordinasi (lama)

| Paket | Target / baseline | Acceptance dan handoff |
|---|---|---|
| A — Low PHPDoc | `C:\Users\USER\.herdr\worktrees\prime-google\fix-checksheet-inactive-start-guard`; branch `fix/checksheet-inactive-start-guard`; HEAD `d2588cec2b45f5b30d8182b3bb43b364432efe79` | Discovery tanpa lock dibedakan dari transaksi authoritative; komentar menyebut Checksheet → Machine → Occurrence → Execution. Executable PHP dan tiga file kandidat lain identik; fingerprint lama/baru untuk QA-1. |
| B — dependency alignment | Source target A yang sama, baseline HEAD dibedakan dari kandidat lokal | Matriks actual locks per writer, inverse/deadlock risk, shared files, hard dependency vs konflik file, serta rencana MySQL dua koneksi. Tidak menyatakan InnoDB PASS. |
| Manager — M-DOC-01 | `C:\laragon\www\prime-google\Docs\{PRIME_MASTER_BACKLOG.md,PRIME_PARALLEL_EXECUTION_PLAN.md,PROJECT_STATE.md}` | Edit selektif dari snapshot byte awal, pertahankan TASK/child/evidence dan encoding; BEFORE/AFTER fingerprint untuk QA-2. Tidak membuat file arsip baru. |
| QA — dua tahap | QA-1 memakai kandidat A frozen; QA-2 memakai tiga dokumen frozen | Nonauthorship, scope/hash tepat, findings dan verdict per paket. Tidak menilai snapshot yang masih berubah. |

**Urutan:** A dan B berjalan paralel; Manager mengedit dokumen pada scope terpisah. QA boleh menyiapkan acceptance/test matrix sejak awal. QA-1 baru setelah A fingerprint; QA-2 setelah Manager fingerprint. Hasil QA dikembalikan ke owner penulis untuk koreksi terotorisasi; perubahan menghasilkan fingerprint baru dan review delta. Tidak ada shared-file writers bersamaan.

**Dependency Slice 2–4 — usulan, bukan assignment coding:** B memiliki kontrak lifecycle/pause, aging/notifikasi dan eligibility/proyeksi terkait; A memiliki batas Save Master/Apply Jadwal. `PmExecutionService.php`/`PmExecutorTest.php` perlu handoff dari Slice 1; `PmScheduleUpdateService.php`, reconciler dan preview/apply tests harus satu owner per tahap; `resources/js/app.js` dan horizon preview perlu koordinasi Slice 3–4. Kontrak pause/legacy dan horizon harus konsisten sebelum integrasi; konflik file tidak otomatis melarang analisis/test planning paralel. Matriks lock aktual dari B menjadi input review Manager, bukan persetujuan protokol lock baru.

**Reservasi lock yang perlu ditutup sebelum implementasi:** wrapper `PmChecksheetService::reconcileScheduleDates()` menemukan Machine dari Schedule aktif sebelum lock Schedule; nested reconciler kembali meminta Machine. Perubahan membership sejak discovery dapat mengganggu asumsi Machine → Schedule terhadap lifecycle writer. B mengklasifikasikan interleaving dan evidence statis; jangan menyatakan semua writer sudah mengikuti satu total order atau InnoDB PASS. Koreksi executable di luar gate comment-only ini memerlukan scope/approval baru.

**Blocker yang tidak diperbaiki oleh dokumen:** same-day pause/resume, legacy boundary unknown, arti aging utang pre-pause, dan kontrak Save/Apply/horizon perlu keputusan sebelum implementasi. Environment/browser M-01 serta MySQL concurrency tetap sesuai [Project State](PROJECT_STATE.md#current-state). Jangan membuat migration atau menebak tanggal legacy.

### Approval dan evidence contract (lama)

- Diizinkan saat itu: satu PHPDoc comment-only, edit selektif tiga dokumen, read-only dependency alignment dan independent review; focused validation aman bila perlu.
- Tidak diizinkan: executable source/test baru, coding Slice 2–5, migration/schema, branch/worktree/terminal baru, source M-01, penghapusan artefak, Git fetch/pull/checkout/reset/rebase/stash/clean, stage/commit/push/PR/merge, dependency install, MySQL/browser/provider live/deployment.
- Root Gemini dirty/untracked dan M-01 frozen dipreservasi. Untracked bukan disposable; jangan overwrite dokumen dari template. Tidak ada perubahan konfigurasi global/persisten dari tugas ini.
- Untuk comment-only: bandingkan executable token stream dan tiga file lain; gunakan PHP 8.3.36 untuk syntax/Pint scoped yang diperlukan, tanpa test suite ulang hanya untuk reconfirm bukti lama. Perubahan perilaku di gate lain memerlukan focused regression; PHPUnit tetap SQLite `:memory:` dengan guard dan fakes, bukan MySQL.
- Handoff menyebut owner/reviewer, branch/HEAD, path/hunk, hash/fingerprint lama/baru, command/exit bila dijalankan, findings, batas SQLite/MySQL dan approval tersisa. `RUNNING`, `READY_FOR_REVIEW`, `CHANGES_REQUESTED`, `VERIFIED` dan `BLOCKED` harus didukung evidence; verdict internal bukan formal GitHub approval.
- Publication adalah gate terpisah: Owner approval spesifik, branch bukan main/master, author/git user benar, secret/scope screen, selective OMP `commit` namespace. Membaca Guard Git bukan bukti hook terpasang; tidak memasang hook pada gate ini.

**Handoff A / QA-1 (lama):** koreksi PHPDoc sudah frozen; QA-1 `SHIP_FOR_OWNER_REVIEW`, findings none. Executable token stream dan tiga file lain identik; fingerprint baru serta batas bukti dimiliki [Project State](PROJECT_STATE.md#current-state). Tidak ada pekerjaan Slice 4 yang diaktifkan. QA-2 hanya menilai dokumen setelah fingerprint terpisah; hasilnya dicatat pada handoff fingerprint, bukan diprediksi dari laporan Manager.

</details>

## Riwayat koordinasi — bukan instruksi aktif

<details>
<summary>SUPERSEDED — checkpoint Manager/A/B/QA dan M-01, 17–18 November 2026</summary>

Bagian berikut dipertahankan untuk traceability recovery, approval dan handoff. Pernyataan “status aktif”, larangan subagent, cap empat sesi total, assignment CI/browser lama dan CI PR #4 FAILURE telah **SUPERSEDED** untuk operasi saat ini oleh workflow di atas dan ledger penutupan Project State. Klaim test/QA historis tetap hanya berlaku pada kandidat dan scope asal.

## SUPERSEDED — rencana Manager / Fullstack / QA terdahulu

### 0. Rancangan singkat opsi 1 — acceptance dan CI

**Status aktif:** gate CI PR #4 ditutup; tidak ada assignment A/B/QA baru. Q-02/Q-03/Q-04 tetap diterima dan tidak diulang pada kandidat identik. Owner perlu menunjuk admin environment QA dan menyediakan akses read-only yang aman sebelum preflight A dengan approval terpisah. Instruksi awal A/B/QA dan checklist di bawah merekam urutan/handoff terdahulu, bukan otorisasi eksekusi baru.

**Tujuan dan alasan:** tuntaskan pembuktian kandidat yang sudah digabung sebelum menambah fitur. Alternatif membuka Checksheet/Report PM berikutnya ditunda karena menambah kandidat dan pekerjaan integrasi sementara browser acceptance masih terbuka.

**Bukti yang diterima:** laporan independent QA Q-02 yang diberikan Owner (`local://paste-9.md` pada sesi review) menyatakan 233 tes PHP/1.816 assertions dan 3 tes JavaScript PASS, fingerprint 33 path cocok sebelum/sesudah, retired view tetap tidak ada, serta manifest/lockfile tidak berubah. Ini bukti reviewer, bukan eksekusi ulang Manager. Typecheck/build tetap bukti integrator. **Q-02 diterima; gate M-01 saat ini `UI_NOT_VERIFIED` dengan acceptance operasional/release masih terbuka.** Q-03 hanya mereview delta CI B yang terpisah; bukan QA M-01 atau approval merge.

**Penutupan CI yang diterima:** HEAD PR `393a505d9c63b36c36d20062bbec84e46369e7ef`; CI PR `44084322427` PASS. Q-04 memberi verdict `SHIP_FOR_OWNER_REVIEW`, findings none, untuk delta pemindahan build setelah baseline review `91f3e32`. Owner kemudian mengizinkan merge normal; [PR #4](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/4) MERGED dan [Actions main `44094015983`](https://github.com/SumberMakmurRejeki/prime-chatbot/actions/runs/44094015983) benar-benar menjalankan/lulus keempat gate tanpa SKIPPED. Ini bukan browser acceptance, CI M-01 atau release approval.

**Kandidat yang dilindungi:** target M-01 `C:\Users\USER\.herdr\worktrees\prime-google\integrate-wave-1-owner-approved`, branch `integrate/gemini-security-user-settings-pm-review`, dengan fingerprint yang tercatat pada checkpoint M-01. Source aplikasi tetap beku. Perubahan CI tidak diterapkan ke kandidat ini selama pemeriksaan browser.

**Fullstack A — prasyarat browser QA:** tahap awal hanya inspeksi source/configuration secara read-only. Susun satu paket izin yang menyebut origin loopback, target database/storage dan secondary connection, environment proses, akun sintetis Admin dengan/tanpa izin AI dan Operator, data historis/current/legacy, operasi tulis yang diperlukan, serta cleanup milik task. Tidak membuat konfigurasi, APP_KEY, akun, fixture, schema, atau runtime pada tahap ini; tidak menjalankan Tinker maupun menghubungi database/provider. `.env.testing` belum tersedia menurut Q-02. Jika memakai MySQL, guard kandidat hanya mengizinkan `db_preventive_maintenance_ai_test`; `db_preventive_maintenance` dan `db_preventive_maintenance_test` dilarang. Skill lama tidak mengalahkan guard. Rencana juga harus menjelaskan cara menguji discovery tanpa provider/credential live; jika belum tersedia, laporkan kebutuhan tersebut secara spesifik, bukan mengklaim UI lengkap.

**Environment QA saat ini — BLOCKED:** belum ada admin lingkungan yang terbukti ditunjuk atau konfigurasi/credential QA yang disetujui. A berhenti sebelum koneksi; identitas koneksi dan schema NOT VERIFIED. Owner perlu menunjuk administrator MySQL/Laragon QA dan menyediakan akun read-only (SELECT + metadata schema yang diperlukan) melalui jalur credential lokal aman, bukan chat/repository. Target `127.0.0.1:3306/db_preventive_maintenance_ai_test`; proses `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_URL` kosong. Default, webpush, session dan seluruh read/write target wajib terbukti menuju QA yang sama sebelum koneksi. Jangan gunakan environment Manager, APP_KEY warisan atau credential PRIME asli. Preflight A memerlukan approval terpisah setelah akses siap; tidak ada izin database access, akun/privilege, fixture atau runtime pada tugas dokumentasi ini.

**Fullstack B — instruksi kandidat CI terdahulu (historis; gate kini ditutup):** konfirmasi worktree/branch/HEAD, ownership, perubahan lokal, dan status kandidat CI sebelum memilih delta. Catatan failure tanggal bisnis Jakarta pada P1 merupakan konteks historis, bukan klaim failure terkini. Jika masih relevan, scope koreksi adalah fixture future pada `tests/Feature/Feature/PM/PmExecutorTest.php`, tanpa mengubah kontrak `BusinessDate`/Executor, melemahkan assertions, atau skip test. Gunakan laporan failure sebagai repro, bukan rerun penuh untuk mengonfirmasi. Source CI terpisah dari M-01; `.github/workflows/quality.yml` tetap milik Manager dan tidak diedit worker tanpa reservasi. Setelah delta selesai, jalankan pemeriksaan terarah, lalu freeze untuk review QA; Git publication dan CI remote tetap memerlukan izin.

**QA — reviewer non-penulis:** gunakan acceptance yang sudah disepakati, bukan mengulang Q-02 tanpa perubahan. Setelah runtime dan fixture mendapat izin serta identitas koneksi efektif terbukti, periksa Pengaturan User/izin AI, konfigurasi/discovery Gemini sesuai isolasi yang disetujui, PM Review historis/current/legacy dan filtering/pagination, serta akses deep link, keyboard/focus dan desktop/mobile. Tetap dua role; `manage_ai` adalah izin Admin, bukan role tambahan. Review CI telah selesai melalui Q-03/Q-04 dan tidak diulang pada kandidat identik. QA tidak menulis perbaikan lalu mengesahkannya sendiri.

**Manager dan urutan saat ini:** Manager memegang dokumen, batas scope, penerimaan handoff dan gate berikutnya. M-GATE-09 mengizinkan update tiga dokumen dan triage read-only saja. A/B/QA tidak di-dispatch; paket pekerjaan berikutnya memerlukan approval Owner. M-01 tetap frozen, tanpa integrasi ulang patch CI/XLSX. Browser baru mengikuti paket environment, preflight, harness, fixture/runtime dan approval yang sesuai; tidak ada agent tambahan atau dispatch tersirat.

**Batas dan exit:** persetujuan rancangan tidak mengizinkan provisioning, penulisan session lewat HTTP, operasi database runtime, provider live, instalasi dependency, commit/push/PR/merge atau deployment. Verifikasi otomatis B/QA hanya memakai SQLite `:memory:` terisolasi dengan guard aktif dan environment proses sintetis. Tidak menyentuh PRIME asli maupun kedua databasenya. Hasil browser dan CI harus menyebut kandidat serta bukti yang benar-benar diamati; hasil CI satu kandidat bukan bukti kandidat lain. Parent TASK-01/06/17, performa MySQL, dan gate rilis tetap terbuka sesuai scope masing-masing. Jika prasyarat belum diizinkan, laporkan blocker spesifik dan pertahankan UI_NOT_VERIFIED; jangan mengarang fallback atau melonggarkan guard.

#### Langkah eksekusi dan kontrak handoff opsi 1

Identitas kandidat pada checkpoint sebelum penutupan CI (historis, 17 November):
- M-01 `C:\Users\USER\.herdr\worktrees\prime-google\integrate-wave-1-owner-approved`, branch `integrate/gemini-security-user-settings-pm-review`, HEAD `c6dc1be28fba22fa4dba8382891ac070c51e76c7`; worktree tetap dirty dengan source integrasi lokal belum dipublikasikan.
- CI `C:\Users\USER\.herdr\worktrees\prime-google\chore-ci-pr-quality-gates`, branch `chore/ci-pr-quality-gates`, HEAD/baseline remote `21c3291f000d1b29cf11f6875b1b71e67347b513`, tracking origin; satu file test termodifikasi. Hash file kandidat saat QA: `660d312f738e2dfe473909b4a835994abc3716f720ffc68ff21b7f9445c4b859`. Perubahan terisolasi dari M-01.

**A-BROWSER-PREREQ, Fullstack A — BLOCKED**
- [x] Inspeksi read-only atas guard, template konfigurasi, kebutuhan session/permission, koneksi sekunder dan jalur mencegah provider call.
- [x] Menyerahkan hasil inspeksi/prasyarat; belum ada paket runtime/fixture yang dapat diprovision tanpa izin Owner.
- [x] Status akhir `BLOCKED`: browser QA tidak dimulai. Runtime, akun/fixture sintetis, database/session write dan cleanup masih memerlukan prasyarat/approval spesifik.

**B-CI-FINALIZE, Fullstack B — checkpoint sebelum M-GATE-08 (historis; gate kini ditutup)**
- [x] Mempertahankan delta existing dan memperbarui dua fixture future-date dalam `tests/Feature/Feature/PM/PmExecutorTest.php`; waktu dibekukan pada `2026-11-15 17:38:34 UTC`, tanggal target dari `BusinessDate::today()->addDay()`, pemulihan melalui `finally`/`travelBack()`.
- [x] Author melaporkan PHPUnit terarah 117 tests/770 assertions dan Pint PASS. QA memeriksa delta read-only; tidak menjalankan ulang tests/Pint.
- [x] Membekukan kandidat dengan SHA-256 `660d312f738e2dfe473909b4a835994abc3716f720ffc68ff21b7f9445c4b859`; belum commit/push. GitHub Actions terbaru PR #4 tetap FAILURE pada PHPUnit (`43742200425`), jadi laporan lokal bukan full/remote CI PASS.

**Q-03, QA independen — SELESAI / DITERIMA**
- [x] Mereview frozen test-only delta B terhadap baseline `21c3291f000d1b29cf11f6875b1b71e67347b513`; verdict **Ship**, findings none, terbatas pada delta tersebut.
- [x] Memeriksa fixture, `BusinessDate`, future-date guard executor, setup/teardown, SQLite `:memory:` dan hash kandidat. Tidak ada edit atau child agent; tidak menjalankan tests/Pint.
- [x] Menegaskan full CI dan browser acceptance tidak diverifikasi. Verdict bukan approval merge/release.

**Q-04, QA independen — SELESAI / DITERIMA**
- [x] Mereview hanya delta urutan build sebelum PHPUnit pada HEAD `393a505d9c63b36c36d20062bbec84e46369e7ef`; verdict `SHIP_FOR_OWNER_REVIEW`, findings none. Tidak membuka ulang Q-03 atau audit changed-file Pint historis.
- [x] Mencocokkan run/job CI yang sukses tanpa menjalankan ulang tests/build/Pint; tidak mengedit source atau submit formal review. Verdict internal terpisah dari approval merge Owner yang kemudian dijalankan pada M-GATE-08.

- [ ] Browser M-01 baru boleh dimulai setelah paket A lengkap, provisioning disetujui eksplisit, runtime tersedia dan target koneksi tervalidasi.
- [x] Gate CI PR #4 ditutup: MERGED pada `f048f742541efd93a59f41899fe5df72996208b3`, Actions main `44094015983` PASS tanpa melemahkan gate.

Instruksi A/B/Q-03 terdahulu telah dijalankan pada scope yang diizinkan; Q-04 selesai dan diterima, lalu merge PR #4 dijalankan melalui approval Owner terpisah pada M-GATE-08. Branch, HEAD dan Git status 11 worktree tetap identik sebelum/sesudah merge; dirty root tidak disinkronkan. Tidak ada browser acceptance M-01 atau release yang dinyatakan selesai. M-GATE-09 tidak membuat agent/child, tidak dispatch worker, dan tidak memberi izin Git publication, database atau runtime baru.

### 0.1 Verifikasi konteks Manager–Herdr — 17 November 2026

Owner menyetujui penyiapan jalur kontrol yang sah dengan mempertahankan tiga worker existing, tanpa membuat sesi/pane tambahan atau mengarang identitas. Diagnosis dan smoke proof berikut tidak menjalankan tugas bisnis, provisioning QA, Git mutation atau perubahan source aplikasi.

- **Koreksi diagnosis:** variabel `HERDR_*` kosong pada tool Bash/Python tidak membuktikan proses Manager berada di luar Herdr. Inspeksi whitelist environment pada proses OMP Manager dan parent PowerShell menunjukkan konteks Herdr asli tersedia; evaluator JavaScript OMP juga mewarisinya. Batas kehilangan konteks yang teramati berada pada jalur tool Bash/Python.
- **Jalur yang terbukti:** gunakan evaluator JavaScript dengan gate `process.env.HERDR_ENV === '1'`, caller workspace/tab/pane dan socket yang benar-benar diwarisi, serta executable dari `process.env.HERDR_BIN_PATH`. Jalankan CLI lewat `Bun.spawn` dengan argumen array dan environment terbatas pada variabel Herdr tersebut serta kebutuhan OS. Jangan menyalin nilai ID/socket ke konfigurasi permanen, mengatur `HERDR_ENV=1` secara manual, atau meneruskan secrets provider dari parent.
- **Bukti runtime:** CLI terpasang `herdr 0.9.4-preview.2026-10-14-fbd2ae23927f`; OMP `18.20.2`. `pane current --current` berhasil dan hasil workspace/tab/pane cocok dengan caller asli; cwd adalah repository PRIME Google dan agent dikenali sebagai `omp`. Pengujian environment forwarding minimal juga berhasil. Tidak perlu restart, instalasi/upgrade, perubahan konfigurasi global, atau penonaktifan filter environment.
- **Batas worker:** inventory aktual `agent list`, `workspace list`, dan `pane list` pada konteks tersebut hanya memperlihatkan Manager dalam satu workspace/pane. `session list` hanya memperlihatkan session default yang berjalan. Ini bukan bukti tiga worker Owner telah terhubung atau hilang; lokasi/identitas A/B/QA masih perlu dicocokkan. Tidak membuat worker pengganti atau mengakses proses lain sebagai proxy.
- **Status:** kontrol baca Manager **VERIFIED**; koneksi tiga worker **NOT VERIFIED**. Belum ada prompt, rename, start/stop agent, pembuatan pane/session, attach/takeover, atau perpindahan fokus. Pertahankan batas ini sampai identitas worker yang benar diperoleh.

Pada pemakaian berikutnya, verifikasi lagi konteks asli evaluator dan identitas self-pane; gunakan ID dari respons live, bukan ID/PID checkpoint ini. Helper/kernel bukan konfigurasi global yang dijamin bertahan setelah restart. Jika gate evaluator yang digunakan tidak terpenuhi, hentikan kontrol; jangan beralih ke focused session.

### 0.2 Pemulihan tiga worker existing — 17 November 2026

Setelah Owner mengonfirmasi A/B/QA tidak muncul di jendela Herdr, Owner memilih `approve_verify_old_sessions_and_restore_three_panes`: izinkan pencocokan sesi lama dan pemulihannya ke tiga pane baru, bukan membuat sesi OMP pengganti atau menjalankan tugas bisnis.

Identitas dibuktikan dari metadata/prompt tersimpan: A memuat `A-BROWSER-PREREQ`, B memuat `B-CI-FINALIZE`, dan attachment QA memuat `M-01 / Q-02`. Sebelum launch, `omp session lease --json` atas tiga ID tersebut mengembalikan `held: []` dan `unknown: []`. Topologi sebelum perubahan hanya memuat Manager.

| Worker | Nama Herdr | Pane checkpoint | Sesi OMP lama yang dipulihkan | Worktree |
| --- | --- | --- | --- | --- |
| Fullstack A | `fullstack-a` | `w5:p6` | `01a1e79e-ac94-7363-8e3f-215898cc7268` | `integrate-wave-1-owner-approved` |
| Fullstack B | `fullstack-b` | `w5:p7` | `01a1e7a0-298f-735e-93c2-75127c51235c` | `chore-ci-pr-quality-gates` |
| QA | `qa` | `w5:p8` | `01a1e764-20dc-74de-90be-7d91f7b38d13` | `integrate-wave-1-owner-approved` |

- Tiga pane shell dibuat pada workspace/tab Manager dengan `--no-focus`, lalu `herdr agent start --kind omp` memakai `--resume` dengan path sesi lama yang eksplisit. Tidak memakai `--restore`, karena opsi tersebut dapat melanjutkan turn/subagent yang tertunda.
- Verifikasi `agent get` mencocokkan path sesi aktual setiap worker dengan path lama. Lease setelah launch menyatakan ketiga ID `held`, tanpa `unknown`. Inventory akhir berisi tepat empat agent: Manager dan tiga worker; A/B/QA berstatus `idle`.
- Uji komunikasi mengirim satu prompt konektivitas per worker, melarang task lama, perubahan file, command/tool proyek, database/provider dan subagent. Output terminal yang dibaca menunjukkan `READY-FULLSTACK-A`, `READY-FULLSTACK-B`, dan `READY-QA`. B juga melakukan kompresi konteks sesi sebelum acknowledgment; itu bukan hasil task proyek baru.
- **Status: pemulihan dan komunikasi Manager–A/B/QA VERIFIED.** Ada tiga pane baru, tetapi ketiga percakapan OMP adalah sesi existing yang sama. Tidak ada tugas bisnis, instalasi/upgrade, provisioning QA, operasi database, Git publication atau deployment yang dijalankan dalam pemulihan ini.
- Nama/ID pane di tabel adalah checkpoint aktual, bukan nilai untuk ditebak pada sesi mendatang. Temukan kembali identitas live sebelum kontrol berikutnya. Worker tetap menunggu penugasan yang sudah siap; jangan mengirim prompt berisi pekerjaan yang masih menunggu prasyarat.




### A. Peran tetap dan aturan yang tidak boleh dilanggar

| Peran | Tanggung jawab | Tidak boleh |
| --- | --- | --- |
| Manager, sesi induk | Menetapkan scope dan penulis, mencatat penerimaan handoff, menjalankan gate, menggabungkan kandidat setelah diizinkan, melaporkan bukti | Menganggap prompt sebagai dispatch atau mengklaim QA yang belum berjalan |
| Fullstack B, worker existing | Menangani delta kandidat CI existing secara terpisah setelah rancangan diterima dan ownership dikonfirmasi | Mengubah kontrak bisnis, workflow milik Manager tanpa reservasi, kandidat M-01, atau spawn child |
| Fullstack A, worker existing | Menyiapkan paket prasyarat browser QA read-only; provisioning baru hanya setelah izin spesifik | Membuat konfigurasi/runtime/fixture tanpa izin, mengedit source M-01, atau spawn child |
| QA, satu worker non-penulis | Browser acceptance M-01 setelah gate runtime; review independen delta CI setelah freeze | Mengulang Q-02 tanpa alasan, menulis perbaikan lalu mengesahkannya sendiri, atau mengklaim UI PASS tanpa browser |

- Maksimal empat sesi total terkait rencana ini: Manager, Fullstack A, Fullstack B, dan QA existing. Ini perubahan eksplisit dari cap tiga total mengikuti instruksi Owner memakai tiga worker. Tidak ada Manager kedua, agent tersembunyi, atau child dari worker.
- Gunakan tiga worker existing; tidak spawn native agent pengganti/tambahan. Jangan mengarang environment Herdr atau memakai focused pane sebagai pengganti identitas sesi. Jika kontrol tidak tersedia, berikan instruksi siap pakai dengan status belum terkirim.
- Satu penulis per worktree/scope; reviewer masuk setelah freeze. Paket prasyarat A read-only dan kandidat CI B terpisah boleh disiapkan paralel; browser dan review delta mengikuti gate masing-masing pada rancangan opsi 1. Assignment B-04/R-02/A-01 sebelumnya adalah checkpoint historis, bukan instruksi aktif baru.
- Semua edit di worktree target dengan path/cwd eksplisit. Checkout `C:\laragon\www\prime-google` tetap sumber yang dipreservasi, kecuali dokumen koordinasi yang dimiliki Manager.
- Tidak ada reset/clean/restore massal, overwrite file dari checkout lain, atau penghapusan artefak yang tidak dimiliki task.
- Tidak ada commit/push/PR/merge, dependency install, Tinker, migration/seeder/runtime fixture, operasi provider live, atau deployment tanpa approval Owner yang spesifik. Native spawn tidak memperluas izin tersebut.
- Sebelum commit yang disetujui: branch bukan main/master, identitas Git/author benar, scope dan secret diperiksa; gunakan selective `commit` namespace OMP, bukan staging seluruh worktree.

### B. Pengalihan assignment worker existing

Riwayat lama: sebelum assignment pada checkpoint 17 November, A/B/QA dilaporkan idle setelah sesi existing dipulihkan. Assignment berikut menggantikan pembagian lama R-02/B-04; status aktual dicatat pada checklist aktif bagian 0 di atas.

- [x] A menyelesaikan inspeksi browser prereq read-only dan mengembalikan `BLOCKED`; belum ada browser session/runtime atau fixture yang dibuat.
- [x] B menyelesaikan kandidat CI test-only dan menyerahkan hash/bukti terarah; file tetap dirty pada worktree CI.
- [x] QA existing mengerjakan Q-03 read-only sebagai reviewer terpisah; findings none untuk delta B. QA tidak menjalankan tests/Pint atau mengubah source.
- Assignment A/B/Q-03 diterima melalui persetujuan eksplisit Owner; bukti idle/connected saja tidak pernah diperlakukan sebagai otorisasi.

Mengetahui pembuat setiap baris sidebar lama bukan syarat melanjutkan kandidat yang telah diadopsi. Syaratnya adalah perubahan lama dipreservasi, penulis ke depan jelas, dan tidak ada penulis bersamaan. Jika file berubah di luar edit worker, berhenti pada file itu dan rekonsiliasi dengan Manager; jangan overwrite.

### C. Dua paket awal dan kontrak yang sudah diputuskan
> **Catatan status:** Bagian C–D berikut adalah rancangan/assignment lama TASK-06 / TASK-01 / TASK-17 dan tidak menjadi instruksi worker aktif. Pekerjaan yang kemudian diselesaikan, kandidat M-01, serta gate kini dirangkum pada bagian 0 dan M-01. Jangan dispatch ulang tugas lama berdasarkan checklist/urutan di bawah.

**Fullstack — TASK-06 / B-04**

- Worktree: `C:\Users\USER\.herdr\worktrees\prime-google\feat-ai-navigation-consolidation`.
- Branch: `feat/ai-navigation-consolidation`; checkpoint laporan B `c6dc1be28fba22fa4dba8382891ac070c51e76c7`, hanya `resources/js/components/Sidebar.vue` dirty, staging kosong. Ini checkpoint laporan, bukan klaim status live.
- Tujuan: tempatkan pengelolaan `manage_ai` pada Pengaturan User; sidebar AI hanya Assistant, Knowledge Documents, dan Konfigurasi Gemini. Pengaturan User tetap di luar AI. Ini menggantikan usulan lama Hak Akses AI sebagai menu terpisah.
- Tetap gunakan halaman User Settings existing, bukan migrasi Vue/redesign baru. Form izin terpisah dari simpan akun, dengan password actor saat ini dan endpoint grant/revoke existing. Tidak ada dua request terselubung pada satu tombol simpan akun.
- Pertahankan `AiManagerService`, gate/middleware, grant/revoke route, validasi target Admin aktif, pencabutan sesi, audit, last-manager protection, dan logout self-revoke. Jangan membuka mass assignment `manage_ai` melalui update akun biasa atau mengubah proteksi akun pengelola pada `UserSettingService`.
- GET AI manager lama diarahkan ke User Settings dengan otorisasi utuh. View standalone hanya dihapus jika sudah tidak dipakai dan penggantinya diverifikasi. Tidak menghapus endpoint mutasi atau melakukan cleanup Blade global.
- Role aplikasi tetap Admin/Operator: Assistant hanya Admin; administrasi AI memerlukan `manage_ai`; Operator tidak mendapat AI/Review/User Settings; Admin tidak mendapat PM Executor.

**Allowed files, relatif terhadap worktree B-04:**

- `resources/js/components/Sidebar.vue`
- `resources/views/partials/sidebar.blade.php`
- `resources/views/pages/settings/users/index.blade.php`
- `resources/views/pages/settings/ai-managers/index.blade.php` hanya retirement yang terbukti aman
- `app/Http/Controllers/Settings/UserSettingController.php`
- `app/Http/Controllers/Settings/AiManagerController.php`
- `resources/js/app.js` hanya blok User Settings
- `tests/Feature/Feature/Settings/UserSettingTest.php`
- `tests/Feature/Feature/Settings/AiManagerTest.php`

Routes, provider, model, account request, serta kedua service adalah dependency read-only. Perluasan scope dijelaskan kepada Manager sebelum edit; keputusan bisnis baru tetap kepada Owner.

**Fullstack A — TASK-01 / A-01, manifest rekonsiliasi read-only**

- Worktree sumber: `C:\Users\USER\.herdr\worktrees\prime-google\fix-gemini-provider-security`, branch `fix/gemini-provider-security`; root `C:\laragon\www\prime-google` hanya sumber pembanding read-only.
- Lanjutkan laporan A-01 existing yang akhirnya BLOCKED karena manifest/caller/overlap belum lengkap. Jangan membangun ulang hardening atau mengklaim source berubah hanya karena penulis lama belum diketahui.
- Pisahkan hardening committed `1076689`, addon lokal lima file yang dilaporkan, dan dependency dirty root yang relevan. Rekam HEAD/hash aktual, included/excluded dengan alasan, caller, dependency, konflik, dan basis integrasi yang diusulkan.
- Pertahankan operational DB/SOP wrapper, gateway text/file/store yang aman, credential endpoint binding, citation fail-closed, dan keputusan UI/provider yang telah disetujui. Jangan replay XLSX yang sudah termasuk basis.
- Source tetap read-only: tidak menerapkan patch, menulis manifest ke repo, memformat, menjalankan build/test, atau mengubah worktree integrasi. Serahkan manifest dan blocker dalam report chat untuk Manager. Jika source berubah selama inspeksi, identifikasi batas snapshot sebelum menyatakan manifest siap.
- Manifest siap bukan security QA PASS atau izin integrasi. Setelah ini, perbaikan/source integration mendapat assignment dan approval tersendiri.


**QA — TASK-17 / R-02**

- Worktree: `C:\Users\USER\.herdr\worktrees\prime-google\fix-pm-review-data-quality-origin-main`.
- Branch: `fix/pm-review-data-quality-origin-main`; checkpoint HEAD `277d7251da2ea9768bef480927223b8a502ca456`.
- Kandidat enam file: `app/Http/Controllers/PM/PmReviewController.php`, `app/Services/PM/PmReviewService.php`, `resources/views/pages/pm/review/index.blade.php`, `resources/js/app.js`, `tests/Feature/Feature/PM/PmReviewTest.php`, `tests/JavaScript/pm-review-server.test.js`.
- Fingerprint laporan B-02: `35300377933d2512b9a899765ba4a1cf30c79769802d90efbf9c25c9a788472b`. Agregat ini hanya dapat dibandingkan bila format/path/urutan sama; jangan menganggap beda format sebagai perubahan source.
- QA merekam HEAD serta hash masing-masing enam file. Untuk handoff baru, gunakan satu format tetap: SHA-256 atas UTF-8 tanpa BOM berisi enam baris `<path relatif><TAB><sha256 file lowercase><LF>` dalam urutan daftar di atas. Hash sebelum/sesudah verifikasi harus sama; bila berubah, hasil tidak berlaku untuk kandidat baru.
- Kontrak Owner final: display/sort identitas historis; filter lokasi snapshot; search kode/nama snapshot OR master mesin terkini. Legacy boleh ditemukan lewat relasi terkini tetapi tetap dilabel legacy dan empat snapshot tidak diisi ulang.
- Periksa diff dan alur nyata, bukan hanya report penulis. Regression `test_pm_review_finds_legacy_record_by_current_machine_without_rewriting_snapshots` dan coverage existing menjadi dasar verifikasi. Jangan meminta keputusan historical/current/both lagi.
- Report PM, benchmark, WATCHDOG, dan YAML di worktree itu tidak otomatis masuk scope enam file. Klaim lulus R-02 tidak menyelesaikan parent TASK-17, performa MySQL, atau integration readiness.

### D. Urutan pelaksanaan dan jalur perbaikan

1. **Historis, bukan assignment aktif:** urutan lama TASK-06/TASK-01/TASK-17 ini mendahului integrasi M-01; langkah 2–6 di bawah menjelaskan alur yang direncanakan saat itu. Status dan hasil aktual A/B/Q-03 tercatat pada bagian 0 dan M-01 di atas.
2. Jika QA menemukan masalah PM Review, QA mengembalikan temuan dengan file/baris, kasus pengguna, dan dampak. QA tidak menulis fix.
3. Jika ada temuan PM Review, A menuntaskan atau memarkir manifest read-only pada titik aman. Manager mengembalikan ownership perbaikan PM Review kepada A dengan scope temuan yang jelas; QA berhenti menguji kandidat itu sampai A menyerahkan freeze/hash baru. B tetap fokus B-04, bukan dipindah untuk menunggu review paket lain.
4. QA dapat memeriksa B-04 yang beku saat Fullstack memperbaiki PM Review di worktree lain. Setelah fix, QA memverifikasi delta dan jalur terdampak pada fingerprint baru; tidak mengulang audit penuh tanpa alasan.
5. Owner menyetujui integrasi lokal M-01 dan instalasi locked secara terpisah. Target `C:\Users\USER\.herdr\worktrees\prime-google\integrate-wave-1-owner-approved`, branch `integrate/gemini-security-user-settings-pm-review`, telah dibuat dari `c6dc1be28fba22fa4dba8382891ac070c51e76c7`. Source TASK-01/06/17 dan dependency yang dinamai telah digabung secara selektif oleh Manager tunggal. Kandidat parsial `integrate-wave-1` tetap dipreservasi. Rincian bukti ada pada bagian M-01 di backlog.
6. Usulan urutan: TASK-01 hardening + addon URL/credential; dependency wajib wrapper/retrieval operasional dan UI/discovery provider; TASK-06 dengan hanya blok User Settings `resources/js/app.js`; TASK-17 dengan hanya blok PM Review `resources/js/app.js`; rekonsiliasi routes/binding/shared hunks; freeze, independent review dan focused combined checks. Jangan replay XLSX. Existing-document processing dan hunk route process tetap dikecualikan serta dipreservasi pada root.
7. **Hasil aktual:** source/provisioning dan instalasi locked dilakukan hanya pada target M-01 yang disetujui. A/B/Q-03 dispatch dan hasilnya dicatat pada status aktif bagian 0 dan backlog F.7. Tahap itu tidak memberi izin runtime/browser/fixture tambahan, DB operasional/provider live, commit/push/PR/merge atau deployment; semuanya tetap memerlukan approval spesifik.

### M-01 — Status rekonsiliasi dan gate kandidat (17 November 2026)

- **Basis dan target aktual:** commit `c6dc1be28fba22fa4dba8382891ac070c51e76c7`; target `C:\Users\USER\.herdr\worktrees\prime-google\integrate-wave-1-owner-approved`, branch `integrate/gemini-security-user-settings-pm-review`, sudah tersedia dan berisi integrasi lokal yang belum di-commit. Bukan salinan seluruh dirty root; worktree sumber dan `integrate-wave-1` dipreservasi.
- **TASK-01:** laporan author/QA terdahulu 64 tests/522 assertions PASS; five local addons remain dirty, raw hash computation/command/exit code/comparison log NOT RECORDED. Rekonsiliasi Base URL resmi + endpoint-bound credential, gateway/caller, dan image mitigation; belum final security acceptance.
- **Dependency wajib dari root lokal:** `OperationalPmAssistantAnswerService` + hunk binding `AppServiceProvider`, jalur `GeminiGroundedAnswerService`/retrieval eligibility/source scope dan tes terkait; UI provider Inertia/Vue + controller, request discovery, model discovery dan hunk middleware provider di `routes/web.php`. Basis committed belum memuat wrapper tersebut dan masih memakai halaman provider Blade. Rincian path/hunk ada pada manifest M-01 di backlog; jangan menyalin seluruh dirty root atau menganggap dependency ini tercakup hasil 64/522.
- **TASK-06:** independent QA report `UserSettingTest` 8/50 dan `AiManagerTest` 10/135 PASS; `READY_FOR_REVIEW / UI_NOT_VERIFIED`. Pertahankan `tests/Feature/Feature/Settings/UserSettingTest.php` sebagai coverage baseline tanpa menciptakan delta lokal; manifest membawa delta `AiManagerTest` dan kedua suite masuk verifikasi gabungan. Sidebar Operator finding dicabut sebagai false positive. Belum ada browser/UI atau integrated route/provider check.
- **TASK-17:** independent QA report `PmReviewTest` 60/564, JS 3 PASS dan enam hash stabil; inventory YAML mismatch/extra local report files belum direkonsiliasi. Bukan penyelesaian parent; MySQL performance NOT VERIFIED.
- **Checkpoint integrator 17 November:** 233 PHP tests/1.816 assertions PASS, Pint scoped PASS, typecheck/build PASS, 3 JavaScript PM Review PASS; source gabungan beku pada fingerprint `fc46780ecd4aa903e7d982d9313ba554a773134d355431a5d30da9eff0dd6a08`. **Q-02 accepted; status M-01 `UI_NOT_VERIFIED`**, dengan browser acceptance, MySQL, operational acceptance, full CI kandidat rilis, dan release gate masih terbuka. Parent TASK-01/06/17 tetap terbuka.
- **Gate terkini:** Q-03 hanya mereview delta CI B, bukan M-01. Full CI terbaru PR #4 gagal di PHPUnit; browser M-01 blocked oleh prerequisite/provisioning yang belum disetujui. Tidak ada persetujuan merge atau release.
- Instalasi pada target baru memakai approval locked khusus M-01, bukan memindahkan izin B-04: Composer 136 installs/0 updates/0 removals dan npm 165 packages, tanpa scripts/plugins otomatis; keempat manifest/lockfile tetap identik. Tes SQLite terisolasi bukan izin database runtime/provider live; publication/deployment tetap gate terpisah.

Paragraf ini adalah panduan koordinasi umum; Q-03 saat ini telah selesai. Jangan menganggap QA masih menunggu kandidat B.

### E. Verifikasi proporsional dan definisi selesai

- Skill dan aturan dimuat sekali per konteks; baca ulang hanya materi yang berubah/belum tersedia. Sebelum edit, ikuti rule index dan aturan domain. Jangan mengulang pemetaan B-03 atau keputusan bisnis yang sudah diterima.
- PHP wajib `C:\laragon\bin\php\php-8.3.36-nts-Win32-vs16-x64\php.exe`; PHPUnit wajib SQLite `:memory:`, synthetic APP_KEY, dan guard repository. HTTP/DNS eksternal difake. Session database yang dibutuhkan tes permission menggunakan SQLite test terisolasi, bukan DB runtime dan bukan bypass guard.
- Untuk B-04, inspect dan jalankan `UserSettingTest` serta `AiManagerTest` existing sebelum membuat tes tambahan; tambah hanya regression integrasi/UI atau celah perilaku yang belum tercakup. Jangan menduplikasi tes keamanan existing.
- Untuk R-02, pakai tes existing yang relevan dengan legacy, search/snapshot/lokasi, sort/pagination, dan akses; jalankan focused PHP checks serta `node --test tests/JavaScript/pm-review-server.test.js`. Tidak mengulang benchmark 10k atau full suite per worker.
- Fullstack menjalankan checks terdampak setelah implementasi selesai. Setelah PHP berubah, Pint sesuai aturan proyek tanpa memformat file di luar ownership. Vue memerlukan build/typecheck relevan; build bukan bukti UI benar.
- QA menjalankan verifikasi independen pada fingerprint yang sama. Source tetap read-only; test/build artifact hanya pada kandidat yang telah diserahkan eksklusif. Tidak menjalankan build/test bersamaan dengan penulis pada worktree yang sama.
- Browser hanya pada runtime QA terisolasi dan fixture yang sudah diizinkan. Buktikan permukaan desktop/mobile dan akses role. Jika runtime belum tersedia, laporkan `UI_NOT_VERIFIED`; code/automated review dapat selesai, tetapi UI acceptance paket tetap terbuka. Manager menangani satu permintaan prerequisite yang spesifik, bukan membuat runtime/DB sendiri.
- Status kerja: `PLANNED` -> `RUNNING` -> `READY_FOR_REVIEW` -> `CHANGES_REQUESTED` atau `VERIFIED`. `BLOCKED` wajib menyebut prasyarat, bukti, dan pihak yang dapat membuka blocker. Lulus code/automated saja tetap `READY_FOR_REVIEW` dengan substatus UI, bukan `VERIFIED` penuh.
- Handoff wajib menyebut paket, author/reviewer, worktree/branch/HEAD, path/hunk/hash, command dan exit code, runtime/isolation, temuan, dan gate tersisa. Bedakan hasil penulis, reviewer, browser, serta operasional; `PASS`, `FAIL`, `SKIPPED`, dan `NOT VERIFIED` tidak dicampur.
- Paket `VERIFIED` berarti seluruh acceptance scope paket dan review independennya terpenuhi, bukan merged/deployed. Parent tetap terbuka selama scope lain belum diterima.

### F. Ledger awal — jangan dibaca sebagai pelaksanaan

- [x] Owner mengizinkan Manager spawn agent dan meminta rencana wajib.
- [x] Rencana diperbarui mengikuti arahan Owner memakai A/B/QA existing: empat sesi total termasuk Manager, tanpa spawn tambahan.
- [ ] Assignment pengganti diterima; A mengakui freeze PM Review dan B mengakui ownership B-04.
- [ ] Identitas/penerimaan worker A/B/QA tercatat dari balasan aktual; tidak ada native agent tambahan.
- [ ] Fullstack menerima ownership B-04 dan mulai implementasi.
- [ ] QA non-penulis menerima R-02 dan mulai review.
- [ ] A menerima dan menuntaskan manifest rekonsiliasi security read-only; source belum diintegrasikan.
- [ ] B-04 serta R-02 memenuhi acceptance scope, termasuk batas UI yang eksplisit.
- [ ] Dependency security/foundation/CI/Checksheet/XLSX dan manifest integrasi ditutup sesuai scope.
- [ ] Approval Git/integrasi/publikasi/operasional diterima sesuai tindakan yang diminta.

</details>

<details>
<summary>SUPERSEDED — rancangan P0–P10, slot M/A/B/C dan instruksi lama yang belum tentu dieksekusi</summary>

Ini arsip rencana, **bukan bukti implementasi atau approval baru**. Acceptance/keputusan bisnis yang masih relevan tetap dilacak pada Master Backlog; hasil yang benar-benar diterima ada pada Project State. Cap agent/subagent, assignment, checklist, command dan izin lama di bawah tidak mengalahkan empat terminal utama serta batas gate aktif. Tidak boleh menjalankan instruksi arsip tanpa scope/approval baru.

## Arsip rencana sebelumnya — bukan instruksi dispatch aktif

Seluruh bagian di bawah heading ini dipertahankan sebagai konteks teknis dan riwayat approval. Mapping historis M/A/B/C, larangan native child secara menyeluruh, gate Herdr sebagai prasyarat semua pekerjaan, status G0 lama, dan usulan menu Hak Akses AI di bawah **tidak berlaku untuk assignment baru**. Kapasitas empat sesi saat ini berasal dari instruksi terbaru memakai A/B/QA existing, bukan pengaktifan ulang mapping lama. Pakai rencana aktif di atas; scope teknis paket lain tetap referensi, bukan izin eksekusi tambahan.

## Global Constraints

- Batas konservatif: **maksimum 4 sesi agent aktif total, termasuk Manager**, baik pada pane, worktree, maupun subagent native OMP. Tidak ada agent kelima untuk QA/review.
- Manager menggunakan satu slot; tersisa tiga worker fullstack. Folder worktree yang tidak memiliki agent aktif tidak mengonsumsi slot, tetapi tetap dilindungi.
- Semua worker dilarang spawn native `task`/`agent()`/review command yang membuka subagent. Pengaturan concurrency per proses bukan semaphore global empat sesi; jangan menganggapnya cukup untuk menegakkan batas lintas pane.
- Project PRIME asli dan databasenya tidak boleh disentuh. PHPUnit wajib SQLite `:memory:`; outbound HTTP difake; tidak memerlukan MySQL/Qdrant/Gemini.
- Runtime PHP wajib `C:\laragon\bin\php\php-8.3.36-nts-Win32-vs16-x64\php.exe`.
- Timezone global tetap UTC; tanggal bisnis PM memakai `Asia/Jakarta` melalui `BusinessDate`. Jangan mengubah timezone global untuk meluluskan test.
- Admin tidak mendapat PM Executor; Operator hanya memperoleh workflow operasional yang diizinkan, bukan Assistant, PM Review atau administrasi. Assistant Admin-only/read-only; administrasi AI tetap tunduk pada `manage_ai`.
- Tidak ada SQL bebas dari model, Qdrant fallback Assistant, publikasi SOP otomatis setelah indexing, ataupun unggah ulang remote ambigu secara buta.
- Tidak menjalankan Tinker, migration/seeder runtime, provisioning fixture browser/MySQL, provider live, penghapusan Google/Qdrant, atau deployment tanpa scope dan approval Owner tersendiri.
- Tidak ada dependency update/install otomatis. Worktree yang belum siap mengikuti skill `prime-worktree-test-setup`; Composer install yang relevan mengikuti `prime-controlled-composer-install`, setelah approval.
- Sebelum PHP/Vue/JS/TS diedit, baca `.ai/rules/code-commenting.md` dan skill domain yang sesuai. Sebelum test baru, baca `testing-best-practices`; sebelum browser/fixture PRIME, baca skill QA runtime yang sesuai.
- Commit/push/PR/merge membutuhkan approval Owner. Sebelum commit, verifikasi branch bukan main/master, `git user` dan author benar; gunakan selective `commit` namespace OMP, bukan staging seluruh worktree. Conventional Commits; screen secret dan artefak lokal.
- Tidak ada reset/clean/force/restore massal, overwrite seluruh file dari checkout lain, atau penghapusan artefak orang lain. Guard-git tidak dianggap sudah terpasang hanya karena skill dibaca.
- Dokumen ini disimpan di folder `Docs` existing, menggantikan lokasi default skill planning agar tidak membuat struktur folder baru.

## Review Focus

1. Tanggal UTC sore bertepatan dengan tanggal Jakarta berikutnya: fixture future harus benar-benar future menurut bisnis; dimiliki P1.
2. Revisi provider mengganti endpoint tanpa key baru: request ditolak tanpa penyimpanan parsial atau kebocoran credential; dimiliki P3.
3. Mesin berpindah lokasi setelah PM: filter/display/sort historis mempertahankan arti yang disetujui; nilai Action legacy `"0"` bukan data kosong; dimiliki P4.
4. Action berubah menjadi Number/Range: state Action lama terhapus, input setelah error terjaga, wizard menuju error yang benar; dimiliki P2.
5. SOP atau provider tidak tersedia: fakta DB tetap benar, sitasi tidak dipalsukan, query error bukan nol, akses Operator tetap ditolak; dimiliki P3/P8 dan acceptance integrasi.

---

## 1. Bukti awal dan syarat memulai

Audit source/Git/GitHub mendahului rencana ini. Status di bawah adalah checkpoint audit, bukan status worker hidup:

| Kandidat | Bukti awal | Implikasi dispatch |
|---|---|---|
| Checkout utama `feature/gemini-file-search` / `c6dc1be` | 21 tracked modified, 13 untracked, staging kosong; foundation lokal belum dipublikasikan | Sumber yang dipreservasi; bukan worktree coding bersama |
| CI `chore-ci-pr-quality-gates` / `21c3291` | PR #4 OPEN; build PASS; PHPUnit 2 failures; JS/Pint skipped | P1 memperbaiki failure, bukan membangun ulang workflow |
| Checksheet `fix-master-checksheet-validation` / `ec841c4` | PR #3 OPEN; enam file implementasi; dua artefak browser untracked | P2 verifikasi/finalisasi kandidat, bukan redesign parent |
| Security `fix-gemini-provider-security` / `1076689` | Hardening committed lokal, tiga file provider dirty | P3 memisahkan patch committed dari addon dan checkout utama |
| Review `fix-pm-review-data-quality-origin-main` / `277d725` | Lima tracked modified, enam untracked; kandidat sebenarnya | P4 hanya setelah ownership/adopsi disetujui |
| `fix-pm-review-data-quality` / `1076689` | Bersih; tidak memiliki patch Review unik | Parkir; jangan salah dispatch |
| XLSX `fix-xlsx-export-text-safety` / `e1bdad7` | PR #2 OPEN; ekuivalen `c6dc1be` | P5 review existing; tidak menerapkan patch dua kali |
| Nav `feat-ai-navigation-consolidation` / `c6dc1be` | Sidebar Vue dirty satu file | P6 mempertahankan kandidat ini, lalu memperluas parity secara disetujui |
| UX `feat-simplify-master-pm-ux` / `c6dc1be` | Bersih; belum ada perubahan UX unik | Dipakai setelah acceptance UX dan basis integrasi jelas |

**Gate Herdr saat penyusunan:** `HERDR_ENV`, `HERDR_WORKSPACE_ID`, `HERDR_TAB_ID`, dan `HERDR_PANE_ID` tidak tersedia. Tidak ada inspeksi/kontrol Herdr atau dispatch yang dilakukan. Ketiadaan variabel bukan diagnosis penyebab dan bukan bukti ownership pane yang ada.

### P0 — Preflight, ownership dan kontrak kandidat

**Owner:** M (Manager). **Files:** tidak ada perubahan aplikasi; ledger handoff pada dokumen ini setelah eksekusi diotorisasi.

- [ ] Manager menjalankan sesi yang memenuhi `HERDR_ENV=1`; identitas workspace/tab/pane tersedia. Jika gagal, hentikan seluruh kontrol Herdr, jangan memakai focused session dari luar.
- [ ] Setelah gate lulus, baca `herdr --help`, grup command terkait, serta versi/status terpasang. Dokumentasi Context7 bukan izin memakai command versi baru yang belum didukung.
- [ ] Inventaris agent/pane yang sudah ada secara read-only. Catat owner, foreground occupant, worktree, branch dan status. Jangan menganggap agent lama milik rencana ini atau menghentikannya untuk menyediakan slot.
- [ ] Hitung semua sesi aktif terkait pekerjaan ini, termasuk Manager dan subagent yang masih berjalan. Jika total sudah empat, tidak ada spawn baru. Status idle tidak membuktikan slot kosong atau tidak ada background child.
- [ ] Refresh read-only Git status, branch/HEAD, upstream dan daftar worktree. Rekam tracked/untracked/staged; jangan memperbaiki kondisi dirty dengan reset/clean/stash otomatis.
- [ ] Owner menyetujui adopsi worktree existing, scope tiap paket, dan batas file. Tidak boleh dua penulis pada worktree yang sama. Jika ownership belum jelas, lane itu tetap tidak boleh menulis.
- [ ] Manager membekukan kontrak role, tanggal bisnis, credential/provenance, snapshot, serta definisi status dari backlog. Perubahan bisnis yang belum disepakati tidak diputuskan diam-diam oleh worker.

**Exit:** daftar empat slot maksimum, pemetaan pane/worktree aktual, owner dan baseline kandidat tercatat. Tanpa ini, Wave 1 belum boleh dimulai.

## 2. Kapasitas dan ownership file

### Empat slot aktif

| Slot / nama usulan | Wave 1 | Worktree aktif usulan | Batas utama |
|---|---|---|---|
| M / `prime-manager` | P0 + P1 CI; koordinasi, kemudian integrasi | CI existing; pindah ke kandidat integrasi terisolasi hanya setelah disetujui | Satu-satunya owner workflow, manifest integrasi dan pembaruan status canonical |
| A / `prime-master` | P2 finalisasi Checksheet; kemudian P5 XLSX | Validation existing; XLSX hanya read-only review kecuali handoff baru | Tidak mengambil patch Review atau redesign besar |
| B / `prime-ai` | P3 Gemini foundation/security/provider | Security existing dengan ownership jelas | Tidak mengubah Sidebar/Review; checkout utama hanya sumber read-only |
| C / `prime-data` | P4 PM Review/data quality | Review `origin-main` existing | Tidak memakai worktree Review yang salah; tidak menyentuh provider |

Nama adalah usulan, bukan nama live terverifikasi. Satu lane fullstack mengerjakan controller/service/UI/test untuk fiturnya; jangan membelah ownership frontend/backend dari fitur yang sama tanpa kebutuhan nyata.

### Peta file dan tanggung jawab

| Paket | File produksi / konfigurasi | Test / bukti |
|---|---|---|
| P1 | `.github/workflows/quality.yml` bila workflow benar-benar perlu berubah; default scope koreksi fixture | `tests/Feature/Feature/PM/PmExecutorTest.php` |
| P2 | `app/Http/Requests/Master/StorePmChecksheetRequest.php`; `app/Services/Master/PmChecksheetService.php`; blok wizard di `resources/js/app.js`; `resources/views/pages/master/checksheet/wizard.blade.php` | `tests/Feature/Feature/Master/MasterPmChecksheetTest.php`; `tests/JavaScript/checksheet-wizard-xss.test.js` |
| P3 | `app/Services/AI/GeminiFileSearchConnection.php`; `app/Http/Requests/Settings/StoreAiProviderConfigurationRequest.php`; `app/Services/Settings/AiProviderConfigurationService.php` | `tests/Feature/AI/GeminiFileSearchConnectionTest.php`; `tests/Feature/Settings/AiProviderConfigurationTest.php` |
| P4 | `app/Http/Controllers/PM/PmReviewController.php`; `app/Services/PM/PmReviewService.php`; blok Review di `resources/js/app.js`; `resources/views/pages/pm/review/index.blade.php`; `app/Services/Report/ReportPmService.php` untuk Action zero | `tests/Feature/Feature/PM/PmReviewTest.php`; `tests/Feature/Feature/Report/ReportPmTest.php`; JS Review dan YAML/harness existing di worktree Review |
| P5 | `app/Exports/Concerns/BindsLiteralTextCells.php`; PM sync/worker dan Breakdown export existing | `tests/Feature/Feature/Report/ReportExportTextSafetyTest.php` di worktree XLSX |
| P6 | `resources/js/components/Sidebar.vue`; `resources/views/partials/sidebar.blade.php`; `routes/web.php` hanya bila perubahan URL disetujui | Permission/deep-link regression yang sudah ada; test UI tambahan hanya untuk edge yang belum tercakup |
| P7 | Checksheet controller/wizard dan `resources/js/pages/Master/Action/Index.vue`; pengganti Vue sesuai acceptance per halaman | Suite Master/Executor dan UI UAT per halaman |
| P8 | `app/Services/AI/OperationalPmAssistantAnswerService.php` dan entrypoint query yang ditetapkan OMP 112-08, bukan arsitektur baru | `tests/Feature/AI/PendingPmReviewAssistantTest.php`; `OperationalPmAssistantAnswerTest.php`; `GeminiAssistantChatTest.php` |
| P9 | Importer/route/schema hanya setelah mapping pilot dan kontrak import diterima; nama file ditetapkan pada subplan sebelum coding | Consumer-visible import regression dan preview/duplikasi/rollback acceptance |
| P10 | Qdrant config/bindings/legacy consumers terinventaris; Blade hanya kandidat yang penggantinya diterima | Regression dokumen/Assistant serta route/export setelah removal |

**Daerah benturan:**
- P2/P4 sama-sama `resources/js/app.js`: A dan C hanya mengedit blok miliknya pada worktree masing-masing. M menggabungkan patch/hunk dan memeriksa kedua perilaku; tidak overwrite file.
- P3 beririsan tiga file provider dengan checkout utama dirty. B membuat rekonsiliasi berbasis perubahan spesifik, bukan mengganti seluruh file.
- P6 beririsan `Sidebar.vue` dengan checkout utama. Satu owner setelah handoff.
- `routes/web.php`, `AppServiceProvider.php`, `resources/css/app.css` dan `vite.config.js` adalah file shared: satu penulis pada satu wave; perubahan di luar paket memerlukan handoff Manager terlebih dahulu.
- M pemilik `Docs/PROJECT_STATE.md` dan `Docs/PRIME_MASTER_BACKLOG.md`; worker menyerahkan koreksi status, bukan mengedit keduanya paralel.

## 3. Wave 1 — Menutup kandidat existing

### P1 — CI dan fixture tanggal bisnis (TASK-19.01)

**Owner:** M. **Independent reviewer:** C setelah kandidat P4 dibekukan.

**Interface:** Tidak mengubah `BusinessDate::today(): Carbon` atau rule `PmExecutionService::getExecutorContext(Machine $machine): array`. Kontrak future tetap memakai tanggal bisnis Jakarta; continuation execution existing tetap sah.

- [ ] Gunakan failure CI yang sudah ada sebagai repro; jangan rerun full suite hanya untuk memastikan failure yang telah diamati. Trace fixture/context waktu sebelum memilih perbaikan.
- [ ] Koreksi dua test `test_future_occurrence_is_rejected_by_executor_context` dan `test_future_occurrence_start_redirects_with_validation_error_without_side_effects` agar future dinyatakan terhadap tanggal bisnis, bukan kalender UTC yang kebetulan berbeda.
- [ ] Pada fixture waktu terkontrol yang mewakili `2026-11-15T17:38:34Z`, pastikan date scheduled yang diuji benar-benar setelah tanggal Jakarta `2026-11-16`. Pertahankan assertion konteks ditolak, session error benar, tidak ada execution baru dan occurrence tetap scheduled; cleanup waktu test tidak boleh bocor.
- [ ] Setelah perubahan selesai, jalankan fokus PmExecutorTest dengan PHP terpilih. Jika failure lain muncul, investigasi sebab; jangan skip test atau mengurangi assertion.
- [ ] C mereview kandidat M; setelah approval publikasi Owner, jalankan CI pada SHA kandidat. Build, PHPUnit, JavaScript dan Pint harus benar-benar berjalan, bukan skipped.

**Exit:** kandidat CI benar dan semua gate berlaku lulus. Tidak mengklaim PR lain otomatis lulus dari PR #4 ini.

### P2 — Finalisasi validasi Checksheet (TASK-07.01 / PR #3)

**Owner:** A. **Reviewer:** C. **Interfaces:** request/service dan route existing dipertahankan; payload valid lama tetap diterima; snapshot/history dan workflow Operator tidak ditulis ulang.

- [ ] Review enam file kandidat terhadap basis PR yang benar dan acceptance backlog, bukan hanya terhadap checkout utama dirty.
- [ ] Pakai test existing untuk duplicate part/standard, invalid numeric/range, status Nonaktif, old input, step error dan perubahan Action ke Number/Range. Tambahkan regression hanya jika gap consumer-visible ditemukan.
- [ ] Verifikasi input Action-only tidak tertinggal pada payload non-Action; input pengguna dan step error benar setelah penolakan server. Jangan mengubah ini menjadi redesign penuh.
- [ ] Jalankan focused PHP/JS checks dan browser QA terisolasi untuk wizard; runtime/fixture browser harus sudah disetujui dan tidak memakai DB operasional.
- [ ] Bekukan kandidat untuk review C; perbaikan kembali ke A, kemudian review ulang delta. Dua artefak browser untracked tidak masuk commit.

**Exit:** acceptance child validasi diterima, bukan parent TASK-07 DONE. A kemudian dapat mengerjakan P5 tanpa spawn baru.

### P3 — Rekonsiliasi Gemini security dan provider (TASK-01)

**Owner:** B. **Reviewer:** A dalam sesi review terpisah setelah freeze P2/P5.

**Interfaces:** `GeminiFileSearchConnection::resolveWithCredential(): array` tetap menghasilkan provider dan identitas configuration/revision. `GroundedAnswerService` tetap dapat dibungkus operational Assistant; credential/provenance dan trusted citations tidak dilemahkan.

- [ ] Pisahkan hardening committed `1076689`, tiga addon dirty security, dan perubahan lokal utama. Pertahankan operational DB/SOP wrapper, UI provider dan workflow process dokumen yang memang diterima; tidak membawa seluruh dirty diff otomatis.
- [ ] Pastikan koneksi yang dipakai `GeminiGroundedAnswerService::generate()` benar-benar memasang `SecureGeminiGateway` untuk teks, sekaligus file/store gateway yang sudah ada.
- [ ] Pertahankan guard endpoint efektif: perubahan endpoint tanpa credential baru ditolak dalam transaksi; normalisasi Base URL Gemini kosong tidak memindahkan key ke endpoint berbeda.
- [ ] Pada test existing, buktikan credential retention endpoint sama, penolakan endpoint berubah tanpa key, tidak ada partial revision, blank URL resmi, DNS/IP/redirect policy dan provenance mismatch. Semua jaringan/DNS eksternal pada seam test dikendalikan fake; tidak memakai key asli.
- [ ] Jalankan focused suites koneksi/provider dan jalur Assistant DB-only/SOP-only/DB+SOP yang terdampak. Perubahan UI provider memerlukan smoke UI terisolasi.
- [ ] A melakukan security-focused independent review; LOW Markdown-image risk mendapat keputusan eksplisit, bukan diasumsikan tertutup oleh HIGH/MEDIUM.

**Exit:** kandidat gabungan terverifikasi secara independen pada satu identitas source; operational activation tetap tidak diotorisasi.

### P4 — PM Review, data historis dan Action zero (TASK-17)

**Owner:** C. **Reviewer:** B setelah freeze P3.

**Interfaces:** `PmReviewService::list(array $filters): array` mempertahankan paginator, allowlist sort/direction, deterministic tie-break dan snapshot identity; `ReportPmService::resultAction(object $row): string` mempertahankan semua hasil Action yang sah, termasuk `"0"`.

- [ ] Adopsi kandidat `origin-main` yang benar. YAML saat audit sudah mencatat enam path; jangan mengulang koreksi inventory yang selesai. Final independent documentation QA atas YAML tetap perlu diselesaikan.
- [ ] Tuntaskan regression filter/sort seluruh result set, tidak hanya data di halaman aktif. Jaga qualified `pm_executions.status`, reset page saat filter, dan snapshot code/name sorting yang sudah ada.
- [ ] Sebelum mengubah arti filter lokasi, minta keputusan Owner atas dua kasus nyata: cari PM berdasarkan lokasi saat pekerjaan dilakukan versus lokasi mesin sekarang. Kontrak harus menyebut keduanya/yang dipilih secara jelas; jangan mendefinisikan ulang histori diam-diam.
- [ ] Tambahkan skenario mesin pindah lokasi dan rename setelah PM; display/filter/sort harus mengikuti kontrak di atas. Search lokasi snapshot hanya diperluas jika termasuk acceptance yang disepakati.
- [ ] Pada suite Report existing, pin `resultAction()` untuk scalar `"0"`, kosong/null, label biasa dan multiple labels. Perbaiki fallback null/empty tanpa merusak multiple-action snapshot; verifikasi output laporan/PDF/XLSX yang memakai method tersebut sesuai jalur terdampak.
- [ ] Jalankan focused Review/Report/JS tests setelah patch selesai. Benchmark SQLite 10k historis tidak perlu diulang tanpa perubahan query/performance; pagination sampling bukan exhaustive correctness.
- [ ] B melakukan review independen. Kinerja/concurrency MySQL hanya diverifikasi pada target QA yang disetujui melalui paket terpisah; status tetap NOT VERIFIED sampai ada bukti.

**Exit:** kandidat Review tidak menyisakan ambiguity acceptance, YAML mendapat QA final dan bug dalam scope punya regression. Jangan mengklaim URL/QR defect selesai hanya dari item backlog tanpa repro.

### P5 — Review kandidat XLSX existing (TASK-19.02 / PR #2)

**Owner reviewer:** A setelah P2. **Integrator:** M. A bukan author patch existing.

- [ ] Buktikan kesetaraan patch `e1bdad7` dan `c6dc1be` untuk baseline integrasi yang dipilih. Terapkan sekali saja.
- [ ] Review binder literal text dan tiga consumer PM sync, PM worker/spool, Breakdown. Jangan mengklaim fix CSV atau semua prefix formula dari scope XLSX `=` yang diuji.
- [ ] Jalankan `ReportExportTextSafetyTest` beserta worker/registration test terdampak; jaga angka tetap numerik dan text/Unicode tetap benar.
- [ ] Serahkan review/verifikasi dan kandidat kepada M. Jika ada fix, tetapkan penulisnya lalu reviewer lain; A tidak mengesahkan perbaikannya sendiri.

**Exit:** bukti kandidat PR #2 sendiri jelas; tidak mengaitkan failure CI PR #4 sebagai failure PR #2.

**Penutupan P5 — 18 November 2026 (M-GATE-10–14):** TASK-19.02 selesai sesuai scope; PR #2 MERGED pada `7e90945ea452911c0c5d7dccaa7a9dc43984cdc9`. CI pasca-merge `44118241952` PASS dan Q-05 diterima. Checklist di atas dipertahankan sebagai rencana historis; tidak dicentang secara mekanis sebagai rekaman langkah yang identik dengan pelaksanaan aktual.

## 4. Review independen, integrasi dan approval

**Independen** berarti reviewer bukan implementor paket, menilai source/diff dan bukti aktual, bukan menyetujui ringkasan author. Reviewer memakai konteks review terpisah dalam slot yang sama; tidak spawn agent baru.

| Kandidat beku | Penulis | Reviewer | Syarat waktu |
|---|---|---|---|
| P1 CI | M | C | C selesai/freeze P4 |
| P2 Checksheet | A | C | C selesai/freeze P4; review read-only |
| P3 Gemini/security | B | A | A selesai/freeze P2 dan P5 |
| P4 Review/data | C | B | B selesai/freeze P3 |
| P5 XLSX existing | Author historis | A | A selesai P2; bila A memperbaiki, review dialihkan |
| Integrasi final | M | B | B bukan penulis integrasi; bidang security tidak hanya dinilai dari author report |

- Review antar-lane tidak menulis worktree author. Build/test yang membuat artefak hanya setelah handoff verifikasi eksklusif atau pada kandidat verifikasi terisolasi yang disetujui.
- Tidak ada lock lintas-lane sambil menunggu reviewer: freeze kandidat dan lepaskan slot penulisan; antrean review tidak boleh deadlock.
- M memilih basis integrasi dari ancestry kandidat, bukan langsung cherry-pick semua ke `main`. Tetapkan satu basis dan ledger patch included/excluded terlebih dahulu; foundation Gemini harus ikut sebelum consumer security/Assistant yang memerlukannya.
- Worktree integrasi baru, bila diperlukan, harus disetujui dan memakai branch unik. M berpindah ke sana; tidak meluncurkan Manager kedua. Checkout utama tetap dipreservasi.
- Urutan logis: basis main/foundation approved -> koreksi CI -> satu patch XLSX -> Checksheet/Review dengan merge blok `app.js` -> rekonsiliasi Gemini/local accepted changes. Urutan aktual ditentukan ancestry; tidak memaksa patch yang sudah termasuk basis diterapkan lagi.
- Setelah snapshot integrasi beku: build bila perlu, regression fokus gabungan, lalu satu full CI pada SHA yang tepat. Perubahan setelah hasil QA mengubah kandidat dan memerlukan checks relevan ulang.
- Setelah independent review dan Owner approval, publikasi/merge dilakukan sesuai scope approval. Jangan menganggap approval rencana sebagai approval commit/push/merge.

## 5. Wave berikutnya — Seluruh kebutuhan produk tetap tercakup

Paket berikut bukan izin mengarang implementasi ketika kontrak bisnis belum ada. Tahap acceptance/subplan adalah deliverable nyata sebelum coding; tidak menyatakan parent selesai hanya karena subplan diterima.

| Wave / slot | A | B | C | M dan exit gate |
|---|---|---|---|---|
| 2 — UX/nav dan fondasi data | P6 AI navigation | P8a real SOP/WI governance dan retrieval readiness | P8a satu mesin pilot + mapping histori + taxonomy berdampak | Koordinasi keputusan UX P7, pemilik data/dokumen dan acceptance; hanya data asli approved setelah security gate |
| 3 — Query/import/Vue | P7 satu halaman Master PM Vue dengan UX accepted | P8b query generalized pertama sesuai OMP 112-08 | P9 importer historis sesuai mapping pilot | Reference answers, kontrak snapshot/provenance; file shared direservasi satu owner |
| 4 — UAT gabungan dan retirement | P10b Blade cleanup per halaman accepted | P10a Qdrant retirement approved | QA independen query/import/role dan regresi operasional | M memimpin cutover/recovery readiness, QA target yang diizinkan dan approval rilis |

### P6 — AI navigation lintas shell (TASK-06)

- [ ] Inventaris current links/deep links pada Vue dan Blade; pertahankan Sidebar worktree lokal sebagai sumber kandidat.
- [ ] Ajukan satu peta: grup AI berisi Assistant; Knowledge Documents, Konfigurasi Gemini dan Hak Akses AI hanya untuk Admin berizin. Pengaturan User tetap pengelolaan akun, bukan digandakan menjadi provider settings.
- [ ] Owner menerima label dan URL contract sebelum URL berubah. Jika URL tetap, perbaikan grouping tidak diklaim URL cutover.
- [ ] Implementasikan parity kedua sidebar oleh A. Backend tetap enforcement; hidden menu bukan authorization.
- [ ] Verifikasi Admin dengan/tanpa manage_ai, Operator, legacy guest, deep link langsung dan active state desktop/mobile. Penolakan middleware/gate mengikuti kontrak response existing, bukan memaksakan semua 403.

**Exit:** kedua shell konsisten; route/name/deep link yang dipertahankan tidak putus.


### P7 — Simplifikasi Master PM + migrasi Vue (TASK-07.02/.03, TASK-14)

**Progress M-GATE-20 (18 November 2026) — dengan deviasi sumber:** discovery UX `MASTER-PM-UX-DISCOVERY-01` diselesaikan Fullstack A secara read-only; peta workflow aktual dengan sitasi `file:line`, masalah UX berbukti vs hipotesis, skenario BEFORE/AFTER Admin dan Operator, serta dua alternatif desain (migrasi Vue/Inertia satu halaman pertama vs simplifikasi Blade dulu) sudah dihasilkan tanpa perubahan file. **Deviasi lingkup sumber:** penugasan menuntut source worktree bersih, tetapi pane A tetap parkir pada worktree M-01 `integrate-wave-1-owner-approved` dan membaca canonical root `C:\laragon\www\prime-google` yang saat itu berstatus kotor (21 file tracked dimodifikasi, 0 staged). Oleh karena itu temuan dipakai sebagai bukti eksploratif yang belum diverifikasi terhadap pohon sumber bersih, bukan discovery compliant penuh; verifikasi ulang pada worktree bersih diperlukan sebelum implementasi. Keputusan desain tetap menunggu Owner; tidak ada coding, QA browser, atau migrasi halaman, dan A tidak mengambil alih M-01.


- [x] Petakan alur create/edit checksheet, relasi Action/standard/mesin, preview/apply jadwal dan proteksi histori; reuse validasi P2. (Selesai M-GATE-20: peta workflow aktual berikut sitasi `file:line` dihasilkan Fullstack A secara read-only.)
- [x] A menyajikan alur yang lebih sederhana beserta langkah yang dihapus/digabung; Owner menerima flow dan parity matrix sebelum implementasi. Tidak menjadikan wizard Blade baru sebagai redesign sementara yang langsung dibuang. (Selesai M-GATE-20: dua alternatif + BEFORE/AFTER + parity matrix dipresentasikan; persetujuan Owner masih tertunda.)
- [ ] Tetapkan satu halaman awal dan nama/route/props kontraknya dalam subplan; gunakan existing Action Vue/components dan NovaSpark, tanpa menulis shell kedua.
- [ ] Implementasi fullstack halaman tersebut dengan rollback boundary jelas; tidak migrasi semua halaman sekaligus.
- [ ] UAT create/edit, invalid input, existing history, schedule preview stale, Admin/Operator, keyboard, responsive dan accessibility. UI-only tidak membutuhkan test implementation-text; regression bisnis existing tetap dipakai.

**Exit:** acceptance halaman pertama diterima; halaman lain tetap antrean TASK-14, bukan klaim migrasi penuh. Blade penggantinya baru menjadi kandidat P10b.

### P8 — Pilot, governance dan query terkontrol (TASK-20/21/22/03/18/05/16)

- [ ] Owner/pemilik maintenance memilih satu mesin dan sumber riwayat asli; pemilik SOP memilih revisi approved beserta applicability. Demo tidak diubah label menjadi data asli.
- [ ] C membuat mapping identitas/status/tanggal/parameter/satuan dan unknown data secara eksplisit; B memvalidasi registry/published/eligible/provenance dokumen. Tidak mengunggah atau mengubah DB saat sekadar mapping.
- [ ] Dapatkan kembali OMP 112-08 approved untuk pelaksana. Jika artefak belum tersedia, hentikan coding generalized-query terkait, bukan merancang pengganti tanpa persetujuan.
- [ ] Dari plan approved pilih satu query PM Review generalized dengan filter yang jelas; subplan mencatat signature input/output dan reference answers sebelum coding. Jangan mempertahankan hardcoded demo identity sebagai query umum.
- [ ] Implementasikan read-only allowlisted Laravel query; nol berbeda dari query error; fakta punya record/date/status/unit dan deep link berizin. SOP tetap citation-verified; tidak ada autonomous write/tool loop.
- [ ] QA C terhadap fakta referensi, filter mesin/lokasi/periode, data kosong/tidak lengkap, query/provider gagal, DB-only tanpa Gemini, DB+SOP abstention, citation mismatch dan cross-role denial. Bukan sekadar HTTP sukses.
- [ ] Tambahkan observability aman untuk failure yang benar-benar diperkenalkan; log tidak menyertakan key/raw provider secrets.

**Exit:** satu capability generalized diterima; lanjutkan tahap schedule/execution/Breakdown/history/KPI sesuai OMP 112-08, dengan parent tetap terbuka sampai seluruh scope diterima.

### P9 — Historical PM import (TASK-08)

- [ ] Setelah pilot mapping diterima, sepakati template CSV/XLSX, identitas sumber, rule duplikasi, tanggal bisnis, satuan dan arti legacy. Jadwal historis tidak dibangkitkan ulang secara palsu; legacy tidak otomatis approved digital.
- [ ] C membuat subplan file/schema/API dari mapping itu dan mengajukannya sebelum migration/import code. Perubahan schema/runtime tetap approval terpisah.
- [ ] Implementasikan preview/validation sebelum import, deteksi duplikasi saat reimport, batch audit/provenance dan transaksi sesuai batch contract. Data operasional baru tidak ditimpa.
- [ ] B mereview dan menguji import valid, row salah mesin/tanggal/satuan, duplicate/reimport, failure tengah batch sesuai atomicity contract, permission serta provenance pada hasil laporan/query.
- [ ] Eksekusi import data asli hanya setelah Owner menerima preview dan memberi approval target/batch; test automated tetap SQLite terisolasi.

**Exit:** import dapat ditelusuri dan dipertanggungjawabkan; bukan seeder demo atau export yang diganti nama.

### P10 — Retirement Qdrant dan Blade (TASK-13/15)

**P10a, B:**
- [ ] Inventaris consumer runtime/test/config/schema untuk vector store, embedding, readiness, provider purpose dan legacy service; class tidak dipakai Assistant bukan bukti seluruh consumer mati.
- [ ] Ajukan daftar keep/remove beserta alasan, pengganti dan hasil cutover/recovery. M meminta approval Owner atas retirement code; penghapusan resource Qdrant/Google bukan bagian approval code removal.
- [ ] Setelah diterima, hapus semua caller/config/test obsolete secara clean cutover, tanpa shim/fallback diam-diam; pertahankan consumer yang masih diperlukan sampai kontraknya dipindah.
- [ ] C memverifikasi Assistant/docs/provider serta non-AI PM/Breakdown tidak regresi, tidak ada dependency Qdrant runtime yang tertinggal dalam scope.

**P10b, A:**
- [ ] Untuk setiap halaman Vue accepted, buktikan tidak ada consumer route/controller/include/component/dynamic reference pada Blade yang akan dihapus.
- [ ] Pertahankan `resources/views/spa.blade.php`, PDF/report server-rendered dan business Blade aktif; kandidat rollback tidak dihapus hanya karena tidak ada direct view-name reference.
- [ ] Hapus per halaman dengan approval scope; C memverifikasi route, permission, fungsi, PDF/export bila terdampak dan rollback acceptance yang disepakati.

**Exit:** removal memiliki bukti consumer dan regression, bukan sekadar jumlah file berkurang.

**Backlog lanjut yang tidak hilang:** TASK-02 adalah planning approved, bukan pekerjaan discovery ulang. TASK-09 multi-turn, TASK-10 advice, TASK-04 function calling conditional, TASK-11 Vision dan TASK-12 revision comparison tetap di roadmap setelah fondasi/benefit masing-masing jelas; tidak ditambahkan diam-diam ke Wave 1. TASK-19 adalah continuous gate seluruh wave.

## 6. Verifikasi, lingkungan dan evidence contract

Semua command di bagian ini **rencana**, belum dijalankan saat menyusun dokumen. Jalankan dari worktree kandidat yang benar; jangan membuat klaim PASS berdasarkan command yang tertulis.

### Command baseline

PowerShell, PHP eksplisit:

```powershell
& 'C:\laragon\bin\php\php-8.3.36-nts-Win32-vs16-x64\php.exe' vendor/bin/phpunit tests/Feature/Feature/PM/PmExecutorTest.php
```

Ganti path terakhir hanya dengan file regression paket yang terdaftar. Untuk JS kandidat:

```text
node --test tests/JavaScript/checksheet-wizard-xss.test.js
node --test tests/JavaScript/pm-review-server.test.js
```

Perintah kedua hanya tersedia di kandidat Review/hasil integrasinya, bukan semua checkout. Baca `phpunit.xml` dan guard sebelum eksekusi: SQLite `:memory:`, DB URL tidak mengarah target eksternal, array cache/session, HTTP fakes. Jangan mengganti PHPUnit menjadi MySQL.

Jika file PHP berubah, formatter project wajib pada tahap verifikasi: PHP eksplisit menjalankan `vendor/bin/pint --dirty --format agent`. Hanya setelah kandidat ownership jelas; bila worktree memuat PHP dirty di luar paket, jangan jalankan broad formatter di sana—gunakan kandidat verifikasi terisolasi yang disetujui. Tidak memformat file milik lane lain.

UI: build manifest terlebih dahulu bila belum ada/terpengaruh, jalankan smoke pada permukaan aktual dan runtime QA terisolasi approved. Jangan membuat seeder/QA DB dari approval rencana ini. URL/port tidak ditebak; ikuti skill PRIME QA runtime yang relevan. Typecheck Vue dilakukan untuk perubahan TypeScript/Vue yang relevan selain build; build bukan bukti typecheck.

### Level gate

| Gate | Bukti minimum | Tidak boleh disimpulkan |
|---|---|---|
| Focused worker verification | SHA atau fingerprint dirty candidate, command, exit code, runtime, data isolation dan hasil assertion terkait | Independent QA / produksi siap |
| Independent review/QA | Reviewer berbeda, snapshot beku yang sama, temuan dan hasil perbaikan, actual UI proof untuk UI | Author report = approval |
| Integration CI | Full source snapshot, semua checks berlaku benar-benar berjalan/lulus, exact SHA | PR lain otomatis PASS |
| Operational acceptance | Target approved, migration/rollback/recovery, MySQL relevan, data/SOP asli, UAT role dan persetujuan Owner | SQLite/demo/live UAT lama = current production proof |

Tidak mengulang full suite/benchmark per worker tanpa kebutuhan. Gunakan focused check setelah perubahan selesai; full CI pada kandidat integrasi. Test baru hanya menutup uncertainty/bug consumer-visible, bukan source-text, wiring atau output tautologis.

## 7. Handoff per paket dan pengendalian Herdr

### Assignment prompt yang diberikan setelah P0 lulus

```text
Role: fullstack owner untuk <Paket>, bukan owner seluruh repo.
Tujuan dan acceptance: salin bagian paket dari rencana approved ini.
Worktree/pane/branch/baseline: isi dari inventory live, bukan contoh atau tebakan.
Allowed files: salin daftar paket; file shared wajib reservasi ke Manager.
Protected: source dirty di luar paket, artefak QA, root checkout dan PRIME asli.
Constraints: maksimal empat sesi total; jangan spawn child; jangan publikasi Git,
DB/runtime/provider live, atau instal dependency tanpa approval terpisah.
Verification: focused tests/UI yang disebut paket; fakes dan SQLite :memory:.
Handoff: kandidat beku + file/hunk + observed results + remaining risks.
Selesai berarti kirim final handoff; bukan hanya pesan 'report ready'.
```

### Handoff wajib

- Paket, author, reviewer dan status `READY_FOR_REVIEW`, `CHANGES_REQUESTED`, `VERIFIED`, atau `BLOCKED` dengan alasan nyata.
- Worktree, branch/HEAD, fingerprint diff jika uncommitted dan daftar file scope. Git commit tidak dilakukan hanya untuk memperoleh SHA tanpa approval.
- Keputusan bisnis/kontrak yang dipakai; perubahan yang sengaja tidak dibawa.
- Command/runtime/isolation dan hasil yang benar-benar diamati; failed/skipped/not run dipisahkan.
- Daftar risiko, acceptance tersisa dan approval yang diperlukan. Tidak memakai persentase completion tanpa basis.

### Aturan operasi Herdr setelah otorisasi

- Pilih pane existing yang foreground-nya shell idle dan ownership-nya jelas; start agent tidak menciptakan pane/worktree sendiri.
- Buat topology hanya setelah disetujui; preserve focus pengguna dengan `--no-focus`; gunakan current/explicit returned opaque IDs dan cwd worktree yang benar.
- Pelajari `agent start --kind omp` dari CLI terpasang sebelum digunakan. Dokumentasi Context7 juga memuat integrasi OMP pada versi lebih baru; **jangan menginstal extension, upgrade atau mengubah konfigurasi** tanpa permintaan Owner.
- `prompt --wait` mengamati lifecycle, bukan acceptance task individual. Setelah idle/done, baca final handoff dan cocokkan kandidat; jangan menganggap status TUI sebagai bukti test.
- Pada blocked/timeout/stalled, baca keadaan sebelum retry; jangan mengirim prompt dua kali atau menjawab dialog approval atas nama Owner.
- Agent yang bekerja tidak dipoll berulang; hasil auto-delivered atau state/event dipakai proporsional. Idle slot boleh membantu review, bukan alasan membuat pekerjaan baru di luar scope.
- Jangan menutup pane/session yang tidak dibuat/dimiliki rencana ini. Tidak ada kill server atau forced worktree removal untuk merapikan tampilan.

## 8. Approval gates dan definition of done

- [x] **G0 Plan approval:** Owner telah menyetujui batas maksimal empat sesi aktif termasuk Manager, pembagian slot M/A/B/C, urutan Wave 1–4, dan isolasi worktree. Persetujuan dicatat tanpa menjalankan P0/dispatch atau membuat perintah/prompt worker maupun pane/agent baru; seluruh eksekusi tetap menunggu instruksi Owner berikutnya.
- [ ] **G1 Candidate acceptance:** implementasi + focused proof + reviewer independen diterima, tanpa mengklaim parent selesai dari satu child.
- [ ] **G2 Publication approval:** branch, author, secret screen dan selected changes jelas; Owner mengizinkan commit/push/PR/merge yang spesifik. Manager menggunakan mekanisme selective commit OMP; tidak stage seluruh worktree.
- [ ] **G3 Integration acceptance:** exact snapshot/SHA, checks gabungan, review final dan dokumen source/status sinkron.
- [ ] **G4 Operational approval:** DB/provider/data/batch/target, recovery, MySQL/performance relevan dan risiko remote resource eksplisit; Owner menerima cutover/deployment.

Sebuah paket DONE hanya untuk scope yang benar-benar diterima. DONE rencana, local verified, PR open, merged dan operationally accepted adalah status berbeda.

## 9. Self-review rencana

- [x] Sembilan kebutuhan audit dicakup: Master UX P2/P7; AI navigation P6; Gemini key/security P3; operational query P8; historical import P9; chatbot UAT P3/P8; Qdrant P10a; Vue P7; Blade P10b.
- [x] Maksimum empat termasuk Manager dipertahankan; review bergilir, tidak ada native child/agent kelima.
- [x] Shared file, candidate ancestry, XLSX duplicate, dirty root/security dan worktree Review yang benar diberi owner/gate.
- [x] Lima review-focus punya paket verifikasi, bukan daftar risiko tanpa pemilik.
- [x] Scope belum memiliki acceptance/artefak bisnis mendapat deliverable keputusan/subplan sebelum coding; tidak diisi signature/schema fiktif.
- [x] Approval rencana tidak disamakan dengan approval Git, runtime mutation atau release; tidak ada eksekusi yang diklaim.

## 10. Referensi teknis Context7

Diambil saat menyusun rencana; versi CLI lokal tetap otoritas sebelum command dijalankan.

- Herdr, existing-shell pane / OMP agent start dan lifecycle: https://github.com/herdrdev/herdr/blob/master/skills/herdr/SKILL.md ; https://github.com/herdrdev/herdr/blob/master/docs/versions/0.9.5/website/src/content/docs/cli-reference.mdx
- Herdr `prompt --wait` mengamati lifecycle, bukan per-turn acceptance: https://github.com/herdrdev/herdr/blob/master/docs/versions/0.9.5/website/src/content/docs/agent-automation.mdx
- Herdr OMP integration yang version-sensitive (bukan instruksi install): https://github.com/herdrdev/herdr/blob/master/docs/versions/0.9.5/website/src/content/docs/integrations.mdx
- OMP task concurrency merupakan setting native tool; tidak otomatis membatasi seluruh sesi pane: https://github.com/can1357/oh-my-pi/blob/main/packages/coding-agent/src/task/settings.ts
- OMP task execution/isolation surface: https://github.com/can1357/oh-my-pi/blob/main/docs/tools/task.md
- Git linked worktree punya HEAD/index sendiri tetapi refs/objects bersama; jangan bypass same-branch/dirty safeguards: https://github.com/git/htmldocs/blob/gh-pages/git-worktree.adoc ; https://github.com/git/htmldocs/blob/gh-pages/glossary-content.adoc

</details>
