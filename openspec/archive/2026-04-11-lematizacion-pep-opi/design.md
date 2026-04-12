# Design: lematizacion-pep-opi

**Status**: Draft  
**Change**: lematizacion-pep-opi  
**Topic Key**: `sdd/lematizacion-pep-opi/design`  
**Date**: 2026-04-11  

---

## Technical Approach

Introduce a database-backed PEP position catalog (two new tables: `cargos_pep`, `entidades_publicas`) and a `PepCatalogService` that loads positions per country with in-request caching. `GeminiPromptBuilder::filtroPEP()` is refactored to accept an optional injected catalog service; when positions exist for the country it builds a 3-section structured prompt, otherwise falls back to the existing hardcoded text. A Bolivia-specific seeder seeds 97 positions + known entities. `FiltroResultadoDTO` gains an `entidadTipo` field. `GeminiFiltroService` passes the country code through to the prompt builder.

---

## Architecture Decisions

### ADR-1: `entidad_tipo` — VARCHAR + CHECK vs PHP Enum

| Option | Tradeoff | Decision |
|--------|----------|----------|
| PG native `ENUM` | Best type safety in PG, breaks SQLite tests | ✗ Rejected |
| PHP `BackedEnum` + `VARCHAR(10)` + CHECK | Portability (SQLite), PHP 8.1+ enum casting, test-friendly | ✓ **Chosen** |
| Plain string + validation | No IDE support, no autocompletion | ✗ Rejected |

**Rationale**: Tests run on SQLite in-memory. PG ENUMs are incompatible with SQLite. `string('entidad_tipo', 10)->...check()` is compatible with both. A PHP `BackedEnum EntidadTipo` is used in model casts and service code for type safety without touching the DB dialect.

**Note**: Laravel's `Schema::...->check()` is skipped silently on SQLite — which is acceptable since the enum is enforced at the PHP layer.

### ADR-2: `categoria` — Column vs Separate Table

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Separate `categorias_pep` table | Over-engineered for a fixed taxonomy | ✗ Rejected |
| `VARCHAR(50) categoria` column on `cargos_pep` | Simple, queryable, groupable, matches spec SHOULD | ✓ **Chosen** |

**Rationale**: Categories are a small, stable set (Ejecutivo, Legislativo, Judicial, Militar, Diplomático, Autónomo). A column allows `GROUP BY categoria` without joins. The spec says SHOULD — a full normalization table is out of scope.

### ADR-3: Cache Strategy — In-request static array

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Laravel Cache (Redis/array) + TTL | Survives multiple requests, requires cache:clear in tests | ✗ Rejected |
| In-memory static array on `PepCatalogService` | Scoped per-request (process lifetime), zero config, cleared by `RefreshDatabase` automatically | ✓ **Chosen** |
| Eager load + pass array to caller | No encapsulation, pollutes service signatures | ✗ Rejected |

**Rationale**: `GeminiFiltroService::analizarLote()` processes N records in one PHP process. A static array on `PepCatalogService` (indexed by country code) executes exactly 2 queries per country (positions + entities) across the entire batch — satisfying REQ-6. Since tests use `RefreshDatabase` + process isolation per test, no cache-clearing boilerplate is needed.

### ADR-4: `PepCatalogService` vs Logic in `GeminiPromptBuilder`

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Logic directly in `GeminiPromptBuilder` | Tight coupling to DB, breaks existing pure-unit tests | ✗ Rejected |
| New `PepCatalogService` class | SRP, injectable, mockable, existing tests unchanged | ✓ **Chosen** |

**Rationale**: `GeminiPromptBuilder` currently has **zero DB dependencies** and is tested as a pure unit (extends `PHPUnit\Framework\TestCase`, no app container). Adding DB access would require converting all its tests to Feature tests. `PepCatalogService` is injected into `GeminiPromptBuilder`'s constructor as a nullable dependency — `null` means "no catalog" → generic prompt.

### ADR-5: Seeder Data — Inline Array vs JSON File

| Option | Tradeoff | Decision |
|--------|----------|----------|
| JSON file in `database/data/` | Requires file I/O, more complex to version | ✗ Rejected |
| PHP inline array in seeder | Standard Laravel pattern (matches `DatosInicialesSeeder`), auto-completed, versionable | ✓ **Chosen** |

**Rationale**: `DatosInicialesSeeder` already uses inline PHP arrays. Consistency wins. 97 records as a PHP array is manageable. Idempotency via `updateOrInsert` (same pattern as existing seeders).

