<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SpaProofController extends Controller
{
    /**
     * Halaman bukti SPA-03: respons Inertia baca-saja tanpa data domain.
     *
     * Halaman ini hanya berguna untuk verifikasi fondasi Inertia + Vue, sehingga
     * ditutup di luar lingkungan lokal/QA. Gerbang diperiksa di handler (bukan saat
     * registrasi rute) agar `route:cache` tetap aman dan daftar rute tidak berubah
     * antar lingkungan.
     */
    public function __invoke(Request $request): InertiaResponse
    {
        abort_unless(
            in_array(config('app.env'), ['local', 'testing', 'qa'], true),
            404,
        );

        $validated = $request->validate([
            'visit' => ['nullable', 'in:server,client'],
        ]);

        $state = ($validated['visit'] ?? 'server') === 'client' ? 'client' : 'server';

        return Inertia::render('SpaProof', [
            'proofStage' => 'Inertia 3 + Vue 3 + TypeScript',
            'state' => $state,
            'clientVisitUrl' => route('internal.spa-proof', ['visit' => 'client']),
            'serverVisitUrl' => route('internal.spa-proof'),
        ]);
    }
}
