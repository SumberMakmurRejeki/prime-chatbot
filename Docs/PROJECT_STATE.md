# PRIME Google — Status Proyek

Dokumen ini adalah **status engineering terkini** (canonical) untuk PRIME Google.
Fakta source, status Git pada checkpoint tertanggal, hasil verifikasi historis,
dan rencana implementasi dibedakan secara eksplisit.

| Item | Nilai |
|---|---|
| Terakhir diperbarui | 2026-11-09 |
| Baseline ter-commit | `cc5378ab19e46370e922aac5ff32c8f1cf4018a1` (`chore: establish initial PRIME Google baseline`, 2026-11-02) |
| Agent-foundation ter-commit | `e70c6eefc5316957ef51f5e8cc09c99245af53e0`; 35 file committed |
| Penggabungan agent-foundation | COMMITTED — PR #1 digabung; `origin/main` = `277d7251da2ea9768bef480927223b8a502ca456` (`Merge pull request #1 from SumberMakmurRejeki/chore/prime-google-agent-foundation`, 2026-11-04) |
| Branch kerja pada checkpoint | `feature/gemini-file-search`; belum dipublikasikan ke `origin` |
| Commit AI/Gemini pada branch kerja | `f156825`, `d3c7ac0` (2026-11-07), `6d8a507` (2026-11-08, head sebelum finalisasi) |
| Commit milestone Gemini (finalisasi 2026-11-09) | `0981c39` (`feat(ai): add Gemini File Search indexing provenance and scoped retrieval`) dan `37e82cc` (`feat(ai): bind Admin Assistant to Gemini File Search`); keduanya hanya lokal |
| Commit dokumentasi status milestone | `741aa70` (`docs: finalize Gemini milestone status and dependency decisions`), HEAD final `741aa70` (2026-11-09); commit lokal, belum dipublikasikan |
| Working tree pada checkpoint | Sisa perubahan lokal yang sengaja tidak di-commit: `.gitignore` (entri `/.playwright-cli/`, artefak runtime agent lokal) dan `Docs/design-system-reference/` (artefak desain, bukan kode kanonis). Ini observasi tertanggal, bukan status permanen |
| Remote | `origin` → `https://github.com/SumberMakmurRejeki/prime-chatbot.git` |

Checkpoint Git diverifikasi 2026-11-08 dan diperbarui pada finalisasi 2026-11-09
melalui `git rev-parse HEAD`, `git branch --show-current`,
`git status --porcelain=v1`, `git log`, `git remote -v`, dan
`git ls-remote --heads origin`.

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

## 1. Identitas proyek dan baseline saat ini

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
| Branch kerja Gemini | `feature/gemini-file-search` (`6d8a5075`) hanya lokal; tidak ada di `origin` | `git branch -a`, `git ls-remote --heads origin` |
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

## 4. Status implementasi AI/RAG saat ini

Ringkasan: jalur Assistant pada branch kerja (commit `0981c39` + `37e82cc`)
memakai **Gemini File Search** untuk retrieval SOP dan **Gemini** untuk generasi
jawaban; tidak ada fallback Qdrant otomatis di jalur Assistant. **Qdrant tetap
menjadi dependency legacy** pada lifecycle dokumen SOP dan layanan vector store
lama. Statusnya harus dibedakan: implementasi ter-commit (SOURCE-VERIFIED),
bukti QA/test (TEST-VERIFIED), dan aktivasi operasional (PENDING).

