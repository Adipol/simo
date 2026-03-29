<?php

use Illuminate\Support\Facades\Route;
use App\Livewire\Dashboard;
use App\Livewire\Scraper\Resultados;
use App\Livewire\Scraper\Sitios;
use App\Livewire\Scraper\Keywords;
use App\Livewire\Pep\Cambios;
use App\Livewire\Pep\Fuentes;
use App\Livewire\Scripts\Estado;
use App\Livewire\Scripts\Configuracion;
use App\Livewire\Usuarios\GestionUsuarios;
use App\Livewire\Configuracion\Paises;
use App\Http\Controllers\ProfileController;

Route::get('/', fn() => redirect()->route('login'));

// Rutas de autenticacion (Breeze)
require __DIR__.'/auth.php';

// Rutas protegidas — requieren autenticacion y cuenta activa
Route::middleware(['auth', 'usuario.activo'])->group(function () {

    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    // Scraper — lectura (todos los roles)
    Route::get('/scraper/resultados', Resultados::class)->name('scraper.resultados');

    // Scraper — gestion (admin y supervisor)
    Route::get('/scraper/sitios', Sitios::class)
        ->middleware('permission:gestionar sitios')
        ->name('scraper.sitios');
    Route::get('/scraper/keywords', Keywords::class)
        ->middleware('permission:gestionar keywords')
        ->name('scraper.keywords');

    // PEP Monitor — lectura (todos los roles)
    Route::get('/pep/cambios', Cambios::class)->name('pep.cambios');

    // PEP Monitor — gestion (admin y supervisor)
    Route::get('/pep/fuentes', Fuentes::class)
        ->middleware('permission:gestionar fuentes')
        ->name('pep.fuentes');

    // Scripts — estado (todos los roles)
    Route::get('/scripts/estado', Estado::class)->name('scripts.estado');

    // Scripts — configuracion (admin y supervisor)
    Route::get('/scripts/configuracion', Configuracion::class)
        ->middleware('permission:configurar scripts')
        ->name('scripts.configuracion');

    // Usuarios — solo admin
    Route::get('/usuarios', GestionUsuarios::class)
        ->middleware('permission:gestionar usuarios')
        ->name('usuarios.index');

    // Paises — admin y supervisor (usa el mismo permiso que sitios)
    Route::get('/configuracion/paises', Paises::class)
        ->middleware('permission:gestionar sitios')
        ->name('configuracion.paises');

    // Perfil (Breeze)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});
