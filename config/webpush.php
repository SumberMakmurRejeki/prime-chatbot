<?php

/*
 * Konfigurasi skema warisan push_subscriptions.
 *
 * Perilaku Web Push sudah dihapus dari runtime PRIME V1, tetapi dua kunci di
 * bawah masih dipakai jalur yang dipertahankan:
 *  - migrasi historis 2026_06_06_215524_create_push_subscriptions_table.php
 *    (koneksi + nama tabel), dan
 *  - guard koneksi sekunder TestingDatabaseServiceProvider.
 * Kunci VAPID/runtime Web Push sengaja dihapus bersama kodenya. Kedua kunci di
 * bawah baru boleh dihapus saat pembersihan schema push_subscriptions
 * diputuskan sebagai risiko terpisah (PRIME-NOTIF-01B/selepasnya).
 */
return [
    'table_name' => env('WEBPUSH_DB_TABLE', 'push_subscriptions'),

    'database_connection' => env('WEBPUSH_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),
];
