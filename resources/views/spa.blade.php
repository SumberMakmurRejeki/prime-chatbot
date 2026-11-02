<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>PRIME SPA Proof</title>
    @vite(['resources/css/app.css', 'resources/js/spa.ts'])
    <x-inertia::head />
</head>
<body class="min-h-screen bg-prime-bg text-prime-ink">
    <x-inertia::app />
</body>
</html>
