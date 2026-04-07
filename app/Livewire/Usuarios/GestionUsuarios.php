<?php

namespace App\Livewire\Usuarios;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

#[Layout('layouts.app', ['title' => 'Gestion de Usuarios'])]
class GestionUsuarios extends Component
{
    use WithPagination;

    public string $buscar = '';

    // Modal
    public bool $mostrarModal = false;

    public bool $esEdicion = false;

    #[Locked]
    public ?int $userId = null;

    // Campos del formulario
    public string $nombre = '';

    public string $email = '';

    public string $password = ''; // Cleared after save; $userId is #[Locked] to prevent tampering

    public string $rol = 'operador';

    public bool $activo = true;

    public function mount(): void
    {
        $this->authorize('gestionar usuarios');
    }

    public function render()
    {
        $usuarios = User::with('roles')
            ->when($this->buscar, fn ($q) => $q->where('name', 'like', "%{$this->buscar}%")
                ->orWhere('email', 'like', "%{$this->buscar}%"))
            ->orderBy('name')
            ->paginate(15);

        $roles = Role::orderBy('name')->pluck('name');

        return view('livewire.usuarios.gestion-usuarios', [
            'usuarios' => $usuarios,
            'roles' => $roles,
        ]);
    }

    public function abrirCrear(): void
    {
        $this->reset(['userId', 'nombre', 'email', 'password', 'rol', 'activo']);
        $this->rol = 'operador';
        $this->activo = true;
        $this->esEdicion = false;
        $this->mostrarModal = true;
    }

    public function abrirEditar(int $id): void
    {
        $user = User::findOrFail($id);
        $this->userId = $user->id;
        $this->nombre = $user->name;
        $this->email = $user->email;
        $this->password = '';
        $this->rol = $user->roles->first()?->name ?? 'operador';
        $this->activo = (bool) $user->activo;
        $this->esEdicion = true;
        $this->mostrarModal = true;
    }

    public function guardar(): void
    {
        $this->authorize('gestionar usuarios');

        $rules = [
            'nombre' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email'.($this->userId ? ",{$this->userId}" : ''),
            'rol' => 'required|exists:roles,name',
        ];

        if (! $this->esEdicion) {
            $rules['password'] = 'required|min:8';
        } elseif ($this->password) {
            $rules['password'] = 'min:8';
        }

        $this->validate($rules, [
            'nombre.required' => 'El nombre es obligatorio.',
            'email.required' => 'El email es obligatorio.',
            'email.unique' => 'Este email ya esta en uso.',
            'password.required' => 'La contrasena es obligatoria para nuevos usuarios.',
            'password.min' => 'La contrasena debe tener al menos 8 caracteres.',
            'rol.exists' => 'El rol seleccionado no es valido.',
        ]);

        if ($this->esEdicion) {
            $user = User::findOrFail($this->userId);
            $data = ['name' => $this->nombre, 'email' => $this->email, 'activo' => $this->activo];
            if ($this->password) {
                $data['password'] = Hash::make($this->password);
            }
            $user->update($data);
        } else {
            $user = User::create([
                'name' => $this->nombre,
                'email' => $this->email,
                'password' => Hash::make($this->password),
                'activo' => $this->activo,
            ]);
        }

        $user->syncRoles([$this->rol]);

        $this->mostrarModal = false;
        $this->reset(['userId', 'nombre', 'email', 'password', 'rol']);
        $this->dispatch('notify', mensaje: $this->esEdicion ? 'Usuario actualizado.' : 'Usuario creado.');
    }

    public function toggleActivo(int $id): void
    {
        $this->authorize('gestionar usuarios');
        $user = User::findOrFail($id);

        // No permitir desactivar al propio admin logueado
        if ($user->id === auth()->id()) {
            return;
        }

        $user->update(['activo' => ! $user->activo]);
    }

    public function eliminar(int $id): void
    {
        $this->authorize('gestionar usuarios');
        $user = User::findOrFail($id);

        // No permitir eliminarse a si mismo
        if ($user->id === auth()->id()) {
            return;
        }

        $user->delete();
    }

    public function cerrarModal(): void
    {
        $this->mostrarModal = false;
        $this->reset(['userId', 'nombre', 'email', 'password', 'rol']);
    }
}
