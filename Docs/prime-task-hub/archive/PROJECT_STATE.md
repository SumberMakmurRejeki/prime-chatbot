# PRIME Google — Status Proyek

Dokumen ini adalah sumber resmi **keadaan kode, baseline Git, CI/QA, risiko dan release readiness** PRIME. [Master Backlog](PRIME_MASTER_BACKLOG.md) memiliki kebutuhan/TASK/status/acceptance; [Parallel Execution Plan](PRIME_PARALLEL_EXECUTION_PLAN.md#active-workflow) memiliki assignment, ownership dan approval aktif. Hasil historis tetap terikat pada kandidat dan tanggal asal.

<a id="current-state"></a>
## Status terkini — 19 November 2026

### Baseline dan batas integrasi

| Target | Keadaan yang dibuktikan |
|---|---|
| Remote `refs/heads/main` | `4a68ff32c93d197afcd66ee5ced6c901a0917fd8`; diverifikasi read-only via GitHub API/MCP pada 2026-11-19T14:23Z, tanpa fetch. Local `origin/main` ≠ remote main; jangan menyamakan. |
| Ref lokal | `origin/main` = `d2588cec…`; branch lokal `main` masih `277d725…`. Jangan menyamakan branch lokal stale dengan remote main atau melakukan sinkronisasi otomatis. |
| Root Gemini | `feature/gemini-file-search`, HEAD `c6dc1be28fba22fa4dba8382891ac070c51e76c7`; dirty/untracked dipreservasi. Kode Gemini lokal/committed di stream ini tidak otomatis masuk main. |
| M-01 | Kandidat lokal frozen pada `integrate-wave-1-owner-approved`, branch `integrate/gemini-security-user-settings-pm-review`, basis `c6dc1be…`; belum committed/published. Q-02 code/tests diterima; **UI_NOT_VERIFIED**. |
| PM-SCHED-SLICE-01 | **MERGED** — PR #5; scope Checksheet inactive start guard, empat file. Head kandidat `045fbba459536cecaa084437c5566b995cd84fce`, merge `6e79fd462e96dfff7ae232484e7102cfe2590716`; CI PR/push `44470933898`/`44535836915` SUCCESS. Fingerprint sebelum/sesudah Low docfix (`cd8a28e…` / `7e1abf…`) dan QA-1 M-GATE-24 dipertahankan sebagai bukti historis. |
| Slice 2 | **PARTIAL** — currently-PAUSED aging/notification DONE / MERGED (PR #6) / QA READY untuk scope itu; temporal no-backlog-after-Resume **NOT IMPLEMENTED**, detail keputusan masih OPEN. |
| Slice 3–5 | **Belum diimplementasikan**; readiness/dependency analysis bukan implementasi, migration atau acceptance runtime. |

### Merge dan CI yang sudah diterima

| Scope | Kandidat → merge SHA | Bukti yang diterima dan batasnya |
|---|---|---|
| PR #4 / TASK-19.01 | `393a505…` → `f048f742541efd93a59f41899fe5df72996208b3` | **MERGED**; Actions main `44094015983` PASS, CI PR `44084322427` PASS; Q-03/Q-04 diterima. |
| PR #2 / TASK-19.02 | `28ce9f2…` → `7e90945ea452911c0c5d7dccaa7a9dc43984cdc9` | **MERGED**; Actions main `44118241952` PASS, CI kandidat `44107526250` PASS; Q-05 diterima. Parent ancestry merge tetap NOT VERIFIED pada bukti historis. |
| PR #3 / TASK-07.01 | `a9929fe…` → `d2588cec2b45f5b30d8182b3bb43b364432efe79` | **MERGED**; Actions main `44224408741` PASS; Q-06 diterima. Policy branch protection/ruleset pra-merge tidak diverifikasi langsung. |
| PR #5 / Slice 1 | `045fbba459536cecaa084437c5566b995cd84fce` → `6e79fd462e96dfff7ae232484e7102cfe2590716` | **[MERGED](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/5)**; scope Checksheet inactive start guard, empat file; CI [PR `44470933898`](https://github.com/SumberMakmurRejeki/prime-chatbot/actions/runs/44470933898) / [push `44535836915`](https://github.com/SumberMakmurRejeki/prime-chatbot/actions/runs/44535836915) SUCCESS; QA M-GATE-24 independen historis diterima. |
| PR #6 / Slice 2 aging/notification | `1fb493c7bb9ac77cd00565f97249c6b8297efafe` → `4a68ff32c93d197afcd66ee5ced6c901a0917fd8` | **[MERGED](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/6)**; dua file `SyncPmScheduleStatusCommand.php` + `SyncPmScheduleStatusCommandTest.php`; CI [PR `44537035666`](https://github.com/SumberMakmurRejeki/prime-chatbot/actions/runs/44537035666) SUCCESS, [post-merge `44540373856`](https://github.com/SumberMakmurRejeki/prime-chatbot/actions/runs/44540373856) SUCCESS (build/PHPUnit/JS/Pint: 1010 tests/6156 assertions, JS 22 pass, Pint 2 files); QA M-NEXT-08 focused SQLite 56 tests/245 assertions PASS dan M-NEXT-09 disposable MySQL/InnoDB 8/8 acceptance + 10/10 concurrency PASS — bukti historis diterima, tidak dijalankan ulang di gate ini. Full Slice 2 TIDAK DONE. |

Run pasca-merge PR #4/#2/#3/#5/#6 tersebut benar-benar menjalankan build, PHPUnit, JavaScript dan changed-file Pint tanpa SKIPPED menurut checkpoint yang diterima; tidak dijalankan/diprobe ulang pada gate ini. Review Q-03–Q-06 adalah internal, bukan formal GitHub approval. TASK-07.01 DONE sesuai scope, **parent TASK-07 tetap IN PROGRESS**; TASK-07.02/.03 PLANNED. Parent TASK-19 juga tetap terbuka; TASK-19.01/.02 DONE sesuai scope. SHA/job/detail bukti dipertahankan pada arsip di bawah, bukan dihapus.

### Kandidat lokal dan gate terbuka

- **M-01:** fingerprint 33-path `fc46780ecd4aa903e7d982d9313ba554a773134d355431a5d30da9eff0dd6a08`. Bukti integrator 233 tests/1.816 assertions, scoped Pint, typecheck/build dan 3 JS PASS; Q-02 diterima. Ini bukan main, full CI rilis atau browser acceptance. Manifest rinci tetap pada [arsip M-01](PRIME_MASTER_BACKLOG.md#m-01--bukti-kandidat-gabungan-beku), tanpa pemindahan besar.
- **Slice 1 sebelum Low docfix:** fingerprint canonical empat-file `cd8a28e1755af74b2fee50c7e4f92d83fc3bb49ec5935614661c94f6216b0d8c`; guard menolak Start baru pada Checksheet Nonaktif, mempertahankan existing Start-reuse/Draft/Submit/Review dan batas Admin/Operator. Bukti lokal sebelumnya: regression RED→GREEN, focused 13 tests/78 assertions dan Pint PASS; QA M-GATE-24 source review independen, tidak rerun tests.
- **Slice 1 setelah Low docfix:** fingerprint `7e1abf212c87b59ac4cc04071a3620a8b090e8bcd7138f39e436098886af98e2`; raw SHA `PmExecutionService.php` berubah dari `09128b74…` ke `c0284af5…`. QA-1 secara independen memverifikasi agregat lama/baru dan tiga raw-file lain tetap sama, exact PHPDoc-only delta serta 8.827 executable tokens identik menggunakan PHP 8.3.36 `TOKEN_PARSE`. Syntax dan scoped Pint lulus menurut A; bukan rerun regression atau MySQL QA. Low ditutup dalam scope komentar; publikasi ditutup melalui [PR #5](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/5) yang merged, bukan menunggu Owner.
- **Batas concurrency Slice 1 candidate:** SQLite/sequential regression dan deterministic collision seam bukan bukti InnoDB row locking. MySQL concurrency untuk Slice 1 local candidate **NOT VERIFIED**; ini tidak membatalkan QA M-NEXT-09 terbatas pada currently-PAUSED aging/notification PR #6. Start/deactivate, apply/lifecycle/reconciler/cron dan provenance race tetap tidak terverifikasi di luar scope M-NEXT-09.
- **Risiko lock alignment source:** wrapper `PmChecksheetService::reconcileScheduleDates()` menemukan Machine dari Schedule aktif sebelum mengambil lock Schedule, kemudian nested reconciler meminta Machine lagi. Bila himpunan Schedule berubah setelah discovery, asumsi semua parent Machine sudah dikunci perlu direkonsiliasi dengan writer lifecycle Machine → Schedule. Ini risiko kondisional pada source existing, bukan regression PHPDoc atau deadlock InnoDB yang sudah diamati; perlu matriks dua koneksi sebelum menerima protokol Slice 2–4.
- **Environment M-01 BLOCKED / OWNER/ENV ADMIN INPUT REQUIRED:** belum ada administrator QA, credential lokal aman dan target koneksi/schema yang terbukti siap. Tidak ada izin DB access/provisioning/runtime baru. Target QA yang diizinkan guard tetap `db_preventive_maintenance_ai_test`; PRIME asli dan kedua database asal dilarang.
- **Release readiness:** browser role/deep-link/responsive M-01, MySQL/performance, scope/YAML di luar manifest, full CI kandidat rilis, migration/rollback/recovery dan operational cutover/approval tetap terbuka. Q-02 tidak dibuka ulang pada kandidat identik; CI PR lain tidak menutup gate M-01.
- **M-GATE-24 selesai:** recovery model sesi, independent Slice 1 review, serta A/B readiness analysis selesai read-only. Pemilihan model bukan QA PASS; verdict berasal dari reviewer non-penulis pada fingerprint yang sama. Analisis Slice 2/4 tidak memberi izin coding.
- **M-NEXT-17C lanjutan:** DocsWriter17C adalah satu-satunya penulis pengganti tiga dokumen canonical; Manager koordinator/final reviewer tanpa penulisan dokumen; B telah menyelesaikan inventaris 12 worktree read-only untuk reuse saja; independent replacement QA meninjau read-only setelah freeze. Hasil QA replacement belum tersedia saat freeze dokumen; tidak ada verdict QA yang diklaim. Tidak ada publikasi, perubahan source/test, cleanup/worktree, DB/runtime, atau deployment dari gate ini.

Ringkasan inventaris frozen 2026-11-19T14:47:29.834Z: 12 worktree (8 PRESERVE, 4 UNVERIFIED, 0 CLEANUP_CANDIDATE); seluruh active ownership UNVERIFIED. Root dan `integrate-wave-1-owner-approved` selalu dipreservasi. Ini ringkasan snapshot, bukan klaim status live.

Slice 2 evidence: [PR #6](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/6) merged ke main `4a68ff32c93d197afcd66ee5ced6c901a0917fd8`; [CI post-merge 44540373856](https://github.com/SumberMakmurRejeki/prime-chatbot/actions/runs/44540373856) SUCCESS. Hanya currently-PAUSED aging/notification yang selesai. Temporal no-backlog-after-Resume tetap NOT IMPLEMENTED; Browser/UAT, globalcron head-of-line/load, dan races lain yang tidak termasuk acceptance tersebut NOT VERIFIED.

Bagian inventory teknologi/domain/AI di bawah merekam **stream Gemini dan checkpoint source yang disebutkan**, bukan klaim semua fitur telah terintegrasi pada main `4a68ff32…`. Kebutuhan/prioritas berikutnya mengikuti backlog; assignment/izin mengikuti rencana aktif. **M-NEXT-17C** adalah gate dokumentasi/inventaris read-only: DocsWriter17C satu-satunya penulis pengganti tiga dokumen canonical; B telah menyelesaikan inventaris read-only untuk reuse saja; independent replacement QA akan menilai kandidat beku terpisah secara read-only. No QA verdict is claimed at freeze.

## Arsip checkpoint Git dan integrasi

<details>
<summary>SUPERSEDED untuk status terkini — snapshot 2–18 November, penutupan PR dan integrasi M-01</summary>

Status OPEN/FAILURE, HEAD main lama, hitungan dirty dan kebutuhan review di dalam snapshot berikut dipertahankan sebagai bukti tanggal asal. Bila berbeda dari ringkasan terkini di atas, snapshot itu **SUPERSEDED**, bukan blocker/assignment baru. Bukti merge/CI/QA yang diterima dan batasnya tidak berubah.

| Item | Nilai |
|---|---|
| Terakhir diperbarui | 2026-11-18 (PR #4 dan PR #2 MERGED; PR #3 validasi/wizard Checksheet MERGED pada `d2588cec2b45f5b30d8182b3bb43b364432efe79`; CI main PASS pada ketiganya; Q-03/Q-04/Q-05/Q-06 diterima sebagai review internal, bukan formal GitHub approval; TASK-19.01/.02 dan TASK-07.01 DONE sesuai scope; parent TASK-19 dan TASK-07 tetap IN PROGRESS; TASK-07.02/.03 PLANNED; branch protection/ruleset pra-merge PR #3 tidak diverifikasi langsung; M-01 tetap UI_NOT_VERIFIED dan environment QA BLOCKED) |
| Baseline ter-commit | `cc5378ab19e46370e922aac5ff32c8f1cf4018a1` (`chore: establish initial PRIME Google baseline`, 2026-11-02) |
| Agent-foundation ter-commit | `e70c6eefc5316957ef51f5e8cc09c99245af53e0`; 35 file committed |
| Penggabungan agent-foundation | COMMITTED — PR #1 digabung; `origin/main` = `277d7251da2ea9768bef480927223b8a502ca456` (`Merge pull request #1 from SumberMakmurRejeki/chore/prime-google-agent-foundation`, 2026-11-04) |
| Branch kerja pada checkpoint | `feature/gemini-file-search`; belum dipublikasikan ke `origin` (diverifikasi 2026-11-11: branch tidak punya upstream, dan `git ls-remote --heads origin` hanya memuat `main` serta `chore/prime-google-agent-foundation`) |
| Commit AI/Gemini sebelumnya di branch kerja | `f156825`, `d3c7ac0` (2026-11-07), `6d8a507` (2026-11-08), lalu `0981c39` dan `37e82cc` (finalisasi 2026-11-09) — keduanya hanya lokal |
| Commit backend Gemini yang diterima | `fc439ee2b8712b0c8e714e07f981108ebe865a99` (`feat(ai): add safe Gemini index recovery and history`, 2026-11-10); bukan lagi HEAD |
| Commit diagnostik Assistant Gemini | `8d45410f880de9242119aad6d34734ab2c97b004` (`fix(ai): log Gemini assistant provider failures safely`, 2026-11-11); HEAD pada checkpoint 2026-11-11; hanya lokal |
| Commit dokumentasi status milestone | `741aa70`, `7a9d7c9`, `eeca798`, `6df69e0` (checkpoint dokumentasi terdahulu), disusul commit dokumentasi pembaruan FNC-01 ini. Semua lokal; `git log` adalah sumber untuk HEAD setelah checkpoint ini |
| Working tree pada checkpoint 2026-11-11 | 14 file termodifikasi dan 5 path tidak terlacak (`.gitignore`, controller/request/service AI, `phpunit.xml`, `Sidebar.vue`, view knowledge-documents, `routes/web.php`, `vite.config.js`, test AI/Settings; serta `Docs/design-system-reference/`, `resources/js/pages/Settings/`, `tests/Feature/AI/Concerns/`, dan dua test Gemini baru); semuanya dipertahankan sengaja di luar commit dokumentasi. Observasi tertanggal, bukan status permanen |
| Remote | `origin` → `https://github.com/SumberMakmurRejeki/prime-chatbot.git` |

Checkpoint Git diverifikasi 2026-11-11 melalui `git rev-parse HEAD`,
`git branch --show-current`, `git status --porcelain=v1`, `git log`,
`git remote -v`, `git branch -a`, dan `git ls-remote --heads origin`;
HEAD adalah `8d45410`, branch kerja tidak ada pada heads `origin`, dan
staging area kosong sebelum edit dokumentasi.

### Checkpoint Git — 15 November 2026

Observasi read-only: branch `feature/gemini-file-search`, HEAD `c6dc1be28fba22fa4dba8382891ac070c51e76c7`; 20 file modified, 12 untracked, 0 staged sebelum edit dokumentasi. Tidak ada upstream untuk branch ini. `origin` memuat `main` (`277d725`), `chore/ci-pr-quality-gates` (`91f3e32`), `chore/prime-google-agent-foundation` (`df17421`), `fix/master-checksheet-validation` (`ec841c4`), dan `fix/xlsx-export-text-safety` (`e1bdad7`); branch kerja tidak terpublikasi.

| Publikasi | Status checkpoint |
|---|---|
| PR #1 | MERGED; `origin/main` `277d725`. |
| PR #2 | OPEN; head `e1bdad7`. |
| PR #3 | OPEN; head `ec841c4`. |
| PR #4 | OPEN; head `91f3e32`; satu-satunya Actions run yang tercatat (`43605824318`) FAILURE pada PHPUnit; langkah JavaScript, changed-file Pint dan build SKIPPED. Koreksi workflow yang terlihat lokal belum dipublikasikan; tidak ada run baru. |

Checkpoint ini tidak mengubah catatan Git 2026-11-11 yang tercantum di atas; semua status working tree bersifat tertanggal, bukan permanen. Detail tujuh worktree dan batas buktinya ada di `Docs/PRIME_MASTER_BACKLOG.md` bagian F.

### Checkpoint Git — 17 November 2026

Read-only observation before this documentation update; working tree is not a permanent status. Root `C:\laragon\www\prime-google` remains on `feature/gemini-file-search`, HEAD `c6dc1be28fba22fa4dba8382891ac070c51e76c7`, no upstream; it had 21 tracked modifications, 13 untracked paths and 0 staged. These counts predate the three requested documentation edits; `Docs/PROJECT_STATE.md` was already modified and the backlog/parallel plan were untracked. Preserve unrelated dirty work.

The M-01 candidate worktree `integrate-wave-1-owner-approved` remains on `integrate/gemini-security-user-settings-pm-review` at `c6dc1be28fba22fa4dba8382891ac070c51e76c7`, with 27 tracked modifications and 5 untracked paths; no remote PR was observed for this branch. The separate CI worktree `chore-ci-pr-quality-gates` remains at `21c3291f000d1b29cf11f6875b1b71e67347b513`, tracks `origin/chore/ci-pr-quality-gates`, and has one modified test file. See `Docs/PRIME_MASTER_BACKLOG.md` F.7 for the complete observed worktree inventory.

GitHub API snapshot: PR #1 MERGED; PR #2 OPEN at `e1bdad7`; PR #3 OPEN at `ec841c4`; PR #4 OPEN at `21c3291`. Latest observed PR #4 Actions run `43742200425` completed FAILURE at PHPUnit; frontend build passed, later JavaScript/Pint steps were skipped. Run #1 `43605824318` also failed at PHPUnit. Details: <https://github.com/SumberMakmurRejeki/prime-chatbot/actions/runs/43742200425>. Local CI candidate edits have not been pushed, and Q-03 review does not change remote status.

### Penutupan CI PR #4 — 17 November 2026 (M-GATE-08)

Checkpoint ini menggantikan status PR #4 pada snapshot Git di atas, tanpa mengubah rekaman historisnya. [PR #4](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/4) **MERGED** melalui Create a merge commit setelah approval Owner. HEAD PR yang diproses `393a505d9c63b36c36d20062bbec84e46369e7ef`; merge commit dan remote `main` `f048f742541efd93a59f41899fe5df72996208b3`. Blob `.github/workflows/quality.yml` dan `tests/Feature/Feature/PM/PmExecutorTest.php` pada main cocok dengan kandidat yang disetujui.

**CI main PASS:** [Actions `44094015983`](https://github.com/SumberMakmurRejeki/prime-chatbot/actions/runs/44094015983), job `134076265906`, selesai success pada merge SHA; frontend build, PHPUnit, JavaScript dan changed-file Pint benar-benar dijalankan/lulus, tanpa gate SKIPPED. CI sebelum merge `44084322427` juga PASS pada HEAD PR. Q-03 diterima untuk delta fixture test-only; Q-04 diterima untuk pemindahan build sebelum PHPUnit, verdict `SHIP_FOR_OWNER_REVIEW`, findings none. **TASK-19.01 selesai sesuai scope automated quality gates; parent TASK-19 tidak otomatis DONE.**

Branch, HEAD dan Git status 11 worktree lokal tetap identik sebelum/sesudah merge. Branch/worktree tidak dihapus dan dirty root tidak disinkronkan. Pembaruan M-GATE-09 hanya dokumentasi lokal; tidak memberi izin Git publication, deployment atau runtime/database QA.

### Penutupan XLSX PR #2 — 17 November 2026 (M-GATE-14)

Checkpoint ini menggantikan status PR #2 pada snapshot Git di atas, tanpa mengubah rekaman historisnya. [PR #2](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/2) **MERGED** melalui Create a merge commit setelah approval Owner. HEAD kandidat `28ce9f2de4a92e8279f8874d825f6798398c42cf`; merge commit dan remote `main` `7e90945ea452911c0c5d7dccaa7a9dc43984cdc9`.

**CI main PASS:** [Actions `44118241952`](https://github.com/SumberMakmurRejeki/prime-chatbot/actions/runs/44118241952), job `134162139383`, selesai success pada merge SHA; frontend build, PHPUnit, JavaScript dan changed-file Pint lulus, tanpa gate SKIPPED. CI sebelum merge `44107526250` juga PASS pada HEAD kandidat. **TASK-19.02 selesai sesuai scope; parent TASK-19 tidak otomatis DONE.**

**Q-05:** review independen XLSX oleh QA existing, `SHIP_FOR_OWNER_REVIEW`, findings none, tanpa perubahan source. Ini bukan formal GitHub approval. Lima file XLSX cocok antara kandidat dan merge SHA berdasarkan GitHub contents API SHA (bukan Git blob SHA): `ReportBreakdownExport.php` `91bf7939e0496aafa099c483d1fbd3da82ac99e2`; `BindsLiteralTextCells.php` `a729762da8e3eab672180796cc8ce1842c10193a`; `ReportPmExport.php` `bc716049ee907820cd1f43bba1fadffbac5478e2`; `ReportPmWorkerExport.php` `98aff7fd76dc831dc267ac5f8ccb68d7cc982e73`; `ReportExportTextSafetyTest.php` `1e45eef35c71d489fe9e767d5495b651310821c9`. **Parent ancestry merge commit: NOT VERIFIED**; available GitHub responses tidak memaparkan field `parents`, dan objek tidak tersedia lokal.

Branch/worktree lokal dipreservasi; tidak dilakukan fetch atau sinkronisasi lokal. M-GATE-14 mencatat merge remote dan pembaruan dokumentasi lokal saja, tanpa izin Git publication tambahan, deployment atau runtime/database QA.
### Penutupan validasi/wizard Checksheet PR #3 — 18 November 2026 (M-GATE-19/20)

Checkpoint ini menggantikan status PR #3 pada snapshot Git 15 dan 17 November di atas, tanpa mengubah rekaman historisnya. [PR #3](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/3) **MERGED** setelah approval Owner; head kandidat `a9929fe9855664a025e6b2f3f6feb3077fdb8daa`, merge commit dan remote `main` `d2588cec2b45f5b30d8182b3bb43b364432efe79`, basis `7e90945`.

**CI pasca-merge PASS:** Actions `44224408741`, job `134508664658`, selesai success pada merge SHA; frontend build, PHPUnit, JavaScript dan changed-file Pint lulus tanpa gate SKIPPED. **TASK-07.01 selesai sesuai scope; parent TASK-07 tetap IN PROGRESS; TASK-07.02/.03 tetap PLANNED.**

**Q-06:** review independen oleh QA existing, internal `SHIP_FOR_OWNER_REVIEW`, tanpa temuan blocking, tanpa perubahan source. Ini review internal sesi, bukan formal GitHub approval.

**Batas policy:** branch protection/ruleset **tidak diverifikasi langsung** sebelum merge. Jangan menyatakan gate tersebut PASS secara retrospektif; merge berikutnya harus berhenti bila kebijakan wajib belum dapat diverifikasi. M-GATE-20 adalah dokumentasi lokal selektif dan kajian UX read-only, bukan izin Git publication, integrasi, database atau runtime baru. Worktree `fix-master-checksheet-validation` dipreservasi termasuk dua artefak QA untracked.

### Integrasi lokal — M-01, 17 November 2026

Owner menyetujui source/provisioning dan instalasi locked secara terpisah. Manager sebagai integrator tunggal membuat `C:\Users\USER\.herdr\worktrees\prime-google\integrate-wave-1-owner-approved`, branch `integrate/gemini-security-user-settings-pm-review`, dari commit `c6dc1be28fba22fa4dba8382891ac070c51e76c7` (bukan seluruh dirty root). Source gabungan belum di-commit atau dipublikasikan. Root `feature/gemini-file-search`, tiga worktree sumber dan worktree parsial `integrate-wave-1` dipreservasi; 43 snapshot input tetap identik.

Nama branch diperjelas pada 17 November 2026 agar menyebut scope Gemini security, Pengaturan User, dan PM Review. Lokasi worktree tidak dipindah; perubahan nama tidak mengubah source kandidat atau fingerprint verifikasinya.

Scope TASK-01/06/17 direkonsiliasi bersama dependency operasional dan UI provider yang memang belum ada pada commit basis: gateway Text/File/Store + endpoint-bound credentials + normalisasi Base URL; `OperationalPmAssistantAnswerService` beserta binding/retrieval/filter dan tesnya; provider Inertia/Vue + controller/request/model discovery dan middleware provider; Pengaturan User/izin AI dan sidebar; PM Review historis/server-side. Dua blok `resources/js/app.js` digabung terpisah. Processing dokumen existing, route process, seeder/demo, Report PM extras, WATCHDOG/YAML/benchmark dan replay XLSX dikecualikan. Baseline `UserSettingTest` tetap dipertahankan.

**Bukti integrator pada target gabungan:** PHP 8.3.36 / PHPUnit 12.5.38: **233 tests / 1.816 assertions PASS**, exit 0; Pint scoped PASS; typecheck/build PASS (Vite 6.4.5, 674 modules); JavaScript PM Review **3 PASS**. Percobaan awal mengungkap duplikasi tes shared, field `base_url` yang tidak dikirim, dan assertion image lama; ketiganya dikoreksi sebelum run akhir. HTTP/DNS fakes, SQLite `:memory:`, secondary SQLite, APP_KEY proses sintetis dan guard repository dipertahankan. Composer 136 installs/0 updates/removals dan npm 165 packages dari lockfile existing tanpa scripts/plugins otomatis; keempat manifest/lockfile identik.

- **Status:** Q-02 untuk kandidat integrasi lokal telah diterima; gate M-01 tetap **`UI_NOT_VERIFIED`**, bukan menunggu independent code review. Q-03 dan Q-04 diterima pada kandidat CI terpisah; penutupan PR #4 di atas bukan bukti CI atau browser acceptance M-01. Fingerprint 33 source/coverage path M-01 tetap `fc46780ecd4aa903e7d982d9313ba554a773134d355431a5d30da9eff0dd6a08`. Browser/UI/deep-link, MySQL performance, operational/release acceptance dan parent TASK-01/06/17 tetap terbuka.
- **Environment QA:** **BLOCKED / OWNER/ENV ADMIN INPUT REQUIRED**. A berhenti sebelum koneksi karena konfigurasi/credential QA belum terbukti aman; identitas koneksi dan schema tetap NOT VERIFIED. Owner perlu menunjuk administrator MySQL/Laragon QA, menyediakan akses read-only dan credential lokal aman, lalu memberi approval preflight A terpisah. Tidak ada izin database access, akun/privilege, fixture atau runtime; kontrak isolasi ada pada rencana aktif.

</details>

Label bukti yang dipakai di dokumen ini:

| Label | Arti |
|---|---|
| SOURCE-VERIFIED | Dibuktikan langsung oleh source/konfigurasi di repository. |
| TEST-VERIFIED | Dibuktikan oleh eksekusi test pada sesi verifikasi yang disebutkan; hasil lama tetap HISTORICAL. |
| TEST-SUPPORTED | Ada test regresi yang menargetkan perilaku ini di repository; **bukan** bukti bahwa test tersebut lulus pada checkpoint ini. |
| DOCUMENTED | Tercatat dalam dokumen/scope resmi repository. |
| OWNER DECISION | Keputusan eksplisit pemilik produk; mengikat dan bukan hasil inferensi engineering. |
| COMMITTED | Dibuktikan oleh kondisi Git saat dokumen disusun. |
| HISTORICAL | Hasil verifikasi masa lalu; **tidak dijalankan ulang** saat dokumen ini dibuat. |
| NOT VERIFIED | Belum ada bukti; jangan dianggap benar. |
| NOT IMPLEMENTED | Fitur/kapabilitas tidak ada di source saat ini. |
| PLANNED | Arah/pekerjaan yang disetujui tetapi belum ada di source. |

## Status branch dan agent-foundation pada checkpoint 2026-11-03

Commit `e70c6eefc5316957ef51f5e8cc09c99245af53e0` berisi 35 file
agent-foundation; commit tersebut sudah tergabung ke `main` melalui PR #1
(merge `277d7251`, 2026-11-04). Pada checkpoint 2026-11-03 commit itu adalah HEAD
`chore/prime-google-agent-foundation` dengan working tree bersih; status Git
terkini ada di tabel atas. `.omp/mcp.json` adalah konfigurasi lokal yang
diabaikan `.gitignore`, jadi bukan kandidat commit. Sejak 2026-11-07/08 pekerjaan
AI/Gemini berlanjut di branch `feature/gemini-file-search` (hanya lokal).

| Komponen agent | Status saat diperiksa | Bukti |
|---|---|---|
| Laravel Boost MCP | SOURCE-VERIFIED — server dikonfigurasi lewat `.omp/mcp.json` (lokal, diabaikan Git); sesi OMP saat dokumen ini diperbarui mengekspos tepat tiga tool Boost: `application-info`, `get-absolute-url`, `search-docs` | `.omp/mcp.json`; daftar tool sesi |
| Batas tool database / MCP | SOURCE-VERIFIED — 8 built-in tool Boost dikecualikan eksplisit (browser logs, database connections/query/schema, last error, log entries, record rule, Tinker); ini daftar pengecualian, bukan allowlist tiga tool | `config/boost.php` |
| Laravel guidelines skill | SOURCE-VERIFIED — 20 file | `.omp/skills/laravel-best-practices/` (verifikasi hitung file) |
| Testing skill | SOURCE-VERIFIED — 10 file | `.omp/skills/testing-best-practices/` (verifikasi hitung file) |
| Total file skill | 30 file pada dua direktori di atas | Hitung file direktori |

`.ai/rules/index.md` dan `.ai/rules/code-commenting.md` di-commit bersama
pembaruan dokumentasi milestone Gemini ini (lihat tabel checkpoint di atas).
Bagian `.ai/rules` dalam pedoman Boost berlaku untuk path yang tercakup.

## 1. Identitas proyek dan baseline stream Gemini pada checkpoint

PRIME Google adalah aplikasi Laravel untuk domain *preventive maintenance*
(PM) yang berdiri sebagai repository Git sendiri di
`C:\laragon\www\prime-google`.

| Fakta | Status | Bukti |
|---|---|---|
| Repository Git independen | COMMITTED | `git rev-parse HEAD`, `git branch --show-current` |
| Baseline commit | `cc5378ab19e46370e922aac5ff32c8f1cf4018a1` (`chore: establish initial PRIME Google baseline`, 2026-11-02) | `git log` |
| Agent-foundation commit | `e70c6eefc5316957ef51f5e8cc09c99245af53e0`, 35 file, HEAD branch saat checkpoint | `git log`, `git show --stat` |
| Remote Git | `origin` → `https://github.com/SumberMakmurRejeki/prime-chatbot.git` | `git remote -v` |
| Branch dipublikasikan | `origin/main` = `277d7251` (merge PR #1) dan `origin/chore/prime-google-agent-foundation` = `df174210` | COMMITTED (2026-11-08), `git ls-remote --heads origin` |
| Branch kerja Gemini | `feature/gemini-file-search` (HEAD `8d45410` pada checkpoint 2026-11-11; sebelumnya `fc439ee` pada 2026-11-10) hanya lokal; tidak ada di `origin` | `git branch --show-current`, `git rev-parse HEAD`; `git ls-remote --heads origin` (2026-11-11) |
| Project asal (dilindungi) | `C:\laragon\www\Preventive_Maintenance`; tidak boleh dimodifikasi dari repository ini | `README.md` |

Catatan baseline: PRIME Google diturunkan dari PRIME dan mempertahankan
workflow domain PM, tetapi memakai identitas database dan vector store sendiri.
Repository ini bukan target deployment PRIME asli.

## 2. Teknologi dan environment

### Backend

| Komponen | Versi | Status | Bukti |
|---|---|---|---|
| Laravel Framework | v13.35.0 | SOURCE-VERIFIED | `composer.lock` |
| PHP | `^8.3`; runtime terpasang 8.3.36 NTS | SOURCE-VERIFIED / RUNTIME-VERIFIED lokal | `composer.json`, `php -v` |
| Composer | 2.10.3 | RUNTIME-VERIFIED lokal | `composer --version` |
| laravel/ai | v1.2.0 | SOURCE-VERIFIED | `composer.lock` |
| inertiajs/inertia-laravel | v3.5.1 | SOURCE-VERIFIED | `composer.lock` |
| maatwebsite/excel | 3.1.70 | SOURCE-VERIFIED | `composer.lock` |
| barryvdh/laravel-dompdf | v3.1.2 | SOURCE-VERIFIED | `composer.lock` |
| intervention/image-laravel | 4.0.0 | SOURCE-VERIFIED | `composer.lock` |
| simplesoftwareio/simple-qrcode | 4.2.0 | SOURCE-VERIFIED | `composer.lock` |
| PHPUnit | 12.5.38 | SOURCE-VERIFIED | `composer.lock` |
| Paket Composer | 101 production, 35 development | SOURCE-VERIFIED | `composer.lock` |

### Frontend

| Komponen | Versi | Status | Bukti |
|---|---|---|---|
| Vue | 3.5.44 | SOURCE-VERIFIED | `package-lock.json` |
| Inertia Vue | 3.9.1 | SOURCE-VERIFIED | `package-lock.json` |
| Vite | 6.4.5 | SOURCE-VERIFIED | `package-lock.json` |
| Tailwind CSS | 4.3.0 (`@tailwindcss/vite`) | SOURCE-VERIFIED | `package-lock.json` |
| TypeScript / vue-tsc | 5.9.3 / 3.3.13 | SOURCE-VERIFIED | `package-lock.json` |
| Chart.js / FullCalendar | 4.4.3 / 6.1.20 | SOURCE-VERIFIED | `package-lock.json` |
| Node.js terpasang | v24.14.1 | RUNTIME-VERIFIED lokal | `node -v` |
| Paket npm | 235 entri `packages` | SOURCE-VERIFIED | `package-lock.json` |

### Konfigurasi waktu

- Timezone global aplikasi: `UTC` (`config/app.php:68`,
  `env('APP_TIMEZONE', 'UTC')`).
- Tanggal bisnis PM: `Asia/Jakarta` melalui helper
  `app/Services/PM/BusinessDate.php`. Jangan mengubah timezone global sebagai
  jalan pintas.

### Template environment

| File | Fungsi | Status |
|---|---|---|
| `.env.example` | Development, `APP_ENV=local`, MySQL `db_preventive_maintenance_ai`, `QDRANT_COLLECTION=prime_google_knowledge` | SOURCE-VERIFIED |
| `.env.testing.example` | QA/testing, `APP_ENV=testing`, MySQL `db_preventive_maintenance_ai_test`, `QDRANT_COLLECTION=prime_google_qa_knowledge` | SOURCE-VERIFIED |

`.env` lokal diabaikan Git (`.gitignore`); tidak ada kredensial nyata di
repository.

## 3. Modul bisnis yang terimplementasi

Bukti utama: `routes/web.php` (240 baris) beserta controller, service, model, dan
test yang sesuai.

| Modul | Cakupan terimplementasi | Bukti source |
|---|---|---|
| Master data | Lokasi, mesin, PM action, checksheet master | `app/Http/Controllers/Master/`, `app/Services/Master/` |
| Akses mesin | Landing mesin via QR/kode, sesi guest | `app/Services/PM/MachineLandingService.php`, `app/Services/Master/QrCodeService.php` |
| Checksheet PM | Standards, parts, machine assignment, operational window, provenance | `app/Models/PmChecksheet*.php`, `app/Services/Master/PmChecksheetService.php` |
| Penjadwalan PM | Schedule, schedule date, occurrence, generator, reconciler, lifecycle | `app/Services/PM/PmScheduleDateGenerator.php`, `app/Services/PM/PmScheduleDateReconciler.php`, `app/Services/PM/ScheduleLifecycleService.php` |
| Eksekusi PM | Eksekusi, item, action result, media, history, review | `app/Services/PM/PmExecutionService.php`, `app/Services/PM/PmReviewService.php`, `app/Services/PM/PmExecutionMediaService.php` |
| Breakdown | Input, review, close, media, history | `app/Services/Breakdown/` |
| Kalender maintenance | Agenda dan event | `app/Services/Calendar/CalendarEventService.php` |
| Dashboard | Monitoring dashboard (Blade) + rute kandidat Vue | `app/Services/Dashboard/DashboardMonitoringService.php` |
| Laporan & export | Laporan PM dan breakdown, export Excel/PDF via job + spool worker | `app/Services/Report/`, `app/Jobs/GeneratePmReportExportJob.php`, `app/Exports/` |
| Notifikasi in-app | Notifikasi admin dan status baca | `app/Services/Notification/`, `app/Models/PrimeNotification.php` |
| Autentikasi & otorisasi | Login, role `admin`/`operator`/`guest`, izin `manage_ai` | `app/Http/Middleware/EnsureOperatorSessionLifetime.php`, `routes/web.php` |
| Settings | User settings, AI provider settings, AI manager | `app/Services/Settings/` |

Kapabilitas yang sudah tidak ada: Web Push dihapus dari runtime. Yang tersisa
adalah skema `push_subscriptions` legacy dan konfigurasi koneksinya
(`config/webpush.php`, migrasi
`database/migrations/2026_06_06_215524_create_push_subscriptions_table.php`), yang masih dipakai
migrasi historis dan guard koneksi sekunder.

## 4. Inventaris implementasi AI/RAG pada stream Gemini

Ringkasan: Gemini File Search adalah jalur retrieval SOP target tunggal PRIME dan
Gemini menghasilkan jawabannya. Qdrant tidak lagi menjadi dependency pemrosesan
PDF maupun publikasi SOP, dan bukan fallback Assistant. Backend lifecycle Gemini
telah diterima pada `fc439ee`; UAT fungsional FNC-01 diterima Owner pada
2026-11-11 (lihat baris status di bawah), sedangkan verifikasi concurrency,
aktivasi operasional, recovery UI Admin, cutover, dan pensiun total kode
Qdrant tetap terpisah dari checkpoint tersebut.

| Aspek | Status | Bukti |
|---|---|---|
| Binding jawaban Assistant | SOURCE-VERIFIED — `GroundedAnswerService` di-bind ke `GeminiGroundedAnswerService`; `AiConversationService` hanya bergantung pada kontrak itu | `app/Providers/AppServiceProvider.php`, `app/Services/AI/AiConversationService.php` |
| Retrieval Gemini ter-scope | SOURCE-VERIFIED — tool File Search hanya dibangun dari kandidat eligible; kandidat kosong berarti tidak ada permintaan, identitas ambigu gagal tertutup | `app/Services/AI/GeminiRetrievalSourceScope.php`, `app/Services/AI/GeminiRetrievalSourceTool.php` |
| Kelayakan SOP terbit | SOURCE-VERIFIED — hanya revisi `ready` yang sedang `is_published` dengan baris indeks Gemini `completed`, snapshot kode/versi/checksum cocok, store cocok, nama dokumen berada di bawah store itu, dan provenance kredensial cocok | `app/Services/AI/GeminiRetrievalEligibilityService.php` |
| Pemrosesan PDF registry | SOURCE-VERIFIED — ekstraksi/chunk dan verifikasi checksum sebelum registry menjadi `ready` (jumlah halaman, chunk, fingerprint pipeline); tidak meminta embedding atau menulis ke Qdrant | `app/Services/AI/KnowledgeDocumentService.php`, `app/Services/AI/DocumentIngestion/` |
| Publikasi SOP | SOURCE-VERIFIED — aksi Admin eksplisit; memakai `trustedIndexFor()`, predikat bukti indeks yang sama dengan retrieval. Verifikasi MySQL/config terjadi sebelum mutasi publikasi dan tanpa jaringan Gemini. Indexing revisi pengganti yang gagal tidak mengubah revisi terbit saat ini | `app/Services/AI/KnowledgeDocumentService.php`, `app/Services/AI/GeminiRetrievalEligibilityService.php`, `app/Services/AI/GeminiDocumentIndexingService.php` |
| Indexing Gemini File Search | SOURCE-VERIFIED — opt-in, default mati, dijalankan antrean, store dari `GEMINI_FILE_SEARCH_STORE_ID`; job di-dispatch `afterCommit`, transisi dikunci pada state + generation, dan job dari generation lama berhenti tanpa menimpa generation baru | `config/gemini_file_search.php`, `app/Services/AI/GeminiDocumentIndexingService.php`, `app/Jobs/IndexKnowledgeDocumentInGemini.php` |
| Generation history | SOURCE-VERIFIED — satu baris indeks saat ini menentukan eligibility; ketika generation diganti, snapshot generation lama diarsipkan ke tabel history dalam transaksi yang sama. History bukan state retrieval/publikasi aktif | `database/migrations/2026_11_10_020604_create_knowledge_document_gemini_indexing_generations_table.php`, `app/Services/AI/GeminiDocumentIndexingService.php` |
| Bukti tulis remote | SOURCE-VERIFIED — `remote_write_evidence` bernilai `not_started` (eksplisit belum ada mutasi), `may_have_started` (unggah/impor mungkin sudah terkirim), atau NULL untuk baris historis/tidak diketahui; bukti hanya bergerak maju dan tidak diturunkan dari error code | `app/Services/AI/GeminiRemoteWriteEvidence.php`, `database/migrations/2026_11_10_065711_add_remote_write_evidence_to_knowledge_document_gemini_indexings_table.php` |
| Recovery backend | SOURCE-VERIFIED — hanya melanjutkan operasi tersimpan lewat pemeriksaan status, atau membuat generation baru bila baris `failed`, bukti tulis `not_started`, identitas resource Google kosong, sumber/checksum valid, dan konfigurasi memenuhi syarat. `may_have_started` maupun NULL menolak unggah ulang; resource Google lama tidak dihapus otomatis | `app/Services/AI/GeminiDocumentIndexingService.php` |
| Recovery/status UI Admin | PARTIAL / LOCAL UNCOMMITTED — status, process, dan retry route Admin ada pada `routes/web.php:202-208`; `recover()` pada `GeminiDocumentIndexingService.php:225` tetap tanpa caller. Endpoint recovery dan tampilan generation/history belum tersedia | Source pada checkpoint 15 November; bukan klaim implementasi committed atau complete |
| Retry indexing | SOURCE-VERIFIED — `schedule()` melanjutkan state `queued`/`uploaded`/`pending`, menandai klaim basi `uploading`/`importing` sebagai `unknown`, dan tidak mengulang buta `completed`/`unknown`; generation baru hanya lewat jalur aman | `app/Services/AI/GeminiDocumentIndexingService.php` |
| Provenance kredensial dan import identity | SOURCE-VERIFIED — `credential_configuration_id`, `credential_revision_id`, dan `import_identity` tersimpan per generasi impor; nilai hilang atau revisi kredensial yang berbeda tidak dipercaya | `database/migrations/2026_11_08_000001_add_indexing_provenance_to_knowledge_document_gemini_indexings_table.php` |
| Kontrak kepercayaan sitasi | SOURCE-VERIFIED — anotasi harus cocok dengan peta registry tepercaya (kode, versi kanonik string, URI store, `import_identity`); jawaban tanpa sitasi terverifikasi tidak ditampilkan sebagai jawaban SOP | `app/Services/AI/KnowledgeCitationMapper.php`, `app/Services/AI/GeminiGroundedAnswerService.php` |
| Assistant Admin-only | SOURCE-VERIFIED + TEST-SUPPORTED — grup `/assistant` memakai `EnsureActiveAdmin`; endpoint AI settings/knowledge memakai `prime.role:admin` + `can:manage_ai` | `routes/web.php`, `app/Providers/AppServiceProvider.php`, `tests/Feature/AI/GeminiAssistantChatTest.php` |
| Kredensial Gemini tunggal | SOURCE-VERIFIED — satu sumber koneksi dari revisi chat provider aktif; kredensial tidak disimpan di config File Search | `app/Services/AI/GeminiFileSearchConnection.php` |
| **AI Settings Gemini driver** | SOURCE-VERIFIED + TEST-VERIFIED — Admin memilih driver dari daftar tervalidasi server-side; create menyimpan pilihan, revise mempertahankan driver konfigurasi, dan pergantian jenis provider pada revisi ditolak | `app/Http/Requests/Settings/StoreAiProviderConfigurationRequest.php`, `app/Services/Settings/AiProviderConfigurationService.php`, `tests/Feature/Settings/AiProviderConfigurationTest.php` |
| Qdrant di runtime | SOURCE-VERIFIED — tidak dipakai `KnowledgeDocumentService::process()`/`publish()` dan bukan fallback Assistant. Implementasi vector store, embedding legacy, readiness inspector, serta consumer/test-nya masih ada di repository; retirement belum disetujui | `app/Services/AI/KnowledgeDocumentService.php`, `app/Providers/AppServiceProvider.php`, `app/Services/AI/` |
| Migration/aktivasi operasional | PENDING / NOT VERIFIED — migration provenance, generation history, dan remote-write evidence sudah di-commit, tetapi belum dibuktikan diterapkan pada target operasional; QA bukan approval produksi | Migration terkait; tidak ada bukti penerapan operasional |
| FNC-01 functional RAG UAT | APPROVED — Owner menerima UAT fungsional 12/12 pada 2026-11-11; memvalidasi RAG dokumen terbit, termasuk sitasi dan abstention (dokumen ID 1 selesai diindeks `completed`, dipublikasikan manual, dan dijawab Assistant tanpa fabrikasi). Hasil ini bukan bukti reliabilitas produksi atau cutover | Penerimaan Owner; laporan UAT OMP 99-08 / 100-08 (bukti handoff eksternal, tidak ada file di repository) |
| Verifikasi concurrency MySQL | PENDING / NOT VERIFIED — belum ada bukti uji concurrency MySQL independen | Tidak ada bukti verifikasi pada checkpoint ini |
| Aktivasi operasional dan cutover | PENDING / NOT VERIFIED — UAT fungsional tidak membuktikan aktivasi pada target operasional; cutover memerlukan approval Owner tersendiri | Tidak ada bukti verifikasi pada checkpoint ini |
| Diagnostik kegagalan Assistant Gemini | COMMITTED — commit `8d45410` menambah logging kegagalan provider pada `GeminiGroundedAnswerService` dan pengujiannya; PHPUnit fokus `GeminiAssistantChatTest` dilaporkan 22 PASS / 148 assertion pada 2026-11-11 (tidak dijalankan ulang pada sesi dokumentasi ini). Logging terstruktur tidak menetapkan akar penyebab error Gemini intermiten sebelumnya | `git show 8d45410`; hasil sesi test 2026-11-11 |
| Web search / grounding via web | NOT IMPLEMENTED — tidak ada integrasi terverifikasi dalam scope ini | Tidak ada bukti source yang diverifikasi di sini |
| AI operasional terhadap database domain | PARTIAL / LOCAL UNCOMMITTED — query PM terbatas dan binding lokal ada pada checkout, termasuk FNC-01; bukan generalized query atau fitur yang telah melalui independent QA/integrasi | `OperationalPmAssistantAnswerService.php` dan binding lokal; status Git 15 November |

**QA (TEST-VERIFIED, sesi 2026-11-09):** run PHPUnit pada subset relevan,
`tests/Feature/Settings` + `tests/Feature/AI`, menghasilkan **459 test, 2.422
assertion, PASS** (PHP 8.3.36, SQLite `:memory:`). Feature test baru membuktikan
konfigurasi Gemini harus lulus uji sebelum aktivasi dan bahwa `ChatProviderResolver`
serta File Search memakai revision credential yang sama tanpa panggilan jaringan.
Browser Playwright CLI pada disposable SQLite fixture di luar repository
membuktikan Admin dapat memilih Gemini, menyimpan credential sintetis, dan
memperbarui model tanpa mengganti driver; fixture menunjukkan dua revision
`gemini` dengan credential tersimpan dan tidak ada permintaan provider live.
Full suite, verifikasi live, dan pemeriksaan browser sebelumnya tetap HISTORICAL
dan tidak dijalankan ulang pada task ini; angka subset di atas tidak
menggantikannya dan tidak membuktikan aktivasi operasional. Angka QA dan
browser ini mendahului `fc439ee`, yang mengubah test lifecycle/provenance;
figur tersebut bukan hasil test pada HEAD dan tidak dijalankan ulang di sini.

Indexing Gemini tetap bukan otorisasi publikasi atau retrieval: hanya aksi Admin
yang mempublikasikan revisi, dan hanya revisi terbit saat ini yang eligible.
Revisi historis tetap arsip.

## 5. Isolasi database dan layanan eksternal

| Identitas | Nama | Status |
|---|---|---|
| Development | `db_preventive_maintenance_ai` | SOURCE-VERIFIED (template & guard) |
| QA/testing (MySQL) | `db_preventive_maintenance_ai_test` | SOURCE-VERIFIED (template & guard) |
| Original (dilindungi) | `db_preventive_maintenance`, `db_preventive_maintenance_test` | SOURCE-VERIFIED (daftar terlarang) |
| PHPUnit | SQLite `:memory:` | SOURCE-VERIFIED `phpunit.xml` |

Guard runtime: `app/Providers/TestingDatabaseServiceProvider.php` menolak
bootstrapping bila koneksi MySQL efektif menunjuk database original, dan
mewajibkan environment `testing` menunjuk database QA yang disetujui. Guard juga
mencakup koneksi sekunder `webpush.database_connection`.

Isolasi test (`phpunit.xml`): `APP_ENV=testing`, `DB_CONNECTION=sqlite`,
`DB_DATABASE=:memory:`, `CACHE_STORE=array`, `SESSION_DRIVER=array`,
`MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`,
`QDRANT_COLLECTION=prime_google_qa_knowledge`, `WEBPUSH_DB_CONNECTION=sqlite`.
Test tidak memerlukan MySQL, Qdrant, atau jaringan.

Layanan eksternal:

- Qdrant: kode dan consumer legacy masih ada, tetapi tidak diperlukan untuk setup, pemrosesan PDF SOP, publikasi SOP, atau Assistant Gemini. Penghapusan menunggu inventaris seluruh consumer runtime dan keputusan Owner.
- Gemini API: kunci di `.env.example`/`.env.testing.example` masih placeholder
  kosong; integrasi File Search memakai kredensial revisi provider aktif, bukan
  nilai di template.
- Eksistensi database MySQL development/QA di server lokal: NOT VERIFIED pada
  penyusunan dokumen ini (tidak ada operasi MySQL).

## 6. Keputusan engineering yang berlaku

| Keputusan | Alasan/implikasi | Bukti |
|---|---|---|
| **OWNER DECISION** — Chatbot dan RAG hanya untuk Admin; otorisasi ditegakkan di server | Operator tetap memakai alur PM, checksheet, akses mesin, dan breakdown sesuai izin yang ada; jangan menambah akses chatbot/RAG untuk operator | Keputusan owner (2026-11-08); `routes/web.php:185-215`, `app/Http/Middleware/EnsureActiveAdmin.php`, `app/Providers/AppServiceProvider.php:57-60` |
| **OWNER DECISION** — Gemini File Search adalah target backend RAG tunggal; Qdrant legacy dihapus setelah cutover terverifikasi dan disetujui | Jangan menggambarkan dukungan dua provider sebagai arsitektur target permanen; keberhasilan indexing bukan otorisasi retrieval; revisi SOP lama tetap diarsipkan | Keputusan owner (2026-11-08); `config/gemini_file_search.php`, docblock `app/Services/AI/GeminiDocumentIndexingService.php` |
| **OWNER DECISION** — Target frontend Laravel + Inertia/Vue + NovaSpark; migrasi dari Blade bertahap | Halaman Blade bisnis tetap sampai pengganti Vue terverifikasi dan diterima; template root Inertia dan template server-rendered lain tetap Blade; urutan migrasi belum final | Keputusan owner (2026-11-08); `resources/js/pages/`, `resources/views/` |
| Timezone global `UTC`, tanggal bisnis via helper `Asia/Jakarta` | Perubahan timezone global adalah keputusan terpisah | `config/app.php:68`, `app/Services/PM/BusinessDate.php` |
| Metadata knowledge document adalah sumber kebenaran (MySQL + storage privat); indeks Gemini adalah state turunan yang harus terverifikasi | Registry menentukan publikasi; indexing tidak mempublikasikan SOP dan publikasi tidak melakukan mutasi ke Google | `KnowledgeDocumentService.php`, `GeminiRetrievalEligibilityService.php` |
| `GroundedAnswerService` memakai Gemini File Search; process/publish SOP tidak memakai Qdrant lagi | Tidak ada fallback; retirement seluruh implementasi Qdrant menunggu verifikasi consumer runtime dan keputusan Owner | `AppServiceProvider.php`, `KnowledgeDocumentService.php` |

| Konfigurasi koleksi Qdrant tanpa default; nilai kosong ditolak saat resolusi | Mencegah akses tak sengaja ke koleksi bersama | `config/qdrant.php:20` |
| Seeder default tidak membuat akun/kredensial | Mencegah akun privileged tersembunyi | `database/seeders/DatabaseSeeder.php`, `tests/Feature/DatabaseSeederSafetyTest.php` |
| Guard database aktif di semua environment | Melindungi database PRIME asli | `app/Providers/TestingDatabaseServiceProvider.php` |
| Web Push dihapus; skema `push_subscriptions` dipertahankan sebagai legacy | Pembersihan skema adalah risiko terpisah | `config/webpush.php` |
| Secret hanya di `.env` lokal; runtime spool, dump, backup diabaikan Git | Menjaga repository bebas rahasia dan artefak | `.gitignore` |

## 6.1 Keputusan dependency dan retirement

| Komponen | Keputusan | Consumer saat ini, alasan dipertahankan, pengganti dan bukti sebelum penghapusan |
|---|---|---|
| Gemini generation dan File Search | **KEEP** — backend target Assistant SOP | `AppServiceProvider` mengikat `GroundedAnswerService` ke `GeminiGroundedAnswerService`; Assistant menggunakannya. Indexing tetap opt-in/queue dan bukan publikasi. Pertahankan eligibility, provenance, dan citation trust; aktivasi operasional tetap menunggu blocker di atas. |
| Satu konfigurasi credential Gemini | **KEEP** | `GeminiFileSearchConnection` mengambil credential dari provider/revisi Gemini aktif untuk generation dan operasi indexing. Store ID tetap konfigurasi resource terpisah, bukan credential kedua. Uji credential/revision mismatch sebelum cutover. |
| Qdrant VectorStore dan embedding | **CONDITIONAL — RETIRE AFTER VERIFICATION** | Tidak dipakai `KnowledgeDocumentService::process()`/`publish()` dan bukan fallback Assistant, tetapi implementasi Qdrant, `DocumentEmbeddingIndexingService`, readiness inspector, dan consumer/test legacy masih ada. Inventaris seluruh consumer runtime sebelum penghapusan; retirement tetap keputusan Owner. |
| Recovery Gemini dan generation history | **BACKEND COMMITTED; ADMIN UI PENDING** | Recovery aman berbasis generation (`recover()`) dan snapshot history tersedia di backend. Trigger recovery, status indexing, dan tampilan generation history Admin belum ada. Resource Google tidak dihapus otomatis; history bukan state retrieval/publikasi aktif. UAT dan verifikasi concurrency MySQL masih diperlukan. |
| Konfigurasi RAG/embedding lama | **CONDITIONAL — RETIRE AFTER VERIFICATION** | `config/ai.php`/`config/qdrant.php`, resolver embedding, dan `DocumentGroundedAnswerService` masih tersedia sebagai legacy/tes, bukan requirement SOP process/publish. Inventaris consumer runtime/test sebelum retirement. `DocumentGroundedAnswerService` bukan fallback runtime Assistant. |
| AI provider dan AI manager settings | **KEEP** | Route Admin `settings/ai/providers` dan `settings/ai/managers` mengelola provider/revisi dan hak akses manager. Driver (`openai`, `openai-compatible`, `gemini`, `openrouter`) divalidasi server-side; create menyimpan pilihan, revisi mempertahankan driver konfigurasi, dan jenis provider lain memerlukan konfigurasi baru. Satu revisi credential Gemini aktif tetap melayani generation dan File Search; Store ID tetap konfigurasi resource terpisah. UI Blade tetap dipertahankan; simplifikasi menu/nav adalah roadmap terpisah. |
| Assistant dan knowledge-document pages | **KEEP** | `resources/views/pages/assistant/*` dirender oleh Assistant routes; `settings/knowledge-documents/index.blade.php` menyajikan workflow Admin untuk registry, indexing, dan publikasi. Pertahankan sampai kebutuhan produk dan pengganti UI yang diterima membenarkan perubahan; halaman knowledge-documents bukan sekadar halaman retrieval Gemini. |
| Blade business views | **CONDITIONAL — RETIRE AFTER VERIFICATION** | `resources/views/pages/**` masih dirender oleh route/controller bisnis yang ada; Vue belum menggantikan seluruh halaman. Ganti per halaman secara inkremental; hapus tiap Blade hanya setelah route/permission, fungsi, responsive behavior, aksesibilitas, dan regression/UAT setara terverifikasi serta diterima. Tidak ada halaman bisnis dihapus pada milestone ini. |
| Inertia root Blade | **KEEP** | `resources/views/spa.blade.php` adalah root template untuk response Inertia; bukan business view yang dapat dihapus lewat migrasi halaman. Pertahankan sebagai infrastruktur kecuali integrasi Inertia diganti dan diuji menyeluruh. |
| Vue/Inertia pages dan SPA proof | **KEEP / CONDITIONAL** | Vue/Inertia adalah arah target; implementasi saat ini bertahap dan termasuk halaman proof internal, bukan bukti bahwa migrasi selesai. Pertahankan aplikasi/pages yang dipakai. Hapus proof hanya setelah acceptance target SPA dan rute internal ditutup/diubah dengan verifikasi route dan build. |
| Web Push leftovers | **RETIRE AFTER VERIFICATION** | Runtime Web Push telah dihapus; `config/webpush.php` masih memberi nama tabel/koneksi bagi migrasi historis dan `TestingDatabaseServiceProvider` masih menjaga koneksi sekunder `push_subscriptions`. Jangan hapus sebelum strategi schema cleanup disetujui, migrasi historis/upgrade baru aman, dan guard/tests/database inventory tidak lagi memerlukan koneksi sekunder. Service-worker cleanup shim tetap dipertahankan sampai browser legacy worker removal terverifikasi; shim bukan runtime push. |
| Jalur jawaban Assistant Qdrant | **REPLACED (runtime)** | Runtime `GroundedAnswerService` kini diikat ke Gemini. `DocumentGroundedAnswerService` masih hidup sebagai implementasi lama dan dipakai tes regresinya, bukan fallback runtime; hapus bersama tes/config terkait hanya setelah cutover Qdrant dan verifikasi consumer selesai. |

## 7. Milestone yang sudah diterima

| Checkpoint | Status | Bukti |
|---|---|---|
| Baseline repo ditegakkan sebagai commit awal | COMMITTED | `cc5378a` (2026-11-02) |
| Baseline hardening (pra-commit pertama) tercatat sebagai scope inisiatif | DOCUMENTED | `specs/product/SCOPE_LATEST.yaml` |
| Agent-foundation committed (35 file) | COMMITTED | `e70c6ee`, HEAD `chore/prime-google-agent-foundation` |
| FNC-01 Functional RAG UAT | APPROVED — 12/12 PASS; Owner acceptance 2026-11-11. Dua belas hasil adalah hasil kumulatif dalam validasi yang dilaporkan, bukan 12 permintaan sukses berturut-turut dan bukan reliabilitas provider 100%. Bukti validasi: external handoff OMP 99-08 / 100-08; Owner acceptance terpisah dari bukti source/test | OWNER DECISION: penerimaan UAT; TEST-VERIFIED menurut laporan eksternal OMP 99-08 / 100-08 (tidak tersimpan di repository) |
| Dokumentasi progres dan checkpoint Git | DOCUMENTED — backlog v4 dan PROJECT_STATE disinkronkan pada 2026-11-15; bukan perubahan kode/test atau approval integrasi | `Docs/PRIME_MASTER_BACKLOG.md`, checkpoint Git di bagian atas |
| Branch dipublikasikan ke `origin` | COMMITTED pada checkpoint 2026-11-03 | `git ls-remote --heads origin` |
| Working tree bersih | HISTORICAL — observasi saat HEAD `e70c6ee` (2026-11-03) diverifikasi; bukan status permanen | `git status --porcelain=v1` |
| PR #1 (agent-foundation) | COMMITTED — digabung; `origin/main` = `277d7251` (merge commit, 2026-11-04) | `git ls-remote --heads origin` (2026-11-08) |
| Implementasi Assistant Gemini File Search | COMMITTED — `0981c39` (provenance, import identity, scoped retrieval) dan `37e82cc` (binding Assistant, publikasi revisi tunggal, 2026-11-09); hasil QA dan PHPUnit-nya HISTORICAL | `git log`, `git show --stat` |
| Backend Gemini SOP lifecycle, provenance, generation history, dan safe recovery | COMMITTED — `fc439ee` (`feat(ai): add safe Gemini index recovery and history`, 2026-11-10); backend complete, tanpa klaim UAT atau aktivasi operasional | `git show --stat fc439ee` |
| Synthetic live Gemini verification | TEST-VERIFIED HISTORICAL menurut laporan OMP 58; tidak dijalankan ulang pada sesi dokumentasi ini | Laporan OMP 58 |
| Browser Assistant E2E | TEST-VERIFIED HISTORICAL menurut laporan OMP 58; tidak dijalankan ulang pada sesi dokumentasi ini | Laporan OMP 58 |
| Full PHPUnit | TEST-VERIFIED pada 2026-11-09: **1.193 test, 6.970 assertion, PASS**; HISTORICAL dan mendahului perubahan test pada `fc439ee` | `artisan test --compact`, PHP 8.3.36, output sesi terdahulu |
| `composer audit` | HISTORICAL: 0 advisory; tidak dijalankan ulang | Checkpoint terdahulu |
| `npm audit` | HISTORICAL: 0 vulnerability; tidak dijalankan ulang | Checkpoint terdahulu |

Inventaris test pada 2026-11-09: **77 file PHP** — 72 Feature dan 5 Unit.
Hasil run 2026-11-09 (HISTORICAL): subset `tests/Feature/AI` +
`tests/Feature/KnowledgeDocumentLifecycleTest.php` menghasilkan **378 test,
1.738 assertion PASS**, dan seluruh suite menghasilkan **1.193 test, 6.970
assertion PASS** (PHP 8.3.36, SQLite `:memory:`).

Commit `fc439ee` (2026-11-10) mengubah `KnowledgeDocumentLifecycleTest`,
`GeminiDocumentIndexingTest`, dan `GeminiIndexingProvenanceTest`, serta
menambah dua migration, sehingga angka di atas tidak lagi mewakili HEAD.
Commit `8d45410` (2026-11-11) menambah logging kegagalan pada
`GeminiGroundedAnswerService` dan pengujian `GeminiAssistantChatTest`;
satu-satunya angka test terbaru yang dicatat adalah 22 PASS / 148 assertion
terfokus pada 2026-11-11 (dilaporkan, tidak dijalankan ulang pada sesi
dokumentasi ini). Angka suite penuh di atas tetap HISTORICAL.

Penerimaan formal atas seluruh kriteria `specs/product/SCOPE_LATEST.yaml` (status
HARDENED/PARTIAL/BLOCKED): NOT VERIFIED.

## 8. Keterbatasan dan pekerjaan terbuka

1. **Branch kerja belum dipublikasikan.** `feature/gemini-file-search` hanya ada
   lokal; `git ls-remote --heads origin` pada 2026-11-11 masih memuat hanya
   `main` dan `chore/prime-google-agent-foundation`. Jangan menyamakan commit
   lokal dengan rilis atau aktivasi operasional.
2. **Backend Gemini dan diagnostik sudah di-commit, bukan readiness produksi.**
   `fc439ee` memisahkan processing PDF, indexing antrean, publikasi eksplisit,
   generation history, dan recovery aman; `8d45410` menambah logging kegagalan
   Assistant. Ini bukan klaim siap produksi; UAT fungsional FNC-01 diterima
   terpisah pada 2026-11-11 (lihat butir 5).
- **Recovery Admin belum lengkap.** Route status/process/retry ada secara lokal pada checkpoint 15 November, tetapi `recover()` tetap tanpa caller; endpoint pemanggil recovery dan tampilan generation history belum tersedia.
4. **Operasional belum diverifikasi.** Penerapan migration, aktivasi provider,
   cutover, dan approval Owner belum dibuktikan pada target operasional.
5. **FNC-01 functional UAT sudah diterima 12/12**, tetapi hasil tersebut
   belum membuktikan stabilitas produksi, reliabilitas setiap permintaan, atau
   operational cutover. Penerimaan Owner dan bukti validasi dilaporkan terpisah;
   laporan eksternal OMP 99-08 / 100-08 tidak tersedia sebagai file repository.
6. **MySQL concurrency verification masih pending.** Test terisolasi tidak
   menggantikan verifikasi concurrency pada MySQL.
7. **Qdrant retirement masih pending.** Qdrant bukan requirement
   `process()`/`publish()` SOP, tetapi implementasi dan consumer legacy masih
   ada. Buktikan seluruh consumer runtime telah hilang sebelum penghapusan.
8. **Recovery fail-closed untuk bukti ambigu.** `remote_write_evidence`
   `not_started` berarti eksplisit belum ada mutasi; `may_have_started` berarti
   unggah/impor mungkin sudah terkirim; NULL berarti historis/tidak diketahui.
   Bukti ambigu tidak mengizinkan unggah ulang buta, dan resource Google lama
   tidak dihapus otomatis.
9. **Driver AI Settings sudah diperbaiki (source + QA).** Admin dapat memilih
   Gemini saat membuat konfigurasi; revisi mempertahankan driver dan API Key
   tersimpan bila credential dikosongkan. QA sintetis belum menjalankan request
   live atau mengaktifkan konfigurasi operasional.
10. **Synthetic live Gemini dan browser Assistant E2E hanya bukti historis**
    menurut laporan OMP 58. Keduanya tidak dijalankan ulang pada sesi
    dokumentasi.
11. **Referensi dokumentasi yang belum ada.** `specs/product/SCOPE_LATEST.yaml`
    menyebut `Docs/modules/AI_ASSISTANT.md`; file tersebut tidak ada di
    repository. Tidak ada referensi runtime yang menunjuk ke path itu.
12. **`public/favicon.ico` berukuran 0 byte** dan dikecualikan dari Git
    (`.gitignore:74-75`); tidak direferensikan aplikasi.
13. **Rute kandidat Dashboard Vue** masih dipertahankan sementara di
    `routes/web.php` (permukaan bukti internal).
14. **QA command `qa:provision-pm-scenario` membutuhkan akun `admin.prime` dan
    `operator.prime` yang sudah aktif**; akun tidak dibuat otomatis.
15. **Dokumentasi canonical lain belum ada**: `Docs/BUSINESS_RULES.md`,
    `Docs/ARCHITECTURE.md`, `Docs/OPERATIONS.md`, `Docs/modules/`. Isi dan
    status: NOT VERIFIED.
16. **Skema `push_subscriptions` legacy** belum diputuskan untuk dihapus.
17. **Status target operasional / deployment UAT**: NOT VERIFIED. PHPUnit SQLite
    dan QA live historis tidak membuktikan konfigurasi atau migration target.
18. **Checkpoint integrasi 15 November 2026 (historis).** Status PR/Actions dan inventory YAML pada paragraf ini merekam snapshot saat itu, bukan status terbaru; lihat Checkpoint Git 17 November dan `Docs/PRIME_MASTER_BACKLOG.md` F.7. Parent tetap terbuka.

## 9. Prioritas dan dependency terbuka

Kebutuhan, prioritas dan acceptance dimiliki [Master Backlog](PRIME_MASTER_BACKLOG.md#g-roadmapwave--tampilan-prioritas-sekunder); assignment dan urutan handoff dimiliki [Parallel Execution Plan](PRIME_PARALLEL_EXECUTION_PLAN.md#active-workflow). Dokumen status ini tidak membuat antrean implementasi kedua atau mengaktifkan instruksi historis.

Urutan Owner yang tetap dipertahankan sebagai arah, bukan izin eksekusi: query operasional read-only/arsitektur → QA terisolasi dengan reference answers → internet search terverifikasi/UAT tiga sumber → modernisasi Vue/Inertia/NovaSpark dan UAT Operator → handoff serta release readiness. Operational Query Capability Plan OMP 112-08 sudah approved; discovery ulang bukan prasyarat baru. Internet search tidak diklaim terimplementasi.

Dependency terbuka: Gemini Admin recovery/status/history UI; MySQL concurrency; migration/aktivasi/cutover; Qdrant retirement setelah verifikasi consumer; historical PM import; retirement Blade per halaman hanya setelah pengganti diterima. Backend Gemini `fc439ee` dan diagnostik `8d45410` committed pada stream lokal tidak berarti kesiapan operasional; **PENDING** tetap berlaku.

Setiap perubahan kapabilitas wajib disertai bukti (source atau test) sebelum
statusnya dinaikkan di dokumen ini.

## 10. Referensi bukti dan kebijakan pembaruan

### Sumber bukti

| Path | Kegunaan |
|---|---|
| `README.md` | Setup, identitas, kontrak isolasi |
| `specs/product/SCOPE_LATEST.yaml` | Scope inisiatif baseline hardening (historis, tidak ditulis ulang) |
| `composer.json`, `composer.lock` | Dependensi backend dan versi terkunci |
| `package.json`, `package-lock.json` | Dependensi frontend dan versi terkunci |
| `config/app.php`, `config/qdrant.php`, `config/ai.php`, `config/webpush.php`, `config/report.php` | Kontrak konfigurasi |
| `bootstrap/providers.php`, `app/Providers/` | Binding vector store dan guard database |
| `app/Services/AI/` | Ingestion, indexing, retrieval, provider |
| `app/Services/PM/`, `app/Services/Master/`, `app/Services/Breakdown/`, `app/Services/Report/` | Logika domain |
| `routes/web.php` | Permukaan modul dan otorisasi rute |
| `database/migrations/` (36 migrasi pada checkpoint 2026-11-10, termasuk provenance Gemini, generation history, dan `remote_write_evidence` yang belum diterapkan operasional) | Skema dan invariant database |
| `phpunit.xml`, `tests/` | Isolasi test dan cakupan perilaku |
| `.env.example`, `.env.testing.example`, `.gitignore` | Template environment dan eksklusi Git |

### Kebijakan pembaruan

- Perbarui dokumen ini bila kapabilitas, baseline Git, konfigurasi isolasi, atau
  status AI berubah. Catat status Git sebagai observasi checkpoint tertanggal
  (`git status`, `git log`, `git remote -v`, `git ls-remote`); jangan menuliskan
  status working tree sebagai keadaan permanen.
- Jangan menyalin dokumentasi PRIME/Qdrant. Rujuk source di repository ini.
- Jangan menulis secret, data database, atau konteks personal.
- Hasil verifikasi lama tetap berlabel HISTORICAL sampai dijalankan ulang.
- Gunakan `NOT VERIFIED` untuk informasi yang belum dapat dibuktikan; jangan
  menebak.
- Laporan OMP yang dirujuk (mis. 58, 99-08, 100-08, 102-08, 103-08) adalah
  bukti handoff eksternal; file laporan tersebut tidak tersimpan di repository,
  dan jangan mengarang path artefaknya.
- `README.md` tetap menjadi pintu masuk setup; `specs/product/SCOPE_LATEST.yaml`
  tetap menjadi scope inisiatif historis dan tidak diubah dari dokumen ini.
