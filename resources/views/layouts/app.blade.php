<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>SIMO — {{ $title ?? 'Monitor' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen">

<div class="flex min-h-screen">

    {{-- ── SIDEBAR ── --}}
    <aside class="w-60 shrink-0 bg-simo-dark flex flex-col fixed inset-y-0 left-0 z-30">

        {{-- Marca --}}
        <div class="h-16 flex items-center px-6">
            <div class="flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-lg bg-indigo-500 flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                </div>
                <span class="text-white font-semibold tracking-tight text-base">SIMO</span>
            </div>
        </div>

        {{-- Nav --}}
        <nav class="flex-1 px-3 py-2 overflow-y-auto space-y-0.5">

            {{-- Dashboard --}}
            <a href="{{ route('dashboard') }}"
               class="simo-sidebar-link {{ request()->routeIs('dashboard') ? 'simo-sidebar-link-active' : '' }}">
                <svg class="simo-sidebar-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Dashboard
            </a>

            {{-- ── MONITOREO ── --}}
            <div class="simo-sidebar-section">Monitoreo</div>

            <a href="{{ route('scraper.resultados') }}"
               class="simo-sidebar-link {{ request()->routeIs('scraper.resultados') ? 'simo-sidebar-link-active' : '' }}">
                <svg class="simo-sidebar-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Resultados scraper
            </a>

            <a href="{{ route('pep.cambios') }}"
               class="simo-sidebar-link {{ request()->routeIs('pep.cambios') ? 'simo-sidebar-link-active' : '' }}">
                <svg class="simo-sidebar-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                </svg>
                Cambios PEP
            </a>

            @can('gestionar resultados')
            <a href="{{ route('gaceta.eventos') }}"
               class="simo-sidebar-link {{ request()->routeIs('gaceta.eventos') ? 'simo-sidebar-link-active' : '' }}">
                <svg class="simo-sidebar-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Gaceta PEP
            </a>
            @endcan

            {{-- ── GESTIÓN ── --}}
            @canany(['gestionar sitios', 'gestionar fuentes', 'gestionar familias lemas', 'gestionar cargos pep', 'gestionar entidades publicas'])
            <div class="simo-sidebar-section">Gestión</div>

            @can('gestionar sitios')
            <a href="{{ route('scraper.sitios') }}"
               class="simo-sidebar-link {{ request()->routeIs('scraper.sitios') ? 'simo-sidebar-link-active' : '' }}">
                <svg class="simo-sidebar-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/>
                </svg>
                Sitios web
            </a>
            @endcan


            @can('gestionar fuentes')
            <a href="{{ route('pep.fuentes') }}"
               class="simo-sidebar-link {{ request()->routeIs('pep.fuentes') ? 'simo-sidebar-link-active' : '' }}">
                <svg class="simo-sidebar-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                </svg>
                Fuentes PEP
            </a>
            @endcan

            @can('gestionar familias lemas')
            <a href="{{ route('scraper.familias-lemas') }}"
               class="simo-sidebar-link {{ request()->routeIs('scraper.familias-lemas') ? 'simo-sidebar-link-active' : '' }}">
                <svg class="simo-sidebar-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                </svg>
                Familias de lemas
            </a>
            @endcan

            @can('gestionar cargos pep')
            <a href="{{ route('scraper.cargos-pep') }}"
               class="simo-sidebar-link {{ request()->routeIs('scraper.cargos-pep') ? 'simo-sidebar-link-active' : '' }}">
                <svg class="simo-sidebar-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                Cargos PEP
            </a>
            @endcan

            @can('gestionar entidades publicas')
            <a href="{{ route('scraper.entidades-publicas') }}"
               class="simo-sidebar-link {{ request()->routeIs('scraper.entidades-publicas') ? 'simo-sidebar-link-active' : '' }}">
                <svg class="simo-sidebar-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                </svg>
                Entidades Públicas
            </a>
            @endcan

            @can('gestionar sitios')
            <a href="{{ route('configuracion.paises') }}"
               class="simo-sidebar-link {{ request()->routeIs('configuracion.paises') ? 'simo-sidebar-link-active' : '' }}">
                <svg class="simo-sidebar-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/>
                </svg>
                Países
            </a>
            @endcan
            @endcanany

            {{-- ── SISTEMA ── --}}
            <div class="simo-sidebar-section">Sistema</div>

            <a href="{{ route('scripts.estado') }}"
               class="simo-sidebar-link {{ request()->routeIs('scripts.estado') ? 'simo-sidebar-link-active' : '' }}">
                <svg class="simo-sidebar-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
                Estado scripts
            </a>

            @can('configurar scripts')
            <a href="{{ route('scripts.configuracion') }}"
               class="simo-sidebar-link {{ request()->routeIs('scripts.configuracion') ? 'simo-sidebar-link-active' : '' }}">
                <svg class="simo-sidebar-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                </svg>
                Configuracion
            </a>
            @endcan

            @can('gestionar usuarios')
            <a href="{{ route('usuarios.index') }}"
               class="simo-sidebar-link {{ request()->routeIs('usuarios.*') ? 'simo-sidebar-link-active' : '' }}">
                <svg class="simo-sidebar-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
                Usuarios
            </a>
            @endcan

        </nav>

        {{-- Usuario + logout --}}
        <div class="px-4 py-4 border-t border-white/10 flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center shrink-0">
                <span class="text-xs font-semibold text-white/80">
                    {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                </span>
            </div>
            <div class="flex-1 min-w-0">
                <div class="text-xs font-medium text-white/90 truncate">{{ auth()->user()->name }}</div>
                <div class="text-[10px] text-white/40 truncate">{{ ucfirst(auth()->user()->roles->first()?->name ?? '') }}</div>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" title="Cerrar sesion"
                    class="p-1.5 rounded-lg text-white/30 hover:text-white/80 hover:bg-white/10 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                </button>
            </form>
        </div>

    </aside>

    {{-- ── CONTENIDO PRINCIPAL ── --}}
    <div class="flex-1 ml-60 flex flex-col min-h-screen">

        {{-- Topbar: bg-white + border-b para separarlo del fondo zinc-100 --}}
        <header class="h-14 flex items-center justify-between px-8 sticky top-0 z-20 bg-white border-b border-zinc-200">
            <h1 class="text-sm font-semibold text-zinc-800 tracking-tight">{{ $title ?? 'Dashboard' }}</h1>
            <span class="text-xs text-zinc-400 tabular-nums">{{ now()->format('d/m/Y  H:i') }}</span>
        </header>

        {{-- Page content --}}
        <main class="flex-1 px-8 py-7">
            {{ $slot }}
        </main>

    </div>
</div>

@livewireScripts

@stack('scripts')

{{-- ── TOAST NOTIFICATIONS ── --}}
<div
    x-data="{
        toasts: [],
        add(mensaje, tipo = 'success') {
            const id = Date.now();
            this.toasts.push({ id, mensaje, tipo });
            setTimeout(() => this.remove(id), 3500);
        },
        remove(id) {
            this.toasts = this.toasts.filter(t => t.id !== id);
        }
    }"
    @notify.window="add($event.detail.mensaje ?? $event.detail[0]?.mensaje ?? '', $event.detail.tipo ?? 'success')"
    class="fixed bottom-5 right-5 z-[9999] flex flex-col gap-2 items-end pointer-events-none"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            :class="toast.tipo === 'error'
                ? 'bg-red-600 text-white'
                : 'bg-gray-900 text-white'"
            class="flex items-center gap-2.5 px-4 py-2.5 rounded-xl shadow-lg text-sm font-medium pointer-events-auto max-w-xs"
        >
            <template x-if="toast.tipo !== 'error'">
                <svg class="w-4 h-4 shrink-0 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </template>
            <template x-if="toast.tipo === 'error'">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </template>
            <span x-text="toast.mensaje"></span>
        </div>
    </template>
</div>

</body>
</html>
