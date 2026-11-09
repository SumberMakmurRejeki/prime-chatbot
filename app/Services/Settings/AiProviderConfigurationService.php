<?php

namespace App\Services\Settings;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Services\Auth\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiProviderConfigurationService
{
    public function __construct(private ActivityLogService $activityLog) {}

    /** @param array{display_name: string, purpose: string, driver?: ?string, model_id: string, base_url: string, credential?: ?string} $data */
    public function create(Request $request, array $data): AiProviderConfiguration
    {
        return DB::transaction(function () use ($request, $data): AiProviderConfiguration {
            $configuration = AiProviderConfiguration::query()->create([
                'display_name' => $data['display_name'],
                'purpose' => $data['purpose'],
            ]);

            // Revisi pertama dibuat atomik bersama provider-nya di dalam satu transaksi.
            // Driver bawaan menjaga kompatibilitas klien lama yang tidak mengirim
            // pilihan eksplisit.
            $revision = $this->createRevision(
                $configuration,
                1,
                $data['driver'] ?? AiProviderConfigurationRevision::DEFAULT_DRIVER,
                $data,
                $data['credential'] ?? null,
            );

            $this->activityLog->log($request, 'ai_provider_configuration', 'create', 'Provider configuration revision created.', 'ai_provider_configurations', $configuration->id, null, [
                'purpose' => $configuration->purpose,
                'revision_number' => $revision->revision_number,
                'driver' => $revision->driver,
                'model_id' => $revision->model_id,
                'credential_configured' => filled($data['credential'] ?? null),
                'test_state' => 'untested',
            ]);

            return $configuration;
        });
    }

    /** @param array{display_name: string, purpose: string, driver?: ?string, model_id: string, base_url: string, credential?: ?string} $data */
    public function revise(Request $request, AiProviderConfiguration $configuration, array $data): AiProviderConfiguration
    {
        return DB::transaction(function () use ($request, $configuration, $data): AiProviderConfiguration {
            // Kunci baris parent: dua Admin AI yang merevisi provider yang sama
            // dialokasikan revision_number satu per satu sehingga tidak ada nomor ganda.
            $locked = AiProviderConfiguration::query()->lockForUpdate()->findOrFail($configuration->id);
            if ($locked->purpose !== $data['purpose'] && (
                $locked->active_revision_id !== null
                || $locked->revisions()->where('test_state', '!=', 'untested')->exists()
            )) {
                throw ValidationException::withMessages([
                    'purpose' => 'Jenis konfigurasi tidak dapat diubah setelah pengujian provider dimulai.',
                ]);
            }

            $locked->update([
                'display_name' => $data['display_name'],
                'purpose' => $data['purpose'],
            ]);

            $revisionNumber = ((int) $locked->revisions()->max('revision_number')) + 1;

            $credential = $data['credential'] ?? null;
            $retainedCipher = null;
            if (blank($credential)) {
                // Credential kosong berarti pertahankan credential terakhir yang pernah
                // diatur. Ciphertext disalin mentah tanpa didekripsi agar plaintext lama
                // tidak pernah dimuat ke memori request; `toBase()` dipakai supaya cast
                // `encrypted` tidak mendekripsi nilai saat dibaca.
                $retainedCipher = $locked->revisions()
                    ->whereNotNull('credential')
                    ->orderByDesc('revision_number')
                    ->toBase()
                    ->value('credential');
            }

            // Driver revisi selalu mengikuti konfigurasi, bukan isi request: pergantian
            // jenis provider hanya lewat konfigurasi baru, sehingga revisi yang salah
            // format tidak pernah mengubah identitas provider.
            $existingDriver = $locked->revisions()
                ->orderByDesc('revision_number')
                ->toBase()
                ->value('driver');

            $revision = $this->createRevision(
                $locked,
                $revisionNumber,
                is_string($existingDriver) && $existingDriver !== '' ? $existingDriver : AiProviderConfigurationRevision::DEFAULT_DRIVER,
                $data,
                $credential,
            );

            if (is_string($retainedCipher) && $retainedCipher !== '') {
                // Salin ciphertext apa adanya lewat query builder agar cast `encrypted`
                // tidak mengenkripsi ulang nilai yang memang sudah terenkripsi.
                AiProviderConfigurationRevision::query()
                    ->whereKey($revision->id)
                    ->update(['credential' => $retainedCipher]);
            }

            $this->activityLog->log($request, 'ai_provider_configuration', 'revise', 'Provider configuration revision created.', 'ai_provider_configurations', $locked->id, null, [
                'purpose' => $locked->purpose,
                'revision_number' => $revisionNumber,
                'driver' => $revision->driver,
                'model_id' => $revision->model_id,
                'credential_configured' => filled($credential) || is_string($retainedCipher),
                'test_state' => 'untested',
            ]);

            return $locked;
        });
    }

    public function delete(Request $request, AiProviderConfiguration $configuration): void
    {
        DB::transaction(function () use ($request, $configuration): void {
            $locked = AiProviderConfiguration::query()->lockForUpdate()->findOrFail($configuration->id);
            $revisionCount = $locked->revisions()->count();

            $locked->forceFill([
                'active_revision_id' => null,
                'previous_known_good_revision_id' => null,
            ])->save();

            $locked->revisions()->delete();
            $locked->delete();

            $this->activityLog->log(
                $request,
                'ai_provider_configuration',
                'provider_deleted',
                'AI provider deleted.',
                'ai_provider_configurations',
                $locked->id,
                null,
                [
                    'display_name' => $locked->display_name,
                    'purpose' => $locked->purpose,
                    'revision_count' => $revisionCount,
                ],
            );
        });
    }

    /** @param array{model_id: string, base_url: string} $data */
    private function createRevision(AiProviderConfiguration $configuration, int $revisionNumber, string $driver, array $data, ?string $credential): AiProviderConfigurationRevision
    {
        // Payload dibangun eksplisit; internal state (configuration_id, revision_number,
        // test_state) tidak dapat diisi dari data request apa pun.
        $revision = $configuration->revisions()->make([
            'driver' => $driver,
            'model_id' => $data['model_id'],
            'base_url' => $data['base_url'],
            'credential' => $credential,
            'generation_parameters' => null,
            'enabled' => true,
        ]);

        $revision->forceFill([
            'revision_number' => $revisionNumber,
            'test_state' => 'untested',
        ])->save();

        return $revision;
    }
}
