<x-layouts.app
    :title="$conversation['title'].' - PRIME Assistant'"
    heading="Assistant"
    :actor-name="auth()->user()?->name ?? 'User'"
    role="admin"
>
    <section class="space-y-6">
        <div class="flex items-center gap-2 text-[15px] font-medium text-[rgba(0,0,0,0.95)]">
            <a href="{{ route('assistant.index') }}" class="text-[var(--color-prime-muted)] hover:text-[var(--color-prime-ink)]">Assistant</a>
            <span class="text-[#a39e98]">/</span>
            <span class="truncate">{{ $conversation['title'] }}</span>
        </div>

        @if (session('flash_success'))
            <x-ui.alert variant="success">{{ session('flash_success') }}</x-ui.alert>
        @endif
        @if (session('flash_error'))
            <x-ui.alert variant="error">{{ session('flash_error') }}</x-ui.alert>
        @endif
        @if ($errors->any())
            <x-ui.alert variant="error">{{ $errors->first() }}</x-ui.alert>
        @endif

        <x-ui.card padding="p-0" class="overflow-hidden">
            <div class="space-y-4 px-5 py-6 sm:px-6">
                <div class="flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <h2 class="truncate text-2xl font-semibold tracking-[-0.03em] text-[var(--color-prime-ink)]">{{ $conversation['title'] }}</h2>
                        <p class="mt-1 text-sm text-[var(--color-prime-muted)]">Dibuat {{ \Illuminate\Support\Carbon::parse($conversation['created_at'])?->diffForHumans() ?? '-' }}</p>
                    </div>
                    @if ($messages->hasMorePages())
                        <a
                            href="{{ route('assistant.show', [$conversation['id'], 'cursor' => $messages->nextCursor()?->encode()]) }}"
                            class="shrink-0 text-sm font-medium text-[var(--color-prime-primary)] hover:underline"
                        >
                            Muat riwayat lebih lama
                        </a>
                    @endif
                </div>

                <div class="flex items-start gap-3 rounded-[1.15rem] border border-[var(--color-prime-border)] bg-[var(--color-prime-soft)] px-4 py-3">
                    <span class="mt-0.5 shrink-0 text-[var(--color-prime-muted)]"><x-ui.icon name="support" class="h-5 w-5 [stroke-width:1.6]" /></span>
                    <p class="text-sm leading-6 text-[var(--color-prime-muted)]">Jawaban hanya menggunakan dokumen PDF terbit. Jika bukti tidak cukup, Assistant akan menyatakan keterbatasannya.</p>
                </div>

                @if ($messages->count() === 0)
                    <x-ui.empty-state title="Belum ada pesan" message="Ajukan pertanyaan tentang dokumen PDF terbit." />
                @else
                    <ol id="assistant-messages" class="space-y-3">
                        @foreach ($displayMessages as $message)
                            <li class="flex {{ $message['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                                <div class="max-w-[90%] rounded-[1.15rem] {{ $message['role'] === 'user' ? 'rounded-br-[0.4rem] bg-[var(--color-prime-primary)] text-white' : 'rounded-bl-[0.4rem] border border-[var(--color-prime-border)] bg-white text-[var(--color-prime-ink)]' }} px-4 py-3 text-base leading-6">
                                    {{-- Jawaban adalah keluaran provider yang tidak dipercaya: hanya penekanan inline Markdown dirender, seluruh HTML lain di-escape. --}}
                                    @php($messageHtml = trim(\Illuminate\Support\Str::inlineMarkdown($message['content'] ?? '', ['html_input' => 'escape', 'allow_unsafe_links' => false])))
                                    <p class="whitespace-pre-wrap break-words">{!! $messageHtml !!}</p>
                                    @if ($message['role'] === 'assistant' && $message['citations'] !== [])
                                        <div class="mt-3 border-t border-[var(--color-prime-border)] pt-2 text-sm">
                                            <p class="font-semibold">Sumber</p>
                                            <ul class="mt-1 list-inside list-disc space-y-1">
                                                @foreach ($message['citations'] as $citation)
                                                    {{-- Locator dan chunk tetap tersimpan di meta, tetapi tidak ditampilkan: keduanya identitas teknis provider tanpa nilai bagi Admin. --}}
                                                    <li><span class="font-medium">[{{ $citation['source'] ?? 'S'.($loop->index + 1) }}]</span> {{ $citation['title'] }} ({{ $citation['code'] }}), versi {{ $citation['version'] }}@if ($citation['page']), halaman {{ $citation['page'] }}@endif</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                    <p class="mt-1.5 text-xs {{ $message['role'] === 'user' ? 'text-white/70' : 'text-[var(--color-prime-muted)]' }}">{{ \Illuminate\Support\Carbon::parse($message['created_at'])?->format('d M Y H:i') ?? '-' }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
                <div id="assistant-error" class="hidden rounded-lg border border-[var(--color-prime-danger)] p-3 text-sm text-[var(--color-prime-danger)]" role="alert"></div>
                <div id="assistant-pending" class="hidden items-center gap-3 text-sm text-[var(--color-prime-muted)]" role="status" aria-live="polite">
                    <span class="flex gap-1" aria-hidden="true"><span class="assistant-dot"></span><span class="assistant-dot"></span><span class="assistant-dot"></span></span>
                    <span>Assistant sedang menyiapkan jawaban…</span>
                </div>
                <style>
                    @keyframes assistant-pulse { 0%, 60%, 100% { opacity: .3; transform: translateY(0); } 30% { opacity: 1; transform: translateY(-3px); } }
                    .assistant-dot { width: .4rem; height: .4rem; border-radius: 9999px; background: currentColor; animation: assistant-pulse 1.2s infinite ease-in-out; }
                    .assistant-dot:nth-child(2) { animation-delay: .15s; }
                    .assistant-dot:nth-child(3) { animation-delay: .3s; }
                    @media (prefers-reduced-motion: reduce) { .assistant-dot { animation: none; opacity: .75; } }
                </style>

            </div>

            <form id="assistant-form" method="POST" action="{{ route('assistant.messages.store', $conversation['id']) }}" data-conversation-id="{{ $conversation['id'] }}" class="border-t border-[var(--color-prime-border)] px-5 py-4 sm:px-6">
                @csrf
                <input type="hidden" name="request_id" value="{{ old('request_id', (string) \Illuminate\Support\Str::uuid()) }}">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <label class="block min-w-0 flex-1">
                        <span class="sr-only">Pesan</span>
                        <textarea name="message" rows="2" required maxlength="10000" placeholder="Tulis pertanyaan..." class="w-full rounded-xl border border-[var(--color-prime-border)] bg-white px-4 py-3 text-base text-[var(--color-prime-ink)] outline-none transition placeholder:text-[var(--color-prime-muted)] focus:border-[var(--color-prime-primary)] focus:ring-4 focus:ring-[var(--color-prime-primary)]/10">{{ old('message') }}</textarea>
                    </label>
                    <x-ui.button type="submit" class="shrink-0" data-assistant-submit>Kirim</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <form method="POST" action="{{ route('assistant.conversations.destroy', $conversation['id']) }}" onsubmit="return confirm('Hapus percakapan ini beserta seluruh pesannya? Tindakan ini permanen.');">
            @csrf
            @method('DELETE')
            <x-ui.button type="submit" variant="danger" size="sm">Hapus Percakapan</x-ui.button>
        </form>
    </section>

    {{-- Kirim asinkron: animasi menunggu hanya selama permintaan nyata berlangsung, dan tombol dikunci untuk mencegah kiriman ganda. --}}
    <script>
        (() => {
            const form = document.getElementById('assistant-form');
            if (!form) { return; }
            const pending = document.getElementById('assistant-pending');
            const error = document.getElementById('assistant-error');
            const submit = form.querySelector('[data-assistant-submit]');
            const input = form.querySelector('textarea');
            const requestId = form.querySelector('[name="request_id"]');
            const storageKey = `prime-assistant-pending:${form.dataset.conversationId}`;
            let inFlight = false;
            let lastSubmitted = null;
            const showError = (message) => {
                error.textContent = message;
                error.classList.remove('hidden');
            };
            try {
                const previous = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
                if (previous && typeof previous.message === 'string' && typeof previous.request_id === 'string') {
                    input.value = previous.message;
                    requestId.value = previous.request_id;
                    lastSubmitted = previous.message;
                    showError('Pengiriman sebelumnya belum terkonfirmasi. Kirim ulang untuk memeriksa atau mencoba lagi tanpa menggandakan pesan.');
                }
            } catch (_) { /* Penyimpanan browser opsional; token form tetap melindungi retry pada halaman ini. */ }
            if (!new URLSearchParams(window.location.search).has('cursor')) {
                document.querySelector('#assistant-messages li:last-child')?.scrollIntoView({ block: 'end' });
            }

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (inFlight || !form.reportValidity()) { return; }
                if (lastSubmitted !== null && lastSubmitted !== input.value) {
                    requestId.value = crypto.randomUUID();
                }
                lastSubmitted = input.value;
                const body = new FormData(form);
                try {
                    sessionStorage.setItem(storageKey, JSON.stringify({ request_id: requestId.value, message: input.value }));
                } catch (_) { /* Tidak menggagalkan pengiriman bila browser menolak sessionStorage. */ }
                inFlight = true;
                error.classList.add('hidden');
                pending.classList.remove('hidden');
                pending.classList.add('flex');
                submit.disabled = true;
                input.readOnly = true;
                form.setAttribute('aria-busy', 'true');
                pending.scrollIntoView({ block: 'nearest' });

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body,
                    });
                    const payload = await response.json().catch(() => null);
                    if (response.redirected || !response.ok || !payload) {
                        const message = response.status === 419 || response.redirected
                            ? 'Sesi berakhir. Muat ulang halaman dan masuk kembali sebelum mencoba lagi.'
                            : payload?.errors?.message?.[0] || payload?.message || 'Jawaban AI belum dapat dibuat. Coba kirim ulang.';
                        throw new Error(message);
                    }
                    try { sessionStorage.removeItem(storageKey); } catch (_) { /* Penyimpanan opsional. */ }
                    window.location.assign(form.action.replace(/\/messages$/, ''));
                } catch (failure) {
                    showError(failure.message || 'Koneksi terputus. Coba kirim ulang tanpa menggandakan pesan.');
                } finally {
                    inFlight = false;
                    pending.classList.add('hidden');
                    pending.classList.remove('flex');
                    submit.disabled = false;
                    input.readOnly = false;
                    form.removeAttribute('aria-busy');
                }
            });
        })();
    </script>
</x-layouts.app>
