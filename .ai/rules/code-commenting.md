---
paths:
  - "**/*.php"
  - "**/*.vue"
  - "**/*.js"
  - "**/*.ts"
  - "**/*.sql"
---

# PRIME — Code Commenting Standard

**Status:** ACTIVE setelah diintegrasikan ke AGENTS.md
**Bahasa komentar:** Bahasa Indonesia
**Cakupan:** PHP, Laravel, Vue, JavaScript, TypeScript, Vuetify, SQL dan automated testing.

## 1. Tujuan

Setiap kode yang memiliki logika bisnis atau teknis kompleks harus memiliki penjelasan yang membantu manusia memahami:

- Apa tujuan kode tersebut.
- Mengapa kode tersebut diperlukan.
- Bagaimana alur logika bisnisnya.
- Kondisi apa yang ditangani.
- Aturan bisnis apa yang dilindungi.
- Dampak apabila logika tersebut diubah.

Komentar harus membantu maintenance, debugging, code review dan pengembangan PRIME tanpa ketergantungan berlebihan terhadap AI.

## 2. Standar bahasa

| Elemen                    | Bahasa       |
| ------------------------- | ------------ |
| Komentar kode             | Indonesia    |
| PHPDoc description        | Indonesia    |
| JSDoc description         | Indonesia    |
| Penjelasan business logic | Indonesia    |
| Penjelasan automated test | Indonesia    |
| Nama class                | Inggris      |
| Nama method dan function  | Inggris      |
| Nama variable             | Inggris      |
| Nama component            | Inggris      |
| Nama constant             | Inggris      |
| PHPDoc dan JSDoc tags     | Standar asli |

Gunakan bahasa Indonesia sederhana, singkat, jelas dan mudah dipahami.

Istilah teknis seperti transaction, locking, middleware, validation, cache, query, request dan response boleh tetap menggunakan bahasa Inggris.

## 3. Kapan komentar diperlukan

| Jenis kode                                     | Ketentuan                                            |
| ---------------------------------------------- | ---------------------------------------------------- |
| Class dengan tanggung jawab bisnis             | Jelaskan tanggung jawab                              |
| Service dengan business logic kompleks         | Wajib                                                |
| Method dengan aturan bisnis khusus             | Wajib                                                |
| Perhitungan jadwal PM                          | Wajib                                                |
| Database transaction                           | Wajib jika memiliki tujuan bisnis atau risiko khusus |
| Concurrency dan locking                        | Wajib                                                |
| Perlindungan histori                           | Wajib                                                |
| Authorization khusus                           | Wajib                                                |
| Query kompleks                                 | Wajib                                                |
| Integrasi Gemini                               | Wajib untuk logika nontrivial                        |
| Error handling khusus                          | Wajib                                                |
| Job, retry dan recovery                        | Wajib untuk perilaku nontrivial                      |
| Vue state dan computed kompleks                | Wajib                                                |
| Watcher dan lifecycle kompleks                 | Wajib                                                |
| Vuetify dengan konfigurasi khusus              | Jika diperlukan                                      |
| Automated test dengan skenario bisnis kompleks | Wajib                                                |
| Getter, setter dan assignment sederhana        | Tidak diperlukan                                     |
| Import dan deklarasi sederhana                 | Tidak diperlukan                                     |
| CRUD sederhana yang sudah jelas                | Tidak diperlukan                                     |

## 4. Format komentar

### 4.1 Inline comment

Gunakan `//` untuk menjelaskan satu keputusan atau blok logika yang membutuhkan konteks tambahan.

Contoh:

```
// Pertahankan histori pemeriksaan meskipun checksheet dinonaktifkan.
```

### 4.2 Block comment

Gunakan block comment untuk menjelaskan alur atau aturan bisnis yang membutuhkan beberapa kalimat.

```
/*
 * Jadwal yang sudah memiliki execution tidak boleh dihapus.
 * Perubahan hanya diterapkan pada jadwal yang memenuhi
 * aturan perubahan Master Checksheet.
 */
```

### 4.3 PHPDoc

