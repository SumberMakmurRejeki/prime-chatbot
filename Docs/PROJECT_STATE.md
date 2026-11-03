# PRIME Google — Status Proyek

Dokumen ini adalah **status engineering terkini** (canonical) untuk PRIME Google.
Fakta source, status Git pada checkpoint tertanggal, hasil verifikasi historis,
dan rencana implementasi dibedakan secara eksplisit.

| Item | Nilai |
|---|---|
| Terakhir diperbarui | 2026-11-03 |
| Baseline ter-commit | `cc5378ab19e46370e922aac5ff32c8f1cf4018a1` (`chore: establish initial PRIME Google baseline`, 2026-11-02) |
| Agent-foundation ter-commit | `e70c6eefc5316957ef51f5e8cc09c99245af53e0`; 35 file committed |
| Branch pada checkpoint | `chore/prime-google-agent-foundation`, dipublikasikan ke `origin` |
| Working tree pada checkpoint | Bersih saat HEAD `e70c6ee` diverifikasi (2026-11-03); ini observasi checkpoint, bukan klaim status permanen |
| Remote | `origin` → `https://github.com/SumberMakmurRejeki/prime-chatbot.git` |
| PR #1 | NOT VERIFIED pada pembaruan ini; GitHub CLI tidak terautentikasi |

Checkpoint Git diverifikasi 2026-11-03 melalui `git status --porcelain=v1`,
`git branch -a`, `git log`, `git remote -v`, dan `git ls-remote --heads origin`.

Label bukti yang dipakai di dokumen ini:

| Label | Arti |
|---|---|
| SOURCE-VERIFIED | Dibuktikan langsung oleh source/konfigurasi di repository. |
| DOCUMENTED | Tercatat dalam dokumen/scope resmi repository. |
| COMMITTED | Dibuktikan oleh kondisi Git saat dokumen disusun. |
| HISTORICAL | Hasil verifikasi masa lalu; **tidak dijalankan ulang** saat dokumen ini dibuat. |
| NOT VERIFIED | Belum ada bukti; jangan dianggap benar. |
| NOT IMPLEMENTED | Fitur/kapabilitas tidak ada di source saat ini. |

## Status branch dan agent-foundation pada checkpoint 2026-11-03

Commit `e70c6eefc5316957ef51f5e8cc09c99245af53e0` berisi 35 file
agent-foundation dan merupakan HEAD `chore/prime-google-agent-foundation` pada
checkpoint 2026-11-03. Branch tersebut dipublikasikan di `origin`; `main`
menunjuk baseline `cc5378ab19e46370e922aac5ff32c8f1cf4018a1`. Working tree
terverifikasi bersih pada checkpoint tersebut; periksa Git untuk status
terkini. Commit agent-foundation belum digabung ke `main` menurut data lokal;
status PR #1 tidak dapat diperiksa karena GitHub CLI tidak terautentikasi.
`.omp/mcp.json` adalah konfigurasi lokal yang diabaikan `.gitignore`, jadi bukan
kandidat commit.

| Komponen agent | Status saat diperiksa | Bukti |
|---|---|---|
| Laravel Boost MCP | SOURCE-VERIFIED — server dikonfigurasi lewat `.omp/mcp.json` (lokal, diabaikan Git); sesi OMP saat dokumen ini diperbarui mengekspos tepat tiga tool Boost: `application-info`, `get-absolute-url`, `search-docs` | `.omp/mcp.json`; daftar tool sesi |
| Batas tool database / MCP | SOURCE-VERIFIED — 8 built-in tool Boost dikecualikan eksplisit (browser logs, database connections/query/schema, last error, log entries, record rule, Tinker); ini daftar pengecualian, bukan allowlist tiga tool | `config/boost.php` |
| Laravel guidelines skill | SOURCE-VERIFIED — 20 file | `.omp/skills/laravel-best-practices/` (verifikasi hitung file) |
| Testing skill | SOURCE-VERIFIED — 10 file | `.omp/skills/testing-best-practices/` (verifikasi hitung file) |
| Total file skill | 30 file pada dua direktori di atas | Hitung file direktori |

Tidak ada `.ai/rules/` di repository saat ini. Bagian `.ai/rules` dalam pedoman
Boost hanya berlaku jika direktori/path terkait tersedia.

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
| Branch dipublikasikan | `chore/prime-google-agent-foundation` ada di `origin`; `main` masih di baseline | `git branch -a`, `git ls-remote --heads origin` |
| Visibility remote | PRIVATE tidak diverifikasi pada checkpoint ini (kredensial `gh` tidak tersedia) | NOT VERIFIED |
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

Ringkasan: AI assistant dan registry knowledge document **ada di source**, tetapi
retrieval masih memakai **Qdrant legacy**. **Gemini File Search belum
diimplementasi.**

