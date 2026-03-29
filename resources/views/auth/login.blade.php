<x-guest-layout>

    {{-- Encabezado del formulario --}}
    <div class="mb-10">
        {{-- Logo visible solo en móvil (panel izquierdo oculto) --}}
        <div class="flex items-center gap-2.5 mb-8 lg:hidden">
            <div class="w-8 h-8 rounded-xl bg-[#0D0D0D] flex items-center justify-center">
                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
            </div>
            <span class="font-semibold text-gray-900">SIMO</span>
        </div>

        <h2 class="text-2xl font-light text-gray-900 tracking-tight">Bienvenido</h2>
        <p class="text-sm text-gray-400 mt-1.5">Ingresa tus credenciales para continuar.</p>
    </div>

    {{-- Session Status --}}
    @if(session('status'))
        <div class="mb-6 px-4 py-3 bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        {{-- Email --}}
        <div class="space-y-1.5">
            <label for="email" class="block text-xs font-medium text-gray-500 uppercase tracking-wider">
                Email
            </label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                required autofocus autocomplete="username"
                class="w-full bg-white border border-black/10 rounded-xl px-4 py-3 text-sm text-gray-900
                       placeholder-gray-300 focus:outline-none focus:ring-2 focus:ring-black/10
                       focus:border-black/20 transition
                       @error('email') border-red-300 @enderror"
                placeholder="nombre@empresa.com" />
            @error('email')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Contraseña --}}
        <div class="space-y-1.5">
            <label for="password" class="block text-xs font-medium text-gray-500 uppercase tracking-wider">
                Contraseña
            </label>
            <input id="password" type="password" name="password"
                required autocomplete="current-password"
                class="w-full bg-white border border-black/10 rounded-xl px-4 py-3 text-sm text-gray-900
                       placeholder-gray-300 focus:outline-none focus:ring-2 focus:ring-black/10
                       focus:border-black/20 transition
                       @error('password') border-red-300 @enderror"
                placeholder="••••••••" />
            @error('password')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Remember --}}
        <div>
            <label for="remember_me" class="flex items-center gap-2.5 cursor-pointer select-none group">
                <div class="relative">
                    <input id="remember_me" type="checkbox" name="remember" class="sr-only peer">
                    <div class="w-4 h-4 rounded border border-black/20 bg-white peer-checked:bg-[#0D0D0D] peer-checked:border-[#0D0D0D] transition"></div>
                    <svg class="absolute inset-0 w-4 h-4 text-white opacity-0 peer-checked:opacity-100 transition pointer-events-none p-0.5"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <span class="text-sm text-gray-500 group-hover:text-gray-700 transition">Recordarme</span>
            </label>
        </div>

        {{-- Submit --}}
        <div class="pt-2">
            <button type="submit"
                class="w-full bg-[#0D0D0D] hover:bg-black text-white text-sm font-medium
                       py-3.5 rounded-xl transition-all duration-200 tracking-wide
                       hover:shadow-lg hover:shadow-black/20 active:scale-[0.99]">
                Ingresar
            </button>
        </div>
    </form>

    {{-- Footer --}}
    <p class="mt-10 text-center text-xs text-gray-300">
        Sistema de uso interno exclusivo
    </p>

</x-guest-layout>
