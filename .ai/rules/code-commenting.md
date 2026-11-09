---
paths:
  - "app/**"
  - "resources/js/**"
  - "tests/**"
---

# Code Commenting & Human Readability Standard

Aturan ini berlaku untuk semua kode PRIME Google (PHP/Laravel, Vue, JavaScript,
TypeScript) dan melengkapi — bukan menggantikan — guideline Laravel Boost di
`AGENTS.md`. Tujuannya: kode yang dapat dipahami manusia, dengan komentar yang
menjelaskan maksud dan batasan, bukan mengulang kode.

## Prinsip Umum

Kode harus dapat dipahami melalui:

- Nama variabel dan method yang bermakna.
- Fungsi kecil dan fokus.
- Alur kontrol yang jelas.
- Komentar yang menjelaskan intent dan reasoning penting.

Komentar melengkapi kode yang mudah dibaca, tidak pernah menggantikannya.

## Kapan Komentar Wajib

Tulis komentar singkat ketika kode memuat:

- Aturan bisnis yang tidak jelas dari kodenya.
- Blok pemrosesan multi-tahap yang penting.
- Keputusan keamanan atau otorisasi.
- Kontrak API eksternal dan workaround provider (mis. perilaku Gemini File Search).
- Validasi atau kalkulasi yang kompleks.
- Job asinkron: retry, idempotensi, urutan pemrosesan.
- Konsistensi data dan keputusan transaksi.
- Perilaku tak terduga yang memang disengaja.
- Failure handling atau pembatasan fallback yang tidak jelas.

Jelaskan MENGAPA logika ada dan BATASAN apa yang dilindunginya. Untuk method
multi-tahap yang kompleks, gunakan komentar pendek pada batas logika yang
bermakna agar pembaca dapat mengikuti alurnya. Tidak perlu komentar untuk
setiap statement.

## Kapan Komentar Opsional

Method sederhana, assignment yang jelas, operasi CRUD dasar, dan kondisi yang
mampu menjelaskan dirinya umumnya tidak butuh komentar. Perbaiki penamaan atau
sederhanakan kode lebih dulu sebelum menambahkan penjelasan yang tidak
diperlukan.

## Pola Komentar yang Dilarang

Jangan menulis:

- Komentar yang mengulang baris berikutnya.
- Narasi generik ala AI.
- Komentar di setiap baris atau setiap blok sepele.
- Penjelasan bisnis yang salah atau spekulatif.
- Komentar yang sudah usang.
- Pemisah dekoratif berlebihan (`====`, `----`).
- Komentar yang memuat kredensial atau data pribadi.
- Klaim menyesatkan tentang keamanan atau kebenaran.
- Penjelasan berulang yang sudah jelas dari nama method.

Jangan memakai komentar untuk menyembunyikan kompleksitas kode yang tidak
perlu; sederhanakan kodenya.

## Bahasa dan Gaya

Gunakan bahasa Indonesia yang natural, profesional, dan lugas untuk komentar
bisnis dan implementasi, kecuali konvensi file atau framework tertentu
mensyaratkan bahasa Inggris. Biarkan identifier teknis, nama field API, nama
tipe, dan nama protokol tetap apa adanya. Utamakan komentar pendek, biasanya
satu sampai tiga baris. Komentar lebih panjang hanya bila diperlukan untuk
menjelaskan kontrak kompleks atau batasan keamanan yang penting. Hindari frasa
robotik dan jargon yang tidak perlu.

## PHP dan Laravel

Gunakan PHPDoc untuk mendokumentasikan kontrak publik atau class dengan
perilaku tidak jelas bila nilainya nyata; jangan menduplikasi type declaration
native PHP dengan PHPDoc redundan. Jelaskan secara signifikan: validasi bisnis,
pemeriksaan otorisasi, aturan konsistensi database, transaksi, pemrosesan
queue, dan perilaku saat layanan eksternal gagal. Sesuai guideline Boost,
PHPDoc diutamakan untuk kontrak; inline comment dipakai untuk logika tidak
jelas — aturan bisnis, keamanan, dan batasan transaksi termasuk kategori
logika kompleks yang boleh dan perlu dijelaskan inline. Ikuti konvensi
Laravel/Pint yang ada.

## Vue, JavaScript, dan TypeScript

Gunakan komentar untuk: perilaku reaktif yang tidak jelas, transisi state yang
kompleks, perilaku UI yang bergantung permission, alur asinkron penting, dan
workaround teknis. Jangan mengomentari template yang jelas atau event handler
dasar.

## Pemeliharaan Komentar

Saat mengubah sebuah blok kode:

- Periksa komentar di sekitarnya.
- Perbarui komentar yang maknanya berubah.
- Hapus komentar yang tidak lagi benar.
- Pertahankan penjelasan lama yang masih berguna.
- Jangan menulis ulang komentar yang tidak berhubungan.

Komentar yang bertentangan dengan implementasi adalah cacat
maintainability dan harus dibereskan.

## Contoh

### Contoh 1 —dokumen knowledge

BAD:

```php
// Check status.
if (!$document->isPublished()) {
    return;
}
```

GOOD:

```php
// Dokumen yang belum dipublikasikan tidak boleh menjadi
// sumber jawaban chatbot, meskipun sudah terindeks di Google.
if (!$document->isPublished()) {
    return;
}
```

### Contoh 2 —indeksasi Gemini

BAD:

```php
// Call API.
$operation = $gemini->importFile($file);
```

GOOD:

```php
// Simpan identitas operasi agar status indexing dapat
// diperiksa tanpa mengunggah ulang PDF secara tidak sengaja.
$operation = $gemini->importFile($file);
```

### Contoh 3 —operasi sepele, tanpa komentar

```php
public function isPublished(): bool
{
    return $this->published_at !== null;
}
```

Contoh bersifat ilustratif, bukan kode PRIME yang dapat dieksekusi.

## Checkpoint untuk Pekerjaan Kode Berikutnya

Untuk setiap tugas implementasi dan code review, pastikan:

- Logika penting yang tidak jelas sudah dijelaskan.
- Komentar menggambarkan perilaku saat ini secara akurat.
- Tidak ada komentar yang tidak perlu ditambahkan.
- Komentar lama tetap sinkron setelah perubahan.
- Komentar tidak membocorkan informasi sensitif.

Kualitas komentar ditinjau bersama kebenaran dan maintainability. Tidak ada
kuota persentase cakupan komentar dan tidak ada komentari setiap baris.
