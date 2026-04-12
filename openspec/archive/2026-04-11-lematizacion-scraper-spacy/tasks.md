# Tasks: lematizacion-scraper-spacy

## Phase 1: PHP Infrastructure (Enum + Migration + Model)

- [x] 1.1 Create `app/Enums/CategoriaFamilia.php` — BackedEnum with cases `Designacion('designacion')`, `Renuncia('renuncia')`, `Crimen('crimen')`; `declare(strict_types=1)`
- [x] 1.2 Create migration `YYYY_MM_DD_create_familias_lemas_table.php` — columns: `id`, `raiz` VARCHAR(100) UNIQUE, `variantes` JSON, `categoria` VARCHAR(50), `activo` BOOL DEFAULT true, `timestamps`; indexes on `activo`, `categoria`
- [x] 1.3 Run migration on SQLite (tests): `php artisan migrate`
- [x] 1.4 Create `app/Models/FamiliaLema.php` — `$table`, `$fillable=['raiz','variantes','categoria','activo']`, `$casts=['variantes'=>'array','activo'=>'boolean','categoria'=>CategoriaFamilia::class]`, scopes `active()`, `byCategoria(CategoriaFamilia|string $cat)`
- [x] 1.5 Create `tests/Unit/Models/FamiliaLemaTest.php` — RED: test `variantes` cast returns array, `activo` cast returns bool, `categoria` cast returns enum, `active()` scope filters correctly, `byCategoria()` scope filters correctly
- [x] 1.6 GREEN: implement FamiliaLema model correctly → all tests pass
- [x] 1.7 GREEN: run migration on PostgreSQL (dev env) to verify

## Phase 2: Seeder (TDD)

- [x] 2.1 RED: test `FamiliasLemasSeeder` creates exactly 33 families after one run
- [x] 2.2 RED: test idempotency — running seeder twice results in 33 families (no duplicates)
- [x] 2.3 RED: test categoria distribution: 9 designacion + 8 renuncia + 16 crimen
- [x] 2.4 Create `database/seeders/FamiliasLemasSeeder.php` — 33 `updateOrCreate(['raiz'=>$raiz],$data)` calls; data from proposal section "Seeder Data"
- [x] 2.5 GREEN: seeder passes all 3 tests
- [x] 2.6 Register `FamiliasLemasSeeder::class` in `DatabaseSeeder::run()` via `$this->call([...])`
- [x] 2.7 Run `php artisan db:seed --class=FamiliasLemasSeeder` — verify 33 rows in DB

## Phase 3: Permission (TDD)

- [x] 3.1 RED: test `gestionar familias lemas` permission NOT exists before seeder runs
- [x] 3.2 RED: test admin role HAS `gestionar familias lemas` permission after seeding
- [x] 3.3 RED: test supervisor role does NOT have `gestionar familias lemas`
- [x] 3.4 RED: test operador role does NOT have `gestionar familias lemas`
- [x] 3.5 GREEN: add `Permission::firstOrCreate(['name'=>'gestionar familias lemas'])` to `RolesPermisosSeeder.php`
- [x] 3.6 Add `gestionar familias lemas` to `$admin->syncPermissions([...])` array
- [x] 3.7 GREEN: all permission tests pass; run `./vendor/bin/pint`

## Phase 4: Livewire CRUD (TDD — follow Sitios.php pattern exactly)

