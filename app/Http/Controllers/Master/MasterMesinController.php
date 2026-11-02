<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\MachineLifecycleRequest;
use App\Http\Requests\Master\StoreMachineRequest;
use App\Http\Requests\Master\UpdateMachineRequest;
use App\Models\Location;
use App\Models\Machine;
use App\Services\Auth\PrimeAuthService;
use App\Services\Master\MachineService;
use App\Services\PM\InvalidMachineTransitionException;
use App\Services\PM\LifecyclePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class MasterMesinController extends Controller
{
    public function __construct(
        protected MachineService $machineService,
        protected PrimeAuthService $primeAuth,
        protected LifecyclePolicy $lifecyclePolicy,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $search = trim($request->string('search')->toString());
        $status = $request->string('status')->toString();
        $locationId = $request->integer('location_id');
        $allowedSorts = ['machine_code', 'machine_name'];
        $sort = $request->string('sort')->toString();
        $direction = $request->string('direction')->toString();

        if (! in_array($sort, $allowedSorts, true) || ! in_array($direction, ['asc', 'desc'], true)) {
            $sort = 'machine_code';
            $direction = 'asc';
        }

        if (! in_array($status, ['active', 'inactive', 'retired'], true)) {
            $status = '';
        }

        $machines = Machine::query()
            ->with('location:id,location_name,is_active')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($subQuery) use ($search): void {
                    $subQuery
                        ->where('machine_code', 'like', "%{$search}%")
                        ->orWhere('machine_name', 'like', "%{$search}%");
                });
            })
            ->when($locationId > 0, fn ($query) => $query->where('location_id', $locationId))
            ->when($status !== '', fn ($query) => $query->where('lifecycle_status', $status))
            ->orderBy($sort, $direction)
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Machine $machine): array => [
                'id' => $machine->id,
                'machine_code' => $machine->machine_code,
                'machine_name' => $machine->machine_name,
                'description' => $machine->description,
                'location_id' => $machine->location_id,
                'location_name' => $machine->location?->location_name ?? 'Lokasi terhapus',
                'lifecycle_status' => $machine->lifecycle_status,
                'created_at' => $machine->created_at?->translatedFormat('d F Y H:i'),
                'updated_at' => $machine->updated_at?->translatedFormat('d F Y H:i'),
                'qr_image_url' => $machine->qr_code_path ? Storage::url($machine->qr_code_path) : null,
                'update_url' => route('master-mesin.update', $machine->id),
                'qr_generate_url' => route('master-mesin.generate-qr', $machine->id),
                // Hanya transisi yang diizinkan LifecyclePolicy yang diekspos ke UI, agar Vue
                // tidak pernah menawarkan aksi lifecycle ilegal untuk state mesin saat ini.
                'activate_url' => $this->lifecyclePolicy->canTransition('machine', $machine->lifecycle_status, 'active')
                    ? route('master-mesin.activate', $machine->id)
                    : null,
                'deactivate_url' => $this->lifecyclePolicy->canTransition('machine', $machine->lifecycle_status, 'inactive')
                    ? route('master-mesin.deactivate', $machine->id)
                    : null,
                'retire_url' => $this->lifecyclePolicy->canTransition('machine', $machine->lifecycle_status, 'retired')
                    ? route('master-mesin.retire', $machine->id)
                    : null,
                'delete_url' => route('master-mesin.destroy', $machine->id),
            ]);

        $locations = Location::query()
            ->orderBy('location_name')
            ->get(['id', 'location_name', 'is_active']);

        return Inertia::render('Master/Mesin/Index', [
            'machines' => $machines,
            'locations' => $locations->map(fn (Location $location): array => [
                'id' => $location->id,
                'location_name' => $location->location_name,
                'is_active' => (bool) $location->is_active,
            ]),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'location_id' => $locationId > 0 ? $locationId : null,
                'sort' => $sort,
                'direction' => $direction,
            ],
            'statusChoices' => [
                ['value' => 'active', 'label' => 'Aktif'],
                ['value' => 'inactive', 'label' => 'Nonaktif'],
                ['value' => 'retired', 'label' => 'Pensiun'],
            ],
            'storeUrl' => route('master-mesin.store'),
            'manageAi' => $this->primeAuth->role($request) === 'admin' && Gate::allows('manage_ai'),
            // Bell notifikasi shell admin tetap aktif di halaman ini memakai endpoint yang sama
            // dengan Dashboard, hanya fallback-nya kembali ke daftar Master Mesin.
            'notificationEndpoints' => [
                'index' => route('admin-notifications.index'),
                'readAll' => route('admin-notifications.read-all'),
                'readTemplate' => route('admin-notifications.read', ['notificationId' => '__ID__']),
                'fallback' => route('master-mesin.index'),
            ],
            'flash' => [
                'success' => $request->session()->get('flash_success'),
                'error' => $request->session()->get('flash_error'),
            ],
        ]);
    }

    public function store(StoreMachineRequest $request): RedirectResponse
    {
        $this->machineService->create($request, $request->validated());

        return redirect()
            ->route('master-mesin.index')
            ->with('flash_success', 'Mesin berhasil ditambahkan.');
    }

    public function update(UpdateMachineRequest $request, int $machineId): RedirectResponse
    {
        $machine = $this->findMachine($machineId);
        $this->machineService->update($request, $machine, $request->validated());

        return redirect()
            ->route('master-mesin.index')
            ->with('flash_success', 'Mesin berhasil diperbarui.');
    }

    public function deactivate(MachineLifecycleRequest $request, int $machineId): RedirectResponse
    {
        $machine = $this->findMachine($machineId);

        try {
            $this->machineService->deactivate($machine);
        } catch (InvalidMachineTransitionException $exception) {
            // Penolakan domain lifecycle menjadi error bisnis terkendali, bukan HTTP 500.
            return redirect()
                ->route('master-mesin.index')
                ->with('flash_error', $exception->getMessage());
        }

        return redirect()
            ->route('master-mesin.index')
            ->with('flash_success', 'Mesin berhasil dinonaktifkan.');
    }

    public function activate(MachineLifecycleRequest $request, int $machineId): RedirectResponse
    {
        $machine = $this->findMachine($machineId);

        try {
            $this->machineService->activate($machine);
        } catch (InvalidMachineTransitionException $exception) {
            // Penolakan domain lifecycle menjadi error bisnis terkendali, bukan HTTP 500.
            return redirect()
                ->route('master-mesin.index')
                ->with('flash_error', $exception->getMessage());
        }

        return redirect()
            ->route('master-mesin.index')
            ->with('flash_success', 'Mesin berhasil diaktifkan kembali.');
    }

    public function retire(MachineLifecycleRequest $request, int $machineId): RedirectResponse
    {
        $machine = $this->findMachine($machineId);

        try {
            $this->machineService->retire($machine);
        } catch (InvalidMachineTransitionException $exception) {
            // Penolakan domain lifecycle menjadi error bisnis terkendali, bukan HTTP 500.
            return redirect()
                ->route('master-mesin.index')
                ->with('flash_error', $exception->getMessage());
        }

        return redirect()
            ->route('master-mesin.index')
            ->with('flash_success', 'Mesin berhasil dipensiunkan.');
    }

    public function destroy(Request $request, int $machineId): RedirectResponse
    {
        $machine = $this->findMachine($machineId);

        // TASK-003 Slice 3: Cek proteksi histori sebelum menghapus
        if (! $this->machineService->delete($request, $machine)) {
            return redirect()
                ->route('master-mesin.index')
                ->with('flash_error', 'Mesin yang sudah memiliki transaksi atau riwayat maintenance tidak dapat dihapus.');
        }

        return redirect()
            ->route('master-mesin.index')
            ->with('flash_success', 'Mesin berhasil dihapus permanen.');
    }

    public function generateQr(Request $request, int $machineId): RedirectResponse
    {
        $machine = $this->findMachine($machineId);
        $this->machineService->regenerateQr($request, $machine);

        return redirect()
            ->back()
            ->with('flash_success', 'QR code mesin berhasil digenerate.');
    }

    protected function findMachine(int $machineId): Machine
    {
        return Machine::query()->findOrFail($machineId);
    }
}
