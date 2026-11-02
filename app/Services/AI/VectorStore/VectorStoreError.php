<?php

namespace App\Services\AI\VectorStore;

enum VectorStoreError: string
{
    case Configuration = 'configuration';

    case Connection = 'connection';

    case Request = 'request';

    case Protocol = 'protocol';

    case Contract = 'contract';
}
