<?php

namespace App\Services\AI;

use App\Models\KnowledgeDocument;
use App\Services\AI\VectorStore\RetrievalQuery;
use App\Services\AI\VectorStore\VectorSearchResult;
use App\Services\AI\VectorStore\VectorStore;
use InvalidArgumentException;

/** PRIME retrieval from the eligible embedding revision through ranked vector results. */
final class DocumentRetrievalService
{
    private const FINAL_LIMIT = RetrievalEvidenceSelector::MAX_FINAL_RESULTS;

    /** Batas kolam kandidat internal, tetap di bawah ceiling adapter Qdrant. */
    private const CANDIDATE_LIMIT = 20;

    public function __construct(
        private readonly EmbeddingRevisionResolver $revisionResolver,
        private readonly SecureEmbeddingClient $embeddingClient,
        private readonly VectorStore $vectorStore,
        private readonly RetrievalEvidenceSelector $evidenceSelector,
    ) {}

    /** @return list<VectorSearchResult> */
    public function search(string $question): array
    {
        if (trim($question) === '' || strlen($question) > 16_384) {
            throw new InvalidArgumentException('Retrieval question is empty or exceeds the allowed size.');
        }

        $revision = $this->revisionResolver->resolve();
        $space = $this->revisionResolver->space($revision);
        $vector = $this->embeddingClient->embed($revision, [$question])[0];
        $candidates = $this->vectorStore->search($space, new RetrievalQuery(
            vector: $vector,
            limit: min(self::CANDIDATE_LIMIT, max(1, (int) config('qdrant.max_retrieval_limit', self::CANDIDATE_LIMIT))),
            onlyActive: true,
        ));

        $eligible = KnowledgeDocument::query()
            ->where('status', 'ready')
            ->where('is_published', true)
            ->whereIn('document_code', array_unique(array_map(static fn (VectorSearchResult $result): string => $result->documentId, $candidates)))
            ->get(['document_code', 'version'])
            ->mapWithKeys(static fn (KnowledgeDocument $document): array => [$document->document_code.'@'.$document->version => true]);
        $publishedCandidates = array_values(array_filter($candidates, static fn (VectorSearchResult $result): bool => $eligible->has($result->documentId.'@'.$result->documentVersion)));

        return $this->evidenceSelector->select($publishedCandidates, self::FINAL_LIMIT);
    }
}
