<?php

declare(strict_types=1);

use App\Http\Controllers\ProfileController;
use App\Livewire\Admin\PrecisionDashboard;
use App\Livewire\Configuracion\Paises;
use App\Livewire\Gaceta\Eventos as GacetaEventos;
use App\Livewire\Gaceta\Normas as GacetaNormas;
use App\Livewire\Gaceta\Personas as GacetaPersonas;
use App\Livewire\Dashboard;
use App\Livewire\Pep\Cambios;
use App\Livewire\Pep\Fuentes;
use App\Livewire\Scraper\CargosPep;
use App\Livewire\Scraper\EntidadesPublicas;
use App\Livewire\Scraper\FamiliasLemas;
use App\Livewire\Scraper\Resultados;
use App\Livewire\Scraper\Sitios;
use App\Livewire\Scripts\Configuracion;
use App\Livewire\Scripts\Estado;
use App\Livewire\Usuarios\GestionUsuarios;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

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
    Route::get('/scraper/familias-lemas', FamiliasLemas::class)
        ->middleware('permission:gestionar familias lemas')
        ->name('scraper.familias-lemas');
    Route::get('/scraper/cargos-pep', CargosPep::class)
        ->middleware('permission:gestionar cargos pep')
        ->name('scraper.cargos-pep');
    Route::get('/scraper/entidades-publicas', EntidadesPublicas::class)
        ->middleware('permission:gestionar entidades publicas')
        ->name('scraper.entidades-publicas');

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

    // Precision Dashboard — admin y supervisor (permission gate also in mount())
    Route::get('/admin/precision', PrecisionDashboard::class)
        ->middleware('permission:gestionar resultados')
        ->name('admin.precision');

    // Gaceta — revision de eventos PEP (admin y supervisor)
    Route::get('/gaceta/eventos', GacetaEventos::class)
        ->middleware('permission:gestionar resultados')
        ->name('gaceta.eventos');

    // Gaceta — cola de normas flaggeadas para revision manual (admin y supervisor)
    Route::get('/gaceta/normas', GacetaNormas::class)
        ->middleware('permission:gestionar resultados')
        ->name('gaceta.normas');

    // Gaceta — perfil de persona PEP (vista agrupada por persona) (admin y supervisor)
    Route::get('/gaceta/personas', GacetaPersonas::class)
        ->middleware('permission:gestionar resultados')
        ->name('gaceta.personas');

    // Perfil (Breeze)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});
