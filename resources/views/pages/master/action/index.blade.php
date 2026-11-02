<x-layouts.app title="Master Action PRIME" heading="Master Action" :actor-name="$actorName" :role="$role">
    <section class="mt-8 space-y-6" data-action-page>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-[15px] font-medium text-[#615d59]">PM Management / Master PM Checksheet / Master Action</p>
                <h2 class="mt-4 text-[40px] font-bold leading-[1.1]">Master Action</h2>
                <p class="mt-2 text-[16px] text-[#615d59]">Kelola pilihan action reusable untuk standard pengecekan PM.</p>
            </div>

        </div>

        @if (session('flash_success'))
            <x-ui.alert variant="success">{{ session('flash_success') }}</x-ui.alert>
        @endif
        @if (session('flash_error'))
            <x-ui.alert variant="error">{{ session('flash_error') }}</x-ui.alert>
        @endif
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <form method="POST" action="{{ route('master-checksheet.actions.store') }}" class="flex flex-col gap-3 sm:flex-row sm:items-start">
                @csrf
                <div class="flex w-full max-w-[320px] flex-col gap-1.5">
                    <input type="text" name="action_label" maxlength="50" required placeholder="Label action baru" data-master-action-label class="h-11 w-full rounded-[6px] border border-[rgba(0,0,0,0.1)] bg-white px-4 text-[14px] outline-none focus:border-[#097fe8]">
                    <p class="text-xs text-[#615d59]">1 Master Action = 1 pilihan di PM Executor. Buat pilihan satu per satu, misalnya: OK / NG / TAMBAH OLI.</p>
                    <p data-master-action-comma-guidance role="status" aria-live="polite" class="hidden text-xs text-amber-700">Label ini mengandung koma. Jika Anda bermaksud membuat beberapa pilihan, buat setiap pilihan sebagai Master Action terpisah.</p>
                </div>
                <button type="submit" class="inline-flex h-11 shrink-0 items-center rounded-[6px] bg-[#0075de] px-[18px] text-[14px] font-semibold text-white hover:bg-[#005bab]">Tambah Action</button>
            </form>
        </div>
        <form method="GET" action="{{ route('master-checksheet.actions.index') }}" class="flex gap-3">
            <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Cari label action..." class="h-11 w-full max-w-[320px] rounded-[6px] border border-[rgba(0,0,0,0.1)] bg-white px-4 text-[14px] outline-none focus:border-[#097fe8]">
            <button type="submit" class="inline-flex h-11 items-center rounded-[6px] border border-[rgba(0,0,0,0.1)] px-4 text-[14px] font-medium">Cari</button>
        </form>
        <x-ui.card padding="p-0" class="overflow-hidden rounded-[12px] border border-[rgba(0,0,0,0.1)] bg-white">
            <div class="overflow-x-auto">
                <table class="min-w-full [&_th]:h-14 [&_th]:px-4 [&_td]:h-14 [&_td]:px-4">
                    <thead class="bg-[#f6f5f4]"><tr><th class="text-left text-[13px]">No</th><th class="text-left text-[13px]">Label Action</th><th class="text-left text-[13px]">Status</th><th class="text-center text-[13px]">Aksi</th></tr></thead>
                    <tbody>
                        @forelse ($actions as $action)
                            <tr class="border-b border-[rgba(0,0,0,0.08)] text-[14px]">
                                <td>{{ $actions->firstItem() + $loop->index }}</td>
                                <td>{{ $action->action_label }}</td>
                                <td>{{ $action->is_active ? 'Aktif' : 'Nonaktif' }}</td>
                                <td class="text-center">
                                    <div class="flex justify-center gap-2">
                                        <button type="button" data-action-edit-open data-action-id="{{ $action->id }}" data-action-label="{{ $action->action_label }}" data-action-update-url="{{ route('master-checksheet.actions.update', $action->id) }}" class="text-[#0075de]">Edit</button>
                                        @if ($action->is_active)
                                            <form method="POST" action="{{ route('master-checksheet.actions.deactivate', $action->id) }}" class="inline" onsubmit="return confirm('Nonaktifkan Master Action ini?')">@csrf @method('PATCH')<button type="submit" class="text-[#b86b00]">Nonaktifkan</button></form>
                                        @else
                                            <form method="POST" action="{{ route('master-checksheet.actions.activate', $action->id) }}" class="inline">@csrf @method('PATCH')<button type="submit" class="text-[#15803d]">Aktifkan</button></form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-10 text-center text-[#615d59]">Belum ada Master Action.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-ui.numeric-pagination :paginator="$actions" container-class="flex flex-col gap-4 border-t border-[rgba(0,0,0,0.1)] px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between" />
        </x-ui.card>
        <dialog data-action-edit-dialog class="rounded-[12px] border-0 p-6 shadow-xl">
            <form method="POST" data-action-edit-form>
                @csrf @method('PATCH')
                <h3 class="text-xl font-semibold">Edit Master Action</h3>
                <input name="action_label" data-action-edit-input class="mt-4 h-11 rounded border px-3" maxlength="50" required>
                <div class="mt-4 flex justify-end gap-3"><button type="button" onclick="this.closest('dialog').close()">Batal</button><button type="submit" class="text-[#0075de]">Simpan</button></div>
            </form>
        </dialog>
    </section>
    <script>
        document.querySelectorAll('[data-action-edit-open]').forEach((button) => button.addEventListener('click', () => {
            const dialog = document.querySelector('[data-action-edit-dialog]');
            dialog.querySelector('[data-action-edit-form]').action = button.dataset.actionUpdateUrl;
            dialog.querySelector('[data-action-edit-input]').value = button.dataset.actionLabel;
            dialog.showModal();
        }));
    </script>
</x-layouts.app>
