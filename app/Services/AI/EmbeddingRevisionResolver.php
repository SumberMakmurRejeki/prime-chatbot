<?php

namespace App\Services\AI;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Services\AI\VectorStore\DistanceMetric;
use App\Services\AI\VectorStore\VectorSpace;
use RuntimeException;

/**
 * Satu sumber kebenaran untuk ruang vektor embedding yang aktif disetujui.
 *
 * Ingestion (indexing) dan retrieval wajib memakai revisi, model, dimensi, dan
 * identitas ruang vektor yang sama. Jika aturan kelayakan atau rumus identitas
 * ruang hidup di dua tempat, pertanyaan pengguna bisa di-embed ke ruang yang
 * berbeda dari dokumen terindeks sehingga hasil retrieval kosong atau menyesatkan.
 */
final class EmbeddingRevisionResolver
{
    public const MODEL = 'text-embedding-3-large';

    public const DIMENSION = 3072;

    /**
     * Rumus identitas ruang administratif, sumber tunggal komposisi id ini.
     * Token metrik 'cosine' adalah bagian dari identitas Qdrant dan saat ini
     * satu-satunya metrik yang didukung ruang RAG. Dipakai ulang oleh guard
     * Slice 2A agar identitas lama tidak pernah ditulis ulang.
     */
    public static function identityFromComponents(int $configurationId, int $revisionId, string $modelId, int $dimension): string
    {
        return sprintf('rag:config-%d:revision-%d:%s:%d:cosine', $configurationId, $revisionId, $modelId, $dimension);
    }

    /**
     * Fail-closed: tanpa revisi embedding aktif yang lolos seluruh syarat,
     * pemanggil tidak boleh melakukan I/O provider atau vector store.
     */
    public function resolve(): AiProviderConfigurationRevision
    {
        $configurations = AiProviderConfiguration::query()
            ->where('purpose', 'embedding')
            ->with('activeRevision')
            ->limit(2)
            ->get();
        $configuration = $configurations->count() === 1 ? $configurations->first() : null;
        $revision = $configuration?->activeRevision;

        if (! $configuration || ! $revision
            || (int) $revision->configuration_id !== (int) $configuration->id
            || $revision->driver !== 'openai-compatible'
            || $revision->model_id !== self::MODEL
            || ! $revision->enabled
            || $revision->test_state !== 'passed'
            || $revision->test_attempt_id === null
            || $revision->tested_at === null
            || (int) $revision->tested_embedding_dimensions !== self::DIMENSION
            || blank($revision->getRawOriginal('credential'))
            || blank($revision->base_url)
        ) {
            throw new RuntimeException('No eligible active embedding revision is available.');
        }

        return $revision;
    }

    public function space(AiProviderConfigurationRevision $revision): VectorSpace
    {
        return new VectorSpace(
            self::identityFromComponents($revision->configuration_id, $revision->id, self::MODEL, self::DIMENSION),
            self::DIMENSION,
            DistanceMetric::Cosine,
        );
    }
}
