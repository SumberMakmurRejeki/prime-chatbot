<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Http\Requests\AI\StoreKnowledgeDocumentRequest;
use App\Jobs\ProcessKnowledgeDocument;
use App\Models\KnowledgeDocument;
use App\Services\AI\KnowledgeDocumentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class KnowledgeDocumentController extends Controller
{
    public function index(): View
    {
        return view('pages.settings.knowledge-documents.index', [
            'documents' => KnowledgeDocument::query()->with('uploader')->latest()->paginate(20),
        ]);
    }

    public function status(): JsonResponse
    {
        // Endpoint polling read-only: baris diambil dari halaman yang sama dengan index (urutan latest, 20 per halaman).
        // Kolom dibatasi agar tidak membocorkan storage_path/checksum; URL aksi hanya diberikan ketika status memungkinkan.
        $documents = KnowledgeDocument::query()
            ->latest()
            ->paginate(20, ['id', 'title', 'version', 'status', 'is_published', 'safe_error_message', 'created_at']);

        $items = $documents->getCollection();

        // has_active_processing hanya untuk halaman yang sedang dilihat; UI berhenti polling ketika halamannya bersih.
        return response()->json([
            'documents' => $items->map(fn (KnowledgeDocument $document): array => [
                'id' => $document->id,
                'title' => $document->title,
                'version' => $document->version,
                'status' => $document->status,
                'is_published' => $document->is_published,
                'safe_error_message' => $document->safe_error_message,
                'created_at' => $document->created_at?->toISOString(),
                'publish_url' => $document->status === 'ready' && ! $document->is_published
                    ? route('knowledge-documents.publish', $document)
                    : null,
                'retry_url' => $document->status === 'failed'
                    ? route('knowledge-documents.retry', $document)
                    : null,
            ])->values(),
            'has_active_processing' => $items->contains(fn (KnowledgeDocument $document): bool => in_array($document->status, ['uploaded', 'processing'], true)),
        ]);
    }

    public function store(StoreKnowledgeDocumentRequest $request, KnowledgeDocumentService $documents): RedirectResponse
    {
        $validated = $request->validated();
        $file = $request->file('document');
        $documentCode = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $documentCode = preg_replace('/[^A-Za-z0-9_-]+/', '-', strtoupper($documentCode)) ?: 'DOC';

        $document = $documents->store($file, mb_substr($documentCode, 0, 64), $validated['title'], $validated['version'], (int) $request->user()->id);
        ProcessKnowledgeDocument::dispatch($document->id)->afterCommit();

        return redirect()->route('knowledge-documents.index')->with('flash_success', 'Dokumen berhasil diunggah dan masuk antrean pemrosesan.');
    }

    public function publish(KnowledgeDocument $knowledgeDocument, KnowledgeDocumentService $documents): RedirectResponse
    {
        try {
            $documents->publish($knowledgeDocument->id, (int) request()->user()->id);
        } catch (DomainException $exception) {
            return redirect()->route('knowledge-documents.index')->with('flash_error', $exception->getMessage());
        }

        return redirect()->route('knowledge-documents.index')->with('flash_success', 'Dokumen siap dan telah dipublikasikan.');
    }

    public function retry(KnowledgeDocument $knowledgeDocument, KnowledgeDocumentService $documents): RedirectResponse
    {
        try {
            $documents->retry($knowledgeDocument->id);
        } catch (DomainException $exception) {
            return redirect()->route('knowledge-documents.index')->with('flash_error', $exception->getMessage());
        }

        return redirect()->route('knowledge-documents.index')->with('flash_success', 'Dokumen dimasukkan kembali ke antrean pemrosesan.');
    }
}
