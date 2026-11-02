<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class DocumentAnswerUnavailable extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Assistant tidak dapat menjawab saat ini. Periksa konfigurasi Chat Provider dan layanan Knowledge Base, lalu coba lagi.', 0, $previous);
    }
}
