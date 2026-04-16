# Code Review Rules — SIMO

## Stack
- Laravel 12, PHP 8.2+, Livewire 4, Tailwind 3, Alpine.js
- PostgreSQL 17
- Gemini AI (2.5-flash) para analisis PEP/OPI

## PHP
- `declare(strict_types=1)` en todo archivo PHP
- Type hints en parametros y retornos obligatorios
- No usar `mixed` salvo casos justificados
- Usar null-safe operator `?->` en cadenas de acceso
- No usar `@` para suprimir errores

## Laravel
- Usar Eloquent, no queries raw salvo optimizacion justificada
- Relaciones definidas en el modelo, no queries ad-hoc
- Validacion en FormRequest o directamente con `$this->validate()`
- Usar config() para acceder a configuracion, nunca env() fuera de config/
- Jobs deben implementar ShouldQueue y usar backoff exponencial
- Observers solo para side-effects (despachar jobs, logs), no logica de negocio

## Livewire
- Usar `#[Computed]` para propiedades derivadas
- Usar `#[Url]` para filtros que persisten en la URL
- Siempre incluir `wire:key` en loops de tabla
- Resetear paginacion al cambiar filtros (`$this->resetPage()`)
- No poner logica pesada en componentes — delegar a Services

## Services y DTOs
- Logica de negocio en Services (`app/Services/`)
- Transferencia de datos via DTOs con metodo estatico `fromArray()`
- Un servicio por dominio (GeminiFiltroService, GeminiAnalisisService)
- Excepciones custom por dominio (`app/Exceptions/Gemini/`)

## Blade
- Componentes reutilizables en `resources/views/components/`
- Clases CSS con prefijo `simo-` para utilidades custom (simo-btn, simo-badge, simo-select)
- No mezclar logica PHP compleja en Blade — usar `#[Computed]` en Livewire

## Base de datos
- Snake_case para columnas y tablas
- Migraciones ordenadas cronologicamente
- Indices en columnas de filtro frecuente (gemini_analyzed, fecha_encontrado)
- Soft deletes solo si el negocio lo requiere

## Seguridad
- NUNCA exponer API keys en codigo o vistas
- NUNCA commitear archivos .env
- Validar todo input del usuario
- Usar middleware `UsuarioActivo` para verificar estado del usuario
- Spatie Permissions para control de acceso

## Testing
- PHPUnit con Laravel TestCase
- Feature tests usan `RefreshDatabase`
- Livewire tests con `Livewire::test()`
- Mockear servicios externos (Gemini API) en tests
- Nombres descriptivos: `test_filtro_gemini_muestra_solo_peps_confirmados`

## Commits
- Conventional commits: feat:, fix:, refactor:, chore:, test:
- Sin "Co-Authored-By" ni atribucion AI
- Mensaje en espanol o ingles, consistente por PR

## No permitido
- var_dump(), dd() o dump() en codigo commiteado
- Comentarios tipo TODO sin issue asociado
- Queries N+1 (usar eager loading)
- Logica de negocio en Controllers — usar Services
- Hardcodear valores que deberian estar en config o .env
