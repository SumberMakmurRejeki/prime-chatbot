<?php

namespace App\Jobs;

use App\Services\AI\KnowledgeDocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessKnowledgeDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $documentId) {}

    public function handle(KnowledgeDocumentService $documents): void
    {
        $documents->process($this->documentId);
    }
}
