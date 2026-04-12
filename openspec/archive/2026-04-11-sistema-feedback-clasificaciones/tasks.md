# Tasks: sistema-feedback-clasificaciones

## Phase 1: Infrastructure (Enums + Migration)

- [x] 1.1 Create `app/Enums/TipoFeedback.php` PHP backed enum
  - Cases: `Correcto = 'correcto'`, `Incorrecto = 'incorrecto'`
  - Acceptance: enum has 2 cases, implements BackedEnum, `declare(strict_types=1)`
  - Test: `tests/Unit/Enums/TipoFeedbackTest.php` — lists cases, values match, `isBacked()` true, `from()` works

- [x] 1.2 Create `app/Enums/CategoriaCorreccion.php` PHP backed enum
  - Cases: `PEP = 'PEP'`, `OPI = 'OPI'`, `NoRel = 'NO_REL'`
  - Acceptance: 3 cases with correct string values, `declare(strict_types=1)`
  - Test: `tests/Unit/Enums/CategoriaCorreccionTest.php` — lists cases, values match, `from()` works

- [x] 1.3 Create migration `database/migrations/2026_04_11_000010_create_clasificaciones_feedback_table.php`
  - Surrogate `$table->id()` (auto-increment PK, NOT composite)
  - `$table->foreignId('resultado_scraping_id')->constrained('resultados_scraping')->cascadeOnDelete()`
  - `$table->foreignId('usuario_id')->constrained('users')->restrictOnDelete()`
  - `$table->string('tipo', 12)`
  - `$table->json('clasificacion_snapshot')`
  - `$table->boolean('corregido_is_pep')->nullable()`
  - `$table->string('corregido_categoria', 6)->nullable()`
  - `$table->string('corregido_nombre', 200)->nullable()`
  - `$table->string('corregido_cargo', 200)->nullable()`
  - `$table->text('motivo')->nullable()`
  - `$table->timestamps()`
  - `$table->unique(['resultado_scraping_id', 'usuario_id'], 'clasif_fb_unique')`
  - `$table->index('tipo')`
  - `$table->index('usuario_id')`
  - Acceptance: migration runs on SQLite (tests) and PostgreSQL (prod)

- [x] 1.4 Run `php artisan migrate` — verify no errors
  - Acceptance: `php artisan migrate:fresh --seed` succeeds

---

## Phase 2: Model + Factory (TDD)

### 2.1 ClasificacionFeedback Model

- [x] 2.1.1 RED: Write `tests/Unit/Models/ClasificacionFeedbackTest.php`
  - Test `$fillable` includes: `resultado_scraping_id`, `usuario_id`, `tipo`, `clasificacion_snapshot`, `corregido_is_pep`, `corregido_categoria`, `corregido_nombre`, `corregido_cargo`, `motivo`
  - Test `$casts`: `clasificacion_snapshot` → array, `corregido_is_pep` → boolean, `tipo` → `TipoFeedback` enum
  - Test `resultadoScraping()` belongsTo relation returns `ResultadoScraping`
  - Test `usuario()` belongsTo relation returns `User`
  - Test scopes: `correctos()`, `incorrectos()`, `porUsuario($userId)`, `porResultado($resultadoId)`
  - Test that `corregido_categoria` cast to `CategoriaCorreccion` enum when not null
  - Test unique constraint enforced at DB level (try create duplicate)

- [x] 2.1.2 GREEN: Create `app/Models/ClasificacionFeedback.php`
  - `declare(strict_types=1)`
  - `$table = 'clasificaciones_feedback'`
  - `$fillable` as per test
  - `$casts`: `clasificacion_snapshot => 'array'`, `corregido_is_pep => 'boolean'`, `tipo => TipoFeedback::class`, `corregido_categoria => CategoriaCorreccion::class`
  - `resultadoScraping()`: `belongsTo(ResultadoScraping::class, 'resultado_scraping_id')`
  - `usuario()`: `belongsTo(User::class, 'usuario_id')`
  - Scope `correctos()`: `where('tipo', TipoFeedback::Correcto)`
  - Scope `incorrectos()`: `where('tipo', TipoFeedback::Incorrecto)`
  - Scope `porUsuario(int $userId)`: `where('usuario_id', $userId)`
  - Scope `porResultado(int $resultadoId)`: `where('resultado_scraping_id', $resultadoId)`

