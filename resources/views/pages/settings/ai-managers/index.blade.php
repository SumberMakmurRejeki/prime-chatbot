<x-layouts.app
    title="Pengelola AI PRIME"
    heading="Pengelola AI"
    :actor-name="auth()->user()?->name ?? 'User'"
    role="admin"
>
    <section class="space-y-6">
        <div class="flex items-center gap-2 text-[15px] font-medium text-[rgba(0,0,0,0.95)]">
            <span>AI Settings</span>
            <span class="text-[#a39e98]">/</span>
            <span>Pengelola AI</span>
        </div>

        <div>
            <h2 class="text-[2.35rem] font-semibold tracking-[-0.05em] text-[var(--color-prime-ink)]">Pengelola AI</h2>
            <p class="mt-2 text-lg text-[var(--color-prime-muted)]">Kelola Admin yang dapat mengatur akses AI.</p>
        </div>

        @if (session('flash_success'))
            <x-ui.alert variant="success">{{ session('flash_success') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <h3 class="text-xl font-semibold">Tambah Pengelola</h3>
            <form method="POST" action="{{ route('settings-ai-managers.grant') }}" class="mt-4 space-y-4" data-ai-manager-grant-form>
                @csrf
                <label class="block">
                    <span class="mb-2 block font-medium">Admin aktif</span>
                    <select name="target_user_id" required class="w-full rounded-xl border border-[var(--color-prime-border)] bg-white px-4 py-3" data-ai-manager-target>
                        <option value="">Pilih Admin</option>
                        @foreach ($admins->where('manage_ai', false) as $admin)
                            <option value="{{ $admin->id }}">{{ $admin->name }} ({{ $admin->username }})</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="mb-2 block font-medium">Password Anda saat ini</span>
                    <input type="password" name="current_password" required autocomplete="current-password" class="w-full rounded-xl border border-[var(--color-prime-border)] bg-white px-4 py-3">
                </label>
                <x-ui.button type="submit">Tambah Pengelola</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-xl font-semibold">Pengelola saat ini</h3>
            <div class="mt-4 divide-y divide-[var(--color-prime-border)]">
                @forelse ($managers as $manager)
                    <div class="flex flex-col gap-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="font-semibold">{{ $manager->name }}</p>
                            <p class="text-sm text-[var(--color-prime-muted)]">{{ $manager->username }}@unless($manager->is_active) · Nonaktif @endunless</p>
                        </div>
                        <form method="POST" action="{{ route('settings-ai-managers.revoke', $manager) }}" class="flex flex-col gap-3 sm:flex-row sm:items-end" onsubmit="return confirm('Cabut akses Pengelola AI?')">
                            @csrf
                            @method('DELETE')
                            <label>
                                <span class="mb-2 block text-sm font-medium">Password Anda saat ini</span>
                                <input type="password" name="current_password" required autocomplete="current-password" class="rounded-xl border border-[var(--color-prime-border)] bg-white px-4 py-3">
                            </label>
                            <x-ui.button type="submit" variant="ghost">Cabut Akses AI</x-ui.button>
                        </form>
                    </div>
                @empty
                    <p class="py-4 text-[var(--color-prime-muted)]">Belum ada Pengelola AI.</p>
                @endforelse
            </div>
        </x-ui.card>
    </section>
</x-layouts.app>
