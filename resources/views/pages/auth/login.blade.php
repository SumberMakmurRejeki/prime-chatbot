<x-layouts.auth title="Login PRIME">
    @php
        $activeTab = $errors->has('guest_name') || old('guest_name') ? 'guest' : 'staff';
    @endphp

    <section class="prime-login-hero relative hidden min-h-[100dvh] w-1/2 overflow-hidden text-white lg:flex">
        <img
            src="{{ asset('images/auth/prime-login-industrial.webp') }}"
            alt=""
            class="absolute inset-0 h-full w-full object-cover"
        >
        <div class="absolute inset-0 bg-gradient-to-b from-[#061a33]/40 via-[#061a33]/30 to-[#061a33]/90"></div>
        <div class="relative z-10 flex w-full flex-col justify-between px-12 py-10 xl:px-16 xl:py-12">
            <img
                src="{{ asset('images/prime-logo.png') }}"
                alt="PRIME"
                class="h-auto w-48 brightness-0 invert"
            >
            <div class="max-w-xl pb-4">
                <p class="mb-5 text-xs font-semibold uppercase tracking-[0.22em] text-white/75">Preventive Maintenance System</p>
                <h1 class="max-w-lg text-4xl font-semibold leading-tight tracking-[-0.04em] text-white xl:text-5xl">
                    Perawatan terencana. Operasional terjaga.
                </h1>
                <p class="mt-5 max-w-md text-base leading-7 text-white/80">
                    Kelola pemeliharaan mesin dan aktivitas operasional dalam satu sistem.
                </p>
            </div>
        </div>
    </section>

    <section class="flex min-h-[100dvh] w-full items-center justify-center bg-[#f3f6f8] px-5 py-10 sm:px-10 lg:w-1/2 lg:px-12 xl:px-16">
        <div class="prime-login-reveal w-full max-w-md" data-login-root data-login-tab="{{ $activeTab }}">
            <div class="mb-10 flex justify-center lg:hidden">
                <img src="{{ asset('images/prime-logo.png') }}" alt="PRIME" class="h-auto w-44">
            </div>

            <header class="mb-8">
                <p class="mb-3 text-xs font-semibold uppercase tracking-[0.18em] text-[var(--color-prime-primary-strong)]">Akses sistem</p>
                <h2 class="text-3xl font-semibold leading-tight tracking-[-0.035em] text-[var(--color-prime-ink)] sm:text-4xl">
                    Selamat Datang Kembali
                </h2>
                <p class="mt-3 text-base leading-7 text-[var(--color-prime-muted)]">Masuk untuk melanjutkan aktivitas pemeliharaan.</p>
            </header>

            @if ($errors->any())
                <x-ui.alert variant="warning" class="mb-6">
                    {{ $errors->first() }}
                </x-ui.alert>
            @endif

            <div class="mb-7 border-b border-[var(--color-prime-border)]">
                <div class="grid grid-cols-2 gap-5">
                    <button
                        type="button"
                        data-login-switch="staff"
                        data-active="{{ $activeTab === 'staff' ? 'true' : 'false' }}"
                        aria-pressed="{{ $activeTab === 'staff' ? 'true' : 'false' }}"
                        class="prime-login-tab border-b-2 border-b-transparent px-2 py-3 text-sm font-semibold text-[var(--color-prime-muted)] transition-colors"
                    >
                        Login dengan akun
                    </button>
                    <button
                        type="button"
                        data-login-switch="guest"
                        data-active="{{ $activeTab === 'guest' ? 'true' : 'false' }}"
                        aria-pressed="{{ $activeTab === 'guest' ? 'true' : 'false' }}"
                        class="prime-login-tab border-b-2 border-b-transparent px-2 py-3 text-sm font-semibold text-[var(--color-prime-muted)] transition-colors"
                    >
                        Login sebagai Tamu
                    </button>
                </div>
            </div>

            <div>
                <form action="{{ route('login.store') }}" method="POST" data-login-panel="staff" class="space-y-5">
                    @csrf
                    <div>
                        <label for="username" class="mb-2 block text-sm font-semibold text-[var(--color-prime-ink)]">Username</label>
                        <input
                            id="username"
                            name="username"
                            type="text"
                            value="{{ old('username') }}"
                            class="prime-login-input @error('username') border-[var(--color-prime-warning)] ring-2 ring-[var(--color-prime-warning)]/10 @enderror"
                            autocomplete="username"
                        >
                    </div>

                    <div>
                        <label for="password" class="mb-2 block text-sm font-semibold text-[var(--color-prime-ink)]">Kata Sandi</label>
                        <div class="relative">
                            <input
                                id="password"
                                name="password"
                                type="password"
                                class="prime-login-input pr-14 @error('password') border-[var(--color-prime-warning)] ring-2 ring-[var(--color-prime-warning)]/10 @enderror"
                                autocomplete="current-password"
                            >
                            <button
                                type="button"
                                data-password-toggle="password"
                                class="absolute inset-y-0 right-0 flex w-12 items-center justify-center text-[var(--color-prime-muted)] transition-colors hover:text-[var(--color-prime-ink)]"
                                aria-label="Tampilkan atau sembunyikan kata sandi"
                            >
                                <span data-password-icon="default">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z" />
                                        <circle cx="12" cy="12" r="3.2" />
                                    </svg>
                                </span>
                                <span data-password-icon="active" class="hidden">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="m4 4 16 16" />
                                        <path d="M10.7 6.2A10.5 10.5 0 0 1 12 6c6.1 0 9.5 6 9.5 6a17 17 0 0 1-3.1 3.8" />
                                        <path d="M14.5 14.7A3.2 3.2 0 0 1 9.3 9.5" />
                                        <path d="M6.1 6.8A16.5 16.5 0 0 0 2.5 12s3.4 6 9.5 6a10.6 10.6 0 0 0 3-.4" />
                                    </svg>
                                </span>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="prime-login-submit prime-login-submit--staff" data-submit-loading>
                        <span>Login</span>
                        <span class="hidden" data-submit-spinner>
                            <svg class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="4"></circle>
                                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path>
                            </svg>
                        </span>
                    </button>
                </form>

                <form action="{{ route('guest-login.store') }}" method="POST" data-login-panel="guest" class="hidden space-y-5">
                    @csrf
                    <div>
                        <label for="guest_name" class="mb-2 block text-sm font-semibold text-[var(--color-prime-ink)]">Nama Lengkap</label>
                        <input
                            id="guest_name"
                            name="guest_name"
                            type="text"
                            value="{{ old('guest_name') }}"
                            class="prime-login-input @error('guest_name') border-[var(--color-prime-warning)] ring-2 ring-[var(--color-prime-warning)]/10 @enderror"
                            autocomplete="name"
                        >
                    </div>

                    <button type="submit" class="prime-login-submit prime-login-submit--guest" data-submit-loading>
                        <span>Masuk Sebagai Tamu</span>
                        <span class="hidden" data-submit-spinner>
                            <svg class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="4"></circle>
                                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path>
                            </svg>
                        </span>
                    </button>
                </form>
            </div>
            <p class="mt-8 text-center text-xs leading-5 text-[var(--color-prime-muted)]">Sistem Pemeliharaan Preventif PRIME</p>
        </div>
    </section>
</x-layouts.auth>
