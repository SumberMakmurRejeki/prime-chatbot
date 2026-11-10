# PRIME Google — Status Proyek

Dokumen ini adalah **status engineering terkini** (canonical) untuk PRIME Google.
Fakta source, status Git pada checkpoint tertanggal, hasil verifikasi historis,
dan rencana implementasi dibedakan secara eksplisit.

| Item | Nilai |
|---|---|
| Terakhir diperbarui | 2026-11-10 |
| Baseline ter-commit | `cc5378ab19e46370e922aac5ff32c8f1cf4018a1` (`chore: establish initial PRIME Google baseline`, 2026-11-02) |
| Agent-foundation ter-commit | `e70c6eefc5316957ef51f5e8cc09c99245af53e0`; 35 file committed |
| Penggabungan agent-foundation | COMMITTED — PR #1 digabung; `origin/main` = `277d7251da2ea9768bef480927223b8a502ca456` (`Merge pull request #1 from SumberMakmurRejeki/chore/prime-google-agent-foundation`, 2026-11-04) |
| Branch kerja pada checkpoint | `feature/gemini-file-search`; belum dipublikasikan ke `origin` |
| Commit AI/Gemini sebelumnya di branch kerja | `f156825`, `d3c7ac0` (2026-11-07), `6d8a507` (2026-11-08), lalu `0981c39` dan `37e82cc` (finalisasi 2026-11-09) — keduanya hanya lokal |
| Commit backend Gemini yang diterima | `fc439ee2b8712b0c8e714e07f981108ebe865a99` (`feat(ai): add safe Gemini index recovery and history`, 2026-11-10); HEAD saat checkpoint |
| Commit dokumentasi status milestone | `741aa70`, `7a9d7c9`, `eeca798` (checkpoint dokumentasi terdahulu), disusul commit dokumentasi pembaruan Gemini backend ini. Semua lokal; `git log` adalah sumber untuk HEAD setelah checkpoint ini |
| Working tree pada checkpoint | `.gitignore` dimodifikasi (entri `/.playwright-cli/`) dan `Docs/design-system-reference/` tidak terlacak; keduanya dipertahankan sengaja di luar commit dokumentasi. Observasi tertanggal, bukan status permanen |
| Remote | `origin` → `https://github.com/SumberMakmurRejeki/prime-chatbot.git` |

Checkpoint Git diverifikasi 2026-11-10 melalui `git rev-parse HEAD`,
`git branch --show-current`, `git status --porcelain=v1`, `git log`,
`git remote -v`, `git branch -a`, dan `git ls-remote --heads origin`;
feature branch tidak ada pada heads `origin`.

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
| Branch kerja Gemini | `feature/gemini-file-search` (HEAD `fc439ee` pada checkpoint 2026-11-10) hanya lokal; tidak ada di `origin` | `git branch --show-current`, `git rev-parse HEAD`; `git ls-remote` terakhir 2026-11-08 |
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

Ringkasan: Gemini File Search adalah jalur retrieval SOP target tunggal PRIME dan
Gemini menghasilkan jawabannya. Qdrant tidak lagi menjadi dependency pemrosesan
PDF maupun publikasi SOP, dan bukan fallback Assistant. Backend lifecycle Gemini
telah diterima pada `fc439ee`; aktivasi operasional, UAT, recovery UI Admin, dan
pensiun total kode Qdrant tetap terpisah dari checkpoint tersebut.

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
| Recovery/status UI Admin | NOT IMPLEMENTED — `recover()` tidak memiliki pemanggil di `app/`, `routes/`, atau `resources/`; belum ada endpoint/trigger maupun tampilan status generation/history | Pencarian pemanggil pada checkpoint ini |
| Retry indexing | SOURCE-VERIFIED — `schedule()` melanjutkan state `queued`/`uploaded`/`pending`, menandai klaim basi `uploading`/`importing` sebagai `unknown`, dan tidak mengulang buta `completed`/`unknown`; generation baru hanya lewat jalur aman | `app/Services/AI/GeminiDocumentIndexingService.php` |
| Provenance kredensial dan import identity | SOURCE-VERIFIED — `credential_configuration_id`, `credential_revision_id`, dan `import_identity` tersimpan per generasi impor; nilai hilang atau revisi kredensial yang berbeda tidak dipercaya | `database/migrations/2026_11_08_000001_add_indexing_provenance_to_knowledge_document_gemini_indexings_table.php` |
| Kontrak kepercayaan sitasi | SOURCE-VERIFIED — anotasi harus cocok dengan peta registry tepercaya (kode, versi kanonik string, URI store, `import_identity`); jawaban tanpa sitasi terverifikasi tidak ditampilkan sebagai jawaban SOP | `app/Services/AI/KnowledgeCitationMapper.php`, `app/Services/AI/GeminiGroundedAnswerService.php` |
| Assistant Admin-only | SOURCE-VERIFIED + TEST-SUPPORTED — grup `/assistant` memakai `EnsureActiveAdmin`; endpoint AI settings/knowledge memakai `prime.role:admin` + `can:manage_ai` | `routes/web.php`, `app/Providers/AppServiceProvider.php`, `tests/Feature/AI/GeminiAssistantChatTest.php` |
| Kredensial Gemini tunggal | SOURCE-VERIFIED — satu sumber koneksi dari revisi chat provider aktif; kredensial tidak disimpan di config File Search | `app/Services/AI/GeminiFileSearchConnection.php` |
| **AI Settings Gemini driver** | SOURCE-VERIFIED + TEST-VERIFIED — Admin memilih driver dari daftar tervalidasi server-side; create menyimpan pilihan, revise mempertahankan driver konfigurasi, dan pergantian jenis provider pada revisi ditolak | `app/Http/Requests/Settings/StoreAiProviderConfigurationRequest.php`, `app/Services/Settings/AiProviderConfigurationService.php`, `tests/Feature/Settings/AiProviderConfigurationTest.php` |
| Qdrant di runtime | SOURCE-VERIFIED — tidak dipakai `KnowledgeDocumentService::process()`/`publish()` dan bukan fallback Assistant. Implementasi vector store, embedding legacy, readiness inspector, serta consumer/test-nya masih ada di repository; retirement belum disetujui | `app/Services/AI/KnowledgeDocumentService.php`, `app/Providers/AppServiceProvider.php`, `app/Services/AI/` |
| Migration/aktivasi operasional | PENDING — migration provenance dan generation history sudah di-commit, tetapi belum dibuktikan diterapkan pada target operasional; QA bukan approval produksi | Migration terkait; tidak ada bukti penerapan operasional |
| UAT FNC-01, MySQL concurrency, cutover | PENDING — belum dijalankan pada task ini; source dan test bukan bukti UAT atau kesiapan produksi | Belum ada bukti verifikasi pada checkpoint ini |
| Web search / grounding via web | NOT IMPLEMENTED — tidak ada integrasi terverifikasi dalam scope ini | Tidak ada bukti source yang diverifikasi di sini |
| AI operasional terhadap database domain | NOT IMPLEMENTED — jalur terverifikasi hanya menjawab dari dokumen SOP | Tidak ada jalur query domain yang diverifikasi |

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

