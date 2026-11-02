<?php

namespace App\Services\PM;

use RuntimeException;

/**
 * Exception yang dilempar ketika expected_revision penulis tidak cocok dengan
 * revision row execution yang sudah dikunci di database.
 *
 * Berbeda dengan ValidationException (error field/lifecycle), exception ini
 * murni sinyal konflik konkurensi: form operator/admin sudah kedaluwarsa
 * terhadap data authoritative, sehingga seluruh side effect result transaction
 * harus dibatalkan dan pengguna wajib memuat ulang data terbaru.
 */
class StalePmExecutionException extends RuntimeException
{
    public function __construct(string $message = 'Data PM ini telah berubah sejak halaman dibuka. Data terbaru tidak ditimpa. Silakan muat ulang untuk melihat perubahan terbaru.', int $code = 409, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