- [x] 2.1.3 REFACTOR: Run `./vendor/bin/pint app/Models/ClasificacionFeedback.php`

### 2.2 ClasificacionFeedback Factory

- [x] 2.2.1 Create `database/factories/ClasificacionFeedbackFactory.php`
  - Define `resultadoScraping` and `usuario` sequence states
  - `tipo`: faker->randomElement(TipoFeedback::cases())
  - `clasificacion_snapshot`: default `['is_pep' => true, 'categoria' => 'PEP', 'confianza' => 85, 'nombre' => 'Nombre Test', 'cargo' => 'Cargo Test']`
  - `corregido_is_pep`, `corregido_categoria`, `corregido_nombre`, `corregido_cargo`, `motivo`: nullable random
  - Acceptance: factory can create valid records; `factory()->count(5)->make()` works

- [x] 2.2.2 Test factory: add test in `ClasificacionFeedbackTest.php`
  - `test_factory_creates_valid_records()`

---

## Phase 3: ResultadoScraping Extensions (TDD)

- [x] 3.1 RED: Write test for `toGeminiSnapshot()` in `tests/Unit/Models/ResultadoScrapingTest.php` (create if not exists)
  - Given a `ResultadoScraping` with `gemini_is_pep`, `gemini_categoria`, `gemini_confianza`, `gemini_nombre`, `gemini_cargo`
  - When calling `$resultado->toGeminiSnapshot()`
  - Then returns array with keys: `is_pep`, `categoria`, `confianza`, `nombre`, `cargo`
  - And values match the model's gemini fields

- [x] 3.2 GREEN: Add `toGeminiSnapshot(): array` method to `app/Models/ResultadoScraping.php`
  ```php
  public function toGeminiSnapshot(): array
  {
      return [
          'is_pep' => $this->gemini_is_pep,
          'categoria' => $this->gemini_categoria,
          'confianza' => $this->gemini_confianza,
          'nombre' => $this->gemini_nombre,
          'cargo' => $this->gemini_cargo,
      ];
  }
  ```

- [x] 3.3 RED: Write test for `feedback()` hasMany relation
  - Given a `ResultadoScraping` with multiple feedback records
  - When accessing `$resultado->feedback`
  - Then returns Collection of `ClasificacionFeedback` models

- [x] 3.4 GREEN: Add `feedback(): HasMany` relation to `ResultadoScraping.php`
  ```php
  public function feedback(): HasMany
  {
      return $this->hasMany(ClasificacionFeedback::class, 'resultado_scraping_id');
  }
  ```

- [x] 3.5 RED: Write test for `withFeedbackFromUser(int $userId)` scope
  - Given 2 resultados with feedback from user A and user B
  - When querying `ResultadoScraping::withFeedbackFromUser($userA->id)->get()`
  - Then only include feedback relationship for user A (eager loaded via closure)
  - Verify no N+1: count queries with `DB::getQueryLog()`

- [x] 3.6 GREEN: Add scope to `ResultadoScraping.php`
  ```php
  public function scopeWithFeedbackFromUser($query, int $userId)
  {
      return $query->with(['feedback' => fn ($q) => $q->where('usuario_id', $userId)]);
  }
  ```

- [x] 3.7 REFACTOR: Run `./vendor/bin/pint app/Models/ResultadoScraping.php`

---

## Phase 4: User Model Extension (TDD)

- [x] 4.1 RED: Write test for `clasificacionesFeedback()` relation in existing User test or `tests/Unit/Models/UserTest.php`
  - Given a User with multiple feedback records
  - When accessing `$user->clasificacionesFeedback`
  - Then returns Collection of `ClasificacionFeedback`

- [x] 4.2 GREEN: Add to `app/Models/User.php`
  ```php
  public function clasificacionesFeedback(): HasMany
  {
      return $this->hasMany(ClasificacionFeedback::class, 'usuario_id');
  }
  ```

- [x] 4.3 REFACTOR: Run `./vendor/bin/pint app/Models/User.php`

---

## Phase 5: Permission Seeder (TDD)

- [x] 5.1 RED: Write test that permission `dar feedback clasificaciones` does NOT exist before seeding
  - In `tests/Unit/Models/ClasificacionFeedbackTest.php` or a new seeder test
  - After fresh migrate (no seeding), verify permission is NOT in permissions table