| Aspek | Status | Bukti |
|---|---|---|
| Binding jawaban Assistant | SOURCE-VERIFIED — `GroundedAnswerService` di-bind ke `GeminiGroundedAnswerService`; `AiConversationService` hanya bergantung pada kontrak itu | `app/Providers/AppServiceProvider.php:31`, `app/Services/AI/AiConversationService.php:115` |
| Retrieval Gemini ter-scope | SOURCE-VERIFIED — tool File Search dibangun hanya dari kandidat eligible; kandidat kosong berarti tidak ada permintaan, identitas ambigu gagal tertutup | `app/Services/AI/GeminiRetrievalSourceScope.php`, `app/Services/AI/GeminiRetrievalSourceTool.php` |
| Kelayakan SOP terbit | SOURCE-VERIFIED — hanya revisi `ready` yang sedang `is_published`, dengan baris indeks Gemini `completed`, snapshot identitas cocok, store cocok, dan provenance kredensial cocok | `app/Services/AI/GeminiRetrievalEligibilityService.php` |
| Provenance kredensial dan import identity | SOURCE-VERIFIED — migration menambah `credential_configuration_id`, `credential_revision_id`, dan `import_identity` unik per generasi impor; nilai hilang tidak dipercaya | `database/migrations/2026_11_08_000001_add_indexing_provenance_to_knowledge_document_gemini_indexings_table.php` |
| Kontrak kepercayaan sitasi | SOURCE-VERIFIED — anotasi harus cocok dengan peta registry tepercaya (kode, versi kanonik string, URI store, `import_identity`); jawaban tanpa sitasi terverifikasi tidak ditampilkan sebagai jawaban SOP | `app/Services/AI/KnowledgeCitationMapper.php`, `app/Services/AI/GeminiGroundedAnswerService.php` |
| Assistant Admin-only | SOURCE-VERIFIED + TEST-SUPPORTED — grup `/assistant` memakai `EnsureActiveAdmin`; endpoint AI settings/knowledge memakai `prime.role:admin` + `can:manage_ai` | `routes/web.php:185-215`, `app/Providers/AppServiceProvider.php:57-60`, `tests/Feature/AI/GeminiAssistantChatTest.php` |
| Indexing Gemini File Search | SOURCE-VERIFIED — opt-in, default mati, diproses antrean, store dari `GEMINI_FILE_SEARCH_STORE_ID`, tidak mempublikasikan SOP dan tidak mengubah retrieval | `config/gemini_file_search.php`, `app/Services/AI/GeminiDocumentIndexingService.php`, `app/Jobs/IndexKnowledgeDocumentInGemini.php` |
| Kredensial Gemini tunggal | SOURCE-VERIFIED — satu sumber koneksi dari revisi chat provider aktif; kredensial tidak disimpan di config File Search | `app/Services/AI/GeminiFileSearchConnection.php` |
| **Cacat AI Settings** | SOURCE-VERIFIED — `createRevision()` selalu menulis driver `openai-compatible`, sehingga membuat/merevisi konfigurasi Gemini dari UI Admin belum benar | `app/Services/Settings/AiProviderConfigurationService.php:132-149` |
| Qdrant legacy di lifecycle SOP | SOURCE-VERIFIED — `VectorStore` masih di-bind ke `QdrantVectorStore` dan dipakai `KnowledgeDocumentService` (process/publish) serta `DocumentEmbeddingIndexingService`; Qdrant bukan backend Assistant saat ini | `app/Providers/AppServiceProvider.php:32`, `app/Services/AI/KnowledgeDocumentService.php:134,206` |
| Migration provenance di target operasional | PENDING — migration sudah di-commit (`0981c39`) tetapi belum dieksekusi pada target operasional; baris historis tanpa provenance/import identity tidak pernah eligible | Migration di atas; belum ada bukti penerapan operasional |
| Aktivasi/cutover operasional | PENDING — QA bukan approval produksi; menunggu perbaikan AI Settings, penerapan migration, verifikasi operasional, dan approval Owner | OWNER DECISION (2026-11-08); tidak ada bukti aktivasi operasional |
| Web search / grounding via web | NOT IMPLEMENTED — tidak ada integrasi terverifikasi dalam scope ini | Tidak ada bukti source yang diverifikasi di sini |
| AI operasional terhadap database domain | NOT IMPLEMENTED — jalur yang diverifikasi hanya menjawab dari dokumen SOP | Tidak ada jalur query domain yang diverifikasi |

**QA (TEST-VERIFIED historis):** laporan OMP 58 mencatat synthetic live Gemini
verification dan browser Assistant E2E berhasil, bersama 378 test dan 1.738
assertion lulus pada run tersebut. Run PHPUnit pada 2026-11-09 (PHP 8.3.36,
`vendor/bin/phpunit`) menghasilkan **1.193 test, 6.970 assertion, PASS**. Angka
itu adalah hasil PHPUnit aktual pada SQLite `:memory:`; ia tidak membuktikan
aktivasi operasional dan tidak menggantikan pemeriksaan live/browser yang tidak
dijalankan ulang pada sesi dokumentasi ini.

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

