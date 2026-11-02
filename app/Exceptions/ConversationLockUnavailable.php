<?php

namespace App\Exceptions;

use RuntimeException;

final class ConversationLockUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Percakapan sementara tidak dapat diproses. Coba lagi.');
    }
}
