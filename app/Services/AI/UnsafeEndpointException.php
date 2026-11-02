<?php

namespace App\Services\AI;

use RuntimeException;

/**
 * Kegagalan endpoint keluar (SSRF/pengalihan/ukuran respons).
 *
 * Pesan tidak pernah ditampilkan atau disimpan; hanya kategori yang aman yang
 * dipakai sebagai hasil uji.
 */
final class UnsafeEndpointException extends RuntimeException
{
    public function __construct(string $message, private readonly ProviderTestCategory $category)
    {
        parent::__construct($message);
    }

    public function category(): ProviderTestCategory
    {
        return $this->category;
    }
}
