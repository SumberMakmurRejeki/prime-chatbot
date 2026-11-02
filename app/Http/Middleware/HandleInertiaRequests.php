<?php

namespace App\Http\Middleware;

use App\Services\Auth\PrimeAuthService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        protected PrimeAuthService $primeAuth,
    ) {}

    /**
     * The isolated root template for Inertia responses; legacy Blade pages keep their own layout.
     *
     * @var string
     */
    protected $rootView = 'spa';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'name' => $this->primeAuth->name($request),
                'role' => $this->primeAuth->role($request),
            ],
        ];
    }
}
