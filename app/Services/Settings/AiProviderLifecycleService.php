<?php

namespace App\Services\Settings;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Services\AI\ProviderCapabilityProbe;
use App\Services\AI\ProviderProbeResult;
use App\Services\AI\ProviderTestCategory;
use App\Services\Auth\ActivityLogService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

/**
 * Mengelola bukti probe dan pointer provider tanpa menahan lock saat jaringan
 * dipanggil. Semua mutasi mengikuti urutan lock provider lalu revisi.
 */
final class AiProviderLifecycleService
{
    private const ACCEPTED_COMPLETION_SECONDS = 60;

    private const SUPPORTED_DRIVERS = ['openai', 'openai-compatible', 'gemini', 'openrouter'];

    public function __construct(
        private readonly ProviderCapabilityProbe $probe,
        private readonly ActivityLogService $activityLog,
    ) {}

    public function test(Request $request, AiProviderConfiguration $configuration, int $revisionId): ProviderProbeResult
    {
        [$attemptId, $purpose, $revision] = DB::transaction(function () use ($configuration, $revisionId): array {
            $provider = AiProviderConfiguration::query()->lockForUpdate()->findOrFail($configuration->id);
            $revision = $this->lockedRevision($provider, $revisionId);

            if (! in_array($provider->purpose, ['chat', 'embedding'], true)
                || ! in_array($revision->driver, self::SUPPORTED_DRIVERS, true)
                || ! $revision->enabled) {
                throw ValidationException::withMessages([
                    'revision' => 'Konfigurasi provider tidak didukung atau revisi dinonaktifkan.',
                ]);
            }

            $attemptId = (string) Str::uuid();
            $revision->forceFill([
                'test_state' => 'testing',
                'test_attempt_id' => $attemptId,
                'test_started_at' => now(),
                'tested_at' => null,
                'test_failure_category' => null,
                'tested_embedding_dimensions' => null,
            ])->save();

            return [$attemptId, $provider->purpose, $revision];
        });

        try {
            $result = $this->probe->probe($revision, $purpose);
        } catch (Throwable) {
            // Tidak ada pesan exception provider yang boleh keluar dari batas probe.
            $result = ProviderProbeResult::failure(ProviderTestCategory::ProviderRequestRejected);
        }

        return $this->complete($request, $configuration->id, $revisionId, $attemptId, $purpose, $result);
    }

    public function activate(Request $request, AiProviderConfiguration $configuration, int $revisionId): void
    {
        DB::transaction(function () use ($request, $configuration, $revisionId): void {
            $provider = AiProviderConfiguration::query()->lockForUpdate()->findOrFail($configuration->id);
            $target = $this->lockedRevision($provider, $revisionId);
            $this->assertEligible($provider, $target);

            if ((int) $provider->active_revision_id === (int) $target->id) {
                return;
            }

            $oldActive = $provider->active_revision_id;
            $oldPrevious = $provider->previous_known_good_revision_id;
            $previous = null;

            if ($oldActive !== null && (int) $oldActive !== (int) $target->id) {
                $outgoing = $this->lockedRevision($provider, (int) $oldActive);
                if ($this->isEligible($provider, $outgoing)) {
                    $previous = $outgoing->id;
                } elseif ($oldPrevious !== null && (int) $oldPrevious !== (int) $target->id) {
                    $candidate = $this->lockedRevision($provider, (int) $oldPrevious);
                    if ($this->isEligible($provider, $candidate)) {
                        $previous = $candidate->id;
                    }
                }
            }

            if ($previous !== null && (int) $previous === (int) $target->id) {
                $previous = null;
            }

            $provider->forceFill([
                'active_revision_id' => $target->id,
                'previous_known_good_revision_id' => $previous,
            ])->save();

            $this->activityLog->log(
                $request,
                'ai_provider_configuration',
                'activate',
                'AI provider revision activated.',
                'ai_provider_configurations',
                $provider->id,
                ['active_revision_id' => $oldActive, 'previous_known_good_revision_id' => $oldPrevious],
                ['active_revision_id' => $target->id, 'previous_known_good_revision_id' => $previous],
            );
        });
    }