Gunakan PHPDoc untuk menjelaskan tanggung jawab class atau kontrak method apabila tidak cukup dijelaskan oleh nama dan type declaration.

```
/**
 * Memeriksa apakah Preview masih sesuai dengan kondisi jadwal.
 *
 * Perubahan data setelah Preview menyebabkan token menjadi
 * tidak valid sehingga Admin perlu melakukan Preview ulang.
 */
```

Gunakan `@param`, `@return` dan `@throws` apabila memberikan informasi tambahan yang diperlukan oleh developer atau tooling.

Jangan mengulang type declaration yang sudah jelas tanpa informasi tambahan.

### 4.4 JSDoc

Gunakan JSDoc untuk function JavaScript atau TypeScript yang memiliki kontrak atau perilaku kompleks.

```
/**
 * Menyiapkan parameter filter untuk pengambilan data jadwal PM.
 * Filter yang kosong tidak dikirim agar backend menggunakan
 * cakupan data yang sesuai dengan kontrak request.
 */
```

## 5. Contoh GOOD dan BAD

Seluruh contoh berikut bersifat ilustratif. Nama function, class dan variable bukan deklarasi struktur aktual PRIME.

### 5.1 Komentar kode sederhana

BAD:

```
// Mengambil nama mesin.
$machineName = $machine->name;
```

GOOD:

```
$machineName = $machine->name;
```

Tidak perlu komentar karena maksud kode sudah jelas.

### 5.2 Business logic

BAD:

```
// Mengecek status.
if ($status === 'waiting_review') {
    // ...
}
```

GOOD:

```
// Operator telah mengirim hasil PM, tetapi pemeriksaan
// masih menunggu keputusan review dari Admin.
if ($status === 'waiting_review') {
    // ...
}
```

### 5.3 Master Checksheet

BAD:

```
// Mengecek token.
if ($previewToken !== $currentToken) {
    // ...
}
```

GOOD:

```
// Tolak Apply apabila kondisi data berubah setelah Preview,
// agar Admin tidak menerapkan perubahan berdasarkan data lama.
if ($previewToken !== $currentToken) {
    // ...
}
```

### 5.4 Database transaction

BAD:

```
// Menjalankan transaction.
DB::transaction(function () {
    // ...
});
```

GOOD:

```
// Simpan execution dan perubahan jadwal dalam satu transaksi
// agar kegagalan salah satu proses tidak menghasilkan data parsial.
DB::transaction(function () {
    // ...
});
```

### 5.5 Concurrency dan locking

BAD:

```
// Lock jadwal.
$schedule = PmSchedule::query()
    ->lockForUpdate()
    ->findOrFail($scheduleId);
```

GOOD:

```
// Kunci record jadwal dalam transaksi aktif untuk menyelaraskan
// perubahan Admin dengan Submit PM yang berjalan bersamaan.
$schedule = PmSchedule::query()
    ->lockForUpdate()
    ->findOrFail($scheduleId);
```

Komentar locking harus sesuai dengan transaction boundary, urutan locking dan perilaku implementasi aktual.

Jangan mengklaim bahwa locking mencegah seluruh race condition apabila belum terbukti.

### 5.6 Perlindungan histori

BAD:

```
// Mengecek execution.
if ($hasExecution) {
    // ...
}
```

GOOD:

```
// Jadwal yang telah memiliki execution harus dipertahankan
// karena menjadi bagian dari histori pemeriksaan mesin.
if ($hasExecution) {
    // ...
}
```

### 5.7 PM Pause dan Resume

GOOD:

```
// Hitung jadwal berikutnya berdasarkan aturan temporal resume
// agar pengaktifan kembali checksheet tidak membuat jadwal ganda.
```

### 5.8 Single dan Multiple Action

GOOD:

```
// Validasi setiap Action secara terpisah agar Submit PM
// tidak diterima ketika hasil pemeriksaan masih belum lengkap.
```

### 5.9 Authorization

BAD:

```
// Cek permission.
Gate::authorize('manage_ai');
```

GOOD:

