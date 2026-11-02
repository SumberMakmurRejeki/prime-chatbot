<?php

// Ukuran halaman pengambilan worker export PM; validasi bilangan positif dilakukan
// di worker agar nilai deployment yang rusak gagal tertutup, bukan diam-diam berubah.
return [
    'pm_export_parent_page_size' => env('REPORT_PM_EXPORT_PARENT_PAGE_SIZE', 200),
    'pm_export_detail_page_size' => env('REPORT_PM_EXPORT_DETAIL_PAGE_SIZE', 500),
    'pm_export_action_page_size' => env('REPORT_PM_EXPORT_ACTION_PAGE_SIZE', 500),
];