    public function restore(Request $request, AiProviderConfiguration $configuration, int $expectedActiveId, int $expectedPreviousId): void
    {
        DB::transaction(function () use ($request, $configuration, $expectedActiveId, $expectedPreviousId): void {
            $provider = AiProviderConfiguration::query()->lockForUpdate()->findOrFail($configuration->id);

            if ((int) $provider->active_revision_id !== $expectedActiveId
                || (int) $provider->previous_known_good_revision_id !== $expectedPreviousId) {
                throw new ConflictHttpException('Pointer provider berubah; muat ulang sebelum mencoba lagi.');
            }

            $target = $this->lockedRevision($provider, $expectedPreviousId);

            if (! $this->isEligible($provider, $target)) {
                throw new UnprocessableEntityHttpException('Revisi rollback tidak lagi memiliki bukti uji yang valid.');
            }

            $oldActive = $provider->active_revision_id;
            $oldPrevious = $provider->previous_known_good_revision_id;
            $provider->forceFill([
                'active_revision_id' => $target->id,
                'previous_known_good_revision_id' => null,
            ])->save();

            $this->activityLog->log(
                $request,
                'ai_provider_configuration',
                'restore',
                'AI provider revision restored.',
                'ai_provider_configurations',
                $provider->id,
                ['active_revision_id' => $oldActive, 'previous_known_good_revision_id' => $oldPrevious],
                ['active_revision_id' => $target->id, 'previous_known_good_revision_id' => null],
            );
        });
    }

    private function complete(
        Request $request,
        int $configurationId,
        int $revisionId,
        string $attemptId,
        string $purpose,
        ProviderProbeResult $result,
    ): ProviderProbeResult {
        return DB::transaction(function () use ($request, $configurationId, $revisionId, $attemptId, $purpose, $result): ProviderProbeResult {
            $provider = AiProviderConfiguration::query()->lockForUpdate()->findOrFail($configurationId);
            $revision = $this->lockedRevision($provider, $revisionId);

            if ($revision->test_attempt_id !== $attemptId
                || $revision->test_state !== 'testing'
                || $revision->test_started_at === null
                || $revision->test_started_at->lt(now()->subSeconds(self::ACCEPTED_COMPLETION_SECONDS))) {
                return ProviderProbeResult::superseded();
            }

            $passed = $result->passed();
            $testedAt = now();
            $revision->forceFill([
                'test_state' => $passed ? 'passed' : 'failed',
                'tested_at' => $testedAt,
                'test_failure_category' => $passed ? null : $result->category->value,
                'tested_embedding_dimensions' => $passed && $purpose === 'embedding' ? $result->embeddingDimensions : null,
            ])->save();

            $this->activityLog->log(
                $request,
                'ai_provider_configuration',
                'connection_test',
                'AI provider connection test completed.',
                'ai_provider_configurations',
                $provider->id,
                null,
                [
                    'provider_id' => $provider->id,
                    'revision_id' => $revision->id,
                    'attempt_id' => $attemptId,
                    'purpose' => $purpose,
                    'final_state' => $passed ? 'passed' : 'failed',
                    'category' => $passed ? ProviderTestCategory::Success->value : $result->category->value,
                    'tested_at' => $testedAt->toISOString(),
                    'embedding_dimensions' => $purpose === 'embedding' ? $result->embeddingDimensions : null,
                    'superseded' => false,
                ],
            );

            return $result;
        });
    }

    private function lockedRevision(AiProviderConfiguration $provider, int $revisionId): AiProviderConfigurationRevision
    {
        $revision = AiProviderConfigurationRevision::query()->lockForUpdate()->find($revisionId);
        if ($revision === null || (int) $revision->configuration_id !== (int) $provider->id) {
            throw (new ModelNotFoundException)->setModel(AiProviderConfigurationRevision::class, [$revisionId]);
        }

        return $revision;
    }

    /**
     * Kelayakan aktivasi/rollback hanya bergantung pada bukti lulus yang sah
     * (bukan usia bukti). Batas ACCEPTED_COMPLETION_SECONDS hanya dipakai saat
     * menerima hasil uji; percobaan yang masih berstatus testing tetap ditolak
     * karena statusnya bukan passed.
     */
    private function assertEligible(AiProviderConfiguration $provider, AiProviderConfigurationRevision $revision): void
    {
        if (! $this->isEligible($provider, $revision)) {
            throw new UnprocessableEntityHttpException('Revisi belum memiliki bukti uji yang valid untuk aktivasi.');
        }
    }

    private function isEligible(AiProviderConfiguration $provider, AiProviderConfigurationRevision $revision): bool
    {
        if (! in_array($provider->purpose, ['chat', 'embedding'], true)
            || ! in_array($revision->driver, self::SUPPORTED_DRIVERS, true)
            || ! $revision->enabled
            || $revision->test_state !== 'passed'
            || $revision->test_attempt_id === null
            || $revision->tested_at === null) {
            return false;
        }

        if ($provider->purpose === 'embedding') {
            return is_int($revision->tested_embedding_dimensions) && $revision->tested_embedding_dimensions > 0;
        }

        return true;
    }
}
