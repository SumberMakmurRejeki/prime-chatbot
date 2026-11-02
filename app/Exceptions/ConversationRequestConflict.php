<?php

namespace App\Exceptions;

use RuntimeException;

class ConversationRequestConflict extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}