- [x] 4.1 RED: test unauthenticated user gets redirected from `/scraper/familias-lemas`
- [x] 4.2 RED: test supervisor cannot access `/scraper/familias-lemas` (403)
- [x] 4.3 RED: test admin CAN access `/scraper/familias-lemas`
- [x] 4.4 RED: test admin can create family with valid data (raiz, variantesRaw, categoria)
- [x] 4.5 RED: test create with duplicate raiz → validation error "raiz already exists"
- [x] 4.6 RED: test create with empty variantesRaw → validation error
- [x] 4.7 RED: test admin can edit existing family
- [x] 4.8 RED: test admin can delete family
- [x] 4.9 RED: test toggleActivo flips activo flag
- [x] 4.10 RED: test search/filter by categoria returns correct families
- [x] 4.11 Create `app/Livewire/Scraper/FamiliasLemas.php` — mirror `Sitios.php`: `WithPagination`, `$modalAbierto`, `$editandoId`, `$raiz`, `$variantesRaw`, `$categoria`, `$activo`, `$busqueda`, `$filtroCategoria`, `$filtroActivo`; `rules()` with unique-except-on-edit; `abrirModal()`, `cerrarModal()`, `guardar()` (parse variantesRaw via `explode("\n")`), `toggleActivo()`; `#[Computed] categorias()` returns `CategoriaFamilia::cases()`
- [x] 4.12 Create `resources/views/livewire/scraper/familias-lemas.blade.php` — mirror `sitios.blade.php`: header with "Familias de lemas", filtros section (busqueda input + categoria select + activo select), table with columns (raiz, variantes preview, categoria, estado, acciones), modal form with textarea for variantesRaw (1 per line), `@can('gestionar familias lemas')` gate on create button and acciones column
- [x] 4.13 Add route in `routes/web.php`: `Route::get('/scraper/familias-lemas', FamiliasLemas::class)->middleware('can:gestionar familias lemas')`
- [x] 4.14 Add navigation menu entry in appropriate nav blade — `@can('gestionar familias lemas')` wrapper with "Familias de lemas" link to `/scraper/familias-lemas`
- [x] 4.15 GREEN: all Livewire tests pass; run `./vendor/bin/pint`

## Phase 5: Python Infrastructure (pytest + lemma_loader)

- [x] 5.1 Add `spacy>=3.7.0` and `pytest>=7.4.0` to `scripts/scraper_v2.2/requirements.txt`
- [x] 5.2 Create `scripts/scraper_v2.2/pytest.ini` — `[pytest]`, `testpaths = tests`, `pythonpath = .`, `addopts = -v`
- [x] 5.3 Create `scripts/scraper_v2.2/tests/__init__.py` — empty file
- [x] 5.4 Create `scripts/scraper_v2.2/tests/conftest.py` — fixtures: `sample_families` (dict with ~3 families), `mock_db_families` (same data as dict), `mock_cursor` (mock object with `fetchall` returning rows)
- [x] 5.5 Create `scripts/scraper_v2.2/utils/lemma_loader.py` — import `Dict, Set` from typing, `get_logger`; define `FALLBACK_FAMILIES: Dict[str, Set[str]]` with 5-10 minimal families (designar, renunciar, detener, etc.); `load_families_from_db() -> Dict[str, Set[str]]` with try/except → return `FALLBACK_FAMILIES` on failure; log warnings at each fallback level
- [x] 5.6 Create `tests/test_lemma_loader.py` — test `load_from_db_returns_families` (mock cursor returns rows), test `load_from_db_failure_returns_fallback` (mock raises), test `fallback_has_essential_families` (assert key 'designar' in FALLBACK_FAMILIES), test `empty_db_returns_fallback` (mock cursor returns [])
- [x] 5.7 Create `tests/test_keyword_matcher.py` — test `expansion_with_families` (designar → includes designacion, designado), test `no_expansion_without_family`, test `keyword_in_title_matches_noun` (designación), test `keyword_in_title_matches_past_tense` (designó), test `keyword_in_title_matches_participle` (designado), test `keyword_in_title_no_match_substring` (designer ≠ designar), test `find_in_text_returns_matched_variant` (returns "designación" not "designar"), test `extract_context_around_variant`, test `multiple_keywords_both_expanded`, test `case_insensitive_matching`, test `graceful_degradation_no_spacy`, test `graceful_degradation_no_db`
- [x] 5.8 Create `tests/test_spacy_integration.py` — test `spacy_model_loads_successfully` (if available), test `spacy_lemmatizes_verb_conjugation` (designó → designar), test `spacy_does_not_lemmatize_noun` (designación stays), test `spacy_classmethod_singleton` (loaded once), test `spacy_failure_sets_unavailable_flag`
- [x] 5.9 Run `pytest` from `scripts/scraper_v2.2/` — verify ≥15 tests pass

