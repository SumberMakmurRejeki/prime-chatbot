<?php

namespace App\Http\Controllers\PM;

use App\Http\Controllers\Controller;
use App\Http\Requests\PM\StorePmExecutionRequest;
use App\Http\Requests\PM\UploadPmMediaRequest;
use App\Models\Machine;
use App\Models\PmExecutionMedia;
use App\Services\Auth\PrimeAuthService;
use App\Services\PM\PmExecutionMediaService;
use App\Services\PM\PmExecutionService;
use App\Services\PM\StalePmExecutionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PmExecutorController extends Controller
{
    public function __construct(
        protected PrimeAuthService $primeAuth,
        protected PmExecutionService $pmExecutionService,
        protected PmExecutionMediaService $mediaService,
    ) {}

    public function show(Request $request, Machine $machine): View|RedirectResponse
    {
        // TASK-006 Slice C (koreksi B-3): execution canonical yang sudah dimulai
        // tetap dapat diselesaikan meski Machine non-live. Exception ini hanya
        // untuk operator; middleware role tetap menjadi authorization authority.
        $continuationAvailable = $this->primeAuth->role($request) === 'operator'
            && $this->pmExecutionService->resolveContinuableExecution($machine) !== null;

        if (! $machine->is_active && ! $continuationAvailable) {
            return redirect()
                ->route('machines.show', $machine)
                ->with('flash_error', 'Mesin nonaktif. PM Executor tidak dapat dibuka.');
        }

        try {
            $context = $this->pmExecutionService->getExecutorContext($machine);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('machines.show', $machine)
                ->with('flash_error', $exception->validator->errors()->first() ?: 'PM Executor belum siap.');
        }

        return view('pages.pm.executor', [
            'actorName' => $this->primeAuth->name($request),
            'role' => $this->primeAuth->role($request),
            'machine' => $machine->load('location'),
            'scheduleDate' => $context['schedule_date'],
            'execution' => $context['execution'],
            'checksheetCode' => $context['checksheet_code'],
            'checksheetName' => $context['checksheet_name'],
            'parts' => $context['parts'],
            'mediaByPart' => $context['media_by_part'],
        ]);
    }

    /**
     * BR-PM-EXEC-004: Mulai PM adalah satu-satunya aksi yang membuat execution
     * canonical; payload hasil tidak dibaca dari request Start.
     */
    public function start(Request $request, Machine $machine): RedirectResponse
    {
        $operator = $this->primeAuth->authenticatedUser();

        if (! $operator) {
            return redirect()->route('login');
        }

        try {
            $this->pmExecutionService->startExecutionOnly(
                request: $request,
                machine: $machine,
                operator: $operator,
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return redirect()
            ->route('pm-executor.show', $machine)
            ->with('flash_success', 'PM berhasil dimulai.');
    }

    public function saveDraft(StorePmExecutionRequest $request, Machine $machine): RedirectResponse
    {
        $operator = $this->primeAuth->authenticatedUser();

        if (! $operator) {
            return redirect()->route('login');
        }

        try {
            $this->pmExecutionService->saveDraft(
                request: $request,
                machine: $machine,
                operator: $operator,
                actionValues: (array) $request->validated('execution_action', []),
                clearValues: (array) $request->validated('execution_clear', []),
                numberValues: (array) $request->validated('execution_number', []),
                partNotes: (array) $request->validated('part_notes', []),
                expectedRevision: (int) $request->validated('expected_revision'),
            );
        } catch (StalePmExecutionException $exception) {
            return back()->with('flash_error', $exception->getMessage())->withInput();
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return redirect()
            ->route('pm-executor.show', $machine)
            ->with('flash_success', 'Draft PM berhasil disimpan.');
    }

    public function submit(StorePmExecutionRequest $request, Machine $machine): RedirectResponse
    {
        $operator = $this->primeAuth->authenticatedUser();

        if (! $operator) {
            return redirect()->route('login');
        }

        try {
            $this->pmExecutionService->submit(
                request: $request,
                machine: $machine,
                operator: $operator,
                actionValues: (array) $request->validated('execution_action', []),
                clearValues: (array) $request->validated('execution_clear', []),
                numberValues: (array) $request->validated('execution_number', []),
                partNotes: (array) $request->validated('part_notes', []),
                expectedRevision: (int) $request->validated('expected_revision'),
            );
        } catch (StalePmExecutionException $exception) {
            return back()->with('flash_error', $exception->getMessage())->withInput();
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return redirect()
            ->route('machines.show', $machine)
            ->with('flash_success', 'PM berhasil disubmit dan menunggu review admin.');
    }

    public function uploadMedia(UploadPmMediaRequest $request, Machine $machine): RedirectResponse|JsonResponse
    {
        $operator = $this->primeAuth->authenticatedUser();

        if (! $operator) {
            return redirect()->route('login');
        }

        // BR-PM-EXEC-004: context halaman bisa stale; resolver membaca ulang
        // occurrence + execution canonical dan menolak bila PM belum dimulai.
        $execution = $this->pmExecutionService->requireExistingInProgressExecution($machine);

        $partId = (int) $request->validated('part_id');
        $file = $request->file('media_file');

        if (! $file) {
            return redirect()->route('pm-executor.show', $machine)->with('flash_error', 'File media tidak ditemukan.');
        }
        // Service menerima ID mentah lalu memvalidasinya terhadap occurrence yang terkunci.
        $media = $this->mediaService->storeForExecution(
            execution: $execution,
            part: null,
            file: $file,
            operator: $operator,
            note: $request->validated('part_note'),
            partId: $partId > 0 ? $partId : null,
        );
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Media PM berhasil diupload.',
                'media' => [
                    'id' => $media->id,
                    'file_name' => $media->file_name,
                    'file_type' => $media->file_type,
                    'file_size' => (int) $media->file_size,
                ],
            ]);
        }

        return redirect()
            ->route('pm-executor.show', $machine)
            ->with('flash_success', 'Media PM berhasil diupload.');
    }

    public function deleteMedia(Request $request, Machine $machine, PmExecutionMedia $media): RedirectResponse|JsonResponse
    {
        $media->loadMissing('execution');
        if ((int) $media->pm_execution_id <= 0 || (int) $media->execution?->machine_id !== (int) $machine->id) {
            abort(404);
        }

        // Lifecycle reject dari service (non-in_progress) diteruskan sebagai 422/flash, bukan error server.
        try {
            $this->mediaService->deleteMedia($media);
        } catch (ValidationException $e) {
            $errorMessage = collect($e->errors())->flatten()->first()
                ?? 'Media PM tidak dapat dihapus.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $errorMessage,
                    'errors' => $e->errors(),
                ], 422);
            }

            return redirect()
                ->route('pm-executor.show', $machine)
                ->with('flash_error', $errorMessage);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Media PM berhasil dihapus.',
            ]);
        }

        return redirect()
            ->route('pm-executor.show', $machine)
            ->with('flash_success', 'Media PM berhasil dihapus.');
    }
}
