# Tasks: lematizacion-pep-opi

## Phase 1: Infrastructure & Enums

- [x] 1.1 Create `app/Enums/EntidadTipo.php` backed enum with cases: Todas ('todas'), Publica ('publica'), Ambas ('ambas')
  - Acceptance: 3 cases, all strings, implements BackedEnum
  - Test: `tests/Unit/Enums/EntidadTipoTest.php` — lists 3 cases, values match

- [x] 1.2 Create migration `create_cargos_pep_table`
  - Columns: id, pais_codigo (char 2 FK→paises.codigo), nombre (string 150), categoria (string 50), entidad_tipo (string 10), activo (bool default true), timestamps
  - Index: composite (pais_codigo, activo)
  - Acceptance: migration runs, table exists with correct columns and FK

- [x] 1.3 Create migration `create_entidades_publicas_table`
  - Columns: id, pais_codigo (char 2 FK), nombre (string 150), sigla (string 30 nullable), activo (bool default true), timestamps
  - Index: composite (pais_codigo, activo)
  - Acceptance: migration runs, table exists

- [x] 1.4 Create migration `add_gemini_entidad_tipo_to_resultados_scraping`
  - Add column: string('gemini_entidad_tipo', 15)->nullable()->after('gemini_categoria')
  - Acceptance: migration runs, column added

- [x] 1.5 Run `php artisan migrate` and verify all 3 migrations succeed
  - Acceptance: `php artisan migrate` exits 0, tables exist in DB

## Phase 2: Models (TDD — RED → GREEN → refactor)

- [x] 2.1 RED: Write failing test `tests/Unit/Models/CargoPepTest.php`
  - Test: scopes (active, forCountry, byEntidadTipo), relationship pais(), casts (entidad_tipo→EntidadTipo, activo→boolean)
  - Acceptance: tests fail initially (no model yet)

- [x] 2.2 GREEN: Implement `app/Models/CargoPep.php`
  - $fillable: pais_codigo, nombre, categoria, entidad_tipo, activo
  - $casts: entidad_tipo=>EntidadTipo::class, activo=>'boolean'
  - BelongsTo pais (pais_codigo→codigo)
  - Scopes: active(), forCountry($code), byEntidadTipo(EntidadTipo)
  - Acceptance: all tests pass

- [x] 2.3 REFACTOR: Run `./vendor/bin/pint` on CargoPep.php
  - Acceptance: Pint reports no changes (or clean)

- [x] 2.4 RED: Write failing test `tests/Unit/Models/EntidadPublicaTest.php`
  - Test: scopes (active, forCountry), relationship pais(), casts
  - Acceptance: tests fail initially

- [x] 2.5 GREEN: Implement `app/Models/EntidadPublica.php`
  - $fillable: pais_codigo, nombre, sigla, activo
  - $casts: activo=>'boolean'
  - BelongsTo pais, scopes: active(), forCountry($code)
  - Acceptance: all tests pass

- [x] 2.6 REFACTOR: Run `./vendor/bin/pint` on EntidadPublica.php

- [x] 2.7 Add `gemini_entidad_tipo` to ResultadoScraping $fillable array
  - Acceptance: $fillable includes 'gemini_entidad_tipo'

## Phase 3: PepCatalogService (TDD)

- [x] 3.1 RED: Write failing test `tests/Unit/Services/PepCatalogServiceTest.php`
  - Test: getCargos() returns Collection, getEntidades() returns Collection, static cache prevents N+1 (call twice, assert DB query count = 1), flushCache() clears both arrays
  - Acceptance: tests fail (no service yet)

- [x] 3.2 GREEN: Implement `app/Services/Gemini/PepCatalogService.php`
  - Private static arrays: $cargosCache[], $entidadesCache[] keyed by pais_codigo
  - public function getCargos(string $paisCodigo): Collection — caches per country
  - public function getEntidades(string $paisCodigo): Collection — caches per country
  - public static function flushCache(): void — clears both arrays
  - Acceptance: all tests pass

- [x] 3.3 REFACTOR: Run `./vendor/bin/pint` on PepCatalogService.php

## Phase 4: Seeders (TDD) ⚠️ REQUIRES USER INPUT

- [x] 4.1 RED: Write failing test `tests/Feature/Seeders/CargosPepBoliviaSeederTest.php`
  - Test: RefreshDatabase, run CargosPepBoliviaSeeder, assert count() === 97, all pais_codigo === 'BO', all activo === true, no duplicates
  - Acceptance: test fails (seeder not written yet)

- [x] 4.2 **[USER INPUT REQUIRED]** Classify 97 Bolivia positions into entidad_tipo (todas/publica/ambas) and categoria (Ejecutivo/Legislativo/Judicial/Militar/Diplomático/Autónomo)
  - Output: PHP array of 97 entries with nombre, categoria, entidad_tipo
  - Acceptance: validated list ready for seeder

- [x] 4.3 GREEN: Implement `database/seeders/CargosPepBoliviaSeeder.php`
  - Inline PHP array of 97 positions (from 4.2)
  - Uses updateOrInsert on ['pais_codigo'=>'BO', 'nombre'=>$cargo['nombre']]
  - Idempotent: safe to run twice
  - Acceptance: test passes — exactly 97 records, all BO, all active

- [x] 4.4 RED: Write failing test `tests/Feature/Seeders/EntidadesPublicasBoliviaSeederTest.php`
  - Test: run seeder twice, assert no duplicates (count equals expected), all pais_codigo === 'BO'
  - Acceptance: test fails (seeder not written)