- [x] 5.2 RED: Write test that `admin` role HAS `dar feedback clasificaciones` permission after seeding
  - Run `RolesPermisosSeeder`
  - Assert admin role has the permission

- [x] 5.3 RED: Write test that `supervisor` role HAS `dar feedback clasificaciones` permission after seeding
  - Assert supervisor role has the permission

- [x] 5.4 RED: Write test that `operador` role does NOT have `dar feedback clasificaciones` permission
  - Assert operador role does NOT have the permission
  - This is explicit verification per proposal decision (calidad sobre cantidad)

- [x] 5.5 GREEN: Modify `database/seeders/RolesPermisosSeeder.php`
  - Add `'dar feedback clasificaciones'` to `$permisos` array
  - Add `'dar feedback clasificaciones'` to `$admin->syncPermissions([...])` array
  - Add `'dar feedback clasificaciones'` to `$supervisor->syncPermissions([...])` array
  - Do NOT add to `$operador->syncPermissions([...])` array

- [x] 5.6 Run seeder: `php artisan db:seed --class=RolesPermisosSeeder`

---

## Phase 6: Livewire Resultados Extension (TDD)

### 6.1 Property & State Tests

- [x] 6.1.1 RED: Write test — unauthorized user (operador) does NOT see feedback buttons
  - Create `tests/Feature/Livewire/Scraper/ResultadosFeedbackTest.php`
  - Acting as operador role
  - Assert feedback buttons NOT visible in rendered HTML

- [x] 6.1.2 RED: Write test — authorized user (admin) sees buttons on rows where `gemini_analyzed = true`
  - Assert buttons visible on analyzed rows
  - Assert buttons NOT visible on non-analyzed rows

- [x] 6.1.3 RED: Write test — row with no feedback shows neutral state (no badge)
  - Assert no verde/orange badge visible for rows without feedback

### 6.2 guardarFeedbackCorrecto Tests

- [x] 6.2.1 RED: Write test — `guardarFeedbackCorrecto` creates feedback immediately (no modal)
  - Click correct button → feedback record created
  - Verify `tipo = TipoFeedback::Correcto`
  - Verify `usuario_id` = authenticated user

- [x] 6.2.2 RED: Write test — `guardarFeedbackCorrecto` is idempotent (upsert)
  - Given existing feedback with `tipo = Incorrecto`
  - Call `guardarFeedbackCorrecto` on same resultado
  - Assert only ONE record exists (not duplicated)
  - Assert `tipo` updated to `Correcto`
  - Assert `updated_at` changed

- [x] 6.2.3 GREEN: Implement `guardarFeedbackCorrecto(int $id): void` in `Resultados.php`
  ```php
  public function guardarFeedbackCorrecto(int $id): void
  {
      $this->authorize('dar feedback clasificaciones');
      
      $resultado = ResultadoScraping::findOrFail($id);
      
      ClasificacionFeedback::updateOrCreate(
          ['resultado_scraping_id' => $id, 'usuario_id' => Auth::id()],
          [
              'tipo' => TipoFeedback::Correcto,
              'clasificacion_snapshot' => $resultado->toGeminiSnapshot(),
          ]
      );
      
      session()->flash('message', 'Feedback guardado correctamente.');
  }
  ```

- [x] 6.2.4 REFACTOR: Run `./vendor/bin/pint app/Livewire/Scraper/Resultados.php`

### 6.3 Modal + Incorrecto Tests

- [x] 6.3.1 RED: Write test — `abrirModalFeedbackIncorrecto` opens modal and pre-fills if feedback exists
  - Given existing feedback with `tipo = Correcto`
  - When calling `abrirModalFeedbackIncorrecto($id)`
  - Then `feedbackModalId` is set
  - And form fields are pre-filled from existing feedback

- [x] 6.3.2 GREEN: Add modal state properties to `Resultados.php`
  ```php
  public ?int $feedbackModalId = null;
  public ?string $feedbackCategoriaCorregida = null;
  public ?string $feedbackNombreCorregido = null;
  public ?string $feedbackCargoCorregido = null;
  public ?bool   $feedbackIsPepCorregido = null;
  public string  $feedbackMotivo = '';
  ```