| Aspek | Status | Bukti |
|---|---|---|
| Abstraksi vector store dengan satu binding | SOURCE-VERIFIED | `app/Services/AI/VectorStore/VectorStore.php`; `app/Providers/AppServiceProvider.php:26` |
| Implementasi vector store | Qdrant (satu-satunya) | `app/Services/AI/VectorStore/Qdrant/` |
| Konfigurasi Qdrant tanpa default koleksi bersama | SOURCE-VERIFIED | `config/qdrant.php:20` (`env('QDRANT_COLLECTION', '')`) |
| Registry knowledge document (upload PDF, lifecycle, publish) | SOURCE-VERIFIED + TEST-SUPPORTED | `app/Services/AI/KnowledgeDocumentService.php`, `tests/Feature/KnowledgeDocument*Test.php` |
| Ingestion PDF (pdftotext + chunking) | SOURCE-VERIFIED | `config/ai.php:29-44`, `app/Services/AI/DocumentIngestion/` |
| Embedding indexing + compatibility guard | SOURCE-VERIFIED + TEST-SUPPORTED | `app/Services/AI/DocumentEmbeddingIndexingService.php`, `tests/Feature/AI/EmbeddingCompatibilityTest.php` |
| Retrieval + grounded answer | SOURCE-VERIFIED + TEST-SUPPORTED | `app/Services/AI/DocumentRetrievalService.php`, `app/Services/AI/DocumentGroundedAnswerService.php`, test terkait |
| Percakapan AI assistant | SOURCE-VERIFIED + TEST-SUPPORTED | `app/Services/AI/AiConversationService.php`, `tests/Feature/AI/Ai*Test.php` |
| Provider AI (termasuk Gemini) via Laravel AI SDK dan transport yang di-harden | SOURCE-VERIFIED | `config/ai.php:96` dan seterusnya, `app/Services/AI/Gateway/SecureGeminiGateway.php` |
| **Gemini File Search** | **NOT IMPLEMENTED** | Tidak ada kode `file_search`/`fileSearch` di `app/`, `config/`, `routes/`, `tests/` |
| Web search / grounding via web | **NOT IMPLEMENTED** | Tidak ada referensi `web_search`/`googleSearch` di `app/`, `config/` |
| AI operasional terhadap database aplikasi | **NOT IMPLEMENTED** | AI hanya membaca indeks knowledge document; tidak ada akses query domain |

Rencana yang **disetujui sebagai arah, bukan kapabilitas**: Gemini File Search
menggantikan Qdrant untuk RAG berbasis dokumen. Selama belum ada source dan test,
jangan menyatakan retrieval Gemini atau AI operasional sebagai selesai.

Reuse abstraksi `VectorStore` untuk Gemini File Search hanyalah **opsi desain yang
masih memerlukan validasi** — kontrak `VectorStore.php` (`VectorSpace`, `RetrievalQuery`,
`VectorChunk` dan lifecycle publikasi) belum tentu selaras dengan model File Search —
bukan pengganti drop-in yang dijamin.

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
- Gemini API: kunci di template masih placeholder kosong; bukan bukti integrasi
  File Search.
- Eksistensi database MySQL development/QA di server lokal: NOT VERIFIED pada
  penyusunan dokumen ini (tidak ada operasi MySQL).

## 6. Keputusan engineering yang berlaku

| Keputusan | Alasan/implikasi | Bukti |
|---|---|---|
| Timezone global `UTC`, tanggal bisnis via helper `Asia/Jakarta` | Perubahan timezone global adalah keputusan terpisah | `config/app.php:68`, `app/Services/PM/BusinessDate.php` |
| Metadata knowledge document adalah sumber kebenaran (MySQL + storage privat); Qdrant hanya indeks yang dapat dibangun ulang | Menjaga lifecycle dokumen meski indeks tidak tersedia | docblock `app/Services/AI/KnowledgeDocumentService.php` |
| Vector store diakses lewat interface `VectorStore` | Abstraksi memisahkan pemanggil dari adapter Qdrant; pemakaian ulang untuk Gemini File Search adalah opsi desain yang memerlukan validasi, bukan drop-in replacement yang terjamin | `app/Services/AI/VectorStore/VectorStore.php`, `app/Providers/AppServiceProvider.php:26`; kompatibilitas Gemini File Search NOT VERIFIED |
| Konfigurasi koleksi Qdrant tanpa default; nilai kosong ditolak saat resolusi | Mencegah akses tak sengaja ke koleksi bersama | `config/qdrant.php:20` |
| Seeder default tidak membuat akun/kredensial | Mencegah akun privileged tersembunyi | `database/seeders/DatabaseSeeder.php`, `tests/Feature/DatabaseSeederSafetyTest.php` |
| Guard database aktif di semua environment | Melindungi database PRIME asli | `app/Providers/TestingDatabaseServiceProvider.php` |
| Web Push dihapus; skema `push_subscriptions` dipertahankan sebagai legacy | Pembersihan skema adalah risiko terpisah | `config/webpush.php` |
| Secret hanya di `.env` lokal; runtime spool, dump, backup diabaikan Git | Menjaga repository bebas rahasia dan artefak | `.gitignore` |

