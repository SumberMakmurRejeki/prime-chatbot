@php
    // Backend menolak perubahan purpose setelah pengujian dimulai
    // (AiProviderConfigurationService::revise()); form mencerminkan aturan yang sama.
    // Form provider baru tidak boleh mewarisi nilai provider mana pun: seluruh
    // nilai awal hanya berasal dari input lama (old) atau string kosong, sehingga
    // API Key, Base URL, dan Model ID provider lain tidak pernah masuk ke modal
    // "Tambah Provider".
    $isNewProvider = $configuration === null;
    $purposeLocked = ! $isNewProvider
        && ($configuration->active_revision_id !== null || $configuration->revisions->contains(fn ($r) => $r->test_state !== 'untested'));
    $displayName = old('display_name', $isNewProvider ? '' : $configuration->display_name);
    $baseUrl = old('base_url', $isNewProvider ? '' : $revision?->base_url);
    $modelId = old('model_id', $isNewProvider ? '' : $revision?->model_id);
    $purpose = old('purpose', $isNewProvider ? 'chat' : $configuration->purpose);
    // Jenis provider hanya dipilih saat membuat konfigurasi; revisi selalu
    // memakai driver konfigurasi yang ada.
    $driver = $isNewProvider
        ? old('driver', \App\Models\AiProviderConfigurationRevision::DEFAULT_DRIVER)
        : ($revision?->driver ?? \App\Models\AiProviderConfigurationRevision::DEFAULT_DRIVER);
    $driverLabels = [
        'openai' => 'OpenAI',
        'openai-compatible' => 'OpenAI-compatible',
        'gemini' => 'Gemini (Google AI)',
        'openrouter' => 'OpenRouter',
    ];
@endphp
<label class="block">
    <span class="mb-2 block font-medium">Nama</span>
    <input name="display_name" required maxlength="120" value="{{ $displayName }}" class="w-full rounded-xl border border-[var(--color-prime-border)] bg-white px-4 py-3">
</label>
<label class="block">
    <span class="mb-2 block font-medium">Digunakan untuk</span>
    @if ($purposeLocked)
        {{-- Select dinonaktifkan; nilai tetap dikirim lewat hidden input --}}
        <input type="hidden" name="purpose" value="{{ old('purpose', $configuration->purpose) }}">
        <select name="purpose_display" disabled class="w-full rounded-xl border border-[var(--color-prime-border)] bg-white px-4 py-3">
            @foreach (['chat' => 'Chatbot PRIME', 'embedding' => 'Pencarian Dokumen (RAG)'] as $key => $label)
                <option value="{{ $key }}" @selected($configuration->purpose === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <span class="mt-1 block text-sm text-[var(--color-prime-muted)]">Jenis konfigurasi tidak dapat diubah setelah pengujian provider dimulai.</span>
    @else
        <select name="purpose" required class="w-full rounded-xl border border-[var(--color-prime-border)] bg-white px-4 py-3">
            @foreach (['chat' => 'Chatbot PRIME', 'embedding' => 'Pencarian Dokumen (RAG)'] as $key => $label)
                <option value="{{ $key }}" @selected($purpose === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <span class="mt-1 block text-sm text-[var(--color-prime-muted)]" data-purpose-help>{{ $purpose === 'embedding' ? 'Untuk pencarian isi dokumen menggunakan embedding.' : 'Untuk menjawab pertanyaan pengguna.' }}</span>
    @endif
</label>
<label class="block">
    <span class="mb-2 block font-medium">Jenis provider</span>
    @if ($isNewProvider)
        <select name="driver" required class="w-full rounded-xl border border-[var(--color-prime-border)] bg-white px-4 py-3">
            @foreach (\App\Models\AiProviderConfigurationRevision::SUPPORTED_DRIVERS as $supportedDriver)
                <option value="{{ $supportedDriver }}" @selected($driver === $supportedDriver)>{{ $driverLabels[$supportedDriver] ?? $supportedDriver }}</option>
            @endforeach
        </select>
        <span class="mt-1 block text-sm text-[var(--color-prime-muted)]">Gemini memakai API Key Google AI; Store File Search diatur terpisah, bukan di sini.</span>
    @else
        {{-- Revisi tidak boleh mengubah jenis provider; nilai tetap dikirim lewat hidden input --}}
        <input type="hidden" name="driver" value="{{ $driver }}">
        <select name="driver_display" disabled class="w-full rounded-xl border border-[var(--color-prime-border)] bg-white px-4 py-3">
            <option selected>{{ $driverLabels[$driver] ?? $driver }}</option>
        </select>
        <span class="mt-1 block text-sm text-[var(--color-prime-muted)]">Jenis provider mengikuti konfigurasi ini. Buat konfigurasi baru untuk mengganti provider.</span>
    @endif
</label>
<label class="block md:col-span-2">
    <span class="mb-2 block font-medium">Base URL <span aria-hidden="true">*</span></span>
    <input type="url" name="base_url" required maxlength="2048" value="{{ $baseUrl }}" placeholder="https://api.river.ai/v1" class="w-full rounded-xl border border-[var(--color-prime-border)] bg-white px-4 py-3">
    <span class="mt-1 block text-sm text-[var(--color-prime-muted)]">Contoh: https://api.river.ai/v1</span>
</label>
<label class="block md:col-span-2">
    <span class="mb-2 block font-medium">API Key @if ($revision?->credential_is_configured) <span class="text-sm text-[var(--color-prime-muted)]">(opsional)</span> @endif</span>
    <input type="password" name="credential" autocomplete="new-password" value="" class="w-full rounded-xl border border-[var(--color-prime-border)] bg-white px-4 py-3" placeholder="{{ $revision?->credential_is_configured ? 'Kosongkan untuk menggunakan kembali API Key tersimpan' : 'Masukkan API Key' }}">
    <span class="mt-1 block text-sm text-[var(--color-prime-muted)]">{{ $revision?->credential_is_configured ? 'Kosongkan untuk menggunakan kembali API Key tersimpan.' : 'Disimpan terenkripsi.' }}</span>
</label>
<div class="md:col-span-2" data-model-picker data-provider-id="{{ $isNewProvider ? '' : $configuration->id }}" data-revision-id="{{ $isNewProvider ? '' : $revision?->id }}">
    <label class="block">
        <span class="mb-2 block font-medium">Model</span>
    <input type="search" data-model-search autocomplete="off" value="{{ $modelId }}" placeholder="Cari model atau masukkan Model ID" class="w-full rounded-xl border border-[var(--color-prime-border)] bg-white px-4 py-3">
    </label>
    <div class="mt-2 flex flex-wrap items-center gap-2">
        <button type="button" data-discover-models class="rounded-lg border px-4 py-2 font-medium">Ambil model dari provider</button>
        <span data-model-discovery-message role="status" aria-live="polite" class="text-sm text-[var(--color-prime-muted)]">Pilih model dari daftar atau masukkan Model ID secara manual.</span>
    </div>
    <label class="mt-3 block">
        <span class="mb-2 block font-medium">Model ID manual</span>
        <input name="model_id" required maxlength="255" value="{{ $modelId }}" data-model-id class="w-full rounded-xl border border-[var(--color-prime-border)] bg-white px-4 py-3">
    </label>
    <ul data-model-results class="mt-2 max-h-48 space-y-1 overflow-auto" aria-label="Model tersedia"></ul>
</div>