### ADR-6: `GeminiFiltroService` — passing country code

`ResultadoScraping` already has a `pais` field (char(2) country code). `GeminiFiltroService::procesarRegistro()` already reads `$record->pais` to pass to `filtroPEP()`. No interface change needed there — the change is internal to `GeminiPromptBuilder`.

---

## Data Flow

```
GeminiFiltroService::analizarLote(Collection)
    └─► procesarRegistro(ResultadoScraping)
            └─► GeminiPromptBuilder::filtroPEP(texto, pais, categoria)
                    └─► PepCatalogService::getCargos(pais_codigo)    ← 1 query (cached)
                    └─► PepCatalogService::getEntidades(pais_codigo) ← 1 query (cached)
                    │   [positions found]
                    ├─► buildDynamicPrompt(cargos, entidades) → 3-section prompt
                    │   [no positions for country]
                    └─► buildGenericPrompt() → hardcoded fallback + Log::warning()
            └─► GeminiService::send(prompt)
            └─► FiltroResultadoDTO::fromArray(response)  [now with entidad_tipo]
            └─► persistirResultado()  [stores entidad_tipo]
```

```
Seeder flow:
DatabaseSeeder
    └─► CargosPepBoliviaSeeder
            └─► DB::table('cargos_pep')->updateOrInsert() × 97
    └─► EntidadesPublicasBoliviaSeeder
            └─► DB::table('entidades_publicas')->updateOrInsert() × N
```

---

## Database Schema

### Migration 1: `create_cargos_pep_table`

```php
Schema::create('cargos_pep', function (Blueprint $table) {
    $table->id();
    $table->char('pais_codigo', 2);
    $table->string('nombre', 150);
    $table->string('categoria', 50);           // Ejecutivo, Legislativo, Judicial, Militar, Diplomático, Autónomo
    $table->string('entidad_tipo', 10);        // 'todas' | 'publica' | 'ambas'
    $table->boolean('activo')->default(true);
    $table->timestamps();

    $table->foreign('pais_codigo')->references('codigo')->on('paises');
    $table->index(['pais_codigo', 'activo']);   // NFR: index on filter columns
});
```

### Migration 2: `create_entidades_publicas_table`

```php
Schema::create('entidades_publicas', function (Blueprint $table) {
    $table->id();
    $table->char('pais_codigo', 2);
    $table->string('nombre', 150);
    $table->string('sigla', 30)->nullable();
    $table->boolean('activo')->default(true);
    $table->timestamps();

    $table->foreign('pais_codigo')->references('codigo')->on('paises');
    $table->index(['pais_codigo', 'activo']);   // NFR: index on filter columns
});
```

### Migration 3: `add_gemini_entidad_tipo_to_resultados_scraping`

```php
Schema::table('resultados_scraping', function (Blueprint $table) {
    $table->string('gemini_entidad_tipo', 15)->nullable()->after('gemini_categoria')
        ->comment('publica | privada | desconocido');
});
```

**Migration order**: cargos_pep → entidades_publicas → add_gemini_entidad_tipo (alter)

---

## PHP Classes

### `EntidadTipo` (PHP Backed Enum)

```php
// app/Enums/EntidadTipo.php
enum EntidadTipo: string {
    case Todas  = 'todas';
    case Publica = 'publica';
    case Ambas  = 'ambas';
}
```

### `CargoPep` (Model)

```php
// app/Models/CargoPep.php
class CargoPep extends Model {
    protected $fillable = ['pais_codigo', 'nombre', 'categoria', 'entidad_tipo', 'activo'];
    protected $casts = ['entidad_tipo' => EntidadTipo::class, 'activo' => 'boolean'];

    public function pais(): BelongsTo { ... }

    public function scopeActive(Builder $q): Builder { return $q->where('activo', true); }
    public function scopeForCountry(Builder $q, string $code): Builder { return $q->where('pais_codigo', $code); }
    public function scopeByEntidadTipo(Builder $q, EntidadTipo $tipo): Builder { return $q->where('entidad_tipo', $tipo); }
}
```

### `EntidadPublica` (Model)

```php
// app/Models/EntidadPublica.php
class EntidadPublica extends Model {
    protected $fillable = ['pais_codigo', 'nombre', 'sigla', 'activo'];
    protected $casts = ['activo' => 'boolean'];

    public function pais(): BelongsTo { ... }
    public function scopeActive(Builder $q): Builder { ... }
    public function scopeForCountry(Builder $q, string $code): Builder { ... }
}
```

