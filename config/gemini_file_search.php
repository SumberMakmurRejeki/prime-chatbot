<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gemini File Search (indeksasi dokumen knowledge)
    |--------------------------------------------------------------------------
    |
    | Jalur indeksasi Gemini File Search terpisah dari jalur Qdrant legacy dan
    | tidak aktif sebelum store tujuan ditetapkan secara eksplisit. Store boleh
    | ditulis sebagai `fileSearchStores/{id}` atau hanya `{id}`. Kredensial
    | tidak pernah disimpan di sini: setiap operasi memakai kredensial revisi
    | chat provider aktif lewat GeminiFileSearchConnection.
    |
    */

    'indexing' => [
        'enabled' => (bool) env('GEMINI_FILE_SEARCH_INDEXING_ENABLED', false),

        'store' => env('GEMINI_FILE_SEARCH_STORE_ID', ''),
    ],

];
