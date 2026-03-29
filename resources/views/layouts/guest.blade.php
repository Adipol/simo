<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'SIMO') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* Grid de puntos sutil en el panel oscuro */
        .dot-grid {
            background-image: radial-gradient(circle, rgba(255,255,255,0.07) 1px, transparent 1px);
            background-size: 28px 28px;
        }
        /* Línea animada decorativa */
        @keyframes slide-right {
            0%   { transform: translateX(-100%); opacity: 0; }
            20%  { opacity: 1; }
            100% { transform: translateX(400%); opacity: 0; }
        }
        .line-anim { animation: slide-right 4s ease-in-out infinite; }
        .line-anim-2 { animation: slide-right 4s ease-in-out 1.5s infinite; }
        .line-anim-3 { animation: slide-right 4s ease-in-out 3s infinite; }
    </style>
</head>
<body class="min-h-screen flex">

    {{-- ── PANEL IZQUIERDO: Branding oscuro ── --}}
    <div class="hidden lg:flex lg:w-1/2 bg-[#0D0D0D] dot-grid flex-col justify-between p-14 relative overflow-hidden">

        {{-- Líneas decorativas animadas --}}
        <div class="absolute inset-0 overflow-hidden pointer-events-none">
            <div class="absolute top-[22%] left-0 w-1/3 h-px bg-gradient-to-r from-transparent via-white/20 to-transparent line-anim"></div>
            <div class="absolute top-[55%] left-0 w-1/4 h-px bg-gradient-to-r from-transparent via-white/15 to-transparent line-anim-2"></div>
            <div class="absolute top-[75%] left-0 w-2/5 h-px bg-gradient-to-r from-transparent via-white/10 to-transparent line-anim-3"></div>
        </div>

        {{-- Orbe de luz sutil arriba a la derecha --}}
        <div class="absolute -top-32 -right-32 w-96 h-96 rounded-full bg-indigo-600/10 blur-3xl pointer-events-none"></div>
        <div class="absolute bottom-0 left-0 w-64 h-64 rounded-full bg-indigo-800/10 blur-3xl pointer-events-none"></div>

        {{-- Marca --}}
        <div class="relative z-10">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-white/10 border border-white/10 flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                </div>
                <span class="text-white font-semibold tracking-tight">SIMO</span>
            </div>
        </div>

        {{-- Copy central --}}
        <div class="relative z-10 space-y-6">
            <div class="space-y-3">
                <p class="text-white/30 text-xs font-medium uppercase tracking-[0.2em]">Sistema de Monitoreo</p>
                <h1 class="text-white text-4xl font-light leading-tight tracking-tight">
                    Inteligencia<br>
                    <span class="text-white/40">en tiempo real.</span>
                </h1>
            </div>
            <p class="text-white/30 text-sm leading-relaxed max-w-xs">
                Monitoreo unificado de sitios web y fuentes PEP desde un solo lugar.
            </p>

            {{-- Stats decorativos --}}
            <div class="flex gap-8 pt-4">
                <div>
                    <div class="text-2xl font-light text-white">24/7</div>
                    <div class="text-[11px] text-white/30 mt-0.5 uppercase tracking-wider">Monitoreo</div>
                </div>
                <div class="w-px bg-white/10"></div>
                <div>
                    <div class="text-2xl font-light text-white">2</div>
                    <div class="text-[11px] text-white/30 mt-0.5 uppercase tracking-wider">Scripts activos</div>
                </div>
                <div class="w-px bg-white/10"></div>
                <div>
                    <div class="text-2xl font-light text-white">PEP</div>
                    <div class="text-[11px] text-white/30 mt-0.5 uppercase tracking-wider">+ Scraper</div>
                </div>
            </div>
        </div>

        {{-- Footer del panel --}}
        <div class="relative z-10">
            <p class="text-white/20 text-xs">© {{ date('Y') }} SIMO. Uso interno.</p>
        </div>
    </div>

    {{-- ── PANEL DERECHO: Formulario ── --}}
    <div class="flex-1 flex items-center justify-center bg-zinc-50 px-8 py-16">
        <div class="w-full max-w-sm">
            {{ $slot }}
        </div>
    </div>

</body>
</html>
