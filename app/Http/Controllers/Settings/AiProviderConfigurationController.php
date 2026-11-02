<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DiscoverAiProviderModelsRequest;
use App\Http\Requests\Settings\RestoreAiProviderConfigurationRequest;
use App\Http\Requests\Settings\StoreAiProviderConfigurationRequest;
use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Services\AI\ModelDiscoveryService;
use App\Services\Settings\AiProviderConfigurationService;
use App\Services\Settings\AiProviderLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiProviderConfigurationController extends Controller
{
    public function __construct(
        private AiProviderConfigurationService $service,
        private AiProviderLifecycleService $lifecycle,
    ) {}

    public function index(): View
    {
        return view('pages.settings.ai-providers.index', [
            'configurations' => AiProviderConfiguration::query()->with(['revisions' => fn ($query) => $query
                ->select(['id', 'configuration_id', 'revision_number', 'driver', 'model_id', 'base_url', 'enabled', 'test_state', 'test_started_at', 'tested_at', 'test_attempt_id', 'test_failure_category', 'tested_embedding_dimensions', 'generation_parameters', 'created_at'])
                // Status "credential terisi" diturunkan dari kolom tanpa pernah membuka/mendekripsi nilainya.
                ->selectRaw("CASE WHEN credential IS NOT NULL AND credential <> '' THEN 1 ELSE 0 END AS credential_is_configured")
                ->orderByDesc('revision_number')])->orderBy('display_name')->get(),
        ]);
    }

    public function discoverModels(DiscoverAiProviderModelsRequest $request, ModelDiscoveryService $discovery): JsonResponse
    {
        $data = $request->validated();
        $credential = $data['credential'] ?? null;

        if (blank($credential) && isset($data['provider_id'], $data['revision_id'])) {
            $provider = AiProviderConfiguration::query()->findOrFail($data['provider_id']);
            $revision = $provider->revisions()->whereKey($data['revision_id'])->firstOrFail();

            // Credential tersimpan hanya boleh dipakai bila Base URL sama dengan
            // endpoint revisi itu, agar kredensial tidak dapat dikirim ke host lain.
            if (rtrim((string) $revision->base_url, '/') !== rtrim($data['base_url'], '/')) {
                return response()->json(['message' => 'API Key ditolak.'], 422);
            }

            $credential = $revision->credential;
        }

        $result = $discovery->discover($data['base_url'], $credential);

        return response()->json(
            $result->succeeded()
                ? ['model_ids' => $result->modelIds]
                : ['message' => $result->error],
            $result->succeeded() ? 200 : 422,
        );
    }

    public function store(StoreAiProviderConfigurationRequest $request): RedirectResponse
    {
        $this->service->create($request, $request->validated());

        return redirect()->route('settings-ai-providers.index')->with('flash_success', 'Konfigurasi provider tersimpan sebagai revisi belum diuji.');
    }

    /** Uji koneksi revisi tepat; hasil hanya berupa kategori aman. */
    public function test(Request $request, AiProviderConfiguration $configuration, AiProviderConfigurationRevision $revision): RedirectResponse
    {
        $result = $this->lifecycle->test($request, $configuration, $revision->id);

        $passedMessage = $result->superseded ? null : $configuration->display_name.' · Revisi '.$revision->revision_number.' berhasil diuji.';

        return redirect()->route('settings-ai-providers.index')->with(
            $result->superseded ? 'flash_error' : ($result->passed() ? 'flash_success' : 'flash_error'),
            $result->superseded
                ? 'Hasil uji koneksi diabaikan karena ada percobaan uji yang lebih baru.'
                : ($result->passed() ? $passedMessage : $result->category->safeLabel()),
        );
    }

    public function activate(Request $request, AiProviderConfiguration $configuration, AiProviderConfigurationRevision $revision): RedirectResponse
    {
        $this->lifecycle->activate($request, $configuration, $revision->id);

        return redirect()->route('settings-ai-providers.index')->with('flash_success', 'Revisi provider diaktifkan.');
    }

    public function restore(RestoreAiProviderConfigurationRequest $request, AiProviderConfiguration $configuration): RedirectResponse
    {
        $this->lifecycle->restore(
            $request,
            $configuration,
            (int) $request->integer('expected_active_revision_id'),
            (int) $request->integer('expected_previous_revision_id'),
        );

        return redirect()->route('settings-ai-providers.index')->with('flash_success', 'Provider dikembalikan ke revisi yang diketahui baik.');
    }

    public function revise(StoreAiProviderConfigurationRequest $request, AiProviderConfiguration $configuration): RedirectResponse
    {
        $this->service->revise($request, $configuration, $request->validated());

        return redirect()->route('settings-ai-providers.index')->with('flash_success', 'Revisi provider tersimpan dan belum diuji.');
    }

    public function destroy(Request $request, AiProviderConfiguration $configuration): RedirectResponse
    {
        $displayName = $configuration->display_name;
        $this->service->delete($request, $configuration);

        return redirect()->route('settings-ai-providers.index')->with('flash_success', 'Provider "'.$displayName.'" berhasil dihapus.');
    }
}
