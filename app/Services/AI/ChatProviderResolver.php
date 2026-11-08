<?php

namespace App\Services\AI;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use Illuminate\Support\Collection;
use Laravel\Ai\Enums\Lab;
use RuntimeException;

final class ChatProviderResolver
{
    /** Driver yang dapat dipakai sebagai provider chat PRIME. */
    private const SUPPORTED_DRIVERS = [Lab::OpenAI, Lab::OpenAICompatible, Lab::Gemini, Lab::OpenRouter];

    public function resolve(): AiProviderConfigurationRevision
    {
        $revisions = $this->eligibleRevisions();
        $revision = $revisions->count() === 1 ? $revisions->first() : null;

        if (! $revision) {
            throw new RuntimeException('No unique eligible active chat provider is available.');
        }

        return $revision;
    }

    /**
     * Seluruh revisi chat aktif yang lolos kelayakan, tanpa memilih pemenang.
     *
     * Dipakai pemanggil yang perlu membedakan "belum dikonfigurasi" dari
     * "lebih dari satu kandidat"; resolve() sendiri tetap gagal tertutup.
     *
     * @return Collection<int, AiProviderConfigurationRevision>
     */
    public function eligibleRevisions(): Collection
    {
        return $this->activeChatRevisions()->filter(
            fn (AiProviderConfigurationRevision $revision): bool => $this->isEligible($revision),
        )->values();
    }

    /** @return Collection<int, AiProviderConfigurationRevision> */
    public function activeChatRevisions(): Collection
    {
        return AiProviderConfiguration::query()
            ->where('purpose', 'chat')
            ->with('activeRevision')
            ->get()
            ->map(function (AiProviderConfiguration $configuration): ?AiProviderConfigurationRevision {
                $revision = $configuration->activeRevision;

                if (! $revision || (int) $revision->configuration_id !== (int) $configuration->id) {
                    return null;
                }

                return $revision;
            })
            ->filter()
            ->values();
    }

    public static function supportsDriver(?string $driver): bool
    {
        return in_array(Lab::tryFrom((string) $driver), self::SUPPORTED_DRIVERS, true);
    }

    public function isEligible(AiProviderConfigurationRevision $revision): bool
    {
        $driver = Lab::tryFrom($revision->driver);

        return $revision->enabled
            && $revision->test_state === 'passed'
            && $revision->test_attempt_id !== null
            && $revision->tested_at !== null
            && filled($revision->getRawOriginal('credential'))
            && filled($revision->model_id)
            && self::supportsDriver($revision->driver)
            && ($driver !== Lab::OpenAICompatible || filled($revision->base_url));
    }
}
