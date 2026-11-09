<x-layouts.app
    title="AI Settings PRIME"
    heading="AI Settings"
    :actor-name="auth()->user()?->name ?? 'User'"
    role="admin"
>
    <section class="space-y-6" data-ai-provider-page @if ($errors->any() && old('_provider_form')) data-provider-error-modal="{{ old('_provider_form') }}" @endif>
        <div class="flex items-center gap-2 text-[15px] font-medium text-[rgba(0,0,0,0.95)]">
            <span>AI Settings</span>
            <span class="text-[#a39e98]">/</span>
            <span>Provider</span>
        </div>

        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-[2.35rem] font-semibold tracking-[-0.05em] text-[var(--color-prime-ink)]">Provider AI</h2>
                <p class="mt-2 text-base text-[var(--color-prime-muted)]">Kelola konfigurasi dan kesiapan provider AI untuk PRIME.</p>
            </div>
            <x-ui.button data-modal-open="ai-provider-create-modal">+ Tambah Provider</x-ui.button>
        </header>

        @if (session('flash_success'))
            <x-ui.alert variant="success">{{ session('flash_success') }}</x-ui.alert>
        @endif
        @if (session('flash_error'))
            <x-ui.alert variant="error">{{ session('flash_error') }}</x-ui.alert>
        @endif
        @if ($errors->any())
            {{-- Kegagalan validasi form modal ditampilkan ulang di halaman; modal
                 asal dibuka otomatis lewat penanda _provider_form di bawah. --}}
            <x-ui.alert variant="error">{{ $errors->first() }}</x-ui.alert>
        @endif

        <div class="space-y-4">
            @forelse ($configurations as $configuration)
                @php
                    $activeRevision = $configuration->revisions->firstWhere('id', $configuration->active_revision_id);
                    $previousRevision = $configuration->revisions->firstWhere('id', $configuration->previous_known_good_revision_id);
                    // Kartu menampilkan konfigurasi terbaru sebagai acuan; status aktif tetap
                    // diturunkan dari pointer revisi aktif provider.
                    $latestRevision = $configuration->revisions->first();
                    $testState = $latestRevision?->test_state ?? 'untested';
                    $testLabel = match ($testState) { 'passed' => 'Koneksi berhasil', 'failed' => 'Uji gagal', 'testing' => 'Sedang diuji', default => 'Belum diuji' };
                    $testVariant = match ($testState) { 'passed' => 'success', 'failed' => 'danger', 'testing' => 'warning', default => 'neutral' };
                    // Predikat wajib sama dengan AiProviderLifecycleService::isEligible():
                    // driver didukung, revisi aktif, percobaan uji tercatat, dan
                    // dimensi embedding teruji untuk purpose embedding.
                    $activatable = fn ($revision): bool => $revision !== null
                        && in_array($configuration->purpose, ['chat', 'embedding'], true)
                        && in_array($revision->driver, \App\Models\AiProviderConfigurationRevision::SUPPORTED_DRIVERS, true)
                        && $revision->enabled
                        && $revision->test_state === 'passed'
                        && $revision->test_attempt_id !== null
                        && $revision->tested_at !== null
                        && ($configuration->purpose !== 'embedding' || (is_int($revision->tested_embedding_dimensions) && $revision->tested_embedding_dimensions > 0));
                    $canActivate = $activatable($latestRevision) && (int) $configuration->active_revision_id !== (int) $latestRevision?->id;
                @endphp
                <x-ui.card padding="p-5 sm:p-6">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0 space-y-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-lg font-semibold text-[var(--color-prime-ink)]">{{ $configuration->display_name }}</h3>
                                <x-ui.badge :variant="$activeRevision ? 'success' : 'neutral'">{{ $activeRevision ? 'Siap digunakan' : 'Belum siap' }}</x-ui.badge>
                            </div>
                            <p class="text-sm text-[var(--color-prime-muted)]">{{ $configuration->purpose === 'chat' ? 'Chatbot PRIME' : 'Pencarian Dokumen (RAG)' }}</p>
                            <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="text-[var(--color-prime-muted)]">Model</dt>
                                    <dd class="break-all font-medium">{{ $latestRevision?->model_id ?? 'Belum ada model' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-[var(--color-prime-muted)]">Koneksi</dt>
                                    <dd class="mt-1"><x-ui.badge :variant="$testVariant">{{ $testLabel }}</x-ui.badge></dd>
                                </div>
                            </dl>
                        </div>
                        @if ($latestRevision)
                            <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                                <form method="POST" action="{{ route('settings-ai-providers.test', [$configuration, $latestRevision]) }}">
                                    @csrf
                                    <x-ui.button type="submit" variant="secondary" size="sm">{{ $testState === 'passed' ? 'Uji ulang' : 'Uji koneksi' }}</x-ui.button>
                                </form>
                                @if ($canActivate)
                                    <form method="POST" action="{{ route('settings-ai-providers.activate', [$configuration, $latestRevision]) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm">Aktifkan</x-ui.button>
                                    </form>
                                @endif
                                <x-ui.button data-modal-open="ai-provider-revision-{{ $configuration->id }}-modal" variant="secondary" size="sm">Buat konfigurasi baru</x-ui.button>
                            </div>
                        @else
                            <x-ui.button data-modal-open="ai-provider-revision-{{ $configuration->id }}-modal" variant="secondary" size="sm">Buat konfigurasi baru</x-ui.button>
                        @endif
                        <x-ui.action-dropdown label="Aksi lainnya">
                            <button type="button" data-modal-open="ai-provider-delete-{{ $configuration->id }}-modal" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium text-[var(--color-prime-danger)] hover:bg-[var(--color-prime-danger-soft)]">
                                <x-ui.icon name="trash" class="h-4 w-4" />
                                Hapus provider
                            </button>
                        </x-ui.action-dropdown>
                    </div>

                    <details class="mt-5 border-t border-[var(--color-prime-border)] pt-3">
                        <summary class="w-fit cursor-pointer rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-prime-primary)]">Riwayat konfigurasi ({{ $configuration->revisions->count() }})</summary>
                        <ol class="mt-3 divide-y divide-[var(--color-prime-border)]">
                            @foreach ($configuration->revisions as $revision)
                                @php
                                    $revisionStateLabel = match ($revision->test_state) { 'passed' => 'Koneksi berhasil', 'failed' => 'Uji gagal', 'testing' => 'Sedang diuji', default => 'Belum diuji' };
                                    $revisionStateVariant = match ($revision->test_state) { 'passed' => 'success', 'failed' => 'danger', 'testing' => 'warning', default => 'neutral' };
                                    $eligible = $activatable($revision);
                                @endphp
                                <li class="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <p class="font-medium">Konfigurasi {{ $revision->revision_number }}</p>
                                        <p class="break-all text-sm text-[var(--color-prime-muted)]">Model: {{ $revision->model_id }}</p>
                                        <div class="mt-1 flex flex-wrap items-center gap-2">
                                            <x-ui.badge :variant="$revisionStateVariant">{{ $revisionStateLabel }}</x-ui.badge>
                                            @if ((int) $configuration->active_revision_id === (int) $revision->id)
                                                <x-ui.badge variant="success">Aktif</x-ui.badge>
                                            @endif
                                            <span class="text-xs text-[var(--color-prime-muted)]">API Key {{ $revision->credential_is_configured ? 'tersimpan (••••••••)' : 'belum diatur' }}</span>
                                        </div>
                                        @if ($revision->tested_at)
                                            <p class="mt-1 text-xs text-[var(--color-prime-muted)]">Uji selesai: {{ $revision->tested_at->format('Y-m-d H:i:s') }}</p>
                                        @endif
                                        @if ($revision->test_state === 'failed' && $revision->test_failure_category)
                                            @php($category = \App\Services\AI\ProviderTestCategory::tryFrom($revision->test_failure_category))
                                            @if ($category)
                                                <p class="mt-1 text-xs text-[var(--color-prime-muted)]">{{ $category->safeLabel() }}</p>
                                            @endif
                                        @endif
                                        @if ($configuration->purpose === 'embedding' && $revision->test_state === 'passed' && $revision->tested_embedding_dimensions)
                                            <p class="mt-1 text-xs text-[var(--color-prime-muted)]">Dimensi embedding teruji: {{ $revision->tested_embedding_dimensions }}</p>
                                        @endif
                                        @if ($revision->base_url)
                                            <p class="mt-1 break-all text-xs text-[var(--color-prime-muted)]">{{ $revision->base_url }}</p>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2 sm:shrink-0">
                                        @if ($eligible && (int) $configuration->active_revision_id !== (int) $revision->id && (int) $latestRevision?->id !== (int) $revision->id)
                                            <form method="POST" action="{{ route('settings-ai-providers.activate', [$configuration, $revision]) }}">
                                                @csrf
                                                <x-ui.button type="submit" variant="secondary" size="sm">Aktifkan</x-ui.button>
                                            </form>
                                        @endif
                                        @if ($previousRevision && $activeRevision && (int) $revision->id === (int) $activeRevision->id)
                                            <form method="POST" action="{{ route('settings-ai-providers.restore', $configuration) }}">
                                                @csrf
                                                <input type="hidden" name="expected_active_revision_id" value="{{ $activeRevision->id }}">
                                                <input type="hidden" name="expected_previous_revision_id" value="{{ $previousRevision->id }}">
                                                <x-ui.button type="submit" variant="secondary" size="sm">Pulihkan konfigurasi sebelumnya</x-ui.button>
                                            </form>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </details>
                </x-ui.card>

                <x-ui.modal id="ai-provider-delete-{{ $configuration->id }}-modal" panel-class="max-w-lg rounded-[var(--radius-modal)] bg-white p-5 shadow-[var(--shadow-level-3)] sm:p-6">
                    <div role="dialog" aria-modal="true" aria-labelledby="ai-provider-delete-{{ $configuration->id }}-title" tabindex="-1" data-provider-dialog>
                        <div class="flex items-start justify-between gap-4">
                            <h3 id="ai-provider-delete-{{ $configuration->id }}-title" class="text-lg font-semibold text-[var(--color-prime-ink)]">Hapus provider?</h3>
                            <button type="button" data-modal-close="ai-provider-delete-{{ $configuration->id }}-modal" class="rounded p-2 text-[var(--color-prime-muted)] hover:bg-[var(--color-prime-soft)]" aria-label="Tutup dialog">Tutup</button>
                        </div>
                        <p class="mt-3 text-sm text-[var(--color-prime-muted)]">Provider &quot;{{ $configuration->display_name }}&quot; beserta seluruh riwayat konfigurasinya akan dihapus dari PRIME.</p>
                        <p class="mt-2 text-sm font-medium text-[var(--color-prime-danger)]">Tindakan ini tidak dapat dibatalkan.</p>
                        <form method="POST" action="{{ route('settings-ai-providers.destroy', $configuration) }}" class="mt-5 flex flex-wrap justify-end gap-2 border-t border-[var(--color-prime-border)] pt-4">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="button" variant="secondary" data-modal-close="ai-provider-delete-{{ $configuration->id }}-modal">Batal</x-ui.button>
                            <x-ui.button type="submit" variant="danger" size="sm">Hapus provider</x-ui.button>
                        </form>
                    </div>
                </x-ui.modal>

                <x-ui.modal id="ai-provider-revision-{{ $configuration->id }}-modal" panel-class="max-w-3xl max-h-[90vh] overflow-y-auto rounded-[var(--radius-modal)] bg-white p-5 shadow-[var(--shadow-level-3)] sm:p-6">
                    <div role="dialog" aria-modal="true" aria-labelledby="ai-provider-revision-{{ $configuration->id }}-title" tabindex="-1" data-provider-dialog>
                        <div class="flex items-start justify-between gap-4">
                            <h3 id="ai-provider-revision-{{ $configuration->id }}-title" class="text-lg font-semibold text-[var(--color-prime-ink)]">Buat konfigurasi baru</h3>
                            <button type="button" data-modal-close="ai-provider-revision-{{ $configuration->id }}-modal" class="rounded p-2 text-[var(--color-prime-muted)] hover:bg-[var(--color-prime-soft)]" aria-label="Tutup dialog">Tutup</button>
                        </div>
                        <p class="mt-1 text-sm text-[var(--color-prime-muted)]">API Key boleh dikosongkan untuk menggunakan kembali API Key tersimpan.</p>
                        <form method="POST" action="{{ route('settings-ai-providers.revise', $configuration) }}" autocomplete="off" class="mt-5 grid gap-4 md:grid-cols-2">
                            @csrf
                            <input type="hidden" name="_provider_form" value="revision:{{ $configuration->id }}">
                            @include('pages.settings.ai-providers._form', ['configuration' => $configuration, 'revision' => $configuration->revisions->first()])
                            <div class="md:col-span-2 flex flex-wrap justify-end gap-2 border-t border-[var(--color-prime-border)] pt-4">
                                <x-ui.button type="button" variant="secondary" data-modal-close="ai-provider-revision-{{ $configuration->id }}-modal">Batal</x-ui.button>
                                <x-ui.button type="submit" size="sm">Simpan konfigurasi</x-ui.button>
                            </div>
                        </form>
                    </div>
                </x-ui.modal>
            @empty
                <x-ui.card padding="p-6">
                    <p class="font-medium">Belum ada provider AI.</p>
                    <p class="mt-1 text-sm text-[var(--color-prime-muted)]">Tambahkan provider untuk mulai mengelola koneksi AI.</p>
                    <x-ui.button class="mt-4" data-modal-open="ai-provider-create-modal">Tambah Provider</x-ui.button>
                </x-ui.card>
            @endforelse
        </div>

        <x-ui.modal id="ai-provider-create-modal" panel-class="max-w-3xl max-h-[90vh] overflow-y-auto rounded-[var(--radius-modal)] bg-white p-5 shadow-[var(--shadow-level-3)] sm:p-6">
            <div role="dialog" aria-modal="true" aria-labelledby="ai-provider-create-title" tabindex="-1" data-provider-dialog>
                <div class="flex items-start justify-between gap-4">
                    <h3 id="ai-provider-create-title" class="text-lg font-semibold text-[var(--color-prime-ink)]">Tambah Provider AI</h3>
                    <button type="button" data-modal-close="ai-provider-create-modal" class="rounded p-2 text-[var(--color-prime-muted)] hover:bg-[var(--color-prime-soft)]" aria-label="Tutup dialog">Tutup</button>
                </div>
                <form method="POST" action="{{ route('settings-ai-providers.store') }}" autocomplete="off" class="mt-5 grid gap-4 md:grid-cols-2">
                    @csrf
                    <input type="hidden" name="_provider_form" value="create">
                    @include('pages.settings.ai-providers._form', ['configuration' => null, 'revision' => null])
                    <div class="md:col-span-2 flex flex-wrap justify-end gap-2 border-t border-[var(--color-prime-border)] pt-4">
                        <x-ui.button type="button" variant="secondary" data-modal-close="ai-provider-create-modal">Batal</x-ui.button>
                        <x-ui.button type="submit" size="sm">Simpan konfigurasi</x-ui.button>
                    </div>
                </form>
            </div>
        </x-ui.modal>

        <script>
            // Model discovery memakai endpoint POST yang sama-sama memakai CSRF;
            // credential hanya dikirim sebagai isi permintaan dan tidak pernah
            // dimasukkan ke URL atau disimpan di mana pun.
            const purposeHelpTexts = {
                chat: 'Untuk menjawab pertanyaan pengguna.',
                embedding: 'Untuk pencarian isi dokumen menggunakan embedding.',
            };
            const discoverUrl = @json(route('settings-ai-providers.models'));

            document.querySelectorAll('form').forEach((form) => {
                const purposeSelect = form.querySelector('select[name="purpose"]');
                const purposeHelp = form.querySelector('[data-purpose-help]');

                if (purposeSelect && purposeHelp) {
                    purposeSelect.addEventListener('change', () => {
                        purposeHelp.textContent = purposeHelpTexts[purposeSelect.value] ?? '';
                    });
                }

                const picker = form.querySelector('[data-model-picker]');

                if (! picker) {
                    return;
                }

                const discoverButton = picker.querySelector('[data-discover-models]');
                const message = picker.querySelector('[data-model-discovery-message]');
                const results = picker.querySelector('[data-model-results]');
                const modelIdInput = picker.querySelector('[data-model-id]');
                const searchInput = picker.querySelector('[data-model-search]');

                const setModel = (modelId) => {
                    modelIdInput.value = modelId;
                    searchInput.value = modelId;
                };

                const renderModels = (modelIds) => {
                    results.replaceChildren();

                    for (const modelId of modelIds) {
                        const item = document.createElement('li');
                        const pick = document.createElement('button');
                        pick.type = 'button';
                        pick.textContent = modelId;
                        pick.className = 'w-full rounded-lg border px-3 py-2 text-left text-sm';
                        pick.addEventListener('click', () => setModel(modelId));
                        item.append(pick);
                        results.append(item);
                    }
                };

                discoverButton.addEventListener('click', async () => {
                    results.replaceChildren();
                    message.textContent = 'Mengambil daftar model…';

                    const body = new FormData();
                    body.append('_token', form.querySelector('input[name="_token"]').value);
                    body.append('base_url', form.querySelector('input[name="base_url"]').value.trim());
                    // Credential kosong saat revisi lama: server memakai credential tersimpan.
                    const credentialInput = form.querySelector('input[name="credential"]');
                    if (credentialInput && credentialInput.value !== '') {
                        body.append('credential', credentialInput.value);
                    }
                    if (picker.dataset.providerId !== '' && picker.dataset.revisionId !== '') {
                        body.append('provider_id', picker.dataset.providerId);
                        body.append('revision_id', picker.dataset.revisionId);
                    }

                    try {
                        const response = await fetch(discoverUrl, {
                            method: 'POST',
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body,
                        });
                        const payload = await response.json();

                        if (response.ok && Array.isArray(payload.model_ids)) {
                            renderModels(payload.model_ids);
                            message.textContent = payload.model_ids.length === 0
                                ? 'Model tidak dapat diambil otomatis. Masukkan Model ID secara manual.'
                                : 'Pilih model dari daftar atau masukkan Model ID secara manual.';
                        } else {
                            message.textContent = payload.message
                                ?? 'Model tidak dapat diambil otomatis. Masukkan Model ID secara manual.';
                        }
                    } catch {
                        message.textContent = 'Model tidak dapat diambil otomatis. Masukkan Model ID secara manual.';
                    }
                });

                if (searchInput) {
                    searchInput.addEventListener('input', () => {
                        modelIdInput.value = searchInput.value;
                        const query = searchInput.value.trim().toLocaleLowerCase();

                        for (const item of results.children) {
                            item.hidden = query !== '' && !item.textContent.toLocaleLowerCase().includes(query);
                        }
                    });
                }
            });
        </script>
    </section>
</x-layouts.app>