- Qdrant: dependensi **legacy sementara**. Tidak diperlukan untuk setup, test,
  atau halaman non-AI. Tidak boleh diarahkan ke data/koleksi PRIME asli.
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
| Metadata knowledge document adalah sumber kebenaran (MySQL + storage privat); indeks Qdrant/Gemini adalah state turunan | Status publikasi tetap dikendalikan PRIME; indexing tidak mempublikasikan SOP | `KnowledgeDocumentService.php`, `GeminiDocumentIndexingService.php` |
| `GroundedAnswerService` memakai Gemini File Search; Qdrant tetap terikat ke `VectorStore`/SOP lifecycle legacy | Tidak ada fallback; removal Qdrant menunggu cutover terverifikasi dan approval Owner | `AppServiceProvider.php`, `KnowledgeDocumentService.php` |

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
| Qdrant VectorStore dan embedding | **CONDITIONAL — RETIRE AFTER VERIFICATION** | `KnowledgeDocumentService::process()` menggunakan `DocumentEmbeddingIndexingService`; `publish()` memakai `VectorStore`; `AppServiceProvider` mengikatnya ke Qdrant. Tidak dapat dihapus sampai workflow upload/process/publish memakai lifecycle Gemini yang sudah aktif dan Qdrant tidak lagi mengubah publikasi/retrieval. Pengganti: lifecycle SOP berbasis registry + Gemini indexing. Verifikasi dengan tes lifecycle, UAT Admin, bukti migration/data reconciliation, dan approval Owner. |
| SOP upload/process/publish yang bergantung Qdrant | **CONDITIONAL — REPLACE** | `KnowledgeDocumentController`, `ProcessKnowledgeDocument`, dan `KnowledgeDocumentService` melayani upload/proses/publish Admin; process menghasilkan embedding Qdrant dan publish mengaktifkan vector store. Pertahankan alur Admin; ganti hanya ketergantungan Qdrant setelah Gemini lifecycle memverifikasi ingestion, readiness, publish/supersede, rollback/failure, dan status index. Jangan menghapusnya pada milestone ini. |
| Konfigurasi RAG/embedding lama | **CONDITIONAL — RETIRE AFTER VERIFICATION** | `config/ai.php`/`config/qdrant.php`, resolver revisi embedding, dan `DocumentGroundedAnswerService` masih menjadi bagian pipeline legacy/tes; Qdrant readiness dan lifecycle mengonsumsi sebagian konfigurasi. Retire setelah consumer-runtime maupun consumer-test diinventaris, Qdrant lifecycle diganti, dan regression suite membuktikan tidak ada rujukan aktif. `DocumentGroundedAnswerService` tidak terikat sebagai fallback runtime; ia tetap diuji sebagai legacy implementation. |
| AI provider dan AI manager settings | **KEEP, WITH DEFECT** | Route Admin `settings/ai/providers` dan `settings/ai/managers` mengelola provider/revisi dan hak akses manager. Provider revisions saat ini dipaksa `openai-compatible`; driver Gemini masih perlu koreksi sebelum konfigurasi Gemini dapat dibuat/direvisi dengan benar. Simplifikasi menu/nav adalah roadmap terpisah, bukan alasan penghapusan. |
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
| Branch dipublikasikan ke `origin` | COMMITTED pada checkpoint 2026-11-03 | `git ls-remote --heads origin` |
| Working tree bersih | HISTORICAL — observasi saat HEAD `e70c6ee` (2026-11-03) diverifikasi; bukan status permanen | `git status --porcelain=v1` |
| PR #1 (agent-foundation) | COMMITTED — digabung; `origin/main` = `277d7251` (merge commit, 2026-11-04) | `git ls-remote --heads origin` (2026-11-08) |
| Implementasi Assistant Gemini File Search | COMMITTED — `0981c39` (provenance, import identity, scoped retrieval) dan `37e82cc` (binding Assistant, publikasi revisi tunggal); hasil QA dan PHPUnit di bawah | `git log`, `git show --stat` |
| Synthetic live Gemini verification | TEST-VERIFIED HISTORICAL menurut laporan OMP 58; tidak dijalankan ulang pada sesi dokumentasi ini | Laporan OMP 58 |
| Browser Assistant E2E | TEST-VERIFIED HISTORICAL menurut laporan OMP 58; tidak dijalankan ulang pada sesi dokumentasi ini | Laporan OMP 58 |
| Full PHPUnit | TEST-VERIFIED pada 2026-11-09: **1.193 test, 6.970 assertion, PASS** | `artisan test --compact`, PHP 8.3.36, output sesi ini |
| `composer audit` | HISTORICAL: 0 advisory; tidak dijalankan ulang | Checkpoint terdahulu |
| `npm audit` | HISTORICAL: 0 vulnerability; tidak dijalankan ulang | Checkpoint terdahulu |

Inventaris test aktual pada 2026-11-09: **77 file PHP** — 72 Feature dan 5 Unit
(72 + 5). Dijalankan ulang pada finalisasi 2026-11-09 dengan PHP 8.3.36:
`tests/Feature/AI` + `tests/Feature/KnowledgeDocumentLifecycleTest.php`
menghasilkan **378 test, 1.738 assertion, PASS**, dan seluruh suite
menghasilkan **1.193 test, 6.970 assertion, PASS**. Angka 378/1.738 dari OMP 58
kebetulan identik karena run itu mencakup subset yang sama; keduanya tidak
membuktikan aktivasi operasional.

Penerimaan formal atas seluruh kriteria `specs/product/SCOPE_LATEST.yaml` (status
HARDENED/PARTIAL/BLOCKED): NOT VERIFIED.

## 8. Keterbatasan dan pekerjaan terbuka

