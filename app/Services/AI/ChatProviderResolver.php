<?php

namespace App\Services\AI;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use Laravel\Ai\Enums\Lab;
use RuntimeException;

final class ChatProviderResolver
{
    public function resolve(): AiProviderConfigurationRevision
    {
        $revisions = AiProviderConfiguration::query()
            ->where('purpose', 'chat')
            ->with('activeRevision')
            ->get()
            ->map(function (AiProviderConfiguration $configuration): ?AiProviderConfigurationRevision {
                $revision = $configuration->activeRevision;

                if (! $revision || (int) $revision->configuration_id !== (int) $configuration->id) {
                    return null;
                }

                $driver = Lab::tryFrom($revision->driver);

                return $revision->enabled
                    && $revision->test_state === 'passed'
                    && $revision->test_attempt_id !== null
                    && $revision->tested_at !== null
                    && filled($revision->getRawOriginal('credential'))
                    && filled($revision->model_id)
                    && in_array($driver, [
                        Lab::OpenAI,
                        Lab::OpenAICompatible,
                        Lab::Gemini,
                        Lab::OpenRouter,
                    ], true)
                    && ($driver !== Lab::OpenAICompatible || filled($revision->base_url))
                    ? $revision
                    : null;
            })
            ->filter();
        $revision = $revisions->count() === 1 ? $revisions->first() : null;

        if (! $revision) {
            throw new RuntimeException('No unique eligible active chat provider is available.');
        }

        return $revision;
    }
}
