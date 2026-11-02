<x-layouts.app
    title="Knowledge Documents PRIME"
    heading="Knowledge Documents"
    :actor-name="auth()->user()?->name ?? 'User'"
    role="admin"
>
    <section class="space-y-6">
        <div class="flex items-center gap-2 text-[15px] font-medium text-[rgba(0,0,0,0.95)]">
            <span>AI Settings</span>
            <span class="text-[#a39e98]">/</span>
            <span>Knowledge Documents</span>
        </div>

        <div>
            <h2 class="text-[2.35rem] font-semibold tracking-[-0.05em] text-[var(--color-prime-ink)]">Knowledge Documents</h2>
            <p class="mt-2 text-lg text-[var(--color-prime-muted)]">Unggah SOP/WI/PM berbasis teks, proses ke indeks Qdrant, lalu publikasikan setelah READY.</p>
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

        <x-ui.card>
            <h3 class="text-xl font-semibold">Unggah Dokumen PDF</h3>
            <form method="POST" action="{{ route('knowledge-documents.store') }}" enctype="multipart/form-data" class="mt-4 space-y-4">
                @csrf
                <label class="block">
                    <span class="mb-2 block font-medium">Judul</span>
                    <input type="text" name="title" required maxlength="200" value="{{ old('title') }}" class="w-full rounded-xl border border-[var(--color-prime-border)] bg-white px-4 py-3">
                </label>
                <label class="block">
                    <span class="mb-2 block font-medium">Versi</span>
                    <input type="text" name="version" required maxlength="64" value="{{ old('version') }}" class="w-full rounded-xl border border-[var(--color-prime-border)] bg-white px-4 py-3">
                </label>
                <label class="block">
                    <span class="mb-2 block font-medium">Berkas PDF (maks 20 MB, berbasis teks)</span>
                    <input type="file" name="document" accept="application/pdf" required class="w-full rounded-xl border border-[var(--color-prime-border)] bg-white px-4 py-3">
                </label>
                <x-ui.button type="submit">
                    <span data-upload-label>Unggah dokumen</span>
                    <span data-upload-spinner class="ml-2 hidden h-4 w-4 animate-spin rounded-full border-2 border-current border-r-transparent motion-reduce:animate-none" aria-hidden="true"></span>
                </x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-xl font-semibold">Dokumen Terdaftar</h3>
            <p class="mt-1 text-sm text-[var(--color-prime-muted)]">Status diperbarui otomatis selama dokumen menunggu atau sedang diproses.</p>
            <div class="mt-4 divide-y divide-[var(--color-prime-border)]" data-documents-list data-status-url="{{ route('knowledge-documents.status') }}" data-current-page="{{ $documents->currentPage() }}" data-active-processing="{{ $documents->getCollection()->contains(fn ($document) => in_array($document->status, ['uploaded', 'processing'], true)) ? '1' : '0' }}">
                @forelse ($documents as $document)
                    @php
                        $status = $document->status;
                        $statusVariant = match ($status) { 'ready' => 'success', 'failed' => 'danger', 'processing' => 'warning', default => 'neutral' };
                        $statusLabel = match ($status) { 'uploaded' => 'Menunggu antrean', 'processing' => 'Sedang diproses', 'ready' => $document->is_published ? 'Selesai diproses' : 'Siap dipublikasikan', 'failed' => 'Gagal diproses', default => 'Status tidak diketahui' };
                        $statusMessage = match ($status) {
                            'uploaded' => 'Dokumen menunggu worker antrean untuk mulai diproses.',
                            'processing' => 'Worker sedang membaca dokumen dan menyiapkan indeks.',
                            'ready' => $document->is_published ? 'Aktif di Knowledge Base untuk retrieval.' : 'Pemrosesan selesai. Publikasikan agar aktif untuk retrieval.',
                            'failed' => 'Pemrosesan tidak selesai. Anda dapat mencoba kembali.',
                            default => 'Status dokumen belum tersedia.',
                        };
                    @endphp
                    <div class="flex flex-col gap-4 py-4 transition-colors duration-300 sm:flex-row sm:items-center sm:justify-between" data-document-row data-document-id="{{ $document->id }}" data-status="{{ $status }}" data-published="{{ $document->is_published ? '1' : '0' }}" data-uploaded-at="{{ $document->created_at?->toIso8601String() }}">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ $document->title }}</p>
                            <p class="text-sm text-[var(--color-prime-muted)]">{{ $document->document_code }} · versi {{ $document->version }} · {{ $document->original_filename }}</p>
                            <p class="text-xs text-[var(--color-prime-muted)]">Diunggah {{ $document->created_at?->format('Y-m-d H:i') }} oleh {{ $document->uploader?->name }}</p>
                            <p class="mt-1 text-sm text-[var(--color-prime-muted)]" data-status-message>{{ $statusMessage }}</p>
                            <p class="mt-1 text-sm text-[var(--color-prime-error, #b91c1c)]" data-safe-error @if ($status !== 'failed' || ! $document->safe_error_message) hidden @endif>{{ $document->safe_error_message }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2" data-document-actions>
                            <x-ui.badge data-status-badge :variant="$statusVariant"><span data-status-label>{{ $statusLabel }}</span><span data-status-spinner class="{{ $status === 'processing' ? '' : 'hidden' }} ml-2 inline-block h-3 w-3 animate-spin rounded-full border-2 border-current border-r-transparent motion-reduce:animate-none" aria-hidden="true"></span></x-ui.badge>
                            <x-ui.badge data-publication-badge :variant="$document->is_published ? 'success' : 'neutral'"><span data-publication-label>{{ $document->is_published ? 'Aktif di Knowledge Base' : 'Belum dipublikasikan' }}</span></x-ui.badge>
                            <span data-document-action-form>
                                @if ($status === 'ready' && ! $document->is_published)
                                    <form method="POST" action="{{ route('knowledge-documents.publish', $document) }}">@csrf<x-ui.button type="submit" variant="ghost">Publikasikan</x-ui.button></form>
                                @elseif ($status === 'failed')
                                    <form method="POST" action="{{ route('knowledge-documents.retry', $document) }}">@csrf<x-ui.button type="submit" variant="ghost">Coba Lagi</x-ui.button></form>
                                @endif
                            </span>
                        </div>
                    </div>
                @empty
                    <p class="py-4 text-[var(--color-prime-muted)]">Belum ada dokumen pengetahuan.</p>
                @endforelse
            </div>
            {{ $documents->links() }}
        </x-ui.card>
    </section>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const uploadForm = document.querySelector('form[action="{{ route('knowledge-documents.store') }}"]');
            uploadForm?.addEventListener('submit', () => {
                const button = uploadForm.querySelector('button[type="submit"]');
                if (! button) return;
                button.disabled = true;
                button.querySelector('[data-upload-label]')?.replaceChildren('Mengunggah…');
                button.querySelector('[data-upload-spinner]')?.classList.remove('hidden');
            });

            const list = document.querySelector('[data-documents-list]');
            if (! list || list.dataset.activeProcessing !== '1') return;

            const labels = { uploaded: 'Menunggu antrean', processing: 'Sedang diproses', ready: 'Siap dipublikasikan', failed: 'Gagal diproses' };
            const messageFor = (doc, row) => {
                if (doc.status === 'uploaded') {
                    return Date.now() - Date.parse(row.dataset.uploadedAt) > 300000
                        ? 'Masih menunggu antrean worker. Status akan diperiksa secara berkala.'
                        : 'Dokumen menunggu worker antrean untuk mulai diproses.';
                }
                if (doc.status === 'processing') return 'Worker sedang membaca dokumen dan menyiapkan indeks.';
                if (doc.status === 'ready') return doc.is_published ? 'Aktif di Knowledge Base untuk retrieval.' : 'Pemrosesan selesai. Publikasikan agar aktif untuk retrieval.';
                if (doc.status === 'failed') return 'Pemrosesan tidak selesai. Anda dapat mencoba kembali.';
                return 'Status dokumen belum tersedia.';
            };
            const actionForm = (url, label) => {
                if (! url) return document.createDocumentFragment();
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = url;
                const token = document.createElement('input');
                token.type = 'hidden';
                token.name = '_token';
                token.value = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                form.append(token);
                const button = document.createElement('button');
                button.type = 'submit';
                button.className = 'rounded-[var(--radius-micro)] border border-[var(--color-prime-border)] bg-white px-4 py-2 text-sm font-semibold text-[var(--color-prime-ink)] transition hover:border-[var(--color-prime-primary)] hover:text-[var(--color-prime-primary)]';
                button.textContent = label;
                form.append(button);
                return form;
            };
            const badgeClasses = {
                neutral: 'bg-[var(--color-prime-soft)] text-[var(--color-prime-muted)]',
                success: 'bg-[var(--color-prime-success-soft)] text-[var(--color-prime-success)]',
                danger: 'bg-[var(--color-prime-danger-soft)] text-[var(--color-prime-danger)]',
                warning: 'bg-[var(--color-prime-warning-soft)] text-[var(--color-prime-warning)]',
            };
            const badgeBase = 'inline-flex items-center rounded-[var(--radius-pill)] px-2.5 py-1 text-[12px] font-semibold tracking-[var(--tracking-micro)]';
            const updateRow = (row, doc) => {
                const publicationBadge = row.querySelector('[data-publication-badge]');
                publicationBadge.className = `${badgeBase} ${badgeClasses[doc.is_published ? 'success' : 'neutral']}`;
                const changed = row.dataset.status !== doc.status || row.dataset.published !== (doc.is_published ? '1' : '0');
                const badge = row.querySelector('[data-status-badge]');
                const variant = doc.status === 'ready' ? 'success' : doc.status === 'failed' ? 'danger' : doc.status === 'processing' ? 'warning' : 'neutral';
                badge.className = `${badgeBase} ${badgeClasses[variant]}`;
                row.dataset.status = doc.status;
                row.dataset.published = doc.is_published ? '1' : '0';
                row.querySelector('[data-status-label]').textContent = doc.status === 'ready' && doc.is_published ? 'Selesai diproses' : (labels[doc.status] ?? 'Status tidak diketahui');
                row.querySelector('[data-status-message]').textContent = messageFor(doc, row);
                const error = row.querySelector('[data-safe-error]');
                error.textContent = doc.status === 'failed' ? (doc.safe_error_message ?? '') : '';
                error.hidden = doc.status !== 'failed' || ! doc.safe_error_message;
                row.querySelector('[data-status-spinner]').classList.toggle('hidden', doc.status !== 'processing');
                row.querySelector('[data-publication-label]').textContent = doc.is_published ? 'Aktif di Knowledge Base' : 'Belum dipublikasikan';
                const action = row.querySelector('[data-document-action-form]');
                action.replaceChildren();
                if (doc.status === 'ready' && ! doc.is_published) action.append(actionForm(doc.publish_url, 'Publikasikan'));
                if (doc.status === 'failed') action.append(actionForm(doc.retry_url, 'Coba Lagi'));
                if (changed && doc.status === 'ready') {
                    row.classList.add('bg-[var(--color-prime-success-soft)]');
                    window.setTimeout(() => row.classList.remove('bg-[var(--color-prime-success-soft)]'), 900);
                }
            };

            let timer = null;
            let requestPending = false;
            const poll = async () => {
                if (requestPending || document.hidden) return;
                requestPending = true;
                try {
                    const url = new URL(list.dataset.statusUrl, window.location.origin);
                    url.searchParams.set('page', list.dataset.currentPage);
                    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    if (! response.ok) throw new Error('Status request failed');
                    const payload = await response.json();
                    payload.documents.forEach((doc) => {
                        const row = list.querySelector(`[data-document-id="${CSS.escape(String(doc.id))}"]`);
                        if (row) updateRow(row, doc);
                    });
                    list.dataset.activeProcessing = payload.has_active_processing ? '1' : '0';
                    if (! payload.has_active_processing && timer !== null) {
                        window.clearInterval(timer);
                        timer = null;
                    }
                } catch {
                    // Perubahan jaringan tidak menghapus status terakhir yang sudah dikonfirmasi server.
                } finally {
                    requestPending = false;
                }
            };
            timer = window.setInterval(poll, 5000);
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) return;
                if (list.dataset.activeProcessing === '1' && timer === null) timer = window.setInterval(poll, 5000);
                poll();
            });
        });
    </script>
</x-layouts.app>