```
// Batasi pengelolaan provider AI kepada pengguna yang memiliki
// izin khusus, meskipun pengguna mengetahui URL endpoint.
Gate::authorize('manage_ai');
```

### 5.10 Operational query

GOOD:

```
// Gunakan periode dan identitas mesin yang telah divalidasi
// agar hasil query tidak keluar dari cakupan pertanyaan Admin.
```

### 5.11 Gemini AI

BAD:

```
// Mengirim request ke Gemini.
```

GOOD:

```
// Kirim hasil query Laravel sebagai sumber fakta operasional.
// Gemini hanya menyusun jawaban dan tidak menjalankan SQL bebas.
```

### 5.12 SOP/WI dan Knowledge Management

GOOD:

```
// Keberhasilan indexing tidak berarti dokumen telah disetujui.
// Retrieval operasional hanya menggunakan dokumen yang eligible.
```

### 5.13 Error handling

BAD:

```
// Menangani error.
```

GOOD:

```
// Bedakan kegagalan pengambilan data dari hasil query kosong.
// Kegagalan tidak boleh dilaporkan kepada Admin sebagai nilai nol.
```

### 5.14 Retry dan recovery

GOOD:

```
// Periksa status resource remote sebelum retry agar proses
// pemulihan tidak membuat dokumen Gemini duplikat.
```

### 5.15 Historical data import

GOOD:

```
// Tolak histori yang sudah pernah diimport agar pengunggahan
// ulang file tidak menggandakan hasil pemeriksaan sebelumnya.
```

## 6. Komentar Vue, Inertia dan Vuetify

### 6.1 State

BAD:

```
// Membuat loading.
const isLoading = ref(false)
```

GOOD:

```
const isLoading = ref(false)
```

State sederhana tidak membutuhkan komentar tambahan.

### 6.2 Computed property

GOOD:

```
// Tombol Apply hanya dapat digunakan ketika Preview masih valid
// dan tidak ada perubahan form setelah Preview dibuat.
const canApply = computed(() => {
    return hasValidPreview.value && !hasUnsavedChanges.value
})
```

### 6.3 Watcher

GOOD:

```
// Perubahan konfigurasi jadwal membatalkan Preview sebelumnya
// agar Admin tidak menerapkan dampak perubahan yang sudah usang.
watch(scheduleConfig, invalidatePreview, { deep: true })
```

### 6.4 Inertia

GOOD:

```
// Pertahankan posisi scroll ketika validasi gagal agar Admin
// tetap berada pada bagian form yang perlu diperbaiki.
```

### 6.5 Vuetify

BAD:

```
<!-- Tombol simpan -->
<VBtn>Simpan</VBtn>
```

GOOD:

```
<VBtn>Simpan</VBtn>
```

Komentar pada komponen Vuetify diperlukan apabila konfigurasi, event atau interaksi komponen memiliki alasan bisnis yang tidak langsung terlihat.

```
<!-- Cegah Apply selama Preview tidak valid atau request berjalan. -->
<VBtn
    :disabled="!canApply"
    :loading="isApplying"
>
    Terapkan Perubahan
</VBtn>
```

Pembatasan tombol frontend tidak menggantikan validasi dan authorization backend.

## 7. Komentar automated testing

Komentar test harus membantu memahami skenario, kondisi dan perilaku bisnis yang dilindungi.

BAD:

```
// Melakukan assertion.
$this->assertDatabaseHas(/* ... */);
```

GOOD:

```
// Pastikan penolakan perubahan checksheet tidak menghapus
// histori execution yang sebelumnya dikirim Operator.
$this->assertDatabaseHas(/* ... */);
```

Untuk skenario kompleks, gunakan pembagian Arrange, Act dan Assert bila membantu keterbacaan.

```
// Arrange: Operator memiliki execution yang menunggu review.

// Act: Admin mencoba mengubah konfigurasi Master Checksheet.

// Assert: Histori execution dan hasil pemeriksaan tetap tersimpan.
```

Komentar test harus sesuai dengan assertion yang benar-benar dilakukan.

