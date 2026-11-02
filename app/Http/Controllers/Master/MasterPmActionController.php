<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StorePmActionRequest;
use App\Http\Requests\Master\UpdatePmActionRequest;
use App\Models\PmAction;
use App\Services\Auth\PrimeAuthService;
use App\Services\Master\PmActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MasterPmActionController extends Controller
{
    public function __construct(
        protected PmActionService $actionService,
        protected PrimeAuthService $primeAuth,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $search = trim($request->string('search')->toString());

        $actions = $this->actionService->query($search)
            ->paginate(10)
            ->withQueryString()
            ->through(fn (PmAction $action): array => [
                'id' => $action->id,
                'action_label' => $action->action_label,
                'is_active' => (bool) $action->is_active,
                // URL aksi disiapkan server agar Vue tidak pernah menyusun endpoint sendiri.
                'update_url' => route('master-checksheet.actions.update', $action->id),
                'activate_url' => route('master-checksheet.actions.activate', $action->id),
                'deactivate_url' => route('master-checksheet.actions.deactivate', $action->id),
            ]);

        return Inertia::render('Master/Action/Index', [
            'actions' => $actions,
            'filters' => ['search' => $search],
            'storeUrl' => route('master-checksheet.actions.store'),
            'manageAi' => $this->primeAuth->role($request) === 'admin' && Gate::allows('manage_ai'),
            // Bell notifikasi shell admin tetap aktif; fallback kembali ke halaman Master Action.
            'notificationEndpoints' => [
                'index' => route('admin-notifications.index'),
                'readAll' => route('admin-notifications.read-all'),
                'readTemplate' => route('admin-notifications.read', ['notificationId' => '__ID__']),
                'fallback' => route('master-checksheet.actions.index'),
            ],
            'flash' => [
                'success' => $request->session()->get('flash_success'),
                'error' => $request->session()->get('flash_error'),
            ],
        ]);
    }

    /**
     * Wizard inline-add mengirim JSON; form HTML biasa tetap dialihkan agar
     * alur admin klasik tidak berubah.
     */
    public function store(StorePmActionRequest $request): JsonResponse|RedirectResponse
    {
        try {
            $action = $this->actionService->create($request, (string) $request->validated('action_label'));
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
            }
            throw $exception;
        }

        if ($request->expectsJson()) {
            return response()->json([
                'id' => $action->id,
                'action_label' => $action->action_label,
                'is_active' => $action->is_active,
            ], 201);
        }

        return redirect()->route('master-checksheet.actions.index')->with('flash_success', 'Master Action berhasil dibuat.');
    }

    public function update(UpdatePmActionRequest $request, PmAction $action): RedirectResponse
    {
        $this->actionService->update($request, $action, (string) $request->validated('action_label'));

        return redirect()->route('master-checksheet.actions.index')->with('flash_success', 'Master Action berhasil diperbarui.');
    }

    public function activate(Request $request, PmAction $action): RedirectResponse
    {
        $this->actionService->activate($request, $action);

        return redirect()->route('master-checksheet.actions.index')->with('flash_success', 'Master Action berhasil diaktifkan.');
    }

    public function deactivate(Request $request, PmAction $action): RedirectResponse
    {
        try {
            $this->actionService->deactivate($request, $action);
        } catch (ValidationException $exception) {
            return redirect()->route('master-checksheet.actions.index')->with('flash_error', $exception->getMessage());
        }

        return redirect()->route('master-checksheet.actions.index')->with('flash_success', 'Master Action berhasil dinonaktifkan.');
    }

    protected function findAction(int $id): PmAction
    {
        $action = PmAction::query()->find($id);
        if (! $action) {
            throw new HttpException(404);
        }

        return $action;
    }
}
