<?php

declare(strict_types=1);

return [
    'mcp' => [
        'tools' => [
            'exclude' => [
                Laravel\Boost\Mcp\Tools\BrowserLogs::class,
                Laravel\Boost\Mcp\Tools\DatabaseConnections::class,
                Laravel\Boost\Mcp\Tools\DatabaseQuery::class,
                Laravel\Boost\Mcp\Tools\DatabaseSchema::class,
                Laravel\Boost\Mcp\Tools\LastError::class,
                Laravel\Boost\Mcp\Tools\ReadLogEntries::class,
                Laravel\Boost\Mcp\Tools\RecordRule::class,
                Laravel\Boost\Mcp\Tools\Tinker::class,
            ],
        ],
    ],
];