Jangan menyatakan bahwa test membuktikan concurrency MySQL apabila test hanya menggunakan SQLite.

## 8. TODO, FIXME dan NOTE

| Marker | Penggunaan                                  |
| ------ | ------------------------------------------- |
| TODO   | Pekerjaan lanjutan yang belum selesai       |
| FIXME  | Masalah yang diketahui dan perlu diperbaiki |
| NOTE   | Informasi penting mengenai perilaku kode    |

Contoh:

```
// TODO(TASK-03): Generalisasi filter mesin untuk query PM Review.
// FIXME(TASK-07.02): Selesaikan acceptance concurrency InnoDB
// untuk perubahan Action definition bersamaan dengan Submit PM.
// NOTE: Tanggal bisnis menggunakan Asia/Jakarta, sedangkan
// penyimpanan timestamp mengikuti konfigurasi waktu aplikasi.
```

TODO dan FIXME harus memiliki alasan serta referensi task apabila tersedia.

Hapus marker ketika pekerjaan terkait sudah diselesaikan.

Jangan menggunakan TODO untuk menyembunyikan kebutuhan acceptance yang belum terpenuhi.

## 9. Larangan

Agent tidak boleh:

- Menambahkan komentar pada setiap baris kode.
- Mengulang nama function atau variable sebagai komentar.
- Menulis komentar yang tidak sesuai dengan implementasi.
- Mengarang aturan bisnis yang belum disetujui.
- Mengklaim fitur aman atau terverifikasi tanpa bukti.
- Menyimpan API key, password, token atau credential dalam komentar.
- Menyimpan data operasional sensitif dalam contoh komentar.
- Menyimpan kode lama yang dinonaktifkan sebagai komentar tanpa alasan.
- Menambahkan komentar yang terlalu panjang dan tidak membantu maintenance.
- Menerjemahkan syntax, identifier, PHPDoc tags atau JSDoc tags.
- Mengubah komentar generated code atau vendor code secara sembarangan.
- Melakukan commenting massal terhadap seluruh repository.

## 10. Penerapan incremental

Standar ini diterapkan pada:

- Kode baru.
- Kode existing yang sedang dimodifikasi.
- Business logic yang sedang diperbaiki.
- Bug fix dengan perilaku khusus.
- Refactoring yang telah disetujui.
- Automated test baru atau yang sedang diperbarui.

Kode existing di luar scope pekerjaan tidak perlu dimodifikasi hanya untuk menambahkan komentar.

Apabila perilaku kode berubah, komentar yang berkaitan wajib diperbarui atau dihapus apabila sudah tidak relevan.

## 11. Checklist sebelum pekerjaan selesai

- Komentar ditulis menggunakan bahasa Indonesia.
- Komentar menjelaskan tujuan atau alasan logika yang tidak langsung terlihat.
- Business logic kompleks memiliki penjelasan yang memadai.
- Komentar sesuai dengan perilaku kode aktual.
- Komentar lama yang terdampak telah diperbarui.
- Tidak terdapat komentar berlebihan.
- Tidak terdapat informasi sensitif.
- Tidak terdapat klaim pengujian atau keamanan tanpa bukti.
- Tidak terdapat perubahan komentar di luar scope.

## 12. Prinsip utama

Kode yang baik harus mudah dibaca melalui penamaan dan struktur yang jelas.

Komentar digunakan untuk melengkapi pemahaman manusia, terutama mengenai alasan teknis, keputusan bisnis, batasan dan perlindungan data.

Komentar bukan pengganti kode yang jelas, validation, authorization atau automated testing.

## 13. Referensi

- Google Engineering Practices: https://google.github.io/eng-practices/review/reviewer/looking-for.html
- PHPDoc: https://docs.phpdoc.org/guide/guides/docblocks.html
- PHPDoc Syntax: https://docs.phpdoc.org/guide/references/phpdoc/basic-syntax.html
- JSDoc: https://jsdoc.app/
- Vue: https://vuejs.org/
- Vuetify: https://vuetifyjs.com/en/