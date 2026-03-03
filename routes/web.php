<?php

use Illuminate\Support\Facades\Route;
use App\Livewire\Dashboard;
use App\Livewire\Scraper\Resultados;
use App\Livewire\Scraper\Sitios;
use App\Livewire\Scraper\Keywords;
use App\Livewire\Pep\Cambios;
use App\Livewire\Pep\Fuentes;
use App\Livewire\Scripts\Estado;

Route::get('/', fn() => redirect()->route('dashboard'));

Route::get('/dashboard', Dashboard::class)->name('dashboard');

// Scraper
Route::get('/scraper/resultados', Resultados::class)->name('scraper.resultados');
Route::get('/scraper/sitios', Sitios::class)->name('scraper.sitios');
Route::get('/scraper/keywords', Keywords::class)->name('scraper.keywords');

// PEP Monitor
Route::get('/pep/cambios', Cambios::class)->name('pep.cambios');
Route::get('/pep/fuentes', Fuentes::class)->name('pep.fuentes');

// Estado de scripts
Route::get('/scripts/estado', Estado::class)->name('scripts.estado');