### `PepCatalogService`

```php
// app/Services/Gemini/PepCatalogService.php
class PepCatalogService {
    /** @var array<string, Collection> */
    private static array $cargosCache = [];
    private static array $entidadesCache = [];

    public function getCargos(string $paisCodigo): Collection {
        return self::$cargosCache[$paisCodigo] ??=
            CargoPep::active()->forCountry($paisCodigo)->orderBy('categoria')->get();
    }

    public function getEntidades(string $paisCodigo): Collection {
        return self::$entidadesCache[$paisCodigo] ??=
            EntidadPublica::active()->forCountry($paisCodigo)->get();
    }

    public static function flushCache(): void {
        self::$cargosCache = [];
        self::$entidadesCache = [];
    }
}
```

### `GeminiPromptBuilder` (modified)

Constructor accepts `?PepCatalogService $catalog = null`. `filtroPEP()` calls catalog when `$catalog !== null && $pais !== ''`; if positions found → dynamic prompt; else → generic prompt + `Log::warning()`.

### `FiltroResultadoDTO` (modified)

Add `public ?string $entidadTipo` field; parsed from `$data['entidad_tipo'] ?? null`.

---

## Prompt Structure

### Dynamic prompt (3-section template)

```
Sos un experto en análisis de riesgo financiero y compliance AML/CFT en {$pais}.
Tu tarea: analizar el siguiente texto y determinar si menciona a una persona PEP u OPI.

DEFINICIONES PEP para {$pais}:

SIEMPRE_PEP (siempre son PEP independientemente de la entidad donde trabajen):
{foreach $cargos->where(entidad_tipo=todas): "- {nombre}"}

PEP_EN_ENTIDAD_PUBLICA (son PEP solo si están en una entidad pública):
{foreach $cargos->where(entidad_tipo=publica): "- {nombre}"}

PUEDE_SER_PEP (depende del contexto; considerar especialmente si están en estas entidades):
{foreach $cargos->where(entidad_tipo=ambas): "- {nombre}"}
Entidades públicas conocidas de {$pais}: {entidades imploded by ", "}

- OPI: líderes de organizaciones criminales, personas bajo investigación por lavado de activos o narcotráfico

Devolvé ÚNICAMENTE el siguiente JSON:
{"is_pep":boolean,"nombre":string|null,"cargo":string|null,"categoria":"PEP"|"OPI"|null,"entidad_tipo":"publica"|"privada"|"desconocido"|null,"confianza":0-100,"motivo":string}

[... same 3 few-shot examples as current prompt ...]

Categoría investigada: {$categoria}
País: {$pais}

TEXTO:
{$texto}
```

### Generic fallback (current hardcoded + warning comment)

Identical to current `filtroPEP()` body. Adds `entidad_tipo` field to JSON spec. `Log::channel('gemini')->warning("No PEP positions for country: {$pais}")`.

---

## Seeder Design

### `CargosPepBoliviaSeeder`

```php
// Classification rules:
// 'todas'   → Diputado, Senador, Asambleísta Departamental (elected legislative roles)
// 'publica' → Ministro, Viceministro, Presidente, Fiscal, Rector, Comandante, Gobernador, Alcalde, Embajador... 
// 'ambas'   → Gerente, Director, Asesor, Consultor, Jefe de Área (roles that depend on entity type)

$cargos = [
    ['nombre' => 'Diputado',        'categoria' => 'Legislativo',  'entidad_tipo' => 'todas'],
    ['nombre' => 'Senador',         'categoria' => 'Legislativo',  'entidad_tipo' => 'todas'],
    // ... 97 total
];

foreach ($cargos as $cargo) {
    DB::table('cargos_pep')->updateOrInsert(
        ['pais_codigo' => 'BO', 'nombre' => $cargo['nombre']],
        array_merge($cargo, ['pais_codigo' => 'BO', 'activo' => true, 'created_at' => now(), 'updated_at' => now()])
    );
}
```

### `EntidadesPublicasBoliviaSeeder`

Seeds: YPFB, ENDE, ENTEL, Banco Unión, UMSA, UMSS, UAB, Banco Central de Bolivia, FONDESIF, SENASAG (and others from spec).