Commit `fc439ee` (2026-11-10) kemudian mengubah
`KnowledgeDocumentLifecycleTest`, `GeminiDocumentIndexingTest`, dan
`GeminiIndexingProvenanceTest`, serta menambah dua migration, sehingga angka
di atas tidak lagi mewakili HEAD. Test tidak dijalankan ulang pada task
dokumentasi ini.

Penerimaan formal atas seluruh kriteria `specs/product/SCOPE_LATEST.yaml` (status
HARDENED/PARTIAL/BLOCKED): NOT VERIFIED.

## 8. Keterbatasan dan pekerjaan terbuka

1. **Branch kerja belum dipublikasikan.** `feature/gemini-file-search` hanya ada
   lokal; status remote tidak diperiksa ulang pada pembaruan ini. Jangan
   menyamakan commit lokal dengan rilis atau aktivasi operasional.
2. **Backend Gemini sudah di-commit, bukan readiness produksi.** `fc439ee`
   memisahkan processing PDF, indexing antrean, publikasi eksplisit, generation
   history, dan recovery aman. Ini bukan klaim UAT selesai atau siap produksi.
3. **Admin recovery/status/history UI belum ada.** `recover()` belum memiliki
   trigger UI/rute, dan belum ada tampilan status indexing maupun generation
   history untuk Admin.
4. **Operasional belum diverifikasi.** Penerapan migration, aktivasi provider,
   cutover, dan approval Owner belum dibuktikan pada target operasional.
5. **FNC-01 RAG UAT belum dijalankan** pada task ini; rencana UAT memakai PDF
   dummy FNC-01 dan 12 pertanyaan validasi.
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

## 9. Prioritas pengembangan berikutnya

Urutan berikut mencatat pekerjaan yang belum selesai; ini bukan jadwal maupun
persetujuan deployment.

1. **FNC-01 RAG UAT** dengan PDF dummy dan 12 pertanyaan validasi; catat hasil
   retrieval, sitasi, dan abstention tanpa menganggapnya aktivasi produksi.
2. **Admin Gemini recovery/indexing UI** — trigger recovery terotorisasi,
   status indexing, dan tampilan generation history.
3. **Verifikasi concurrency MySQL** untuk lifecycle dan publikasi.
4. **Migration/cutover operasional** dengan verifikasi target dan approval Owner.
5. **Qdrant retirement** setelah seluruh runtime consumer terinventarisasi dan
   terbukti tidak diperlukan.
6. **AI expansion discovery** — query terkontrol atas data PRIME operasional
   serta internet grounding dengan atribusi sumber.
7. **Master PM Checksheet / PM Action UX improvements.**
8. **Historical PM before PRIME** — kebutuhan dan desain masih perlu discovery.
9. **PM Executor dan operational workflow UAT.**
10. **Modernisasi gradual Vue/Inertia/NovaSpark**; halaman Blade diganti hanya
    setelah penggantinya diverifikasi dan diterima.
11. **AI navigation/menu simplification dan final release/legacy cleanup** tetap
    PENDING; retirement tidak dilakukan dalam milestone dokumentasi ini.

Status backend Gemini: **COMPLETED / COMMITTED** pada `fc439ee`.
Kesiapan operasional: **PENDING**.

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
- `README.md` tetap menjadi pintu masuk setup; `specs/product/SCOPE_LATEST.yaml`
  tetap menjadi scope inisiatif historis dan tidak diubah dari dokumen ini.