- [x] 6.3.3 GREEN: Implement `abrirModalFeedbackIncorrecto(int $id): void`
  ```php
  public function abrirModalFeedbackIncorrecto(int $id): void
  {
      $this->authorize('dar feedback clasificaciones');
      
      $resultado = ResultadoScraping::withFeedbackFromUser(Auth::id())->findOrFail($id);
      $this->feedbackModalId = $id;
      
      // Pre-fill from existing feedback if any
      $existing = $resultado->feedback->first();
      if ($existing) {
          $this->feedbackCategoriaCorregida = $existing->corregido_categoria?->value;
          $this->feedbackNombreCorregido = $existing->corregido_nombre;
          $this->feedbackCargoCorregido = $existing->corregido_cargo;
          $this->feedbackIsPepCorregido = $existing->corregido_is_pep;
          $this->feedbackMotivo = $existing->motivo ?? '';
      } else {
          $this->reset(['feedbackCategoriaCorregida', 'feedbackNombreCorregido', 'feedbackCargoCorregido', 'feedbackIsPepCorregido', 'feedbackMotivo']);
      }
  }
  ```

- [x] 6.3.4 RED: Write test — `guardarFeedbackIncorrecto` validation fails when `feedbackCategoriaCorregida` missing
  - Open modal, leave categoria empty, submit
  - Assert validation error

- [x] 6.3.5 RED: Write test — `guardarFeedbackIncorrecto` validation fails when `feedbackMotivo` missing or < 10 chars
  - Submit with empty motivo → validation error
  - Submit with 5 chars → validation error

- [x] 6.3.6 RED: Write test — `guardarFeedbackIncorrecto` happy path
  - Fill all required fields, submit
  - Assert feedback created with `tipo = TipoFeedback::Incorrecto`
  - Assert modal closed (`feedbackModalId = null`)
  - Assert success message

- [x] 6.3.7 RED: Write test — `guardarFeedbackIncorrecto` upsert (correcto → incorrecto)
  - Given existing `Correcto` feedback
  - Submit `Incorrecto` feedback
  - Assert only ONE record (upsert)
  - Assert `tipo` updated to `Incorrecto`

- [x] 6.3.8 GREEN: Add validation rules method to `Resultados.php`
  ```php
  protected function rulesFeedbackIncorrecto(): array
  {
      return [
          'feedbackCategoriaCorregida' => ['required', Rule::enum(CategoriaCorreccion::class)],
          'feedbackMotivo'             => 'required|string|min:10|max:1000',
          'feedbackNombreCorregido'    => 'nullable|string|max:200',
          'feedbackCargoCorregido'     => 'nullable|string|max:200',
          'feedbackIsPepCorregido'     => 'nullable|boolean',
      ];
  }
  ```

- [x] 6.3.9 GREEN: Implement `guardarFeedbackIncorrecto(): void`
  ```php
  public function guardarFeedbackIncorrecto(): void
  {
      $this->authorize('dar feedback clasificaciones');
      
      $validated = $this->validate($this->rulesFeedbackIncorrecto());
      
      $resultado = ResultadoScraping::findOrFail($this->feedbackModalId);
      
      ClasificacionFeedback::updateOrCreate(
          ['resultado_scraping_id' => $this->feedbackModalId, 'usuario_id' => Auth::id()],
          [
              'tipo' => TipoFeedback::Incorrecto,
              'clasificacion_snapshot' => $resultado->toGeminiSnapshot(),
              'corregido_is_pep' => $this->feedbackIsPepCorregido,
              'corregido_categoria' => CategoriaCorreccion::from($this->feedbackCategoriaCorregida),
              'corregido_nombre' => $this->feedbackNombreCorregido,
              'corregido_cargo' => $this->feedbackCargoCorregido,
              'motivo' => $this->feedbackMotivo,
          ]
      );
      
      $this->cerrarModalFeedback();
      session()->flash('message', 'Feedback guardado correctamente.');
  }
  ```

- [x] 6.3.10 RED: Write test — `cerrarModalFeedback` resets all feedback state
  - After opening modal and filling fields
  - Call `cerrarModalFeedback`
  - Assert `feedbackModalId = null`
  - Assert all form fields reset to defaults

