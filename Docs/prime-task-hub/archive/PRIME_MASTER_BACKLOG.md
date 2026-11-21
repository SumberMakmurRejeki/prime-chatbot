# PRIME — Master Product & Engineering Backlog v4

**Status diperbarui:** 19 November 2026 — M-NEXT-17C lanjutan: DocsWriter17C satu-satunya penulis pengganti tiga dokumen canonical; Manager koordinator/final reviewer, bukan penulis dokumen bersamaan; B telah menyelesaikan inventaris 12 worktree read-only untuk reuse saja; independent replacement QA review read-only setelah snapshot dibekukan. Tidak ada publikasi, source/test edit, cleanup/worktree, DB/runtime atau deployment dari gate ini. Hasil QA replacement belum tersedia saat freeze; jangan menyatakan DONE.
**Baseline dokumen:** v4, 14 November 2026; sebelumnya v3, 13 November 2026; v2, 11 November 2026  
**Project:** PRIME — Preventive Maintenance Management + AI Assistant  
**Stack:** Laravel 13, Vue.js, Inertia.js, MySQL, Gemini API + File Search  
**Strategi:** Data Foundation First → Real Operational Pilot → AI Capabilities → Product Improvement

## A. Cara memakai backlog dan batas bisnis

Dokumen referensi koordinasi IT Engineering Manager. Definisi parent dikelompokkan menurut domain bisnis; wave merupakan tampilan prioritas sekunder. Detail teknis lintas domain dirujuk, bukan dibuat sebagai parent baru.

