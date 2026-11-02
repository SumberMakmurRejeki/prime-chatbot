<x-layouts.app
    title="PRIME Assistant"
    heading="Assistant"
    :actor-name="auth()->user()?->name ?? 'User'"
    role="admin"
>
    <section class="space-y-6">
        <div class="flex items-center gap-2 text-[15px] font-medium text-[rgba(0,0,0,0.95)]">
            <span>Assistant</span>
        </div>

        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-[2.35rem] font-semibold tracking-[-0.05em] text-[var(--color-prime-ink)]">PRIME Assistant</h2>
                <p class="mt-2 text-base text-[var(--color-prime-muted)]">Tanyakan panduan teknis berdasarkan dokumen PDF terbit yang tersedia.</p>
            </div>
            <form method="POST" action="{{ route('assistant.store') }}">
                @csrf
                <x-ui.button type="submit">+ Chat Baru</x-ui.button>
            </form>
        </header>

        @if (session('flash_success'))
            <x-ui.alert variant="success">{{ session('flash_success') }}</x-ui.alert>
        @endif
        @if (session('flash_error'))
            <x-ui.alert variant="error">{{ session('flash_error') }}</x-ui.alert>
        @endif

        @if ($conversations->count() === 0)
            <x-ui.empty-state
                title="Belum ada percakapan"
                message="Klik Chat Baru untuk menyimpan percakapan pertama Anda."
            />
        @else
            <x-ui.card padding="p-0" class="overflow-hidden">
                <ul class="divide-y divide-[var(--color-prime-border)]">
                    @foreach ($conversations as $conversation)
                        <li>
                            <a
                                href="{{ route('assistant.show', $conversation['id']) }}"
                                class="flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-[var(--color-prime-soft)] sm:px-6"
                            >
                                <span class="min-w-0">
                                    <span class="block truncate text-base font-medium text-[var(--color-prime-ink)]">{{ $conversation['title'] }}</span>
                                    <span class="mt-1 block text-sm text-[var(--color-prime-muted)]">{{ \Illuminate\Support\Carbon::parse($conversation['updated_at'])?->diffForHumans() ?? '-' }}</span>
                                </span>
                                <span class="shrink-0 text-[var(--color-prime-muted)]">
                                    <x-ui.icon name="chevron-down" class="h-5 w-5 -rotate-90 [stroke-width:1.6]" />
                                </span>
                            </a>
                            <form method="POST" action="{{ route('assistant.conversations.destroy', $conversation['id']) }}" onsubmit="return confirm('Hapus percakapan ini beserta seluruh pesannya? Tindakan ini permanen.');" class="px-5 pb-3 sm:px-6">
                                @csrf
                                @method('DELETE')
                                <x-ui.button type="submit" variant="danger" size="sm">Hapus Percakapan</x-ui.button>
                            </form>
                        </li>
                    @endforeach
                </ul>
                <x-ui.numeric-pagination :paginator="$conversations" />
            </x-ui.card>
        @endif
    </section>
</x-layouts.app>