- [x] 6.3.11 GREEN: Implement `cerrarModalFeedback(): void`
  ```php
  public function cerrarModalFeedback(): void
  {
      $this->feedbackModalId = null;
      $this->reset([
          'feedbackCategoriaCorregida',
          'feedbackNombreCorregido',
          'feedbackCargoCorregido',
          'feedbackIsPepCorregido',
          'feedbackMotivo',
      ]);
  }
  ```

- [x] 6.3.12 REFACTOR: Run `./vendor/bin/pint app/Livewire/Scraper/Resultados.php`

### 6.4 Eager Loading Integration

- [x] 6.4.1 Modify `buildQuery()` in `Resultados.php` to call `withFeedbackFromUser(Auth::id())`
  - Add at end of `buildQuery()`: `$q->withFeedbackFromUser(Auth::id());`
  - Note: `Auth` facade must be imported
  - Acceptance: paginated results include user's feedback without N+1

---

## Phase 7: Blade Template Updates

- [x] 7.1 Add feedback buttons to actions cell inside `@can('dar feedback clasificaciones')` wrapper
  - After "Ver análisis" button, inside `gemini_analyzed` conditional block
  - Two buttons side by side:
    ```blade
    <button wire:click="guardarFeedbackCorrecto({{ $r->id }})"
        class="simo-btn text-xs {{ isset($r->feedback[0]) && $r->feedback[0]->tipo->value === 'correcto' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-50 text-gray-500 hover:bg-emerald-50 hover:text-emerald-600' }}">
        ✓ Correcto
    </button>
    <button wire:click="abrirModalFeedbackIncorrecto({{ $r->id }})"
        class="simo-btn text-xs {{ isset($r->feedback[0]) && $r->feedback[0]->tipo->value === 'incorrecto' ? 'bg-amber-100 text-amber-700' : 'bg-gray-50 text-gray-500 hover:bg-amber-50 hover:text-amber-600' }}">
        ✗ Incorrecto
    </button>
    ```
  - Wrap with `@can('dar feedback clasificaciones')` ... `@endcan`
  - Buttons only render when `$r->gemini_analyzed = true`

- [x] 7.2 Add visual state badges per row (outside `@can` for visual feedback)
  - After existing badges section, before closing `<div class="flex items-center gap-1.5 flex-wrap">`
  - If user has feedback on this row:
    - Correcto: green badge `bg-emerald-50 text-emerald-600 border-emerald-100`
    - Incorrecto: amber badge `bg-amber-50 text-amber-600 border-amber-100`

- [x] 7.3 Add feedback modal after "Ver análisis" modal in blade
  - Place after the `verAnalisisId` modal block (before closing `</div>` of main container)
  - Modal structure:
    ```blade
    @if($feedbackModalId)
    <div class="fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50 p-4"
        wire:click.self="cerrarModalFeedback">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-800">Corregir Clasificación</h2>
                <button wire:click="cerrarModalFeedback"
                    class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 text-lg">&times;</button>
            </div>
            <form wire:submit="guardarFeedbackIncorrecto" class="px-6 py-5 space-y-4">
                <!-- Current Gemini classification (read-only) -->
                @if($fb = collect($resultados->items())->firstWhere('id', $feedbackModalId)?->feedback?->first())
                    <div class="bg-gray-50 rounded-lg p-3 text-xs text-gray-500">
                        Clasificación actual: <strong>{{ $fb->clasificacion_snapshot['categoria'] ?? '—' }}</strong>
                    </div>
                @endif
                
                <!-- Categoria corregida (required) -->
                <div>
                    <label class="simo-label">Categoría corregida *</label>
                    <select wire:model="feedbackCategoriaCorregida" class="simo-select w-full">
                        <option value="">Seleccionar...</option>
                        @foreach(\App\Enums\CategoriaCorreccion::cases() as $cat)
                            <option value="{{ $cat->value }}">{{ $cat->value }}</option>
                        @endforeach
                    </select>
                    @error('feedbackCategoriaCorregida') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
                
                <!-- Motivo (required, min 10) -->
                <div>
                    <label class="simo-label">Motivo de la corrección *</label>
                    <textarea wire:model="feedbackMotivo" rows="3" class="simo-input w-full" placeholder="Explica por qué la clasificación es incorrecta (mín. 10 caracteres)"></textarea>
                    @error('feedbackMotivo') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
                
                <!-- Optional fields -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="simo-label">PEP / No PEP</label>
                        <select wire:model="feedbackIsPepCorregido" class="simo-select w-full">
                            <option value="">—</option>
                            <option value="1">PEP</option>
                            <option value="0">No PEP</option>
                        </select>
                    </div>
                    <div>
                        <label class="simo-label">Nombre corregido</label>
                        <input wire:model="feedbackNombreCorregido" type="text" class="simo-input w-full" />
                    </div>
                </div>
                
                <div>
                    <label class="simo-label">Cargo corregido</label>
                    <input wire:model="feedbackCargoCorregido" type="text" class="simo-input w-full" />
                </div>
                
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="cerrarModalFeedback" class="simo-btn bg-gray-100 text-gray-600">Cancelar</button>
                    <button type="submit" class="simo-btn bg-indigo-600 text-white hover:bg-indigo-700">Guardar</button>
                </div>
            </form>
        </div>
    </div>
    @endif
    ```