**Sumber kebenaran:** dokumen ini memiliki kebutuhan, TASK/child, prioritas, scope, acceptance dan status pekerjaan. [Parallel Execution Plan](PRIME_PARALLEL_EXECUTION_PLAN.md#active-workflow) memiliki assignment/ownership/handoff aktif; [Project State](PROJECT_STATE.md#current-state) memiliki baseline, kode terintegrasi vs kandidat lokal, CI/QA dan risiko. Ringkasan status di sini merujuk bukti tersebut, bukan membuat ledger eksekusi kedua. Status M-DOC-01/Low docfix dan Slice-01-Local pada pembaruan sebelumnya kini SUPERSEDED oleh penutupan PR #5/#6 dan M-NEXT-17.

ID backlog lama berprefiks `PR` dimigrasikan menjadi `TASK` dengan nomor tetap 01–22. Contoh: nomor 07 tetap TASK-07, nomor 19 tetap TASK-19. GitHub PR #1, #2, #3, dan #4 adalah nomor publikasi, bukan ID task.

Child memakai format `TASK-nn.nn`, tetap berada dalam cakupan parent dan tidak menambah jumlah 22 parent. Satu child selesai tidak menyelesaikan parent. Tidak ada persentase completion karena belum ada dasar pengukuran yang disetujui.

### A.1 Status dan kekuatan bukti

| Status | Makna |
|---|---|
| NOT STARTED | Belum ada bukti pelaksanaan scope tersebut. |
| PLANNED | Audit/rencana tersedia; implementasi scope belum terbukti. |
| IN PROGRESS | Ada implementasi parsial; acceptance parent belum lengkap. |
| LOCAL VERIFIED | Scope tertentu telah diuji lokal pada kandidat yang disebutkan; bukan independent QA atau merge. |
| INDEPENDENT QA | Scope/kandidat sedang melalui verifikasi independen; bukan otomatis lulus. Hasil QA harus dicatat terpisah. |
| PR OPEN | Kandidat scope tertentu telah dipublikasikan dalam GitHub PR terbuka; belum merge. |
| BLOCKED | Gate/dependensi eksplisit belum tertutup; bukti parsial tetap dicatat. |
| MERGED / DONE | Merge terbukti atau seluruh acceptance scope selesai dan diterima. Approval planning saja tidak menyelesaikan parent. |

SOURCE-VERIFIED berarti source diperiksa, bukan test dijalankan. HISTORICAL berarti hasil/checkpoint terdahulu, tidak dijalankan ulang dalam pembaruan dokumentasi ini. NOT VERIFIED berarti bukti belum tersedia.

Audit selesai ≠ bug selesai; commit ≠ push; push ≠ PR; PR ≠ merge; merge ≠ deployment. DONE baseline juga bukan readiness produksi. Status parent mengikuti seluruh scope, bukan tahap paling maju dari salah satu child.

### A.2 Dua role aplikasi — wajib untuk seluruh acceptance

| Role | Akses yang harus dipertahankan | Batas yang harus ditegakkan |
|---|---|---|
| Admin | Modul administratif, Master Checksheet, Master Action, PM Review, administrasi AI, Input Breakdown | Tidak memiliki akses PM Executor. Administrasi AI tetap tunduk pada izin `manage_ai` yang relevan. |
| Operator | Input Breakdown dan PM Executor | Tidak memiliki akses menu/modul administratif, PM Review, atau Assistant/administrasi AI. |

Assistant tetap Admin-only dan read-only. Label `guest` masih terlihat pada route legacy; itu bukan persetujuan role bisnis ketiga. Penyelarasan legacy harus menjaga dua role di atas, bukan memperluas akses Operator atau Executor Admin.

### A.3 Ownership dan otorisasi kerja

Ownership di setiap task adalah **usulan tipe penanggung jawab**, bukan assignment orang/agent yang disetujui. Utamakan satu owner fullstack per fitur, dengan QA independen dan dukungan spesialis sesuai risiko. Prioritas P2/P3 dan rincian acceptance yang memperjelas judul task adalah usulan koordinasi untuk review Manager, bukan approval scope baru.

Pekerjaan independen boleh paralel. File yang beririsan atau dependensi commit harus dikoordinasikan sebelum coding/integrasi. Perubahan scope dan keputusan bisnis memerlukan approval; backlog ini tidak mengaktifkan worker atau mengizinkan publikasi.

<a id="baseline"></a>
## B. Empat baseline selesai — bukan parent backlog tambahan

| ID baseline | Kemampuan | Status/cakupan bukti yang dipertahankan |
|---|---|---|
| DONE-01 | Dataset demo operasional, termasuk FNC-01 | DONE — Development; bukan data operasional asli. |
| DONE-02 | Knowledge Base + Gemini File Search + verified citation | DONE — Baseline; hardening dan operational cutover tetap terpisah. |
| DONE-03 | Query jumlah dan daftar PM waiting review | DONE — UAT terbatas; bukan seluruh generalized operational query. |
| DONE-04 | Query pengukuran PM FNC-01 terbaru | DONE — UAT terbatas; bukan dukungan semua mesin/parameter. |

Keempatnya dipertahankan sebagai kemampuan baseline historis. Rencana query yang sudah approved dipindahkan ke definisi [TASK-02](#task-02), bukan dihitung sebagai baseline kelima. Gemini tetap memerlukan gate keamanan sebelum penggunaan luas.

## Domain 1 — Master PM Checksheet dan Master Action

<a id="task-07"></a>
### TASK-07 — Simplify Master PM Checksheet & Action

- **Modul/tujuan bisnis:** Master Checksheet dan Master Action; Admin dapat menyiapkan definisi PM yang konsisten dan mudah dipakai tanpa merusak riwayat.
- **Skenario Admin/Operator:** Admin membuat/mengubah checksheet dan Action; Operator menjalankan PM dari master valid, bukan mengelola master.
- **Status parent:** IN PROGRESS. Child TASK-07.01 (validasi/wizard) selesai sesuai scope dan PR #3 MERGED pada 18 November 2026; simplifikasi bisnis Checksheet/Action yang lebih luas (TASK-07.02/.03) tetap PLANNED dan belum selesai.
- **Sudah selesai:** validasi nested checksheet/part/standard/assignment, batas numerik, status Nonaktif, pembersihan mapping Action dan pemulihan input/step error pada TASK-07.01. PR #3 MERGED; bukti Q-06 dan CI pada [Project State](PROJECT_STATE.md#current-state).
- **Sisa scope:** persetujuan Owner atas opsi UX TASK-07.02/.03, lalu scope implementasi kecil per child; review final, checks/integrasi dan merge PR #3 sudah ditutup sesuai TASK-07.01. Jangan redesign Master PM dua kali saat migrasi Vue.
- **Dependensi:** gate [TASK-19](#task-19); koordinasi identitas/status [TASK-17](#task-17) dan migrasi [TASK-14](#task-14), bukan menunggu semua implementasinya.
- **Prioritas:** P1; Wave 1 urutan 3.
- **GitHub/branch/worktree:** [GitHub PR #3](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/3), MERGED 18 November 2026; `fix/master-checksheet-validation`, head kandidat `a9929fe`, merge/main `d2588cec2b45f5b30d8182b3bb43b364432efe79`; worktree `fix-master-checksheet-validation` pada ledger F.2 (branch/HEAD kandidat dipreservasi).
- **Tindakan berikutnya:** TASK-07.02/.03 simplifikasi UX tetap PLANNED dan perlu keputusan Owner; jangan membuka ulang coding/validasi/QA/integrasi PR #3 yang sudah diterima. Catatan historis: status review-policy pra-merge tidak diverifikasi langsung; bukan bukti gate approval wajib atau dasar membuka kembali merge yang selesai.
- **Usulan ownership:** fullstack domain Master PM; QA independen dan review backend validasi.

**Child dan batas deliverable:**

| Child | Scope/status | Acceptance khusus |
|---|---|---|
| TASK-07.01 | Validasi/wizard — DONE sesuai scope; PR #3 MERGED, CI pasca-merge PASS | Payload tidak valid ditolak; error menuju step yang tepat; Action → Number/Range tidak menyisakan selection state; kandidat final direview dan checks dicatat. Merge `d2588ce`, head `a9929fe`, basis `7e90945`; Q-06 internal bukan formal GitHub approval; parent TASK-07 tetap IN PROGRESS. |
| TASK-07.02 | Simplifikasi UX Checksheet — PLANNED, detail perlu disepakati | Admin menyelesaikan create/edit pada alur yang disetujui; error dan input terjaga; tidak ada regresi riwayat/jadwal. |
| TASK-07.03 | Simplifikasi UX Master Action — PLANNED, detail perlu disepakati | Admin mengelola Action pada alur yang disetujui; mapping checksheet dan hasil PM lama tetap benar. |

**PM scheduling:** Slice 1 inactive-start guard DONE/MERGED melalui PR #5 (`6e79fd462e96dfff7ae232484e7102cfe2590716`); detail QA/CI/fingerprint pada [Project State](PROJECT_STATE.md#current-state), bukan kandidat lokal lagi. Slice 2 PARTIAL: currently-PAUSED aging/notification DONE/MERGED/QA READY melalui PR #6, tetapi temporal no-backlog-after-Resume NOT IMPLEMENTED. Slice 3 rolling horizon; Slice 4 Save Master/Apply Jadwal (kontrak ada); Slice 5 simplifikasi UI—belum diimplementasikan. Keputusan PAUSED yang disetujui: kewajiban baru selama pause ditangguhkan tanpa backlog/overdue/missed palsu saat Resume, histori dan running execution terlindungi, mesin ACTIVE lain tetap berjalan. OPEN: [P,R), pause+resume hari sama, boundary PAUSED legacy, actionability overdue pra-pause, provenance temporal existing vs new. Jangan inferensikan resolusi bisnis dari rekomendasi QA; lihat [rencana aktif](PRIME_PARALLEL_EXECUTION_PLAN.md#active-workflow). Ini bukan child TASK baru atau completion TASK-07.02/.03.

**Acceptance parent:**
- Admin dapat membuat dan mengubah checksheet valid; kesalahan input ditampilkan jelas.
- Pengaturan status sesuai keputusan Admin; hasil numerik mempunyai batas dan satuan yang tepat.
- Riwayat PM lama tidak berubah akibat revisi master; workflow Operator dan review Admin tidak mengalami regresi.
- Seluruh child diterima, scope bisnis tersisa selesai, serta gate review/integrasi ditutup. Pengujian terfokus bukan UAT seluruh Operator atau readiness produksi.

## Domain 2 — PM Execution, Review dan Operational Data

<a id="task-17"></a>
### TASK-17 — Data Quality & Machine Identity

- **Modul/tujuan bisnis:** identitas mesin, PM Execution/Review, laporan dan export; cegah error operasional serta data salah sebelum merapikan tampilan.
- **Skenario Admin/Operator:** Operator mengisi PM/Breakdown pada mesin yang benar; Admin mencari, memfilter, mengurutkan dan mereview seluruh hasil, bukan hanya halaman yang sedang dimuat.
- **Status parent:** BLOCKED. Kandidat PM Review masuk integrasi lokal M-01; Q-02 code/tests gabungan telah diterima. Parent belum selesai karena browser/operasional, rekonsiliasi inventory YAML, temuan di luar scope dan performa MySQL masih terbuka.
- **Sudah selesai:** historical/current identity, snapshot lokasi, legacy, filter/sort/pagination dan batas akses pada kandidat lokal. Bukti review/test kandidat asal dan integrasi M-01 dipertahankan pada [Project State](PROJECT_STATE.md#current-state) dan arsip F; bukan bukti main atau readiness produksi.
- **Sisa scope:** inventory YAML/artefak; Action `"0"` pada report dan temuan data/URL/SQL di luar manifest; browser/operasional dan performa MySQL. Q-02 yang sudah diterima tidak dibuka ulang tanpa perubahan kandidat.
- **Dependensi:** kandidat berbasis main dan gate [TASK-19](#task-19); sinkron definisi dengan [TASK-20](#task-20). Tidak perlu foundation Gemini untuk patch Review berbasis main.
- **Prioritas:** P2 pelaksanaan terkontrol; Wave 1 urutan 2; bug penyebab error/data salah didahulukan.
- **GitHub/branch/worktree:** kandidat lokal `fix/pm-review-data-quality-origin-main`, HEAD `277d7251da2ea9768bef480927223b8a502ca456`, dengan enam file kandidat yang dilaporkan plus perubahan lokal di luar enam file dan untracked harness/artefak. Inventory sebelumnya tidak lengkap; bukan clean commit dan PR kandidat belum diverifikasi.
- **Tindakan berikutnya:** tutup prerequisite browser M-01 melalui approval environment terpisah; rekonsiliasi inventory/scope file di luar manifest dan verifikasi MySQL hanya setelah diotorisasi.
- **Usulan ownership:** fullstack PM Review/data quality; QA independen, dukungan backend/data.

**Acceptance:**
- Kode mesin yang diterima dapat dipakai pada URL/QR atau ditolak dengan error yang jelas.
- Kombinasi filter/sort Review bekerja pada seluruh hasil dan semua halaman tanpa SQL ambigu; tie-break/pagination konsisten.
- Assignment mesin nonaktif, snapshot laporan dan nilai Action `"0"` mempertahankan makna bisnis yang benar.
- Status execution tidak disamakan dengan status occurrence; perubahan identitas live tidak menulis ulang snapshot historis.
- Temuan audit ditutup dengan bukti scope masing-masing; independent QA/integrasi selesai. Benchmark SQLite sintetis bukan klaim performa MySQL produksi.

<a id="task-20"></a>
### TASK-20 — Operational Data Classification & Taxonomy

- **Modul/tujuan bisnis:** kategori/status PM, Breakdown dan laporan; data operasional dapat dibandingkan tanpa interpretasi yang saling bertentangan.
- **Skenario Admin/Operator:** Operator mencatat kondisi/tindakan sesuai definisi; Admin memahami kategori dan status yang sama saat Review/laporan.
- **Status parent:** PLANNED — audit selesai, implementasi koreksi belum terbukti.
- **Sudah selesai:** audit kategori dan status pada PM, Breakdown serta laporan.
- **Sisa scope:** identifikasi inkonsistensi yang benar-benar memengaruhi workflow, perbaiki mapping/interpretasi bermasalah, tambah regression yang bermakna.
- **Dependensi:** kontrak execution/occurrence dan snapshot [TASK-17](#task-17); hasil menjadi acuan [TASK-22](#task-22), [TASK-08](#task-08), [TASK-03](#task-03).
- **Prioritas:** P2; Wave 1 urutan 4.
- **GitHub/branch/worktree:** belum ada kandidat implementasi khusus yang diverifikasi; audit bukan bukti coding.
- **Tindakan berikutnya:** pilih satu inkonsistensi berdampak nyata dan sepakati mapping serta acceptance sebelum coding.
- **Usulan ownership:** fullstack data operasional dengan validasi domain maintenance dan QA.

**Kategori awal yang tetap diusulkan:** jenis masalah/gejala; komponen terdampak; penyebab belum diketahui/investigasi/terkonfirmasi; jenis tindakan maintenance; hasil pemeriksaan menurut definisi checksheet.

**Acceptance:** kategori/status yang dipakai workflow dipetakan dan disetujui; input, Review dan laporan memakai makna yang sama; data tidak diketahui tetap eksplisit; regression membuktikan koreksi. Jangan membuat taxonomy engine/menu kompleks tanpa kebutuhan data nyata.

<a id="task-22"></a>
### TASK-22 — Real Operational Data Pilot & Mapping

- **Modul/tujuan bisnis:** pilot data maintenance asli; membuktikan struktur PRIME cocok dengan pekerjaan nyata, bukan hanya demo.
- **Skenario Admin/Operator:** Admin memvalidasi riwayat satu mesin pilot; Operator mencatat operasional baru yang dapat dibedakan dari demo/import.
- **Status parent:** NOT STARTED — NEW pada v3; belum ada bukti pilot asli selesai.
- **Sudah selesai:** dataset demo [DONE-01](#baseline) tersedia, bukan penyelesaian pilot asli.
- **Sisa scope:** pemilihan mesin, pemeriksaan riwayat, mapping sumber dan persetujuan hasil pilot.
- **Dependensi:** fondasi [TASK-07](#task-07), [TASK-17](#task-17), [TASK-20](#task-20); dokumen sesuai mesin [TASK-21](#task-21); mapping mengarahkan [TASK-08](#task-08).
- **Prioritas:** P2; Wave 2 urutan 6.
- **GitHub/branch/worktree:** belum ada kandidat pilot asli yang diverifikasi.
- **Tindakan berikutnya:** minta penetapan satu mesin pilot dan akses sumber riwayat yang disetujui, tanpa mutasi database runtime.
- **Usulan ownership:** fullstack data operasional bersama pemilik data maintenance; QA menjaga isolasi.

**Acceptance:** satu mesin pilot dipilih; riwayat PM asli diperiksa; mapping ke PRIME terdokumentasi; data tidak lengkap ditandai, bukan dikarang; demo/import historis/operasional baru dapat dibedakan; pengujian tidak mencemari database produksi.

## Domain 3 — Historical PM Import

<a id="task-08"></a>
### TASK-08 — Historical PM CSV/Excel Import

- **Modul/tujuan bisnis:** import riwayat PM; menghadirkan data lama yang dapat ditelusuri tanpa memalsukan status digital atau jadwal.
- **Skenario Admin/Operator:** Admin mempreview dan mengimpor file yang disetujui; hasil Operator baru tidak tertimpa atau dianggap sama dengan legacy.
- **Status parent:** NOT STARTED — belum ada implementasi importer yang diverifikasi.
- **Sudah selesai:** kebutuhan import tercatat; bukan bukti importer tersedia.
- **Sisa scope:** template CSV/XLSX, validasi, preview, duplikasi, batch audit dan provenance.
- **Dependensi:** mapping pilot [TASK-22](#task-22), identitas/snapshot [TASK-17](#task-17), klasifikasi [TASK-20](#task-20), gate [TASK-19](#task-19).
- **Prioritas:** P2; Wave 2 urutan 7.
- **GitHub/branch/worktree:** belum ada kandidat importer yang diverifikasi.
- **Tindakan berikutnya:** gunakan mapping pilot untuk menyepakati template dan aturan duplikasi/status legacy.
- **Usulan ownership:** fullstack import/data, dukungan backend transaksi dan QA.

**Acceptance:** CSV/XLSX mengikuti template approved; kode mesin, tanggal, parameter, nilai dan satuan divalidasi; ada preview sebelum import, deteksi duplikasi dan batch audit; provenance terlacak; status legacy tidak otomatis approved digital; jadwal historis tidak diciptakan ulang secara keliru.

## Domain 4 — Gemini Provider dan AI Configuration

<a id="task-01"></a>
### TASK-01 — Gemini API Security & Configuration Hardening

- **Modul/tujuan bisnis:** provider Gemini, credential dan transport; penggunaan dokumen/kredensial operasional tidak membocorkan secret atau mengakses tujuan jaringan yang tidak aman.
- **Skenario Admin/Operator:** Admin berizin mengatur/merevisi provider dan menggunakan Assistant; Operator tidak memperoleh akses administrasi AI.
- **Status parent:** BLOCKED — hardening dan dependency telah diintegrasikan lokal pada M-01; Q-02 code/tests gabungan diterima. Browser/operasional, keputusan LOW yang belum ditutup secara eksplisit, CI kandidat rilis dan acceptance parent tetap terbuka; bukan DONE.
- **Sudah selesai:** secure Text/File/Store transport, endpoint-bound credentials dan normalisasi Base URL berada pada kandidat gabungan lokal. Bukti historis 64/522 dan integrasi 233/1.816 dibedakan pada [Project State](PROJECT_STATE.md#current-state) serta arsip M-01.
- **Sisa scope:** acceptance browser/operasional, CI kandidat rilis, keputusan LOW image mitigation dan verifikasi foundation pada target rilis. Tidak ada operational activation.
- **Dependensi:** history foundation Gemini yang tepat, termasuk backend lifecycle/recovery; bukan sekadar agent foundation GitHub PR #1. Gate [TASK-19](#task-19); koordinasi kesesuaian credential/retrieval dengan [TASK-21](#task-21), bukan ketergantungan implementasi dua arah.
- **Prioritas:** P1; Wave 1 urutan 1; penggunaan credential/dokumen asli tetap digate.
- **GitHub/branch/worktree:** `fix/gemini-provider-security`, HEAD `1076689df2cd25f3351d0b07045a30cb00524d58`, lima file modified: request dan service provider, Assistant Blade, serta dua suite test. Basis integrasi rekomendasi `c6dc1be28fba22fa4dba8382891ac070c51e76c7` pada checkout root yang dirty. PR security belum diverifikasi.
- **Tindakan berikutnya:** gunakan kandidat M-01 frozen dan Q-02 yang diterima; tutup gate tersisa tanpa membuat ulang hardening atau mengulang independent code review pada snapshot identik.
- **Usulan ownership:** satu integrator yang ditetapkan Owner dengan reviewer security dan QA independen.

**Temuan audit yang wajib tetap dilacak:**

| Risiko | Temuan asli | Kondisi rekonsiliasi |
|---|---|---|
| HIGH | Generasi jawaban Gemini belum memakai pengamanan transport yang konsisten dengan File Search | Fix berada pada M-01 lokal; Q-02 diterima untuk snapshot gabungan. Operational/release acceptance tetap terbuka. |
| MEDIUM | API key tersimpan berisiko dipakai kembali saat endpoint berubah | Endpoint-bound reuse berada pada kandidat gabungan yang direview; bukan bukti deployment atau aktivasi operasional. |
| LOW | Render Markdown dapat memuat gambar eksternal | Tetap PENDING REVIEW; tidak dianggap tertutup oleh fix HIGH/MEDIUM. |

**Acceptance:** HIGH/MEDIUM ditutup dan diverifikasi sebelum Gemini digunakan luas dengan dokumen/credential asli; HTTPS/public-host/DNS pinning dan penolakan redirect/IP berbahaya berlaku pada jalur relevan; reuse credential aman; perubahan lokal/provenance terjaga; LOW ditinjau dan keputusan dicatat.

## Domain 5 — Knowledge Documents dan SOP Governance

<a id="task-21"></a>
### TASK-21 — Real SOP/WI Governance & Machine Applicability

- **Modul/tujuan bisnis:** registry, versi/publikasi SOP/WI dan applicability mesin; jawaban memakai dokumen asli yang memang berlaku.
- **Skenario Admin/Operator:** Admin/pemilik dokumen menyetujui versi dan mesin sasaran; Operator tetap tidak masuk menu knowledge/administrasi AI.
- **Status parent:** NOT STARTED untuk governance SOP/WI asli. Baseline retrieval/publikasi teknis sudah ada, bukan bukti governance data asli selesai.
- **Sudah selesai:** [DONE-02](#baseline), backend lifecycle dan citation trust historis; snapshot status pada PROJECT_STATE bukan operational cutover.
- **Sisa scope:** approval pemilik, identitas/versi/status berlaku, applicability mesin, pemisahan dokumen aktif/historis; kebutuhan UI status/history/recovery dan verifikasi operasional tetap terbuka.
- **Dependensi:** keamanan [TASK-01](#task-01) sebelum penggunaan dokumen asli; release gate [TASK-19](#task-19). Koordinasi applicability dengan pilot [TASK-22](#task-22) dan traceability [TASK-18](#task-18) boleh paralel, bukan blocker dua arah.
- **Prioritas:** P2; Wave 2 urutan 5.
- **GitHub/branch/worktree:** baseline di history Gemini; perubahan knowledge lokal pada checkout `feature/gemini-file-search` belum boleh dianggap kandidat terintegrasi.
- **Tindakan berikutnya:** pilih SOP/WI asli dengan pemilik dokumen dan bukti applicability setelah gate keamanan jelas.
- **Usulan ownership:** fullstack knowledge governance dengan pemilik SOP/WI dan QA independen.

**Acceptance:** SOP/WI asli disetujui pemilik; keamanan Gemini diverifikasi; identitas, versi, status berlaku dan cakupan mesin jelas; SOP tidak dipilih hanya dari kemiripan nama mesin; dokumen sekarang dibedakan dari arsip; retrieval hanya published/eligible dengan sitasi terverifikasi.

Indexing bukan publikasi. Registry MySQL/storage privat tetap sumber kebenaran; indeks Gemini state turunan. Recovery dengan bukti remote ambigu tidak mengizinkan unggah ulang buta atau penghapusan otomatis resource Google.

<a id="task-12"></a>
### TASK-12 — SOP/WI Revision Comparison

- **Modul/tujuan bisnis:** perbandingan revisi dokumen; Admin memahami perubahan instruksi tanpa mencampur status berlaku.
- **Skenario Admin/Operator:** Admin membandingkan revisi yang diizinkan; Operator tidak memperoleh akses Assistant/admin dokumen.
- **Status parent:** NOT STARTED.
- **Sudah selesai:** registry/version baseline tersedia; kemampuan membandingkan revisi belum dibuktikan.
- **Sisa scope:** kebutuhan perbandingan, sumber kedua revisi, hasil bersitasi dan batas penggunaan arsip.
- **Dependensi:** governance [TASK-21](#task-21), traceability [TASK-18](#task-18), keamanan [TASK-01](#task-01). Urutan Wave 6 setelah Vision adalah prioritas, bukan ketergantungan teknis otomatis.
- **Prioritas:** P3; Wave 6 urutan 20; lanjut hanya bila manfaat/kebutuhan pengguna nyata terbukti.
- **GitHub/branch/worktree:** belum ada kandidat perbandingan yang diverifikasi.
- **Tindakan berikutnya:** kumpulkan satu contoh kebutuhan perubahan revisi dan sepakati acceptance dengan pemilik SOP.
- **Usulan ownership:** fullstack knowledge/Assistant bersama pemilik dokumen dan QA.

**Acceptance:** perbandingan menunjuk kode/versi dan bukti masing-masing revisi; perubahan dapat diperiksa pada sumber; arsip tidak dipresentasikan sebagai SOP yang berlaku; bukti tidak cukup dinyatakan, bukan dikarang.

## Domain 6 — AI Assistant dan Operational Database Query

### Kontrak bersama semua task AI

- Assistant tetap Admin-only dan read-only; query dijalankan Laravel, bukan SQL bebas dari Gemini.
- Data PM memuat identitas sumber, tanggal, status dan satuan; SOP harus published, eligible dan bersitasi terverifikasi.
- Kegagalan query tidak dilaporkan sebagai nol; data tidak lengkap harus dinyatakan.
- Approval PM tidak otomatis berarti mesin aman; KPI tidak boleh mengklaim MTBF/OEE tanpa data memadai.
- Typed intent dan query Laravel terkontrol adalah pendekatan dasar. Tidak membangun autonomous agent loop atau perubahan maintenance otomatis.

<a id="task-02"></a>
### TASK-02 — Operational Query Capability Plan OMP 112-08

- **Modul/tujuan bisnis:** perencanaan query operasional; development mengikuti rencana approved, bukan discovery berulang.
- **Skenario Admin/Operator:** pertanyaan Admin dipetakan ke query read-only; data yang dicatat Operator tidak dimutasi Assistant.
- **Status parent:** PLANNED — planning approved. Approval OMP 112-08 adalah bukti planning selesai, bukan completion parent, merge GitHub atau implementasi seluruh query.
- **Sudah selesai:** Operational Query Capability Plan OMP 112-08 tercatat approved pada backlog asli.
- **Sisa scope:** tidak ada re-planning parent ini. Implementasi dan pembuktian berada pada [TASK-03](#task-03), [TASK-05](#task-05), [TASK-18](#task-18).
- **Dependensi:** artefak handoff OMP 112-08 perlu tersedia bagi pelaksana; tidak ditemukan sebagai file repo pada pencarian dokumen/scope saat ini.
- **Prioritas:** referensi wajib sebelum coding query; prasyarat historis Wave 3, bukan antrean pekerjaan baru.
- **GitHub/branch/worktree:** bukti planning eksternal OMP 112-08; tidak dipetakan secara keliru ke GitHub PR #2.
- **Tindakan berikutnya:** ambil kembali handoff approved untuk pelaksana TASK-03 tanpa menyusun rencana pengganti.
- **Usulan ownership:** fullstack operational-query lead sebagai pengguna rencana; Manager menjaga keputusan approved.

**Acceptance:** rencana approved dipakai kembali; batas read-only/role dan peta query tetap dilacak; approval planning tidak dianggap completion parent atau implementasi. Lokasi artefak eksternal belum diverifikasi, bukan alasan menghapus bukti approved historis.

<a id="task-03"></a>
### TASK-03 — Reusable Operational Query Tools

- **Modul/tujuan bisnis:** query database operasional reusable; Admin memperoleh fakta maintenance terkontrol dari data nyata.
- **Skenario Admin/Operator:** Admin menanyakan antrean Review, jadwal, hasil PM dan Breakdown; Operator tetap hanya menjalankan alur operasional yang diizinkan.
- **Status parent:** IN PROGRESS — baseline query terbatas ada, generalized tools belum selesai.
- **Sudah selesai:** DONE-03/DONE-04; source lokal untracked `OperationalPmAssistantAnswerService.php` beserta binding di `AppServiceProvider.php` menangani pending Review dan FNC-01. Source bukan bukti QA terbaru atau generalisasi seluruh mesin.
- **Sisa scope:** generalized PM review count/list/oldest dengan mesin/lokasi/periode; schedule upcoming/overdue/missed; execution terbaru/detail item; Breakdown jumlah insiden/mesin unik/open-closed/frekuensi; historical measurements dan basic KPI.
- **Dependensi:** rencana [TASK-02](#task-02), kontrak data [TASK-17](#task-17)/[TASK-20](#task-20), pilot [TASK-22](#task-22), security [TASK-01](#task-01) untuk jawaban gabungan SOP, gate [TASK-05](#task-05).
- **Prioritas:** P2; Wave 3 urutan 8.
- **GitHub/branch/worktree:** implementasi terbatas pada checkout dirty `feature/gemini-file-search`; PR generalized-query belum diverifikasi.
- **Tindakan berikutnya:** gunakan OMP 112-08 untuk menuntaskan satu query generalized PM Review berikut reference answer, bukan menulis ulang baseline.
- **Usulan ownership:** fullstack Assistant/operational query, QA berbasis reference answers.

**Acceptance:** setiap tahap query menjawab fakta yang benar untuk scope/filter yang diminta; nol dibedakan dari error; sumber/tanggal/status/satuan terlacak; kontrak bersama AI dipenuhi. Tahapan boleh incremental tanpa menunggu semuanya, tetapi parent baru DONE setelah seluruh scope diterima.

<a id="task-18"></a>
### TASK-18 — AI Source Traceability & Deep Links

- **Modul/tujuan bisnis:** bukti jawaban dan tautan record/dokumen; Admin dapat memeriksa dasar jawaban.
- **Skenario Admin/Operator:** Admin membuka sumber PM/SOP dari jawaban sesuai izin; tautan tidak membuka menu admin untuk Operator.
- **Status parent:** IN PROGRESS — PARTIAL pada v2/v3; belum ada bukti penyelesaian baru seluruh scope.
- **Sudah selesai:** verified citation baseline SOP serta ID record pada query PM terbatas; bukan seluruh deep link bisnis.
- **Sisa scope:** deep link operasional dan dokumen yang konsisten serta pemeriksaan akses, record/versi tidak tersedia dan konteks historis.
- **Dependensi:** [TASK-03](#task-03), [TASK-21](#task-21), kontrak role serta [TASK-05](#task-05).
- **Prioritas:** P2; Wave 3 urutan 9.
- **GitHub/branch/worktree:** baseline/partial berada pada stream Gemini; kandidat penyelesaian khusus belum diverifikasi.
- **Tindakan berikutnya:** pilih satu jawaban query yang sudah ada dan buktikan navigasi Admin ke record sumber yang tepat.
- **Usulan ownership:** fullstack Assistant/source navigation, QA otorisasi.

**Acceptance:** fakta dapat ditelusuri ke record atau kode/versi dokumen yang benar; tautan mematuhi role; sumber hilang/tidak eligible dinyatakan tanpa fabrikasi; tanggal, status dan satuan tetap jelas.

<a id="task-05"></a>
### TASK-05 — AI End-to-End UAT & Regression

- **Modul/tujuan bisnis:** pembuktian jawaban database + SOP end-to-end; penerimaan didasarkan pada jawaban bisnis, bukan hanya request sukses.
- **Skenario Admin/Operator:** Admin membandingkan jawaban Assistant dengan reference answers; Operator ditolak dari endpoint AI dan alur PM-nya tetap berjalan.
- **Status parent:** IN PROGRESS — PARTIAL / ONGOING; UAT terbatas tidak menutup seluruh regresi AI.
- **Sudah selesai:** UAT baseline FNC-01 dan query terbatas; bukti historis dipisahkan pada F.3.
- **Sisa scope:** UAT query generalized, gabungan data asli + SOP, negative/abstention, permission dan kegagalan provider/query; regression sesuai risiko.
- **Dependensi:** tersedia kandidat uji [TASK-01](#task-01)/[TASK-03](#task-03)/[TASK-18](#task-18)/[TASK-21](#task-21), tidak harus menunggu parent semuanya DONE. Data pilot [TASK-22](#task-22) untuk UAT data asli; hasil menjadi input release [TASK-19](#task-19).
- **Prioritas:** P2; Wave 3 urutan 10 dan gate setiap peningkatan AI.
- **GitHub/branch/worktree:** evidence lintas stream; belum ada bukti UAT keseluruhan pada satu SHA kandidat final.
- **Tindakan berikutnya:** susun cakupan QA terhadap kandidat nyata dan reference answers dari rencana existing, lalu QA independen terisolasi.
- **Usulan ownership:** QA independen sebagai verifier; fullstack AI menutup temuan dan pemilik bisnis menerima hasil.

**Acceptance:** jawaban, sitasi, abstention dan denial akses cocok dengan reference answers/kontrak; query gagal bukan nol; data tidak lengkap dinyatakan; hasil menyebut SHA/runtime/data scope; full acceptance dan approval tercatat tanpa mengekstrapolasi UAT terbatas.

<a id="task-16"></a>
### TASK-16 — AI Observability & Diagnostics

- **Modul/tujuan bisnis:** diagnosis kegagalan AI; Admin/support membedakan kegagalan provider, query dan retrieval tanpa membocorkan credential.
- **Skenario Admin/Operator:** Admin mendapat kegagalan yang jelas dan support dapat menelusurinya; Operator tidak diberi panel AI administratif.
- **Status parent:** IN PROGRESS — diagnostik parsial, belum diverifikasi menyeluruh.
- **Sudah selesai:** logging aman kegagalan Assistant pada commit lokal historis `8d45410`; bukan bukti akar penyebab error Gemini intermiten.
- **Sisa scope:** observability jalur query/retrieval/provider dan pembuktian informasi diagnostik cukup untuk operasi; jangan menganggap logging berarti reliability selesai.
- **Dependensi:** kontrak error [TASK-01](#task-01)/[TASK-03](#task-03), pembuktian [TASK-05](#task-05), recovery/release [TASK-19](#task-19).
- **Prioritas:** P2; Wave 3 urutan 11.
- **GitHub/branch/worktree:** `8d45410` berada di history Gemini lokal; kandidat penyelesaian seluruh observability belum diverifikasi.
- **Tindakan berikutnya:** petakan satu kegagalan end-to-end ke kategori log aman dan pesan Admin yang dapat ditindaklanjuti.
- **Usulan ownership:** fullstack AI dengan dukungan operability/security dan QA.

**Acceptance:** kegagalan dibedakan secara benar, bukti diagnosis terlacak, log tidak memuat secret/data sensitif yang tidak perlu, dan tidak ada error dipresentasikan sebagai hasil bisnis kosong/sukses.

<a id="task-09"></a>
### TASK-09 — AI Multi-turn Context

- **Modul/tujuan bisnis:** konteks percakapan; pertanyaan lanjutan Admin memakai scope mesin/periode yang benar.
- **Skenario Admin/Operator:** Admin bertanya lanjutan pada percakapannya sendiri; Operator tidak memperoleh akses chat.
- **Status parent:** NOT STARTED untuk multi-turn semantic context.
- **Sudah selesai:** penyimpanan/history percakapan baseline bukan bukti pemahaman pertanyaan lanjutan.
- **Sisa scope:** referensi konteks lintas giliran, perubahan/ambiguity scope dan pembuktian isolasi percakapan.
- **Dependensi:** query/bukti stabil [TASK-03](#task-03)/[TASK-18](#task-18), QA [TASK-05](#task-05).
- **Prioritas:** P3; Wave 4 urutan 12.
- **GitHub/branch/worktree:** belum ada kandidat semantic multi-turn yang diverifikasi.
- **Tindakan berikutnya:** pilih skenario follow-up mesin/periode nyata dan sepakati perilaku saat ambigu.
- **Usulan ownership:** fullstack Assistant conversation dengan QA.

**Acceptance:** follow-up mempertahankan scope yang benar, perpindahan konteks tidak memakai data stale, ambiguity dinyatakan dan konteks tidak bocor antar Admin/percakapan; bukti sumber tetap tersedia.

<a id="task-10"></a>
### TASK-10 — Maintenance Advice & Prioritization

- **Modul/tujuan bisnis:** saran maintenance berbasis bukti; membantu Admin memprioritaskan tindak lanjut tanpa mengotomatisasi keputusan keselamatan.
- **Skenario Admin/Operator:** Admin membaca saran dari fakta PM + SOP; Operator tetap menjalankan instruksi operasional yang disetujui, bukan perintah otomatis AI.
- **Status parent:** IN PROGRESS — PARTIAL historis; belum ada bukti rekomendasi generalized diterima.
- **Sudah selesai:** jawaban baseline SOP dan analisis FNC-01 terbatas mendukung pembacaan bukti, bukan engine prioritas lengkap.
- **Sisa scope:** rekomendasi/prioritas dengan alasan terverifikasi, batas data dan pemisahan fakta dari saran.
- **Dependensi:** [TASK-03](#task-03)/[TASK-18](#task-18)/[TASK-21](#task-21); [TASK-09](#task-09) untuk pengalaman follow-up, bukan semua saran single-turn.
- **Prioritas:** P3; Wave 4 urutan 13.
- **GitHub/branch/worktree:** belum ada kandidat penyelesaian advice/prioritization yang diverifikasi.
- **Tindakan berikutnya:** validasi satu kebutuhan prioritas maintenance dengan pemilik bisnis dan reference evidence.
- **Usulan ownership:** fullstack AI bersama ahli maintenance dan QA.

**Acceptance:** saran menjelaskan bukti PM/SOP dan keterbatasan data; fakta dibedakan dari rekomendasi; approval PM tidak diartikan mesin aman; tidak ada perubahan maintenance otomatis atau klaim MTBF/OEE tanpa data memadai.

<a id="task-04"></a>
### TASK-04 — Gemini Structured Outputs & Function Calling

- **Modul/tujuan bisnis:** kontrak output/intent terstruktur bila bermanfaat; bukan syarat tambahan untuk menghambat query Laravel terkontrol.
- **Skenario Admin/Operator:** Admin memperoleh jawaban query konsisten; tidak ada perluasan akses Operator atau tindakan tulis oleh model.
- **Status parent:** PLANNED — conditional; implementasi belum terbukti.
- **Sudah selesai:** keputusan baseline memakai typed intent/query Laravel terkontrol, bukan function calling wajib.
- **Sisa scope:** buktikan manfaat terukur, sepakati kontrak dan implementasikan hanya bila dipilih.
- **Dependensi:** evaluasi kebutuhan nyata [TASK-03](#task-03)/[TASK-10](#task-10), keamanan [TASK-01](#task-01), QA [TASK-05](#task-05).
- **Prioritas:** P3, bersyarat; Wave 4 urutan 14; bukan prasyarat seluruh query.
- **GitHub/branch/worktree:** belum ada kandidat yang diverifikasi.
- **Tindakan berikutnya:** bandingkan kebutuhan nyata dengan typed intent existing; minta keputusan manfaat sebelum coding.
- **Usulan ownership:** fullstack AI/query dengan reviewer kontrak/security.

**Acceptance:** bila dipilih, output tervalidasi terhadap kontrak, tool hanya allowlist Laravel read-only, invalid intent tidak menjadi SQL bebas; manfaat terbukti dan tidak ada autonomous agent loop. Keputusan tidak memakai function calling dicatat, bukan diklaim implementasi DONE.

<a id="task-11"></a>
### TASK-11 — AI Vision for Maintenance Photos

- **Modul/tujuan bisnis:** bantuan membaca foto maintenance bila kebutuhan nyata terbukti; tidak menggantikan penilaian keselamatan.
- **Skenario Admin/Operator:** Admin menilai foto maintenance yang diizinkan; ini tidak membuka menu AI bagi Operator.
- **Status parent:** NOT STARTED.
- **Sudah selesai:** media maintenance baseline bukan kemampuan Vision yang terverifikasi.
- **Sisa scope:** kebutuhan foto/privasi, kontrak analisis, batas ketidakpastian dan QA.
- **Dependensi:** security [TASK-01](#task-01), bukti/saran [TASK-18](#task-18)/[TASK-10](#task-10), QA [TASK-05](#task-05).
- **Prioritas:** P3; Wave 6 urutan 19; hanya setelah kebutuhan/manfaat terbukti.
- **GitHub/branch/worktree:** belum ada kandidat Vision yang diverifikasi.
- **Tindakan berikutnya:** sepakati satu skenario foto dan dataset evaluasi yang boleh digunakan.
- **Usulan ownership:** fullstack AI/media dengan ahli maintenance, security dan QA.

**Acceptance:** hanya media berizin diproses; hasil mengacu foto yang benar, menyatakan ketidakpastian dan batas keselamatan, tidak mengarang diagnosis/izin operasi; manfaat divalidasi sebelum acceptance fitur.

## Domain 7 — UI/UX, Navigation dan Vue Migration

<a id="task-06"></a>
### TASK-06 — AI Menu & URL Consolidation

- **Modul/tujuan bisnis:** navigasi Assistant, AI settings dan knowledge; Admin menemukan alur AI yang konsisten.
- **Skenario Admin/Operator:** Admin mengakses menu AI sesuai izin; Operator hanya melihat menu yang diizinkan, bukan administrasi.
- **Status parent:** IN PROGRESS — paket Pengaturan User dan navigasi sudah masuk M-01 lokal; Q-02 code/tests gabungan diterima. Browser/UI, kontrak URL/deep link dan acceptance keseluruhan belum selesai; `UI_NOT_VERIFIED` bukan DONE parent.
- **Sudah selesai:** pengelolaan `manage_ai` melalui form izin terpisah di Pengaturan User serta parity sidebar Vue/Blade pada kandidat lokal. Bukti suite asal dan gabungan tercatat pada [Project State](PROJECT_STATE.md#current-state) dan arsip M-01; false positive sidebar Operator tidak dibuka ulang tanpa jalur reachable baru.
- **Sisa scope:** browser/UI dan deep link/permission acceptance pada M-01; konsolidasi TASK-06 lainnya. Independent code review Q-02 bukan lagi blocker pada kandidat identik.
- **Dependensi:** role A.2, traceability [TASK-18](#task-18), koordinasi [TASK-14](#task-14); tidak menghapus workflow registry karena dianggap sekadar retrieval Gemini.
- **Prioritas:** P3; Wave 5 urutan 15.
- **GitHub/branch/worktree:** `feat/ai-navigation-consolidation`, checkpoint `c6dc1be28fba22fa4dba8382891ac070c51e76c7` dengan perubahan lokal; kandidat bukan clean commit. Lihat usulan integrasi pada bagian M-01.
- **Tindakan berikutnya:** penuhi prerequisite environment dan approval browser M-01, lalu verifikasi akses/deep link pada kandidat frozen; jangan ulangi integrasi atau Q-02 tanpa perubahan scope.
- **Usulan ownership:** fullstack navigasi/AI UX dengan QA akses.

**Acceptance:** menu/URL konsisten dan tidak menduplikasi alur; deep link yang diterima menuju sumber benar; backend tetap menolak akses tidak sah; workflow Admin/Operator tidak regresi.

<a id="task-14"></a>
### TASK-14 — Blade to Vue/Inertia Migration

- **Modul/tujuan bisnis:** modernisasi UI bertahap dengan design system NovaSpark; menjaga parity bisnis, bukan sekadar mengganti template.
- **Skenario Admin/Operator:** Admin memakai master/review yang dimigrasikan; Operator tetap dapat Input Breakdown dan PM Executor tanpa menu admin.
- **Status parent:** IN PROGRESS — PROGRESS/PARTIAL; halaman Vue existing tidak membuktikan seluruh migrasi/redesign selesai.
- **Sudah selesai:** shell/halaman Vue/Inertia dan kandidat UI parsial tersedia; AI-provider Vue lokal masih bagian checkout dirty.
- **Sisa scope:** inventaris halaman, urutan migrasi approved, parity fitur/role/responsive/accessibility, build dan UAT tiap halaman; redesign Vue keseluruhan belum selesai.
- **Dependensi:** acceptance bisnis [TASK-07](#task-07), navigasi [TASK-06](#task-06), quality gate [TASK-19](#task-19). Hasil diterima menjadi syarat [TASK-15](#task-15).
- **Prioritas:** P3; Wave 5 urutan 17.
- **GitHub/branch/worktree:** implementasi bertahap di history/checkout Gemini; belum ada kandidat migrasi keseluruhan yang diverifikasi.
- **Tindakan berikutnya:** pilih satu halaman beserta acceptance parity; gunakan hasil TASK-07 agar Master PM tidak didesain dua kali.
- **Usulan ownership:** fullstack pemilik fitur halaman, dukungan frontend design system dan QA independen.

**Acceptance:** pengganti Vue halaman yang dipilih memenuhi route/permission, fungsi, responsive, aksesibilitas, regression dan UAT sebelum cutover; Blade bisnis tetap sampai pengganti diterima; root Inertia/server-rendered Blade tidak ikut dihapus tanpa alasan terverifikasi.

<a id="task-15"></a>
### TASK-15 — Unused Blade Cleanup

- **Modul/tujuan bisnis:** pengurangan template mati; codebase lebih jelas tanpa memutus halaman aktif.
- **Skenario Admin/Operator:** halaman yang masih dipakai kedua role tetap berfungsi sesudah template yang benar-benar tidak terpakai dihapus.
- **Status parent:** NOT STARTED.
- **Sudah selesai:** kebijakan retirement bertahap tercatat; cleanup bukan otomatis selesai karena ada halaman Vue.
- **Sisa scope:** inventaris consumer route/controller/include, bukti pengganti diterima dan penghapusan aman per halaman.
- **Dependensi:** parity/cutover [TASK-14](#task-14), navigasi [TASK-06](#task-06), gate [TASK-19](#task-19).
- **Prioritas:** P3; Wave 5 urutan 18.
- **GitHub/branch/worktree:** belum ada kandidat cleanup yang diverifikasi.
- **Tindakan berikutnya:** inventaris satu kandidat Blade tanpa consumer setelah pengganti Vue diterima; jangan hapus massal.
- **Usulan ownership:** fullstack pemilik fitur yang sudah dimigrasikan, QA regresi route.

**Acceptance:** tidak ada consumer runtime tersisa untuk file yang dihapus; route/permission serta fungsi kedua role tetap lulus; template root `resources/views/spa.blade.php` dan template server-rendered yang masih diperlukan dipertahankan.

## Domain 8 — Engineering Quality, CI dan Release

<a id="task-13"></a>
### TASK-13 — Qdrant Legacy Dependency Audit & Cut-off

- **Modul/tujuan bisnis:** retirement backend/konfigurasi legacy setelah Gemini terbukti; tidak memelihara dua backend AI sebagai target permanen.
- **Skenario Admin/Operator:** Admin tetap mengelola/mencari SOP melalui jalur Gemini; retirement tidak mengganggu workflow PM/Breakdown Operator.
- **Status parent:** NOT STARTED untuk audit/cut-off menyeluruh. Dokumentasi dependency parsial bukan bukti retirement selesai.
- **Sudah selesai:** PROJECT_STATE historis mencatat process/publish SOP tidak membutuhkan Qdrant dan Assistant tidak menggunakannya sebagai fallback; implementasi vector store/embedding/readiness serta consumer/test legacy masih ada.
- **Sisa scope:** inventaris semua consumer runtime/test/config, keputusan retirement, cutover terverifikasi, penghapusan aman dan regression.
- **Dependensi:** foundation/security [TASK-01](#task-01), workflow dokumen [TASK-21](#task-21), cutover/approval [TASK-19](#task-19). Urutan sesudah [TASK-06](#task-06) pada roadmap bukan blocker teknis otomatis.
- **Prioritas:** P3; Wave 5 urutan 16.
- **GitHub/branch/worktree:** kode legacy pada repo; belum ada kandidat cut-off menyeluruh yang diverifikasi.
- **Tindakan berikutnya:** buat inventaris consumer read-only dan bukti pengganti sebelum meminta keputusan retirement Owner.
- **Usulan ownership:** fullstack AI integration dengan backend/dependency reviewer dan QA.

**Acceptance:** seluruh consumer yang relevan dipetakan; Gemini cutover/recovery diterima; tidak ada fallback Qdrant tak terdokumentasi atau consumer runtime tertinggal; removal disetujui dan tidak mengakses/menghapus layanan Qdrant project PRIME asal.

<a id="task-19"></a>
### TASK-19 — Release Readiness, Recovery & Documentation

- **Modul/tujuan bisnis:** quality gates dan kesiapan operasi lintas fitur; rilis dapat diverifikasi dan dipulihkan.
- **Skenario Admin/Operator:** kedua role menjalankan workflow yang diizinkan sesudah integrasi; Admin tidak mendapatkan PM Executor dan Operator tidak mendapatkan menu admin.
- **Status parent:** IN PROGRESS — TASK-19.01 selesai sesuai scope automated quality gates: PR #4 MERGED dan CI main PASS. TASK-19.02 (XLSX Export Text Safety) selesai sesuai scope dan PR #2 MERGED; gate recovery/UAT, kandidat lain dan kesiapan rilis di bawah belum seluruhnya ditutup. Parent TASK-19 tidak otomatis DONE.
- **Sudah selesai:** automated quality gates TASK-19.01 dan literal-text XLSX TASK-19.02 telah masuk main melalui PR #4/#2, dengan Q-03/Q-04/Q-05 diterima serta CI pasca-merge PASS. SHA/run dan batas ancestry/policy dilacak pada [Project State](PROJECT_STATE.md#current-state); bukan bukti CI atau browser M-01.
- **Sisa scope:** final review/checks kandidat lain, regression/UAT sesuai risiko, role checks, MySQL relevan, migration/rollback, recovery, dokumentasi aktual, hosting dan approval cutover. Tidak membuka ulang Q-03/Q-04 atau CI PR #4 tanpa perubahan konkret.
- **Dependensi:** kandidat tiap fitur yang akan dirilis, bukan seluruh parent harus DONE sebelum CI/QA dimulai. Gate berjalan bersama implementasi lalu wajib lulus sebelum release; task ini berlaku pada seluruh Wave, bukan menunggu Wave 6.
- **Prioritas:** P1 continuous gate; bukan parent tambahan khusus CI atau XLSX.
- **GitHub/branch/worktree:** [GitHub PR #4](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/4) MERGED, HEAD PR `393a505d9c63b36c36d20062bbec84e46369e7ef`; [PR #2](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/2) MERGED, HEAD `28ce9f2de4a92e8279f8874d825f6798398c42cf`, merge/main `7e90945ea452911c0c5d7dccaa7a9dc43984cdc9`. Status ancestry parents merge commit **NOT VERIFIED**; Q-05 internal review bukan formal GitHub approval. Root/worktrees lokal tidak disinkronkan.
- **Tindakan berikutnya:** tutup gate TASK-19.03 sesuai kandidat/target yang disetujui, termasuk Role Check, MySQL relevan, migration/rollback, recovery, hosting dan approval cutover; jangan menyatakan release READY tanpa bukti tersebut. PR #3/TASK-07.01 sudah MERGED/DONE sesuai scope; Slice 1 merge `6e79fd46`, PR #5; Slice 2 sebagian saat ini (aging/notification PR #6) tapi temporal Resume belum diimplementasikan, tidak menutup TASK-19. Tidak ada izin Git publication, database atau runtime tambahan dari backlog ini.
- **Usulan ownership:** fullstack release/quality owner, dukungan CI/operability dan QA independen; setiap fitur tetap memiliki owner domain.

**Child dan batas deliverable:**

| Child | Scope/status | Acceptance khusus |
|---|---|---|
| TASK-19.01 | Automated CI Quality Gates — DONE sesuai scope; PR #4 MERGED, CI main PASS | PHPUnit, JavaScript, changed-file Pint dan build benar-benar dijalankan/lulus pada HEAD PR dan merge SHA; review historis, Q-03/Q-04 diterima; event/base/head/path handling tidak dilemahkan. Bukan penyelesaian parent/release. |
| TASK-19.02 | XLSX Export Text Safety — DONE sesuai scope; PR #2 MERGED, CI main PASS | Teks pengguna menyerupai formula diekspor literal pada PM sinkron, worker dan Breakdown; tipe angka/teks biasa tetap benar. Merge commit `7e90945…84cdc9`, kandidat `28ce9f2…42cf`; Q-05 `SHIP_FOR_OWNER_REVIEW` tanpa temuan; Actions `44118241952` PASS. Parent commit ancestry merge tetap NOT VERIFIED. |
| TASK-19.03 | Recovery, UAT dan dokumentasi rilis — IN PROGRESS, gate belum lengkap | Permission, skenario database relevan, migration/rollback, recovery, hosting, dokumentasi accepted dan approval cutover memiliki bukti sesuai target. |

**Catatan integrasi XLSX:** PR #2 sudah MERGED. Patch ekuivalen ada pada history Gemini `c6dc1be`; jangan diterapkan dua kali pada integrasi berikutnya. Detail SHA dan batas ancestry berada di Project State.

**Acceptance parent:** semua gate rilis yang berlaku pada kandidat target lulus dan diterima; review sebelum merge, approval Owner dan bukti operasi/recovery tersedia; tidak menyatakan siap produksi hanya dari test lokal, PR terbuka atau merge. Jangan menyembunyikan pemeriksaan agar CI tampak PASS.

## F. Arsip bukti dan rekonsiliasi audit

<details>
<summary>SUPERSEDED untuk dispatch/status terkini — checkpoint 15–18 November dan manifest historis</summary>

Arsip ini mempertahankan QA, acceptance, fingerprint, manifest dan traceability yang sudah ada; bukan assignment aktif atau izin mengulang pekerjaan. Status PR OPEN/CI FAILURE, kebutuhan Q-02 dan aturan agent lama yang bertentangan telah **SUPERSEDED** oleh penutupan pada [Project State](PROJECT_STATE.md#current-state) dan [workflow aktif](PRIME_PARALLEL_EXECUTION_PLAN.md#active-workflow). Snapshot/hasil historis tidak diubah menjadi klaim PASS baru. Tidak ada arsip/path baru atau pemindahan manifest besar.

### F.1 GitHub MCP — diperiksa 15 November 2026
> Snapshot historis; status PR #2/#3/#4 kini mengikuti ledger penutupan Project State. Baris PR #3 di bawah pernah diperbarui pada 18 November dan bukan snapshot murni 15 November.

| Publikasi | Status yang terbukti | Hubungan backlog dan batas klaim |
|---|---|---|
| [GitHub PR #1](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/1) | MERGED, 4 November 2026 | Agent foundation; merge main `277d725`. Bukan publikasi seluruh foundation/security Gemini. |
| [GitHub PR #2](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/2) | OPEN, head `e1bdad7` | TASK-19.02; status diperiksa 15 November. Bukan CI PASS atau approval GitHub. |
| [GitHub PR #3](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/3) | MERGED 18 November 2026; head `a9929fe`, merge/main `d2588ce`, basis `7e90945` | TASK-07.01; QA yang dilaporkan adalah review internal sesi, bukan formal GitHub approval; CI pasca-merge `44224408741` PASS. Status baris ini menggantikan snapshot 15 November; branch protection/ruleset pra-merge tidak diverifikasi langsung. |
| [GitHub PR #4](https://github.com/SumberMakmurRejeki/prime-chatbot/pull/4) | OPEN, head `91f3e32`; Actions FAILURE | TASK-19.01; status diperiksa 15 November. |

[Actions run 43605824318](https://github.com/SumberMakmurRejeki/prime-chatbot/actions/runs/43605824318), attempt 1, head `91f3e327bca814fe82ce61c61c9170452a67cbc5`, selesai FAILURE pada 14 November 2026.

Job `PHP and frontend quality` gagal pada **Run PHPUnit**. JavaScript, changed-file Pint dan frontend build berstatus **SKIPPED** setelahnya, bukan PASS. Pengambilan detail job log melalui MCP timeout; akar kegagalan tidak ditetapkan ulang dari log dalam sesi ini.

### F.2 Tujuh worktree — checkpoint lokal 15 November 2026

Root Herdr: `C:\Users\USER\.herdr\worktrees\prime-google\`. Nama pendek pada tabel di bawah berada di root ini, kecuali path target yang ditulis absolut. Semuanya checkout berbeda dari satu repository; bukan file yang sama.

| Lokasi | Branch / HEAD | Kondisi dan implikasi |
|---|---|---|
| `C:\laragon\www\prime-google` | `feature/gemini-file-search` / `c6dc1be` | Sebelum edit dokumentasi: 20 file modified, 12 untracked, 0 staged; backlog untracked, `Docs/PROJECT_STATE.md` tracked; tidak ada upstream. |
| `chore-ci-pr-quality-gates` | `chore/ci-pr-quality-gates` / `91f3e32` | `quality.yml` modified lokal; kandidat PR #4. Koreksi lokal belum dipublikasikan dan belum ada Actions run baru. |
| `fix-gemini-provider-security` | `fix/gemini-provider-security` / `1076689` | Bersih; hardening lokal, membutuhkan foundation/integrasi; bukan operational cutover. |
| `fix-master-checksheet-validation` | `fix/master-checksheet-validation` / `ec841c4` | Kandidat GitHub PR #3; dua artefak QA untracked `storage/framework/qabrowser/pr07.sqlite` dan `router.php` tetap dipertahankan. |
| `fix-pm-review-data-quality` | `fix/pm-review-data-quality` / `1076689` | Bersih; tidak memuat patch Review lokal terbaru, jangan keliru dijadikan kandidatnya. |
| `fix-pm-review-data-quality-origin-main` | `fix/pm-review-data-quality-origin-main` / `277d725` | Patch Review belum committed: lima file tracked modified dan enam path untracked (termasuk `WATCHDOG.yml`); inventory YAML verifikasi tidak lengkap. |
| `fix-xlsx-export-text-safety` | `fix/xlsx-export-text-safety` / `e1bdad7` | Bersih; kandidat GitHub PR #2 berbasis main, ekuivalen patch `c6dc1be` pada history Gemini. |

Bukti source yang diperiksa: koneksi/gateway transport dan service revisi provider pada worktree security; service/controller/UI/test Review pada worktree `origin-main`; request validasi Checksheet; service operational query dan binding-nya pada checkout target.

`README.md`, `Docs/PROJECT_STATE.md` (checkpoint 11 November 2026), dan `specs/product/SCOPE_LATEST.yaml` dibaca sebagai konteks. Scope YAML adalah inisiatif baseline historis, bukan pembatas seluruh roadmap kini. Ketiganya tidak diubah.

### F.3 Bukti historis — tidak dijalankan ulang pada pembaruan ini

| Scope/checkpoint | Bukti yang dipertahankan | Batas penggunaan |
|---|---|---|
| Gemini baseline | `c6dc1be` tersedia pada branch security; backend lifecycle/recovery `fc439ee`; diagnostik `8d45410`; FNC-01 functional RAG UAT accepted 12/12, 11 November | Bukan security/integrasi/operational readiness. UAT terbatas bukan reliabilitas 100% atau cutover. |
| TASK-07.01, v3 pada `64df822` | PHP Master Checksheet 52/52 PASS, 426 assertions; focused JS 10/10 PASS; browser QA, build dan focused Pint PASS; scope enam file | Saat v3 dicatat PR belum dibuka. Informasi publikasi itu sudah digantikan F.1, bukan penghapusan bukti test. |
| TASK-07.01, body PR #3 kandidat `ec841c4` | QA follow-up APPROVE; JS 11 PASS; Master Checksheet 52 PASS; PM Executor 117 PASS; Master Action 17 PASS; PHP 8.3.36/SQLite terisolasi. QA sebelumnya mencakup build, focused Pint dan browser flows 10 screenshot | Laporan QA terdahulu, bukan eksekusi test sesi ini, bukan penyelesaian TASK-07.02/.03. |
| TASK-19.01, v3 pada `78d9af0` | PHPUnit 989 PASS/5.948 assertions; JS 19/19 PASS; build PASS; workflow YAML/permissions valid; diff check PASS; full baseline Pint FAIL pada 39 file lama | Bukti lokal lama; bukan hasil kandidat/head PR #4. Remediasi 39 file adalah perubahan terpisah terkoordinasi, bukan syarat memformat ulang semua file pada PR fokus. |
| TASK-19.01, body PR #4 `91f3e32` | Changed-file Pint QA/review APPROVE; valid PHP pass/invalid fail; PR/push, rename, deletion, spasi nama path dan empty set diuji; gates PHPUnit/JS/build dipertahankan | Tidak menggantikan Actions yang kini FAILURE; changed-file Pint SKIPPED pada run tersebut. |
| TASK-19.02, `e1bdad7` / body PR #2 | Regression PM/Breakdown export; text-safety 4 tests/50 assertions PASS; worker/registration PASS; focused Pint/diff check PASS | Suite Report lain saat itu 93 tests mempunyai 23 failures + 8 errors karena manifest Vite belum ada; bukan bukti full Report lulus atau defect patch XLSX. |
| TASK-17, artefak `specs/verifications/pr-17-pm-review.yaml` di worktree `origin-main` | Handoff mencatat benchmark SQLite sintetis 10.000 record PASS (73 detik, exit 0; `:memory:` per proses; prewrite preflight dan guard sebelum mutasi), PHPUnit 55/535 PASS; independent QA APPROVE dengan caveat SQLite | YAML menyatakan inventory untracked lima path, sementara worktree memuat enam termasuk `WATCHDOG.yml`; inventory perlu direkonsiliasi dan independent QA atas YAML terbaru belum terbukti. MySQL performance NOT VERIFIED; tidak ada klaim performa produksi. |
| TASK-02 | OMP 112-08 planning approved pada backlog asli | Handoff eksternal belum ditemukan sebagai file repo; gunakan kembali, jangan re-plan. |
| TASK-03/05/10/14/16/18 | PARTIAL/ONGOING pada v2/v3; source baseline terbatas ada sesuai definisi task | Tidak ada bukti baru yang menutup seluruh parent. |
| TASK-20 | Audit kategori/status selesai pada v3 | Implementasi koreksi dan regression masih terbuka. |

Hasil QA harus menyebut scope, kandidat/commit dan sumber bukti. Angka test lama tidak dijumlahkan menjadi coverage atau completion percentage. Pane/agent Herdr aktif/selesai bukan dasar status DONE.



### F.4 Audit tujuh worktree dan koordinasi yang dipertahankan

Sumber historis: sesi audit selesai `01a1dad1-fec4-7241-bdb3-4dbb1c97d9f5`, dibaca melalui konsultasi arsip; bukan pengulangan audit/test. Temuan identitas/status dicocokkan dengan bukti lokal dan GitHub F.1/F.2.

- Semua staging area kosong saat audit; foundation Gemini dan security belum dipublikasikan. Audit mencatat masing-masing 14 dan 15 commit lokal tidak terjangkau remote refs; jumlah itu bukti historis, bukan penghitungan ulang sesi ini.
- Log CI yang sempat berhasil dibaca audit melaporkan **989 tests, 4.550 assertions, 8 errors, 165 failures, exit code 2**. Banyak error menyebut `Vite manifest not found`; workflow membangun frontend setelah PHPUnit. Ada pula assertion Inertia/view dan HTTP 500, jadi jangan menyimpulkan seluruh failure pasti selesai hanya dengan manifest.
- PM Review `origin-main` memang memuat patch; gate 10.000 record belum tertutup. Search/sort masih memakai identitas master mesin terkini sementara tabel menampilkan snapshot historis: gap yang perlu QA/keputusan scope, bukan dianggap tertutup oleh server-side filtering.
- Master Checksheet dan PM Review sama-sama mengubah `resources/js/app.js`; blok berbeda tetap memerlukan integrasi terkontrol, bukan overwrite file.
- Security beririsan dengan checkout Gemini dirty pada `AiProviderConfigurationService.php` dan test provider; foundation/operational Assistant juga menyentuh binding/source AI. Jangan mengambil security sendirian atau membawa seluruh dirty diff tanpa review.
- Patch ID XLSX `c6dc1be` dan `e1bdad7` dinyatakan identik dalam audit: `05c62d82f1b6eb516e816ff33a0d50106bc17393`. Branch XLSX sudah dipublikasikan walau upstream lokal saat audit masih `origin/main`.
- Rekomendasi awal menempatkan PM Review + Gemini sebagai dua slot kemudian dibatalkan. Rekomendasi akhir memilih penyelesaian kandidat existing Master Checksheet GitHub PR #3 dan XLSX GitHub PR #2 karena file implementasinya tidak beririsan. Ini **rekomendasi historis, bukan assignment/approval Owner**.
- PM Review/Gemini tetap recovery/verifikasi/integrasi existing, bukan coding ulang. QA terpisah dari implementor; koordinator menangani CI dan dependensi. PR #2/#3 boleh direview, belum siap merge tanpa QA, CI kandidat dan approval Owner.
- Audit tidak menetapkan delapan domain formal; pengelompokan domain di dokumen ini mengikuti brief pembaruan, bukan diklaim keputusan audit terdahulu.

### F.5 Inkonsistensi yang tetap terlihat

- PROJECT_STATE kini disinkronkan dengan checkpoint dokumentasi 15 November; status source lokal dan status Git tetap dibedakan. AI operasional database lokal terbatas/uncommitted, bukan generalized query yang terverifikasi.
- V3 menyebut security coding pending serta PR Checksheet/CI belum dibuka. Status itu historis; kandidat lokal dan GitHub kini tercatat pada task/ledger yang benar.
- Baseline Pint 39 file tetap utang historis terpisah; kegagalan run CI terbaru terjadi di PHPUnit sebelum Pint dijalankan. Detail akar kegagalan perlu bukti log/QA, bukan asumsi PASS.
- Planning OMP 112-08 dan beberapa handoff QA adalah bukti eksternal; jangan mengarang lokasi artefak atau menganggap seluruh bukti ada dalam repo.
- Definisi dua role aplikasi pada A.2 mengikat. Label guest legacy pada source tidak menambah role bisnis; penutupan konsistensinya merupakan acceptance akses di TASK-19, bukan klaim sudah diselaraskan.

### F.6 Checkpoint parallel execution plan — 15 November 2026

Status adalah bukti yang dapat diamati, bukan assignment: lane tidak dinyatakan RUNNING/COMPLETED tanpa bukti owner/agent yang terverifikasi. Verifikasi ownership melalui Herdr tidak dilakukan karena konteks sesi tidak memenuhi gate skill; status ownership tetap NOT VERIFIED.

| Lane | Bukti checkpoint | Batas/status |
|---|---|---|
| CI / TASK-19.01 | PR #4 OPEN; Actions run `43605824318` FAILURE pada PHPUnit. Worktree CI memiliki perubahan lokal `quality.yml`; Actions API mencatat hanya run ini. | Koreksi lokal belum dipublikasikan; tidak ada remote rerun. BLOCKED. |
| PM Review / TASK-17 | Benchmark 10.000 record PASS dan QA handoff APPROVE; kandidat uncommitted di worktree `origin-main`. | YAML inventory mismatch; final independent QA atas YAML terbaru dan integrasi belum terbukti; MySQL NOT VERIFIED. BLOCKED. |
| Gemini security / TASK-01 | Candidate commit `1076689` ada pada worktree security yang bersih. | Integrasi dengan checkout utama, acceptance security independen, dan keputusan Owner belum terbukti. BLOCKED. |
| Master Checksheet / TASK-07.01 | PR #3 OPEN pada `ec841c4`; kandidat berada di worktree terpisah. | Parent tetap IN PROGRESS; simplifikasi Checksheet/Action yang lebih luas belum selesai. |
| XLSX / TASK-19.02 | PR #2 OPEN pada `e1bdad7`; worktree terpisah. | Review/CI/approval merge belum terbukti selesai. |
| Dokumentasi / sinkronisasi | Pembaruan backlog v4 dan PROJECT_STATE sebagai checkpoint 15 November. | Dokumentasi saja; bukan verifikasi kode, assignment lane, integrasi, atau approval Owner. |
> Inventory worktree F.7 adalah observasi 17 November sebelum tiga edit dokumen ini. Hitungan modified/untracked hanya berlaku pada saat pengambilan tersebut; edit dokumen menambah perubahan root sesudah snapshot.
### F.7 Checkpoint kode, worktree dan GitHub — 17 November 2026

**Penutupan PR #4 — M-GATE-08, 17 November 2026:** PR **MERGED** melalui Create a merge commit setelah approval Owner. HEAD PR `393a505d9c63b36c36d20062bbec84e46369e7ef`; merge commit dan remote main `f048f742541efd93a59f41899fe5df72996208b3`. [Actions pasca-merge `44094015983`](https://github.com/SumberMakmurRejeki/prime-chatbot/actions/runs/44094015983), job `134076265906`, selesai success; frontend build, PHPUnit, JavaScript dan changed-file Pint PASS, tanpa gate SKIPPED. CI HEAD PR `44084322427` juga PASS. Kedua blob file PR cocok di main; branch, HEAD dan Git status 11 worktree lokal tetap identik sebelum/sesudah merge, tanpa penghapusan branch/worktree atau sinkronisasi dirty root.

**Review dan batas completion:** Q-03 diterima untuk fixture test-only; Q-04 diterima untuk pemindahan build sebelum PHPUnit, verdict `SHIP_FOR_OWNER_REVIEW`, findings none. Gate CI PR #4 ditutup dan TASK-19.01 selesai sesuai scope. Bukti ini tidak menutup parent TASK-19, CI/browser M-01 atau pekerjaan lain. Environment MySQL QA tetap **BLOCKED / OWNER/ENV ADMIN INPUT REQUIRED**; tidak ada izin database access, provisioning, akun/privilege, fixture atau runtime.

**Rekaman historis sebelum penutupan:** paragraf berikut dipertahankan sebagai checkpoint terdahulu; status PR #4/CI terkini mengikuti penutupan di atas.

**GitHub (read-only API, 17 November 2026):** PR #1 MERGED; PR #2 OPEN (`fix/xlsx-export-text-safety`, head `e1bdad7ddf3792c23d21946c5fd2262529ab5b60`); PR #3 OPEN (`fix/master-checksheet-validation`, head `ec841c41ed64997a0b593b58a9fe6a2f707fdf59`); PR #4 OPEN (`chore/ci-pr-quality-gates`, head `21c3291f000d1b29cf11f6875b1b71e67347b513`). Tidak ada PR terbuka untuk branch M-01. Branch remote yang teramati: `main` `277d725`, PR #2/#3/#4 heads di atas dan agent-foundation branch. Ini snapshot, bukan status permanen.

**TASK-19.01 / PR #4:** Actions run terbaru yang teramati `43742200425`, selesai FAILURE pada PHPUnit; frontend build PASS, JavaScript dan changed-file Pint SKIPPED sesudah kegagalan. Run sebelumnya `43605824318` juga FAILURE pada PHPUnit. Detail run/job: <https://github.com/SumberMakmurRejeki/prime-chatbot/actions/runs/43742200425>. Worktree `chore-ci-pr-quality-gates` berada pada branch `chore/ci-pr-quality-gates`, HEAD tetap `21c3291f000d1b29cf11f6875b1b71e67347b513`, tracking `origin/chore/ci-pr-quality-gates`, dengan hanya `tests/Feature/Feature/PM/PmExecutorTest.php` termodifikasi. Hash kandidat: `660d312f738e2dfe473909b4a835994abc3716f720ffc68ff21b7f9445c4b859`.

QA Q-03 memeriksa kandidat test-only tersebut terhadap baseline, fixture waktu `2026-11-15 17:38:34 UTC`, penggunaan `BusinessDate::today()->addDay()`, pemulihan waktu via `finally`/`travelBack()`, executor guard dan isolasi SQLite `:memory:`. Verdict **Ship; findings none** hanya untuk delta beku ini. QA tidak menjalankan test/Pint; laporan B menyebut 117 tests/770 assertions dan Pint PASS sebagai bukti terarah. Ini bukan full CI, bukan hasil remote, dan bukan approval merge/release. Browser acceptance masih belum diverifikasi.

**Worktree pada snapshot:** root `C:\laragon\www\prime-google` branch `feature/gemini-file-search`, HEAD `c6dc1be28fba22fa4dba8382891ac070c51e76c7`, tanpa upstream; banyak perubahan dirty. `integrate-wave-1-owner-approved` berada pada branch `integrate/gemini-security-user-settings-pm-review`, HEAD sama `c6dc1be`, 27 tracked modified dan 5 untracked; M-01 lokal belum dipublikasikan. `integrate-wave-1` masih dirty di baseline `277d725` dan bukan target integrasi yang diterima. Worktree lain yang diinventarisasi: `feat-ai-navigation-consolidation` (dirty, c6dc1be), `feat-simplify-master-pm-ux` (clean, c6dc1be), `fix-gemini-provider-security` (dirty, 1076689), `fix-master-checksheet-validation` (untracked QA artifacts, ec841c4), `fix-pm-review-data-quality` (clean, 1076689), `fix-pm-review-data-quality-origin-main` (dirty, 277d725), `fix-xlsx-export-text-safety` (clean, e1bdad7; ahead of origin/main by 1). Preserve all; this inventory is observational only.

Root tracking snapshot before these doc edits: 21 unstaged tracked changes and 13 untracked paths; `Docs/PROJECT_STATE.md` already modified, the two other requested documents already untracked. Do not attribute pre-existing dirt to this update. No source mutation, commit, push, PR, merge or deployment occurred.

**Penutupan XLSX PR #2 — M-GATE-14, 17 November 2026:** PR **MERGED** melalui Create a merge commit setelah approval Owner. HEAD kandidat `28ce9f2de4a92e8279f8874d825f6798398c42cf`; merge commit dan remote main `7e90945ea452911c0c5d7dccaa7a9dc43984cdc9`. [Actions pasca-merge `44118241952`](https://github.com/SumberMakmurRejeki/prime-chatbot/actions/runs/44118241952), job `134162139383`, selesai success; frontend build, PHPUnit, JavaScript dan changed-file Pint PASS, tanpa gate SKIPPED. CI HEAD kandidat `44107526250` juga PASS. Q-05 `SHIP_FOR_OWNER_REVIEW`, findings none (internal, bukan formal GitHub approval). Lima file XLSX cocok antara kandidat dan merge SHA berdasarkan contents API SHA (bukan Git blob SHA). Parent ancestry merge commit **NOT VERIFIED**; tidak ada respons GitHub yang memaparkan field `parents` dan objek commit tidak tersedia lokal (tidak di-fetch).

**Pemeriksaan kesiapan integrasi PR #3 — M-GATE-15, read-only (PR3-READINESS-01):** Worktree `fix-master-checksheet-validation`, branch `fix/master-checksheet-validation`, HEAD `ec841c41ed64997a0b593b58a9fe6a2f707fdf59`, tracking `origin/fix/master-checksheet-validation`; tracked status clean, satu direktori untracked `storage/framework/qabrowser/` berisi dua artefak QA (`pr07.sqlite`, `router.php`) yang dipreservasi dan tidak disentuh. Enam file kandidat: `app/Http/Requests/Master/StorePmChecksheetRequest.php`, `app/Services/Master/PmChecksheetService.php`, `resources/js/app.js`, `resources/views/pages/master/checksheet/wizard.blade.php`, `tests/Feature/Feature/Master/MasterPmChecksheetTest.php`, `tests/JavaScript/checksheet-wizard-xss.test.js`. Kandidat TIDAK turunan main terbaru `7e90945` (diverge dari basis `277d725`); origin/main lokal masih stale `f048f74`. Delta basis→`f048f74` hanya menambah `quality.yml` dan mengubah `PmExecutorTest.php` (baseline drift, bukan edit PR #3). Path PR #3 disjoint dari path XLSX PR #2; tidak ada duplikasi patch XLSX. **Konflik hunk-level dan CI kandidat final PR #3 BELUM DIVERIFIKASI** (objek `7e90945` tidak tersedia lokal, tanpa fetch). Rekomendasi B: siap diintegrasikan, **belum siap merge**; memerlukan approval Owner terpisah untuk fetch, integrasi (merge --no-ff mempertahankan ancestry direkomendasikan), push refspec eksplisit, lalu build→PHPUnit fokus→JS→Pint→CI final. Ini pemeriksaan kontrak read-only, bukan izin coding/integrasi dan bukan penyelesaian TASK-07.02/.03.


### M-01 — Rekonsiliasi dan integrasi TASK-01, TASK-06, TASK-17 (16–17 November 2026)

Status M-01, 17 November 2026: **integrasi lokal selesai; Q-02 diterima; UI_NOT_VERIFIED / operational and release gates open**. Tidak ada independent code review M-01 yang tertunda pada checkpoint ini. Q-03 adalah review berbeda atas delta test-only kandidat CI B dan tidak mengubah status M-01. Owner menyetujui source/provisioning dan instalasi locked secara terpisah; Manager menjalankan sebagai integrator tunggal. Kandidat dedicated belum di-commit atau dipublikasikan. Snapshot sumber root dan worktree tetap dipreservasi; seluruh dirty root bukan baseline integrasi.

**Keputusan yang diterapkan.** Basis committed `c6dc1be28fba22fa4dba8382891ac070c51e76c7`, bukan working tree root. Target `C:\Users\USER\.herdr\worktrees\prime-google\integrate-wave-1-owner-approved`, branch `integrate/gemini-security-user-settings-pm-review`, sudah dibuat dan berisi integrasi selektif. Worktree parsial `integrate-wave-1` pada `277d7251da2ea9768bef480927223b8a502ca456` tidak diadopsi atau diedit; sumbernya tetap dipreservasi.

**Scope paket dan overlap yang disetujui:**
- TASK-01 sumber `C:\Users\USER\.herdr\worktrees\prime-google\fix-gemini-provider-security`, branch `fix/gemini-provider-security`, HEAD `1076689df2cd25f3351d0b07045a30cb00524d58`. Bawa delta hardening committed pada `app/Services/AI/GeminiFileSearchConnection.php`, `app/Services/Settings/AiProviderConfigurationService.php`, `tests/Feature/AI/GeminiFileSearchConnectionTest.php`, dan `tests/Feature/Settings/AiProviderConfigurationTest.php`, lalu rekonsiliasi lima addon lokal: `app/Http/Requests/Settings/StoreAiProviderConfigurationRequest.php`, `app/Services/Settings/AiProviderConfigurationService.php`, `resources/views/pages/assistant/show.blade.php`, `tests/Feature/AI/GeminiAssistantChatTest.php`, dan `tests/Feature/Settings/AiProviderConfigurationTest.php`. Pertahankan secure Text/File/Store gateway, Base URL Gemini kosong ke endpoint resmi, serta endpoint-bound credential reuse secara bersamaan. Mitigasi gambar eksternal Assistant dan regression-nya termasuk scope yang disetujui, bukan cleanup yang boleh dibuang diam-diam.
  - **Dependency wajib Assistant operasional:** basis `c6dc1be` masih mengikat `GroundedAnswerService` langsung ke `GeminiGroundedAnswerService`; file wrapper belum ada pada tree commit itu. Integrasi membawa `app/Services/AI/OperationalPmAssistantAnswerService.php` dari root lokal, hunk import/binding pada `app/Providers/AppServiceProvider.php`, serta hunk terkait pada `app/Services/AI/GeminiGroundedAnswerService.php`, `app/Services/AI/GeminiRetrievalEligibilityService.php`, dan `app/Services/AI/GeminiRetrievalSourceScope.php`. Wrapper memerlukan `answerWithOperationalFacts()` dan penerusan filter kode dokumen sampai eligibility; membawa binding saja tidak cukup. Sertakan `tests/Feature/AI/OperationalPmAssistantAnswerTest.php` dan `tests/Feature/AI/PendingPmReviewAssistantTest.php`; pertahankan dan jalankan coverage `tests/Feature/AI/GeminiRetrievalEligibilityTest.php`, `tests/Feature/AI/GeminiRetrievalSourceScopeTest.php`, dan hunk relevan `tests/Feature/AI/GeminiAssistantChatTest.php`. Ini mempertahankan kemampuan lokal pending Review/FNC-01, bukan mengklaim query operasional telah digeneralisasi. Sitasi/provenance tetap fail-closed tanpa Qdrant fallback.
  - **Dependency wajib UI/discovery provider:** kandidat yang diusulkan mempertahankan halaman Inertia/Vue lokal, bukan kembali ke halaman Blade pada basis. Bawa `resources/js/pages/Settings/AiProviders/Index.vue`, hunk terkait `app/Http/Controllers/Settings/AiProviderConfigurationController.php`, `app/Http/Requests/Settings/DiscoverAiProviderModelsRequest.php`, dan `app/Services/AI/ModelDiscoveryService.php`; pada `routes/web.php` hanya hunk middleware `HandleInertiaRequests` untuk grup provider dengan gate Admin + `manage_ai` tetap utuh. Rekonsiliasi request penyimpanan dan service dengan hardening di atas; discovery tidak menggantikan endpoint-bound guard saat revisi disimpan. Gabungkan assertion Inertia/discovery lokal pada `tests/Feature/Settings/AiProviderConfigurationTest.php` dengan regression security, bukan memilih seluruh salah satu file. Pertahankan dan jalankan `tests/Feature/Settings/AiProviderModelDiscoveryTest.php`. Dependency lokal ini tercakup pemeriksaan integrator 233/1.816 pada kandidat gabungan; hasil historis 64/522 dari kandidat security tidak boleh diperluas kepadanya atau dianggap QA gabungan.
  - Basis sudah memuat foundation/lifecycle Gemini, gateway/citation baseline dan XLSX literal-cell fix; jangan replay XLSX. Bawa hanya dependency dan hunk yang dinamai, bukan seluruh dirty root. Hunk route `knowledge-documents.process`, processing dokumen existing, seeder/demo dan fitur lain tetap di luar usulan ini serta dipreservasi pada sumbernya. Tidak ada penghapusan Blade global atau penggantian provider binding di luar kebutuhan yang disebutkan.
- TASK-06 sumber `C:\Users\USER\.herdr\worktrees\prime-google\feat-ai-navigation-consolidation`, branch `feat/ai-navigation-consolidation`, HEAD `c6dc1be28fba22fa4dba8382891ac070c51e76c7`. Scope delta: `app/Http/Controllers/Settings/AiManagerController.php`, `app/Http/Controllers/Settings/UserSettingController.php`, `resources/js/components/Sidebar.vue`, `resources/views/partials/sidebar.blade.php`, `resources/views/pages/settings/users/index.blade.php`, retirement `resources/views/pages/settings/ai-managers/index.blade.php` hanya jika route/redirect pengganti tetap utuh, `tests/Feature/Feature/Settings/AiManagerTest.php`, serta hanya blok User Settings pada `resources/js/app.js`. `tests/Feature/Feature/Settings/UserSettingTest.php` wajib dipertahankan sebagai coverage regresi dan masuk verifikasi gabungan; perbandingan read-only kandidat B tidak menunjukkan delta lokal pada file itu, sehingga tidak perlu membuat atau menyalin perubahan tes fiktif. Pertahankan rute mutasi, grant/revoke dan authorization; rekonsiliasi provider binding dengan dependency Assistant di atas. Gabungkan blok User Settings dan PM Review di `app.js` per hunk, bukan replace-file. Hasil QA source/tests belum membuktikan browser/UI; paket tetap `READY_FOR_REVIEW / UI_NOT_VERIFIED`.
- TASK-17 sumber `C:\Users\USER\.herdr\worktrees\prime-google\fix-pm-review-data-quality-origin-main`, branch `fix/pm-review-data-quality-origin-main`, HEAD `277d7251da2ea9768bef480927223b8a502ca456`. Scope kandidat enam file yang dilaporkan: `app/Http/Controllers/PM/PmReviewController.php`, `app/Services/PM/PmReviewService.php`, `resources/views/pages/pm/review/index.blade.php`, blok Review pada `resources/js/app.js`, `tests/Feature/Feature/PM/PmReviewTest.php`, `tests/JavaScript/pm-review-server.test.js`. `ReportPmService.php` dan `tests/Feature/Feature/Report/ReportPmTest.php` adalah delta lokal tambahan, bukan bagian enam file yang dijelaskan sebagai scope penerimaan ini; jangan bawa sebagai bagian TASK-17 M-01 tanpa manifest/approval scope tersendiri. Demikian pula `WATCHDOG.yml`, `specs/verifications/`, benchmark/harness/unit support untracked tidak otomatis ikut. QA menyatakan enam hash tetap sebelum/sesudah; ini tidak menyelesaikan mismatch inventory YAML terhadap enam path aktual. Integrasikan hanya bila setiap file/hunk direkonsiliasi; pertahankan blok User Settings di `app.js` dari TASK-06. Scope fitur adalah historical/current identity, snapshot lokasi, legacy, filter/sort/pagination dan access; Action `"0"`, URL/QR, MySQL performance serta temuan lain tetap gate bila belum dibuktikan.

**Urutan kandidat yang disarankan:** (1) setelah persetujuan, sediakan target baru dari basis yang disetujui; (2) bawa hardening TASK-01 dan rekonsiliasi addon URL/credential, lalu dependency wajib wrapper/retrieval serta UI/discovery provider yang dinamai di atas; (3) integrasikan TASK-06 beserta blok User Settings `app.js` dan pertahankan kedua suite Settings; (4) integrasikan enam file TASK-17 beserta blok PM Review `app.js`; (5) rekonsiliasi routes, binding provider dan shared hunks, bekukan snapshot, lalu lakukan review independen dan verifikasi gabungan. Jangan replay XLSX. Pemrosesan dokumen existing tetap **terpisah dan dikecualikan**, termasuk hunk route process; tidak dihapus dari root dan bukan dependency wave ini. Gate CI/Checksheet dan gate proyek lainnya tetap terbuka.

**Bukti historis yang direkonsiliasi (bukan hasil eksekusi M-01):** laporan author/independent QA TASK-01: `GeminiFileSearchConnectionTest` 10 tests/38 assertions, `AiProviderConfigurationTest` 31/324, `GeminiAssistantChatTest` 23/160; total 64/522. Laporan QA menyebut PHP 8.3.36, PHPUnit 12.5.38, SQLite `:memory:`, APP_KEY proses sintetis dan HTTP fakes. Lima hash dilaporkan tersedia di handoff A dan QA menyatakan cocok. Perhitungan hash raw oleh QA, command tepat, exit code dan comparison log **TIDAK TERCATAT**; jangan menyebut hash handoff sebagai hasil hitung raw QA. Author TASK-06 melaporkan focused tests, Pint, typecheck dan build PASS; independent QA melaporkan dua suite di atas; UI tetap NOT VERIFIED. Temuan sidebar Operator dicabut sebagai false positive setelah penelusuran route→role→layout. Laporan independent QA TASK-17: `PmReviewTest` 60 tests/564 assertions, JavaScript PM Review 3 tests PASS, enam hash tidak berubah saat verifikasi; ini tidak membuktikan file laporan lokal tambahan atau YAML inventory stale/mismatch tercakup QA. Saat laporan tersebut dibuat, browser, MySQL, kandidat gabungan, release dan kesiapan operasional belum terbukti PASS. Bukti integrator gabungan berikut dicatat terpisah; parent TASK-01/TASK-06/TASK-17 tetap terbuka.

**Ownership dan tindakan berikut (setelah M-GATE-08, 17 November):** Integrasi M-01 lokal tetap milik Manager; Q-02 diterima dan source tetap frozen. Q-03/Q-04 selesai dan diterima hanya pada scope CI masing-masing; kandidat CI sudah MERGED dengan CI main PASS, bukan bukti CI/browser M-01. Browser tetap `UI_NOT_VERIFIED`; environment MySQL QA **BLOCKED / OWNER/ENV ADMIN INPUT REQUIRED**. Owner perlu menunjuk administrator MySQL/Laragon QA dan menyediakan akun read-only beserta credential lokal aman; preflight A hanya setelah akses tersedia dan approval terpisah. Tidak ada dispatch, database access atau runtime baru pada sinkronisasi dokumentasi ini.

**Dua persetujuan eksplisit yang diterima:** Owner menjawab kedua pilihan source/provisioning dan dependency dengan arahan mengikuti rekomendasi best practice. Ini diterapkan hanya sebagai persetujuan A (target/basis/scope M-01 dan pemeriksaan terisolasi) serta B (instalasi dari lockfile existing pada target ini). Bukan izin umum. Composer memakai `install --no-interaction --prefer-dist --no-scripts --no-plugins` (136 installs, 0 updates/removals); npm memakai `ci --include=dev --ignore-scripts --no-audit --no-fund` (165 packages). Keempat manifest/lockfile tetap identik. Warning baseline PSR-4 `FixtureSeedingIntentionalFailure` pada `tests/Support/SLICE-A/lifecycle-finite-state-proof.php` dipertahankan, tidak diperbaiki di luar scope.

**Tidak termasuk persetujuan tahap tersebut:** perubahan dependency/lockfile, runtime/browser/fixture yang memerlukan provisioning baru, MySQL/database operasional, Tinker/migration/seeder, provider live, commit/push/PR/merge, deployment atau aktivasi operasional. Masing-masing tetap memerlukan approval spesifik. Jangan menyamakan tes SQLite terisolasi dengan operasi database runtime.

- **Verifikasi integrator, 17 November 2026:** PHP 8.3.36 / PHPUnit 12.5.38, 12 suite terarah **233 tests / 1.816 assertions PASS**, exit 0 (16,186 detik); Pint scoped PASS. `npm --ignore-scripts run typecheck`, `npm --ignore-scripts run build` (Vite 6.4.5, 674 modules), dan `node --test tests/JavaScript/pm-review-server.test.js` (3 tests) PASS. Ini eksekusi target gabungan, bukan penjumlahan hasil QA lama atau pengganti Q-02. Parent TASK-01/06/17 tetap terbuka. A/B/Q-03 dispatch selesai pada scope masing-masing, tetapi tidak ada browser/MySQL/full CI/provider-live/release verification atau Git publication.


#### M-01 — Bukti kandidat gabungan beku

- Target: `C:\Users\USER\.herdr\worktrees\prime-google\integrate-wave-1-owner-approved`; branch `integrate/gemini-security-user-settings-pm-review`, basis/HEAD committed `c6dc1be28fba22fa4dba8382891ac070c51e76c7` dengan source integrasi lokal belum di-commit.
- Input: 43 snapshot source pada root/worktree tetap identik; manifest/lockfile Composer/npm juga tetap identik. Tidak ada copy seluruh dirty checkout, replay XLSX, modifikasi source asal, atau perubahan pada worktree parsial lama.
- Rekonsiliasi nyata: gateway Text/File/Store dan endpoint-bound credentials dipertahankan bersama normalisasi Base URL; wrapper/binding/retrieval operasional dan UI provider Inertia/discovery dibawa; blok User Settings dan PM Review `resources/js/app.js` digabung tanpa overlap. Halaman izin AI lama dipensiunkan setelah tidak ada konsumen view; GET/aksi izin tetap memakai redirect/gate/service existing.
- Kegagalan integrasi yang diselesaikan: percobaan PHP pertama exit 255 karena metode tes shared duplikat (basis salah); kedua file dibangun dari root yang sebenarnya + regresi security yang belum ada. Eksekusi 233 tes berikutnya menemukan 2 failures (absent `base_url`, assertion escaped-image lama). Normalisasi kini menerima field Gemini yang tidak dikirim; assertion tampilan gambar lama dihapus, sedangkan perlindungan XSS, blok gambar, formatting dan sitasi tetap diuji. Eksekusi akhir 233/1.816 PASS, exit 0; tidak ada source edit sesudahnya.
- Isolasi: `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL=''`, secondary webpush SQLite, cache/session array; permission tests memakai session database SQLite terisolasi sesuai fixture existing. APP_KEY sintetis 32-byte hanya untuk proses. Guard repository tetap aktif; HTTP/DNS fakes pada jalur provider. Tidak ada .env target/config cache yang digunakan, MySQL runtime, Tinker, seeder atau provider live.

Invocation akhir (environment sintetis/terisolasi seperti di atas):

```text
C:\laragon\bin\php\php-8.3.36-nts-Win32-vs16-x64\php.exe vendor/bin/phpunit --colors=never tests/Feature/AI/GeminiFileSearchConnectionTest.php tests/Feature/Settings/AiProviderConfigurationTest.php tests/Feature/Settings/AiProviderModelDiscoveryTest.php tests/Feature/AI/GeminiAssistantChatTest.php tests/Feature/AI/OperationalPmAssistantAnswerTest.php tests/Feature/AI/PendingPmReviewAssistantTest.php tests/Feature/AI/GeminiRetrievalEligibilityTest.php tests/Feature/AI/GeminiRetrievalSourceScopeTest.php tests/Feature/AI/GeminiFileSearchCitationMappingTest.php tests/Feature/Feature/Settings/UserSettingTest.php tests/Feature/Feature/Settings/AiManagerTest.php tests/Feature/Feature/PM/PmReviewTest.php
```

Pint final mencakup service konfigurasi provider dan tes Assistant yang dikoreksi; initial `--dirty --format agent` serta scoped formatting tes shared sebelumnya telah dijalankan. Tidak ada broad lint/test suite tambahan setelah smoke proof.

Fingerprint SHA-256 **fc46780ecd4aa903e7d982d9313ba554a773134d355431a5d30da9eff0dd6a08** dihitung dari UTF-8 tanpa BOM, satu baris per path yang diurutkan: `<relative path><TAB><lowercase sha256><LF>`. Manifest memuat 33 source/coverage path; bukan hanya daftar delta Git. Penghapusan `resources/views/pages/settings/ai-managers/index.blade.php` diperiksa terpisah sebagai wajib tidak ada, tidak dimasukkan sebagai file ber-hash:

```text
app/Http/Controllers/PM/PmReviewController.php	8808aec5c97bf70b288ebcac0e67b9df1f46e75109f1e6c8614aa5748201028e
app/Http/Controllers/Settings/AiManagerController.php	bf87d2b79ba4a7bf4a96c696dd0dc98f8304d8caa7751cf3fc974b8840b688d3
app/Http/Controllers/Settings/AiProviderConfigurationController.php	35f4ec580d7347ecbcbaf1f5ae65dea7077a9937deac010af08d2b8cbe674bdf
app/Http/Controllers/Settings/UserSettingController.php	43c8e295ebeaae61e3836f52feb505ed5491740a47d303008f9d9aa80bac4f0f
app/Http/Requests/Settings/DiscoverAiProviderModelsRequest.php	7417d3b1db1fa8eb79579a8f0a071cca625519d093daa5881d82c05fc4a020b0
app/Http/Requests/Settings/StoreAiProviderConfigurationRequest.php	330c34b978b2d201c26ab482496876f3dd137ada2efbc70dcf04cee2cb236832
app/Providers/AppServiceProvider.php	eed4db5a402c1df5f7e461619111330a502d4d3d267d3cf07aec61e200730863
app/Services/AI/GeminiFileSearchConnection.php	08d573b58d391b03d580b644cbe51e41803fd5953b840014c885ff4dea3be2be
app/Services/AI/GeminiGroundedAnswerService.php	b92e0c5ab64b2b1e430b11e5b1a19021b6cb9ea33a3f00cdc201bf432b4ae3a4
app/Services/AI/GeminiRetrievalEligibilityService.php	19b6f1eb5a74cf1a403f53c87b7cf49e705c11f271c2b77f21e7f0f23d12f82c
app/Services/AI/GeminiRetrievalSourceScope.php	6835ac3bdd3ca4391a19d5392a1285440ae9fce4a18f776d11195f5e5b0cb047
app/Services/AI/ModelDiscoveryService.php	03936a9654ad9d4dbee61f07770bbe3e6ef7bda5d59f4e1421ca2f88bcde20cf
app/Services/AI/OperationalPmAssistantAnswerService.php	6fd246739b6367c613d0d4dcc9f7c61e52014ecf5f7efc880ab977621f773895
app/Services/PM/PmReviewService.php	0aede435d6455c3b1c069f592ce8e83dcdb4b42ceeab533744f2ad9dd5c8fbe3
app/Services/Settings/AiProviderConfigurationService.php	7052ef474bd7326c7fe45f75a76bffadcf288c9a552f1a239d33b500c8d58e09
resources/js/app.js	002d02b9d12b0214aa1777cd49821600d3f68623e46d4de8d020ae005b874972
resources/js/components/Sidebar.vue	511ab253fb30f60a0e95d15ebf1bd123638e8fe7f716741e4a3875cb15ea6990
resources/js/pages/Settings/AiProviders/Index.vue	8527df99c77119aa9d4f715050afa6a48af7f841b6b149cb8d27201f00853960
resources/views/pages/assistant/show.blade.php	1f7b285626a8ca7db74575df838beab1f866853d07f32223f9b03bb9e6043a40
resources/views/pages/pm/review/index.blade.php	e329b56df353e410c9411e10f34c414f4f71b68771b915bb9f838722f9727252
resources/views/pages/settings/users/index.blade.php	c49303de8636034c38d4b82bc28cd11519b234cf12ab4926bc345530030e065a
resources/views/partials/sidebar.blade.php	7291d6ea08b5e181e7d1fd01f8d729414b85ce923e62096f2f8681905f1e38bc
routes/web.php	97ee0f95f4f5cd81909f756e3ca2a471d042ff5925e8f37c9ca45fa854eb1fe1
tests/Feature/AI/GeminiAssistantChatTest.php	1a31c289c258ea90bdc0539cc21be6c6e1f2ca05c0fc83f9829ebcb4fb5a3d70
tests/Feature/AI/GeminiFileSearchConnectionTest.php	492795d623a11f787135cf243561d19326861bac0b4e5837fa9c16ae8d678c16
tests/Feature/AI/OperationalPmAssistantAnswerTest.php	87911c8c7e4fd7a1cdd447bc3fd64e2207a35337d63cd89d78440bbb39d2b228
tests/Feature/AI/PendingPmReviewAssistantTest.php	7806196c4fdc32fb2c41d45e98c6a48cb1e5ce362bc73b9ba64d8be5558d9c90
tests/Feature/Feature/PM/PmReviewTest.php	9f851482bdd1b537ab1154245c8841d5c0265a1f88095531bc2c5516ea1627b2
tests/Feature/Feature/Settings/AiManagerTest.php	2eb8bf4e4bb4b2be7a7b6ac2f717fd732ca1ad93ae8a5e8a43d3687d7e0736d7
tests/Feature/Feature/Settings/UserSettingTest.php	e5c205e7f89b8ee0f8bc40430c326a0e353f360c55702528333da0424a895960
tests/Feature/Settings/AiProviderConfigurationTest.php	be18a600e69d3ccee9666e78f4c173e09e01d68a0fc649e8a9ad0ea9c6085d6c
tests/Feature/Settings/AiProviderModelDiscoveryTest.php	b2e7207cc5f83888e19ffdf31717ec4cead3e5d6748938920312145b1c116c47
tests/JavaScript/pm-review-server.test.js	e683c43d13ab4ba2d8988dce12b819c4a970e9ebbaefc5488bd5ee6790f35fd8
```

Gate berikutnya: reviewer/QA independen memeriksa fingerprint yang sama; browser role/deep-link/responsive menunggu runtime/fixture yang diizinkan; YAML/inventory PM Review, scope data/report di luar wave, performa MySQL, full CI dan acceptance parent tetap terbuka. Tidak ada persetujuan commit/push/PR/merge/deployment/aktivasi operasional. Perubahan fingerprint membatalkan handoff kandidat ini sampai scope dan bukti terdampak direkonsiliasi.

</details>

## G. Roadmap/wave — tampilan prioritas sekunder

**Ordering: Mixed.** Urutan di bawah mempertahankan roadmap lama; bukan dependensi teknis otomatis. Blocker nyata tercantum di tiap task. Pekerjaan bebas overlap dapat paralel; semua integrasi tetap melalui TASK-19.

| Wave / tujuan | Urutan parent yang dipertahankan | Gate bisnis |
|---|---|---|
| 1 — Fondasi data dan Master PM: data Operator konsisten, mudah digunakan/dianalisis | 1 TASK-01 → 2 TASK-17 → 3 TASK-07 → 4 TASK-20 | Master PM dan definisi data disetujui, validasi stabil, data baru konsisten. Fondasi boleh paralel menurut dependensi nyata. |
| 2 — Dokumen/data historis asli: buktikan penggunaan maintenance nyata | 5 TASK-21 → 6 TASK-22 → 7 TASK-08 | Satu mesin mempunyai riwayat tervalidasi dan dokumen asli yang memang berlaku. |
| 3 — AI membaca data nyata: jawaban database + dokumen berbukti | 8 TASK-03 → 9 TASK-18 → 10 TASK-05 → 11 TASK-16 | Admin mendapat jawaban bisnis benar, konsisten dan terlacak; QA/diagnostik menyertai query, bukan menunggu seluruhnya. TASK-02 adalah planning approved prasyarat. |
| 4 — Pengalaman AI lebih natural | 12 TASK-09 → 13 TASK-10 → 14 TASK-04 bila diperlukan | Follow-up dan rekomendasi berbukti; tidak ada perubahan maintenance otomatis. |
| 5 — UX consolidation dan pengurangan legacy | 15 TASK-06 → 16 TASK-13 → 17 TASK-14 → 18 TASK-15 | Navigasi konsisten, dependency legacy tidak lagi diperlukan, fitur kedua role tetap berjalan. Migrasi bertahap menurut design system. |
| 6 — Fitur AI tambahan | 19 TASK-11 → 20 TASK-12 | Kebutuhan pengguna nyata/manfaat dibuktikan sebelum pengembangan tambahan. |
| Continuous gate | TASK-19 di setiap Wave | Review, CI, regression/UAT, recovery dan approval sesuai risiko/kandidat. |

### G.1 Prioritas koordinasi terdekat

| Prioritas | Referensi | Fokus konkret |
|---|---|---|
| P1 | TASK-19.03 / Slice 1 | Low PHPDoc ditutup dan publikasi selesai: Slice 1 MERGED melalui PR #5; QA-1 `SHIP_FOR_OWNER_REVIEW` diterima. MySQL concurrency untuk kandidat lokal Slice 1 tetap NOT VERIFIED. |
| P1 | TASK-07.02/.03 | Tetap PLANNED; Slice 2 PARTIAL (currently-PAUSED aging/notification merged; temporal Resume belum diimplementasikan), Slice 3–5 OPEN. Bekukan dependency, date boundary, legacy dan acceptance sebelum coding. TASK-07.01 tidak dibuka ulang. |
| P1 | TASK-01 / M-01 | Q-02 diterima; kandidat lokal frozen/UI_NOT_VERIFIED. Penuhi prerequisite environment dan release/security acceptance yang belum ditutup. |
| P2 | TASK-17 | Rekonsiliasi YAML/scope di luar manifest, browser M-01 dan MySQL; jangan mengulang code review gabungan pada fingerprint identik. |
| P2 | TASK-20 | Koreksi kategori/status berdampak nyata dengan kontrak dan regression yang disetujui, bukan taxonomy engine baru. |

PR #2/#3/#4 sudah MERGED dan CI pasca-merge diterima; bukan blocker remote PHPUnit yang masih aktif. Gate terbuka: kandidat lokal, environment/browser, concurrency/performa MySQL, taxonomy, pilot data/dokumen asli dan release readiness. Assignment harian hanya pada Parallel Execution Plan.

## H. Kebijakan update dan preservasi

- Status naik setelah perubahan nyata diverifikasi; test PASS mempunyai scope, kandidat dan bukti yang jelas.
- Jangan menyatakan DONE hanya dari planning, audit, laporan tanpa bukti, test lokal atau satu child selesai.
- Tidak perlu mengulang pekerjaan selesai tanpa alasan regresi yang jelas; perubahan scope/keputusan bisnis harus disetujui.
- Sesudah acceptance, perbarui status sesuai scope yang disetujui; ownership penulisan mengikuti workflow aktif. Pada M-NEXT-17C, DocsWriter17C adalah satu-satunya penulis pengganti tiga dokumen canonical; Manager koordinator/final reviewer, bukan penulis dokumen bersamaan; independent replacement QA mereview read-only setelah freeze. Ownership tunggal Manager pada M-DOC-01 adalah konteks historis, bukan assignment aktif.
- M-DOC-01 hanya menyederhanakan tiga dokumen existing di root; tidak memberi izin perubahan source lain atau arsip baru. Backlog dan Parallel Plan tetap untracked sampai ada approval publikasi.
- **Snapshot historis v3:** 22.092 byte, SHA-256 `23e3e7d0676c14fdc428dab70733d03ef751e730d53865d06807b40523adf704`; bukan baseline edit M-DOC-01. Snapshot byte awal tiga dokumen pada gate ini dicatat terpisah untuk QA BEFORE/AFTER.
- Source Gemini/M-01, dirty/untracked di luar scope, staging/index, runtime database dan PRIME asal dipreservasi. Tidak ada reset/checkout/clean/rebase, penghapusan untracked, commit/push/PR/merge/deploy pada pembaruan ini.

**Prinsip akhir:** selesaikan pekerjaan yang sudah berjalan sebelum menambah kompleksitas baru. Percepat PRIME dengan paralelisme terkontrol tanpa mengorbankan keamanan, integritas data dan kualitas operasional.