- [x] 4.5 GREEN: Implement `database/seeders/EntidadesPublicasBoliviaSeeder.php`
  - Seeds entities: YPFB, ENDE, ENTEL, Banco Unión, UMSA, UMSS, UAB, Banco Central de Bolivia, FONDESIF, SENASAG + others
  - updateOrInsert with ['pais_codigo'=>'BO', 'nombre'=>...]
  - Acceptance: test passes, idempotent

- [x] 4.6 Register both seeders in `database/seeders/DatabaseSeeder.php`
  - Add calls to CargosPepBoliviaSeeder and EntidadesPublicasBoliviaSeeder
  - Acceptance: DatabaseSeeder calls both

## Phase 5: FiltroResultadoDTO (TDD)

- [x] 5.1 RED: Write failing test for entidadTipo field parsing
  - Test: FiltroResultadoDTO::fromArray(['entidad_tipo'=>'publica']) sets entidadTipo = 'publica'
  - Test: missing field defaults to null
  - Acceptance: test fails (field not yet in DTO)

- [x] 5.2 GREEN: Add `public ?string $entidadTipo` to `app/Services/Gemini/DTOs/FiltroResultadoDTO.php`
  - Update fromArray(): $this->entidadTipo = $data['entidad_tipo'] ?? null
  - Acceptance: tests pass

- [x] 5.3 REFACTOR: Run `./vendor/bin/pint` on FiltroResultadoDTO.php

## Phase 6: GeminiPromptBuilder Refactor (TDD)

- [x] 6.1 RED: Write failing test for constructor injection
  - Test: new GeminiPromptBuilder($pepCatalogService) works, prompt contains sections
  - Test: new GeminiPromptBuilder(null) falls back to generic
  - Acceptance: test fails (constructor not updated yet)

- [x] 6.2 GREEN: Refactor `app/Services/Gemini/GeminiPromptBuilder.php`
  - Add constructor: `public function __construct(?PepCatalogService $catalog = null)`
  - Add private method `buildDynamicPrompt(array $cargos, array $entidades, string $pais, string $categoria): string`
  - Add private method `buildGenericPrompt(string $pais, string $categoria): string`
  - Modify filtroPEP(): if $this->catalog && $cargos->isNotEmpty() → buildDynamicPrompt else → buildGenericPrompt + Log::warning()
  - Acceptance: existing tests + new tests pass

- [x] 6.3 RED: Write failing test for 3-section dynamic prompt structure
  - Test: prompt contains "SIEMPRE_PEP", "PEP_EN_ENTIDAD_PUBLICA", "PUEDE_SER_PEP" headers
  - Test: correct positions appear under each section
  - Acceptance: test fails (prompt builder not refactored)

- [x] 6.4 GREEN: Verify buildDynamicPrompt produces correct 3-section structure
  - Acceptance: test passes, prompt matches spec template

- [x] 6.5 RED: Write failing test for fallback behavior
  - Test: when catalog returns empty, prompt uses hardcoded generic + Log::warning called
  - Acceptance: test fails (no warning yet)

- [x] 6.6 GREEN: Implement fallback in buildGenericPrompt + Log::warning
  - Acceptance: test passes, warning logged when no positions for country

- [x] 6.7 REFACTOR: Run `./vendor/bin/pint` on GeminiPromptBuilder.php

## Phase 7: Integration Wiring

- [x] 7.1 Add binding in `app/Providers/AppServiceProvider.php`
  - Bind PepCatalogService as singleton or instance in register()
  - Bind GeminiPromptBuilder with constructor injection of PepCatalogService
  - Acceptance: app bootstraps without errors

- [x] 7.2 Update `tests/Unit/Gemini/GeminiPromptBuilderTest.php`
  - Add tests for dynamic prompt with mock PepCatalogService
  - Keep existing pure-unit tests (null catalog) unchanged
  - Acceptance: all tests pass

- [x] 7.3 Update `tests/Feature/Gemini/GeminiFiltroServiceTest.php`
  - Add `entidad_tipo` to fake Gemini response JSON
  - Verify entidadTipo field flows through to DTO
  - Acceptance: all tests pass

- [x] 7.4 Update `app/Models/ResultadoScraping.php` — ensure gemini_entidad_tipo in $fillable
  - Acceptance: confirmed in $fillable

## Phase 8: Verification & Cleanup

- [x] 8.1 Run full test suite: `php artisan test`
  - Acceptance: all tests pass, no failures (6 pre-existing failures excluded)

- [x] 8.2 Run `./vendor/bin/pint` on all modified/new PHP files
  - Acceptance: Pint reports no style violations on all new/modified files

- [x] 8.3 Verify N+1 prevention: `DB::enableQueryLog()`, call `filtroPEP()` 3× for same country, assert query count ≤ 2
  - Acceptance: exactly 1 query for cargos, 1 for entidades (cached after first call)

- [x] 8.4 Manual smoke test: seed Bolivia (`php artisan db:seed --class=CargosPepBoliviaSeeder`), build prompt via tinker, inspect output
  - Acceptance: prompt contains 3 sections, all 97 positions accounted for ✅

- [x] 8.5 Update `gemini_entidad_tipo` persistence in `AnalizarScrapingConFlash` job if needed
  - Check if job stores resultado_scraping with new column
  - Acceptance: entidad_tipo from Gemini response persisted to resultados_scraping.gemini_entidad_tipo
