# Design: Sistema Feedback Clasificaciones

## Technical Approach

Extend `Livewire\Scraper\Resultados` with feedback props/methods following the existing `verAnalisisId` modal pattern. New `clasificaciones_feedback` table with surrogate `id` + UNIQUE `(resultado_scraping_id, usuario_id)`. New `ClasificacionFeedback` Eloquent model, two PHP BackedEnums, and a permission `dar feedback clasificaciones` seeded to `admin` + `supervisor` only.

## Architecture Decisions

| # | Decision | Choice | Alternatives | Rationale |
|---|----------|--------|--------------|-----------|
| 1 | Primary key | Surrogate `id` + UNIQUE `(resultado_scraping_id, usuario_id)` | Composite PK w/ `HasCompositePrimaryKey` trait; `$incrementing=false` | Laravel-idiomatic; `updateOrCreate`, relations, model events, factories all work without trait hacks |
| 2 | `tipo` storage | `string(12)` + `TipoFeedback` BackedEnum cast | DB native ENUM; bool flag | Portable SQLite/PG (spec requirement); enum gives type safety in PHP like `EntidadTipo` |
| 3 | `corregido_categoria` | Separate `CategoriaCorreccion` BackedEnum (`PEP`, `OPI`, `NO_REL`) | Reuse `gemini_categoria` string | Spec REQ-6 defines exact values; isolated enum prevents coupling with Gemini output strings |
| 4 | Snapshot | JSON column, built by `ResultadoScraping::toGeminiSnapshot()` | Denormalized columns; serialized model | JSON matches spec REQ-4; builder method keeps serialization in one place and testable |
| 5 | Eager loading | Query scope `withFeedbackFromUser(int $userId)` → `hasMany` relation loaded via closure | Relation that reads `Auth::id()` internally | Avoids Auth-in-model anti-pattern; explicit at call site; no N+1 |
| 6 | Modal pattern | Extend Resultados (same component) | New `FeedbackModal` component w/ events | Matches `verAnalisisId` pattern already in blade; spec requires no reload; minimal churn |
| 7 | Permission guard | `@can` in blade + `$this->authorize()` in every action | Policy on model | Matches existing pattern (`gestionar sitios`); Livewire actions are the single entry point |
| 8 | FK delete rules | `resultado_scraping_id` CASCADE, `usuario_id` RESTRICT | SET NULL; CASCADE both | Spec NFR Data Integrity mandates these exact rules |

## Data Flow

    Blade row ──click──▶ Resultados::guardarFeedbackCorrecto($id)
                              │ authorize + updateOrCreate + snapshot
                              ▼
                        clasificaciones_feedback (upsert)
                              ▲
    Blade row ──click──▶ Resultados::abrirModalFeedbackIncorrecto($id)
                              │ loads existing feedback → pre-fill props
                              ▼
                        Modal form ──submit──▶ guardarFeedbackIncorrecto()
                              │ validate + authorize + updateOrCreate
                              ▼
                        re-render (row indicator updates via eager-loaded relation)

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/2026_04_11_000010_create_clasificaciones_feedback_table.php` | Create | Table w/ FKs, UNIQUE, indexes on `tipo` and `usuario_id` |
| `app/Enums/TipoFeedback.php` | Create | `Correcto = 'correcto'`, `Incorrecto = 'incorrecto'` |
| `app/Enums/CategoriaCorreccion.php` | Create | `PEP`, `OPI`, `NoRel = 'NO_REL'` |
| `app/Models/ClasificacionFeedback.php` | Create | `$fillable`, `$casts` (tipo enum, snapshot array, corregido_is_pep bool), relations, scopes `correctos/incorrectos/porUsuario/porResultado` |
| `app/Models/ResultadoScraping.php` | Modify | Add `feedback()` hasMany, `toGeminiSnapshot(): array`, scope `withFeedbackFromUser(int $userId)` |
| `app/Models/User.php` | Modify | Add `clasificacionesFeedback()` hasMany |
| `app/Livewire/Scraper/Resultados.php` | Modify | New props + methods (see Interfaces); `buildQuery()` calls `withFeedbackFromUser(Auth::id())` |
| `resources/views/livewire/scraper/resultados.blade.php` | Modify | Feedback buttons + badge in actions cell (`@can`); feedback modal after Ver análisis modal |
| `database/seeders/RolesPermisosSeeder.php` | Modify | Add `dar feedback clasificaciones` to `$permisos`, `admin`, `supervisor` |
| `tests/Unit/Models/ClasificacionFeedbackTest.php` | Create | Casts, scopes, relations, enum |
| `tests/Feature/Livewire/Scraper/ResultadosFeedbackTest.php` | Create | Permissions, correcto, incorrecto modal, upsert, N+1, cascade |
| `database/factories/ClasificacionFeedbackFactory.php` | Create | For tests |

## Interfaces / Contracts

```php
// Migration essentials
$t->id();
$t->foreignId('resultado_scraping_id')->constrained('resultados_scraping')->cascadeOnDelete();
$t->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
$t->string('tipo', 12);
$t->json('clasificacion_snapshot');
$t->boolean('corregido_is_pep')->nullable();
$t->string('corregido_categoria', 6)->nullable();
$t->string('corregido_nombre', 200)->nullable();
$t->string('corregido_cargo', 200)->nullable();
$t->text('motivo')->nullable();
$t->timestamps();
$t->unique(['resultado_scraping_id', 'usuario_id'], 'clasif_fb_unique');
$t->index('tipo');
$t->index('usuario_id');

// Livewire additions
public ?int $feedbackModalId = null;
public ?string $feedbackCategoriaCorregida = null;
public ?string $feedbackNombreCorregido = null;
public ?string $feedbackCargoCorregido = null;
public ?bool   $feedbackIsPepCorregido = null;
public string  $feedbackMotivo = '';

public function guardarFeedbackCorrecto(int $id): void;
public function abrirModalFeedbackIncorrecto(int $id): void;
public function guardarFeedbackIncorrecto(): void;
public function cerrarModalFeedback(): void;

protected function rulesFeedbackIncorrecto(): array {
    return [
        'feedbackCategoriaCorregida' => ['required', Rule::enum(CategoriaCorreccion::class)],
        'feedbackMotivo'             => 'required|string|min:10|max:1000',
        'feedbackNombreCorregido'    => 'nullable|string|max:200',
        'feedbackCargoCorregido'     => 'nullable|string|max:200',
        'feedbackIsPepCorregido'     => 'nullable|boolean',
    ];
}
```

All actions start with `$this->authorize('dar feedback clasificaciones')` and persist via `ClasificacionFeedback::updateOrCreate(['resultado_scraping_id' => $id, 'usuario_id' => Auth::id()], [...])` with `clasificacion_snapshot` from `$resultado->toGeminiSnapshot()`.

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| Unit | Enums, model casts/scopes/relations, `toGeminiSnapshot()` | PHPUnit, RefreshDatabase, factory |
| Feature | Permission gate, buttons visibility, correcto/incorrecto flow, upsert, validation, cascade, eager-load no N+1 (`DB::getQueryLog`) | `Livewire::test()` with acting user |

## Migration / Rollout

Single migration, no data backfill (new table). Seeder re-run required for new permission. Rollback = `migrate:rollback` + revert seeder + git revert.

## Open Questions

None — all decisions resolved against proposal + spec.
