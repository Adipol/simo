# Proposal: Lemmatization — Scraper + spaCy

> ⚠️ **Naming note**: This is the ACTUAL Python-side lemmatization change. A prior change
> (`lematizacion-pep-opi`, archived 2026-04-11) was mis-named — it covered PEP catalog tables,
> not lemmatization. This change is what was deferred as "FASE 3" and is now urgent.
> Primary deliverable: `KeywordMatcher("designar")` matches articles with "designación".

## Intent

The Python scraper uses pure regex matching. Keyword `"designar"` matches only the literal string
"designar" — it misses "designó", "designado", "designación" (past tense, past participle, derived
noun). Real news articles overwhelmingly use these derived forms. The scraper silently skips them,
producing false negatives on critical PEP/OPI detection.

## Scope

### In Scope

**Laravel (PHP)**
- Migration `create_familias_lemas_table` — columns: `id`, `raiz`, `variantes` (JSON, not TEXT[] — SQLite compat), `categoria`, `activo`, `created_at`, `updated_at`; unique on `raiz`; indexes on `activo`, `categoria`
- Model `app/Models/FamiliaLema.php` — `$fillable`, `$casts` (variantes→array, activo→bool), scopes: `active()`, `byCategoria()`
- Seeder `FamiliasLemasSeeder` — 33 families (9 designacion + 8 renuncia + 16 crimen)
- Permission `gestionar familias lemas` added to `RolesPermisosSeeder` (admin only)
- Livewire `app/Livewire/Scraper/FamiliasLemas.php` — CRUD + modal, follows `Sitios.php` pattern
- Blade `resources/views/livewire/scraper/familias-lemas.blade.php` — table + modal, follows `sitios.blade.php`
- Route `/scraper/familias-lemas` in `routes/web.php`
- Navigation menu entry (admin-only gate)
- PHPUnit tests: model (fillable, casts, scopes), seeder (count, distribution), Livewire (CRUD, permission gate, validation)

**Python**
- `requirements.txt` — add `spacy>=3.7.0`, `pytest>=7.4.0`
- New `utils/lemma_loader.py` — loads families from DB via `DatabaseManager`, fallback to hardcoded dict on failure
- Modify `core/scraper.py` — `KeywordMatcher.__init__` expands keywords via families; spaCy loaded once at module level; all other methods unchanged; triple graceful degradation (no spaCy → no families → original keyword)
- New `tests/` directory: `__init__.py`, `conftest.py`, `test_lemma_loader.py`, `test_keyword_matcher.py`, `test_spacy_integration.py`
- New `pytest.ini`

### Out of Scope

- Full-content lemmatization (article body — titles only for v1)
- spaCy `PhraseMatcher` / semantic similarity matching
- Multi-language families
- Family versioning / audit trail
- Bulk import/export
- FK from `palabras_clave` → `familias_lemas`
- `GeminiPromptBuilder` integration with families
- Migration of existing `palabras_clave` to families
- Performance benchmarking

## Capabilities

### New Capabilities
- `lemma-families-catalog`: DB-backed catalog of Spanish word families (raiz → variantes), admin-managed via Livewire CRUD
- `scraper-lemma-matching`: Python scraper expands keywords via families at `KeywordMatcher` init time, enabling verb↔noun morphological matching

### Modified Capabilities
- `scraper-keywords-ui`: Admin navigation extended with families management entry (existing keyword CRUD unchanged)

## Approach

**DB catalog + Python reads + in-place KeywordMatcher expansion (Approach 1 from exploration):**

1. `familias_lemas` table stores `raiz` → `variantes[]` (JSON column)
2. `lemma_loader.py` queries DB on scraper startup, builds `dict[str, set[str]]`
3. `KeywordMatcher.__init__` expands each keyword: `"designar"` → `{"designar","designación","designaciones","designado","designada"}`
4. Regex pattern becomes `\b(designar|designación|designaciones|designado|designada)\b`
5. All existing methods (`find_in_text`, `keyword_in_title`, `extract_context`, `calculate_relevance`) unchanged
6. spaCy `es_core_news_sm` (13 MB / ~50-100 MB RAM) loaded once at module level for verb conjugation fallback

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/..._create_familias_lemas_table.php` | New | New table with JSON variantes |
| `app/Models/FamiliaLema.php` | New | Eloquent model with casts + scopes |
| `database/seeders/FamiliasLemasSeeder.php` | New | 33 seed families |
| `database/seeders/RolesPermisosSeeder.php` | Modified | Add `gestionar familias lemas` permission |
| `app/Livewire/Scraper/FamiliasLemas.php` | New | CRUD Livewire component |
| `resources/views/livewire/scraper/familias-lemas.blade.php` | New | Blade view |
| `routes/web.php` | Modified | New route `/scraper/familias-lemas` |
| `scripts/scraper_v2.2/requirements.txt` | Modified | Add spacy + pytest |
| `scripts/scraper_v2.2/utils/lemma_loader.py` | New | DB loader with fallback |
| `scripts/scraper_v2.2/core/scraper.py` | Modified | KeywordMatcher.__init__ expansion |
| `scripts/scraper_v2.2/tests/` | New | pytest suite (≥15 tests) |
| `scripts/scraper_v2.2/pytest.ini` | New | pytest config |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| spaCy model not installed in prod | Medium | Triple graceful degradation; documented install step |
| Memory pressure from spaCy (~100 MB) | Low | `es_core_news_sm` only; load once at module level |
| False positives from broad families | Medium | Carefully curated 33 families; pytest coverage |
| SQLite `TEXT[]` incompatibility | Medium | JSON column (`->json('variantes')`); Eloquent cast handles it |
| No Python tests today → regressions | High | Introduce pytest as part of this very change |
| DB query failure during scraper run | Low | Fall back to hardcoded dict (minimal safety net) |
| Family data drift (DB vs fallback) | Low | DB is source of truth; fallback is emergency-only |

## Rollback Plan

1. `php artisan migrate:rollback` — drops `familias_lemas` table
2. `git revert` — restores `scraper.py`, removes Livewire component, model, seeder
3. Python: remove `spacy` from `requirements.txt` or `pip uninstall spacy`
4. Original `KeywordMatcher` (pure regex) resumes immediately — no data loss
5. `palabras_clave` table is untouched throughout

## Dependencies

- `scraper_v2.2` virtualenv exists and is active
- PostgreSQL 17 running (dev + prod); SQLite for tests
- Python 3.13 available in venv
- `pip install spacy && python -m spacy download es_core_news_sm` run in venv before deploying

## Success Criteria

- [ ] Migration runs clean on SQLite (tests) AND PostgreSQL (dev + prod)
- [ ] `FamiliasLemas` UI at `/scraper/familias-lemas` visible ONLY to admin
- [ ] Admin can Create / Read / Update / Delete families via UI
- [ ] Seeder inserts exactly 33 families (9 designacion + 8 renuncia + 16 crimen)
- [ ] **Primary**: `KeywordMatcher(["designar"])` + title `"Designación de nuevo ministro"` → match ✅
- [ ] `KeywordMatcher(["designar"])` + title `"Presidente designó a su gabinete"` → match ✅
- [ ] `KeywordMatcher(["designar"])` + title `"Ministro designado deja el cargo"` → match ✅
- [ ] pytest suite runs and passes ≥ 15 Python tests
- [ ] Scraper with spaCy disabled falls back gracefully (no crash)
- [ ] Scraper with DB unreachable falls back to hardcoded dict (no crash)
- [ ] Existing 402 PHP tests still pass (zero regressions)
- [ ] `./vendor/bin/pint` reports no violations
