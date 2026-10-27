<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="bg-transparent">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light">

    <title>@yield('title', 'Pay an Invoice') · {{ config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="bg-transparent font-sans text-slate-900 antialiased">
    {{-- No fixed heights: the iframe can size itself to the content. --}}
    <main id="iframe-root" class="mx-auto w-full max-w-lg p-4 sm:p-6">
        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>
