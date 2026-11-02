<?php

namespace App\Http\Controllers\PM;

use App\Http\Controllers\Controller;
use App\Models\Breakdown;
use App\Models\Machine;
use App\Services\Auth\PrimeAuthService;
use App\Services\PM\MachineLandingService;
use App\Services\PM\PmExecutionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MachineLandingController extends Controller
{
    public function __construct(
        protected PrimeAuthService $primeAuth,
        protected MachineLandingService $machineLandingService,
        protected PmExecutionService $pmExecutionService,
    ) {}

    public function show(Request $request, Machine $machine): View
    {
        $machine->load('location');

        $landingData = $this->machineLandingService->build($machine);
        $role = $this->primeAuth->role($request);
        $backUrl = $role === 'operator'
            ? (string) $request->session()->get('machine_access_return_url', route('machine-access'))
            : url()->previous();

        // TASK-006 Slice C (koreksi B-3): alasan disable dipisahkan menurut
        // tujuannya. Blokir role/admin dan notice Machine non-live tidak pernah
        // dilewati; hanya blokir pekerjaan BARU yang dapat dilewati continuation.
        $pmExecutorDisabledReason = null;        // blokir role/admin
        $transactionDisabledReason = null;       // notice/blokir Machine non-live
        $pmExecutorNewWorkDisabledReason = null; // blokir pekerjaan baru

        if (! $machine->is_active) {
            $transactionDisabledReason = 'Mesin nonaktif. Transaksi baru tidak dapat dibuat. Mesin nonaktif tetap bisa dilihat, tetapi transaksi baru harus disabled.';
        } elseif ($role === 'admin') {
            $pmExecutorDisabledReason = 'Admin tidak mengerjakan PM Executor.';
        } elseif ($role === 'operator' && ! $landingData['hasActivePmSchedule']) {
            $pmExecutorNewWorkDisabledReason = 'Tidak ada jadwal PM aktif.';
        }

        // Exception continuation hanya membuka kondisi yang mencegah pekerjaan
        // baru, dan hanya untuk operator; middleware role tetap authority utama.
        $pmExecutorContinuationAvailable = $role === 'operator'
            && $this->pmExecutionService->resolveContinuableExecution($machine) !== null;

        return view('pages.machine.show', [
            'machine' => $machine,
            'role' => $role,
            'actorName' => $this->primeAuth->name($request),
            'lastPm' => $landingData['lastPm'],
            'nextPm' => $landingData['nextPm'],
            'hasActivePmSchedule' => $landingData['hasActivePmSchedule'],
            'openBreakdowns' => $landingData['openBreakdowns'],
            'partOptions' => $landingData['partOptions'],
            'pmExecutorDisabledReason' => $pmExecutorDisabledReason,
            'transactionDisabledReason' => $transactionDisabledReason,
            'pmExecutorNewWorkDisabledReason' => $pmExecutorNewWorkDisabledReason,
            'pmExecutorContinuationAvailable' => $pmExecutorContinuationAvailable,
            'backUrl' => $backUrl,
        ]);
    }

    public function openPmExecutor(Request $request, Machine $machine): RedirectResponse
    {
        $landingData = $this->machineLandingService->build($machine);

        if (! $machine->is_active) {
            return back()->with('flash_error', 'Mesin nonaktif. PM Executor tidak dapat dibuka.');
        }

        if ($this->primeAuth->role($request) !== 'operator') {
            return back()->with('flash_error', 'Hanya operator yang dapat mengakses PM Executor.');
        }

        if (! $landingData['hasActivePmSchedule']) {
            return back()->with('flash_error', 'Tidak ada jadwal PM aktif untuk mesin ini.');
        }

        return redirect()->route('pm-executor.show', $machine);
    }

    public function openBreakdownInput(Machine $machine): RedirectResponse
    {
        if (! $machine->is_active) {
            return back()->with('flash_error', 'Mesin nonaktif. Input breakdown tidak dapat dibuka.');
        }

        return back()->with('flash_info', 'Input breakdown akan dilanjutkan pada FASE 13.');
    }

    public function closeBreakdown(Breakdown $breakdown): RedirectResponse
    {
        if ($breakdown->status !== 'open') {
            return back()->with('flash_error', 'Breakdown ini sudah CLOSED.');
        }

        if ($breakdown->machine && ! $breakdown->machine->is_active) {
            return back()->with('flash_error', 'Mesin nonaktif. Close breakdown tidak dapat diproses.');
        }

        return back()->with('flash_info', 'Close breakdown akan dilanjutkan pada FASE 14.');
    }
}