- [x] 7.4 Verify no `var()` in class attributes (tailwind-4 compliance)
  - Acceptance: no inline `style="--tw-..."` or `var()` patterns

- [x] 7.5 Test: render blade without errors, verify buttons appear for admin, not for operador

---

## Phase 8: Integration Tests

- [x] 8.1 Test N+1 prevention
  - `DB::enableQueryLog()` before paginated query
  - Assert query count ≤ expected (1 main + 1 feedback per page, not N+1)
  - Use `expectsQueries()` or count in `DB::getQueryLog()`

- [x] 8.2 Test cascade delete
  - Create `ResultadoScraping` with `ClasificacionFeedback`
  - Delete `ResultadoScraping`
  - Assert feedback is gone: `ClasificacionFeedback::where('resultado_scraping_id', $id)->count() === 0`

- [x] 8.3 Test restrict delete (usuario with feedback)
  - Create `User` with `ClasificacionFeedback`
  - Attempt to delete user → should throw (or use model events to prevent)
  - If FK is RESTRICT, DB will raise integrity constraint error

- [x] 8.4 Test full flow: login as admin → see buttons on analyzed rows → mark correct → reload page → mark incorrect → update → reload page
  - Use `actingAs()` with admin user
  - Livewire test with state assertions between each action

---

## Phase 9: Verification

- [x] 9.1 Run enum tests: `php artisan test --filter=TipoFeedbackTest`
- [x] 9.2 Run model tests: `php artisan test --filter=ClasificacionFeedbackTest`
- [x] 9.3 Run ResultadoScraping extension tests: `php artisan test --filter=ResultadoScrapingTest`
- [x] 9.4 Run ResultadosFeedback tests: `php artisan test --filter=ResultadosFeedbackTest`
- [x] 9.5 Run permission tests: `php artisan test --filter=RolesPermisosSeeder`
- [x] 9.6 Run `./vendor/bin/pint --test app/Enums/TipoFeedback.php app/Enums/CategoriaCorreccion.php app/Models/ClasificacionFeedback.php app/Models/ResultadoScraping.php app/Models/User.php app/Livewire/Scraper/Resultados.php`
- [x] 9.7 Run full suite: `php artisan test` — verify no regressions
- [x] 9.8 Smoke test blade: verify renders with `php artisan view:cache` (if applicable)

---

## Summary

| Phase | Tasks | Files Created/Modified |
|-------|-------|----------------------|
| Phase 1: Infrastructure | 4 | 2 enums + 1 migration + run |
| Phase 2: Model + Factory | 5 | 1 model + 1 factory + 1 test file |
| Phase 3: ResultadoScraping | 7 | 1 model modified (3 features) |
| Phase 4: User | 3 | 1 model modified |
| Phase 5: Permission Seeder | 6 | 1 seeder modified |
| Phase 6: Livewire | 15 | 1 component modified |
| Phase 7: Blade | 5 | 1 blade modified |
| Phase 8: Integration | 4 | 1 test file |
| Phase 9: Verification | 8 | — |
| **Total** | **57** | **13 files** |

## Task Count Per Phase

- Phase 1: 4 tasks
- Phase 2: 5 tasks
- Phase 3: 7 tasks
- Phase 4: 3 tasks
- Phase 5: 6 tasks
- Phase 6: 15 tasks
- Phase 7: 5 tasks
- Phase 8: 4 tasks
- Phase 9: 8 tasks
