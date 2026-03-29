<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Gestion de Usuarios</h1>
            <p class="text-sm text-gray-400 mt-0.5">Administra cuentas y roles del sistema</p>
        </div>
        <button wire:click="abrirCrear" class="simo-btn-primary text-sm px-4 py-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Nuevo usuario
        </button>
    </div>

    {{-- Buscador --}}
    <div class="mb-4">
        <input type="text" wire:model.live.debounce.300ms="buscar"
            placeholder="Buscar por nombre o email..."
            class="simo-input max-w-sm" />
    </div>

    {{-- Tabla --}}
    <div class="simo-card p-0 overflow-hidden">
        <table class="simo-table min-w-full">
            <thead>
                <tr>
                    <th>Usuario</th>
                    <th>Email</th>
                    <th>Rol</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($usuarios as $usuario)
                    <tr class="{{ !$usuario->activo ? 'opacity-60' : '' }}">
                        <td>
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-slate-700 flex items-center justify-center text-white text-xs font-bold shrink-0">
                                    {{ strtoupper(substr($usuario->name, 0, 1)) }}
                                </div>
                                <div>
                                    <span class="font-medium text-gray-800">{{ $usuario->name }}</span>
                                    @if($usuario->id === auth()->id())
                                        <span class="ml-1.5 simo-badge bg-indigo-50 text-indigo-600">Tu</span>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="text-gray-500">{{ $usuario->email }}</td>
                        <td>
                            @php $rol = $usuario->roles->first()?->name ?? 'sin rol'; @endphp
                            <span class="simo-badge
                                {{ $rol === 'admin'      ? 'bg-red-50 text-red-700' :
                                   ($rol === 'supervisor' ? 'bg-purple-50 text-purple-700' : 'bg-blue-50 text-blue-700') }}">
                                {{ ucfirst($rol) }}
                            </span>
                        </td>
                        <td>
                            <button wire:click="toggleActivo({{ $usuario->id }})"
                                @if($usuario->id === auth()->id()) disabled @endif
                                class="simo-badge transition cursor-pointer
                                    {{ $usuario->activo
                                        ? 'bg-green-50 text-green-700 hover:bg-green-100'
                                        : 'bg-zinc-100 text-zinc-500 border-zinc-200 hover:bg-zinc-200' }}
                                    disabled:opacity-50 disabled:cursor-not-allowed">
                                <span class="w-1.5 h-1.5 rounded-full {{ $usuario->activo ? 'bg-green-500' : 'bg-zinc-400' }}"></span>
                                {{ $usuario->activo ? 'Activo' : 'Inactivo' }}
                            </button>
                        </td>
                        <td>
                            <div class="flex items-center gap-1">
                                <button wire:click="abrirEditar({{ $usuario->id }})"
                                    class="simo-btn-ghost text-xs">
                                    Editar
                                </button>
                                @if($usuario->id !== auth()->id())
                                    <button wire:click="eliminar({{ $usuario->id }})"
                                        wire:confirm="Eliminar a {{ $usuario->name }}? Esta accion no se puede deshacer."
                                        class="simo-btn-danger text-xs">
                                        Eliminar
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-12 text-center text-gray-400">
                            <svg class="w-8 h-8 mx-auto mb-2 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            No hay usuarios.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-5 py-3 border-t border-gray-100">{{ $usuarios->links() }}</div>
    </div>

    {{-- Modal crear / editar --}}
    @if($mostrarModal)
    <div class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md">
            {{-- Modal header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-800">{{ $esEdicion ? 'Editar usuario' : 'Nuevo usuario' }}</h2>
                <button wire:click="cerrarModal"
                    class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition text-lg leading-none">
                    &times;
                </button>
            </div>

            {{-- Modal body --}}
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Nombre</label>
                    <input type="text" wire:model="nombre"
                        class="simo-input @error('nombre') border-red-400 @enderror">
                    @error('nombre') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Email</label>
                    <input type="email" wire:model="email"
                        class="simo-input @error('email') border-red-400 @enderror">
                    @error('email') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">
                        Contrasena
                        @if($esEdicion)
                            <span class="text-gray-400 font-normal">(dejar vacio para no cambiar)</span>
                        @endif
                    </label>
                    <input type="password" wire:model="password"
                        class="simo-input @error('password') border-red-400 @enderror">
                    @error('password') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Rol</label>
                    <select wire:model="rol"
                        class="simo-select w-full @error('rol') border-red-400 @enderror">
                        @foreach($roles as $r)
                            <option value="{{ $r }}">{{ ucfirst($r) }}</option>
                        @endforeach
                    </select>
                    @error('rol') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
                    <input type="checkbox" wire:model="activo" id="activo"
                        class="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    <span class="text-gray-700">Cuenta activa</span>
                </label>
            </div>

            {{-- Modal footer --}}
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50/50 rounded-b-2xl flex justify-end gap-2">
                <button wire:click="cerrarModal" class="simo-btn-ghost text-sm px-4 py-2 border border-gray-200">
                    Cancelar
                </button>
                <button wire:click="guardar" class="simo-btn-primary text-sm px-4 py-2">
                    {{ $esEdicion ? 'Guardar cambios' : 'Crear usuario' }}
                </button>
            </div>
        </div>
    </div>
    @endif
</div>