1. **Branch kerja belum dipublikasikan.** `feature/gemini-file-search` hanya ada
   lokal; jangan menganggap commit `f156825`/`d3c7ac0`/`6d8a507`/`0981c39`/
   `37e82cc` (dan commit dokumentasi milestone ini) sudah ada di `origin`.
   PR #1 sudah digabung (`origin/main` = `277d7251`). `.omp/mcp.json`
   tetap lokal (diabaikan Git).
2. **AI Settings belum dapat menyetel driver Gemini dengan benar.**
   `AiProviderConfigurationService::createRevision()` menetapkan
   `openai-compatible` untuk semua revisi. Perbaikan konfigurasi UI adalah
   blocker sebelum setup Gemini operasional.
3. **Migration provenance belum diterapkan pada target operasional.** Migration
   sudah di-commit sebagai `0981c39`; jangan menjalankan migration pada target
   operasional tanpa otorisasi. Baris lama tanpa provenance/import identity
   gagal tertutup sebagai kandidat.
4. **Gemini belum mendapat approval cutover operasional.** Source dan QA bukan
   bukti aktivasi atau readiness produksi. Perlu verifikasi deployment/UAT dan
   keputusan Owner setelah blocker di atas selesai.
5. **Qdrant masih dependency legacy.** Qdrant bukan backend Assistant, tetapi
   masih terikat ke SOP process/publish dan embedding/vector-store lama. Removal
   adalah milestone terpisah setelah migration/cutover disetujui.
6. **Synthetic live Gemini dan browser Assistant E2E hanya bukti historis**
   menurut laporan OMP 58. Keduanya tidak dijalankan ulang pada sesi dokumentasi.
7. **Referensi dokumentasi yang belum ada.** `specs/product/SCOPE_LATEST.yaml`
   menyebut `Docs/modules/AI_ASSISTANT.md`; file tersebut tidak ada di repository.
   Tidak ada referensi runtime yang menunjuk ke path itu.
8. **`public/favicon.ico` berukuran 0 byte** dan dikecualikan dari Git
   (`.gitignore:74-75`); tidak direferensikan aplikasi.
9. **Rute kandidat Dashboard Vue** masih dipertahankan sementara di
   `routes/web.php` (permukaan bukti internal).
10. **QA command `qa:provision-pm-scenario` membutuhkan akun `admin.prime` dan
   `operator.prime` yang sudah aktif**; akun tidak dibuat otomatis.
11. **Dokumentasi canonical lain belum ada**: `Docs/BUSINESS_RULES.md`,
   `Docs/ARCHITECTURE.md`, `Docs/OPERATIONS.md`, `Docs/modules/`. Isi dan status:
   NOT VERIFIED.
12. **Skema `push_subscriptions` legacy** belum diputuskan untuk dihapus.
13. **Status target operasional / deployment UAT**: NOT VERIFIED. PHPUnit SQLite
   dan QA live historis tidak membuktikan konfigurasi atau migration target.

## 9. Prioritas pengembangan berikutnya

Urutan ini adalah rekomendasi engineering berdasarkan gap di atas, bukan
komitmen jadwal.

1. **Gemini Settings configuration correction** — pilih/persist driver Gemini
   secara benar; satu konfigurasi credential tetap menjadi sumber chat dan File
   Search. Status: PENDING.
2. **Gemini SOP lifecycle cutover dari Qdrant** — setelah provenance migration,
   data/index verification, dan UAT; approval Owner dibutuhkan sebelum
   operasional. Qdrant tetap dependency sampai cutover disetujui. Status: PENDING.
3. **AI navigation dan menu simplification.** Status: PENDING.
4. **Master PM Checksheet dan PM Action UX audit.** Status: PENDING.
5. **Incremental Vue/Inertia migration.** Status: PENDING.
6. **Retirement of unused Blade business pages.** Status: PENDING; per halaman
   setelah pengganti diverifikasi dan diterima.
7. **Historical pre-PRIME maintenance data feature** — kebutuhan produk dan
   desain akses historis masih perlu discovery. Status: DISCOVERY NEEDED.
8. **Gemini controlled maintenance-data queries.** Status: DISCOVERY NEEDED;
   bukan bagian dari Assistant SOP saat ini.
9. **Operational SOP/data UAT.** Status: PENDING.
10. **Final release dan legacy cleanup**, termasuk Qdrant sesuai keputusan
    cutover. Status: PENDING.

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
| `database/migrations/` (34 migrasi pada checkpoint 2026-11-09, termasuk migration provenance Gemini yang belum diterapkan operasional) | Skema dan invariant database |
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
- `README.md` tetap menjadi pintu masuk setup; `specs/product/SCOPE_LATEST.yaml`
  tetap menjadi scope inisiatif historis dan tidak diubah dari dokumen ini.