## 7. Milestone yang sudah diterima

| Checkpoint | Status | Bukti |
|---|---|---|
| Baseline repo ditegakkan sebagai commit awal | COMMITTED | `cc5378a` (2026-11-02) |
| Baseline hardening (pra-commit pertama) tercatat sebagai scope inisiatif | DOCUMENTED | `specs/product/SCOPE_LATEST.yaml` |
| Agent-foundation committed (35 file) | COMMITTED | `e70c6ee`, HEAD `chore/prime-google-agent-foundation` |
| Branch dipublikasikan ke `origin` | COMMITTED pada checkpoint 2026-11-03 | `git ls-remote --heads origin` |
| Working tree bersih | HISTORICAL — observasi saat HEAD `e70c6ee` (2026-11-03) diverifikasi; bukan status permanen | `git status --porcelain=v1` |
| PR #1 | NOT VERIFIED — GitHub CLI tidak terautentikasi pada checkpoint | Tidak ada pernyataan open/merged |
| Full PHPUnit | **HISTORICAL**: 989 PASS, 5.951 assertion | Tidak dijalankan ulang untuk dokumen ini |
| `composer audit` | **HISTORICAL**: 0 advisory | Tidak dijalankan ulang |
| `npm audit` | **HISTORICAL**: 0 vulnerability | Tidak dijalankan ulang |

Test suite saat ini berisi 66 file test (61 Feature, 5 Unit). Jumlah test yang
benar-benar lulus hanya boleh dinyatakan dari eksekusi aktual, bukan dari
dokumen ini.

Penerimaan formal atas seluruh kriteria `specs/product/SCOPE_LATEST.yaml` (status
HARDENED/PARTIAL/BLOCKED): NOT VERIFIED.

## 8. Keterbatasan dan pekerjaan terbuka

1. **Status PR #1 belum terverifikasi pada checkpoint ini** karena GitHub CLI
   tidak terautentikasi. Jangan menyimpulkan PR terbuka atau sudah digabung
   tanpa memeriksa metadata GitHub. `.omp/mcp.json` tetap lokal (diabaikan Git).
2. **Gemini File Search belum ada** — belum ada implementasi, dokumentasi modul,
   maupun test. RAG masih Qdrant legacy.
3. **Qdrant tidak boleh menjadi dependensi setup.** Tidak ada jaminan operasional
   Qdrant untuk environment mana pun; retrieval tidak berjalan tanpa Qdrant dan
   kredensial provider embedding.
4. **Referensi dokumentasi yang belum ada.** `specs/product/SCOPE_LATEST.yaml`
   menyebut `Docs/modules/AI_ASSISTANT.md`; file tersebut tidak ada di repository.
   Tidak ada referensi runtime yang menunjuk ke path itu.
5. **`public/favicon.ico` berukuran 0 byte** dan dikecualikan dari Git
   (`.gitignore:74-75`); tidak direferensikan aplikasi.
6. **Rute kandidat Dashboard Vue** masih dipertahankan sementara di
   `routes/web.php` (permukaan bukti internal).
7. **QA command `qa:provision-pm-scenario` membutuhkan akun `admin.prime` dan
   `operator.prime` yang sudah aktif**; akun tidak dibuat otomatis.
8. **Dokumentasi canonical lain belum ada**: `Docs/BUSINESS_RULES.md`,
   `Docs/ARCHITECTURE.md`, `Docs/OPERATIONS.md`, `Docs/modules/`. Isi dan status:
   NOT VERIFIED.
9. **Skema `push_subscriptions` legacy** belum diputuskan untuk dihapus.

## 9. Prioritas pengembangan berikutnya

Urutan ini adalah rekomendasi engineering berdasarkan gap di atas, bukan
komitmen jadwal.

1. Tetapkan dokumentasi canonical berikutnya sesuai standar: aturan bisnis PM,
   arsitektur, operasional. Mulai dari modul yang paling sering berubah.
2. Putuskan dan rencanakan inisiatif **Gemini File Search** sebagai pengganti
   Qdrant untuk RAG berbasis dokumen (desain, migrasi indeks, test, dokumentasi).
   Sampai itu selesai, Qdrant tetap berstatus legacy.
3. Bersihkan sisa legacy: keputusan skema `push_subscriptions` dan rute kandidat
   Dashboard Vue.
4. Tindak lanjuti review dan keputusan merge `chore/prime-google-agent-foundation`
   (PR #1) sesuai arahan owner.

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
| `database/migrations/` (32 migrasi) | Skema dan invariant database |
| `database/seeders/` | Kebijakan seeding |
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
