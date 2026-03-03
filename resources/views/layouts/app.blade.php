<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIMO - {{ $title ?? 'Monitor' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-gray-100 min-h-screen font-sans">

    {{-- Barra de navegacion superior --}}
    <nav class="bg-slate-800 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-14">
                <div class="flex items-center gap-6">
                    <a href="{{ route('dashboard') }}" class="font-bold text-lg tracking-wide text-white hover:text-slate-300">
                        SIMO
                    </a>
                    <div class="hidden md:flex items-center gap-1">
                        <a href="{{ route('dashboard') }}"
                           class="px-3 py-2 rounded text-sm {{ request()->routeIs('dashboard') ? 'bg-slate-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                            Dashboard
                        </a>
                        <a href="{{ route('scraper.resultados') }}"
                           class="px-3 py-2 rounded text-sm {{ request()->routeIs('scraper.*') ? 'bg-slate-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                            Scraper
                        </a>
                        <a href="{{ route('pep.cambios') }}"
                           class="px-3 py-2 rounded text-sm {{ request()->routeIs('pep.*') ? 'bg-slate-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                            PEP Monitor
                        </a>
                        <a href="{{ route('scripts.estado') }}"
                           class="px-3 py-2 rounded text-sm {{ request()->routeIs('scripts.*') ? 'bg-slate-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                            Scripts
                        </a>
                    </div>
                </div>
                <span class="text-xs text-slate-400">{{ now()->format('d/m/Y H:i') }}</span>
            </div>
        </div>
    </nav>

    {{-- Contenido principal --}}
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
