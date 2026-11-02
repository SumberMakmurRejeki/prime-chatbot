<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Requests\Dashboard\DashboardFilterRequest;
use Inertia\Response as InertiaResponse;

/**
 * Permukaan kandidat /internal/dashboard-vue; dipertahankan sementara untuk
 * verifikasi cutover dan tertutup di luar lokal/testing/qa.
 */
class DashboardVueCandidateController extends DashboardController
{
    public function __invoke(DashboardFilterRequest $request): InertiaResponse
    {
        abort_unless(in_array(config('app.env'), ['local', 'testing', 'qa'], true), 404);

        return parent::__invoke($request);
    }
}
