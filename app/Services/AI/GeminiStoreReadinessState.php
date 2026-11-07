<?php

namespace App\Services\AI;

enum GeminiStoreReadinessState: string
{
    case InvalidResponse = 'invalid_response';
    case Accessible = 'accessible';
    case NotConfigured = 'not_configured';
    case AccessDenied = 'access_denied';
    case MissingStore = 'missing_store';
    case Unavailable = 'unavailable';
}
