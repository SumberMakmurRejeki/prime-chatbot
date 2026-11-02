<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Qdrant (infrastruktur vector store)
    |--------------------------------------------------------------------------
    |
    | Konfigurasi infrastruktur Qdrant, terpisah dari config/ai.php dan dari AI
    | Provider Settings. Qdrant self-hosted biasanya loopback/privat; lokasi
    | jaringan dan kredensial bukan bagian dari pengaturan AI biasa.
    |
    */

    'url' => env('QDRANT_URL', 'http://127.0.0.1:6333'),

    'api_key' => env('QDRANT_API_KEY'),

    'collection' => env('QDRANT_COLLECTION', ''),

    'connect_timeout' => (int) env('QDRANT_CONNECT_TIMEOUT', 5),

    'request_timeout' => (int) env('QDRANT_REQUEST_TIMEOUT', 20),

    'max_upsert_points' => (int) env('QDRANT_MAX_UPSERT_POINTS', 256),

    'max_retrieval_limit' => (int) env('QDRANT_MAX_RETRIEVAL_LIMIT', 50),

];