---

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/YYYY_MM_DD_create_cargos_pep_table.php` | Create | New table migration |
| `database/migrations/YYYY_MM_DD_create_entidades_publicas_table.php` | Create | New table migration |
| `database/migrations/YYYY_MM_DD_add_gemini_entidad_tipo_to_resultados_scraping.php` | Create | Adds `gemini_entidad_tipo` column |
| `app/Enums/EntidadTipo.php` | Create | PHP backed enum for the 3 classifications |
| `app/Models/CargoPep.php` | Create | Eloquent model + scopes |
| `app/Models/EntidadPublica.php` | Create | Eloquent model + scopes |
| `app/Services/Gemini/PepCatalogService.php` | Create | In-request cached catalog loader |
| `app/Services/Gemini/GeminiPromptBuilder.php` | Modify | Constructor injection of `?PepCatalogService`, new `buildDynamicPrompt()` and `buildGenericPrompt()` private methods |
| `app/Services/Gemini/DTOs/FiltroResultadoDTO.php` | Modify | Add `entidadTipo` field |
| `app/Providers/AppServiceProvider.php` | Modify | Bind `GeminiPromptBuilder` with `PepCatalogService` injection |
| `database/seeders/CargosPepBoliviaSeeder.php` | Create | 97 Bolivia PEP positions |
| `database/seeders/EntidadesPublicasBoliviaSeeder.php` | Create | Bolivia public entities |
| `database/seeders/DatabaseSeeder.php` | Modify | Register new seeders |
| `tests/Unit/Gemini/GeminiPromptBuilderTest.php` | Modify | Add tests for dynamic prompt; keep existing pure-unit tests unchanged |
| `tests/Unit/Models/CargoPepTest.php` | Create | Unit tests for model scopes |
| `tests/Unit/Models/EntidadPublicaTest.php` | Create | Unit tests for model scopes |
| `tests/Unit/Services/PepCatalogServiceTest.php` | Create | Cache behavior tests |
| `tests/Feature/Seeders/CargosPepBoliviaSeederTest.php` | Create | Seeder idempotency + 97 records |
| `tests/Feature/Gemini/GeminiFiltroServiceTest.php` | Modify | Update fake responses to include `entidad_tipo` field |

---

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `CargoPep` scopes (`active`, `forCountry`, `byEntidadTipo`) | Extends `Tests\TestCase` with `RefreshDatabase`; seed inline fixtures |
| Unit | `EntidadPublica` scopes | Same pattern |
| Unit | `PepCatalogService` static cache | Call twice, assert DB::getQueryLog() count = 1; call `flushCache()` between tests |
| Unit | `GeminiPromptBuilder::filtroPEP()` with catalog | Mock `PepCatalogService`, assert prompt contains SIEMPRE_PEP / PEP_EN_ENTIDAD_PUBLICA / PUEDE_SER_PEP sections |
| Unit | `GeminiPromptBuilder::filtroPEP()` fallback | Pass catalog returning empty collection, assert prompt contains hardcoded "ministros, legisladores..." |
| Unit | `FiltroResultadoDTO` with `entidad_tipo` field | Pure PHPUnit test, assert field parsed correctly |
| Feature | `CargosPepBoliviaSeeder` | `RefreshDatabase`, run seeder, assert `CargoPep::count() === 97`, all `pais_codigo = 'BO'` |
| Feature | `EntidadesPublicasBoliviaSeeder` | Run seeder twice (idempotency), assert no duplicates |
| Feature | N+1 prevention | `DB::enableQueryLog()`, call `filtroPEP()` 3× for same country via service, assert query count ≤ 2 |
| Feature | Prompt structure integration | Seed Bolivia, call builder, assert string contains all 3 section headers |
| Feature | `GeminiFiltroServiceTest` | Update existing tests to include `entidad_tipo` in fake response JSON |

**Note on pure unit tests**: `GeminiPromptBuilderTest` currently extends `PHPUnit\Framework\TestCase` (no DB). Tests for dynamic prompts will use a mock `PepCatalogService` — keeping it a pure unit test. Cache and DB-dependent tests move to Feature layer.

---

## Open Questions

- [ ] Final list of 97 Bolivia positions and their exact `entidad_tipo` / `categoria` classification — needs domain validation before seeder is written
- [ ] Should `EntidadesPublicasBoliviaSeeder` be a separate class or merged into `CargosPepBoliviaSeeder`? (Prefer separate for SRP, but open to team preference)
- [ ] `AppServiceProvider` binding: inject `PepCatalogService` via constructor or via `app()->make()`? Constructor injection preferred for testability.
