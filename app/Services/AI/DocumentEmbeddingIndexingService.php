<?php

namespace App\Services\AI;

use App\Services\AI\DocumentIngestion\DocumentChunk;
use App\Services\AI\VectorStore\VectorChunk;
use App\Services\AI\VectorStore\VectorSpace;
use App\Services\AI\VectorStore\VectorStore;
use InvalidArgumentException;

/** Memiliki batas produksi dari revisi embedding aktif yang disetujui hingga upsert vector. */
final class DocumentEmbeddingIndexingService
{
    public function __construct(
        private readonly EmbeddingRevisionResolver $revisionResolver,
        private readonly SecureEmbeddingClient $embeddingClient,
        private readonly VectorStore $vectorStore,
    ) {}

    /** @param list<DocumentChunk> $chunks */
    public function index(array $chunks, bool $retrievalActive = true): VectorSpace
    {
        $maxUpsertPoints = (int) config('qdrant.max_upsert_points', 256);
        if ($maxUpsertPoints < 1 || count($chunks) > $maxUpsertPoints) {
            throw new InvalidArgumentException('Document exceeds the configured atomic vector upsert limit.');
        }

        if (! array_is_list($chunks) || $chunks === []) {
            throw new InvalidArgumentException('Prepared document chunks must be a non-empty list.');
        }
        $first = $chunks[0] ?? null;
        if (! $first instanceof DocumentChunk) {
            throw new InvalidArgumentException('Prepared chunks must be document chunks.');
        }
        foreach ($chunks as $chunk) {
            if (! $chunk instanceof DocumentChunk
                || $chunk->documentCode !== $first->documentCode
                || $chunk->documentVersion !== $first->documentVersion
                || $chunk->sourceSha256 !== $first->sourceSha256
                || $chunk->machineCode !== $first->machineCode
                || $chunk->pipelineFingerprint !== $first->pipelineFingerprint
                || preg_match('/^[a-f0-9]{64}$/D', $chunk->sourceSha256) !== 1
                || $chunk->stableChunkIdentity === ''
            ) {
                throw new InvalidArgumentException('Prepared chunks have inconsistent document provenance.');
            }
        }

        $revision = $this->revisionResolver->resolve();
        $space = $this->revisionResolver->space($revision);
        $vectors = $this->embeddingClient->embed($revision, array_map(static fn (DocumentChunk $chunk): string => $chunk->text, $chunks));
        $vectorChunks = [];

        foreach ($chunks as $index => $chunk) {
            $vectorChunks[] = new VectorChunk(
                self::pointId($space, $chunk->stableChunkIdentity),
                $vectors[$index],
                $chunk->text,
                sprintf('p%d-%d', $chunk->pageStart, $chunk->pageEnd),
                $chunk->chunkIndex,
                $chunk->pageStart,
                array_filter([
                    'document_code' => $chunk->documentCode,
                    'source_sha256' => $chunk->sourceSha256,
                    'machine_code' => $chunk->machineCode,
                    'page_start' => $chunk->pageStart,
                    'page_end' => $chunk->pageEnd,
                    'section_identifier' => $chunk->sectionIdentifier,
                    'section_title' => $chunk->sectionTitle,
                    'stable_chunk_identity' => $chunk->stableChunkIdentity,
                    'pipeline_fingerprint' => $chunk->pipelineFingerprint,
                    'embedding_configuration_id' => $revision->configuration_id,
                    'embedding_revision_id' => $revision->id,
                    'embedding_model_id' => EmbeddingRevisionResolver::MODEL,
                    'embedding_dimension' => EmbeddingRevisionResolver::DIMENSION,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            );
        }

        $this->vectorStore->ensureCollection($space);
        $this->vectorStore->upsert($space, $first->documentCode, $first->documentVersion, $vectorChunks, $retrievalActive);

        return $space;
    }

    public static function pointId(VectorSpace $space, string $stableChunkIdentity): string
    {
        if ($stableChunkIdentity === '') {
            throw new InvalidArgumentException('Stable chunk identity is required.');
        }
        $hex = hash('sha256', $space->identity."\0".$stableChunkIdentity);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
