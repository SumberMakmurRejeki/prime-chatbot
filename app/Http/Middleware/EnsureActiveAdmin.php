<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI-2 Active Admin guard: percakapan Assistant hanya boleh diakses oleh
 * Admin PRIME yang masih aktif. Sesi Admin yang sudah terautentikasi tetap
 * ditolak bila akunnya dinonaktifkan, tanpa mengubah middleware auth/role
 * global PRIME.
 */
class EnsureActiveAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null || $user->role !== 'admin' || ! $user->is_active) {
            return redirect()->route('forbidden');
        }

        return $next($request);
    }
}