## Phase 6: Python KeywordMatcher Modification (TEST-FIRST — CRITICAL)

- [x] 6.1 **CRITICAL BUG FIX**: Modify `keyword_in_title()` in `core/scraper.py` — expand keyword via `self.families` before regex build (currently rebuilds from raw keyword, bypassing expansion)
- [x] 6.2 **CRITICAL BUG FIX**: Modify `extract_context()` in `core/scraper.py` — expand keyword via `self.families` before pattern match
- [x] 6.3 Add `_init_spacy()` classmethod to `KeywordMatcher` — singleton spaCy loader with `_nlp` class var and `_spacy_tried` guard flag; try `spacy.load('es_core_news_sm')`, set `_nlp=None` on failure; log WARNING appropriately
- [x] 6.4 Modify `KeywordMatcher.__init__` — call `cls._init_spacy()`; load families from `lemma_loader.load_families_from_db()` into `self.families`; expand original keywords via families dict; build expanded regex pattern; store `self.original_keywords` for backward compat
- [x] 6.5 Ensure `find_in_text()` returns ACTUAL matched variant string (not raiz) — already correct via `match.group(1)`
- [x] 6.6 Verify the 3 critical test cases pass: `"Designación de nuevo ministro"` + `"designar"` → True; `"Presidente designó a su gabinete"` + `"designar"` → True; `"Ministro designado deja el cargo"` + `"designar"` → True

## Phase 7: Integration Tests

- [x] 7.1 PHP: run `php artisan test` — all 402+ existing tests pass + new tests pass
- [x] 7.2 Python: run `pytest` from `scripts/scraper_v2.2/` — all ≥15 tests pass
- [ ] 7.3 PHP: `FamiliasLemas` UI end-to-end — login as admin → create family → verify in DB
- [x] 7.4 Python: full flow integration — mock DB → load families → create KeywordMatcher → match title
- [x] 7.5 Verify graceful degradation: mock spaCy failure → scraper continues
- [x] 7.6 Verify graceful degradation: mock DB failure → fallback dict used
- [x] 7.7 Verify seeder idempotency: run twice → 33 families total

## Phase 8: Polish & Cleanup

- [x] 8.1 Run `./vendor/bin/pint` on all PHP files — zero violations
- [x] 8.2 Verify all new PHP files have `declare(strict_types=1)`
- [x] 8.3 Verify Python files: type hints on public methods, docstrings on `load_families_from_db()`, PEP 8 compliant
- [x] 8.4 Verify migration has proper `down()` method (drop table)
- [x] 8.5 Add comment in `scraper.py` above `KeywordMatcher`: `# IMPLEMENTATION NOTE: keyword_in_title() and extract_context() expand keywords via self.families — see design section REQ-6`

## Phase 9: Final Verification & Documentation

- [x] 9.1 **CONCRETE TEST CASE 1**: `KeywordMatcher(["designar"])` + title `"Designación de nuevo ministro"` → `keyword_in_title()` returns True
- [x] 9.2 **CONCRETE TEST CASE 2**: `KeywordMatcher(["designar"])` + title `"Presidente designó a su gabinete"` → `keyword_in_title()` returns True
- [x] 9.3 **CONCRETE TEST CASE 3**: `KeywordMatcher(["designar"])` + title `"Ministro designado deja el cargo"` → `keyword_in_title()` returns True
- [x] 9.4 **FALSE POSITIVE GUARD**: `KeywordMatcher(["designar"])` + title `"Designer de modas famoso"` → `keyword_in_title()` returns False (word boundary test)
- [x] 9.5 Final `php artisan test` — zero failures (on new tests)
- [x] 9.6 Final `pytest` — 100% pass rate
- [x] 9.7 Document migration command: `php artisan migrate` and `php artisan db:seed --class=FamiliasLemasSeeder`
- [x] 9.8 Document Python setup: `pip install spacy && python -m spacy download es_core_news_sm` in venv
