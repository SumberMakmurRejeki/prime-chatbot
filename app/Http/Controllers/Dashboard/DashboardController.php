<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\DashboardFilterRequest;
use App\Services\Auth\PrimeAuthService;
use App\Services\Dashboard\DashboardMonitoringService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Produksi GET /dashboard sejak cutover: presentasi Inertia/Vue dengan kontrak
 * data dari DashboardMonitoringService; Blade hanya disimpan sebagai rollback.
 */
class DashboardController extends Controller
{
    public function __construct(
        protected DashboardMonitoringService $dashboardMonitoring,
        protected PrimeAuthService $primeAuth,
    ) {}

    public function __invoke(DashboardFilterRequest $request): InertiaResponse
    {
        $period = $this->dashboardMonitoring->normalizedPeriod($request);
        $role = $this->primeAuth->role($request);

        return Inertia::render('Dashboard', [
            'manageAi' => $role === 'admin' && Gate::allows('manage_ai'),
            'dateRange' => [
                'start' => $period['start']->toDateString(),
                'end' => $period['end']->toDateString(),
            ],
            'defaultDateRange' => [
                'start' => $this->dashboardMonitoring->defaultPeriod()['start']->toDateString(),
                'end' => $this->dashboardMonitoring->defaultPeriod()['end']->toDateString(),
            ],
            ...$this->dashboardMonitoring->payload($period['start'], $period['end']),
            'notificationEndpoints' => $role === 'admin' ? [
                'index' => route('admin-notifications.index'),
                'readAll' => route('admin-notifications.read-all'),
                'readTemplate' => route('admin-notifications.read', ['notificationId' => '__ID__']),
                'fallback' => route('dashboard'),
            ] : null,
        ]);
    }
}
