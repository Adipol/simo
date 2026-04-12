# Design: Lemmatization — Scraper + spaCy

## Technical Approach

DB-backed lemma catalog (Laravel) + Python loader with 3-level graceful degradation + in-place `KeywordMatcher` expansion. spaCy `es_core_news_sm` loaded ONCE at class level for future verb-conjugation support; current v1 solves verb↔noun gap via explicit `families` dict. Primary insight: **the regex pattern is expanded at `__init__`, so all existing `KeywordMatcher` methods work unchanged**.

## Architecture Decisions

| # | Decision | Choice | Alternatives | Rationale |
|---|----------|--------|--------------|-----------|
| 1 | Storage | DB table `familias_lemas` + JSON `variantes` | TEXT[], hardcoded dict, extend `palabras_clave` | JSON works on SQLite (tests) + PG (prod) + MySQL (existing dual-engine). Eloquent `'array'` cast is transparent. Admin-editable. |
| 2 | Category type | PHP backed enum `CategoriaFamilia: string` | Validated string, separate table | Matches existing `EntidadTipo`/`CategoriaCorreccion` convention; type-safe; enum cast in model |
| 3 | Variantes UX | Textarea, one per line | Alpine tags input, CSV | Zero JS, works with Livewire `wire:model`, parse on `guardar()` via `explode("\n")` + trim + filter |
| 4 | Model scopes | `active()`, `byCategoria($c)` | Raw queries in component | Reusable; `byCategoria` accepts enum OR string for Python FFI flexibility |
| 5 | spaCy loading | Classmethod `_init_spacy()` with class-level cache | Module-level, per-instance | Loads exactly once across ALL instances; lazy — no cost if never instantiated; testable via class reset |
| 6 | Family loader | Separate `utils/lemma_loader.py` module | Inline in `scraper.py` | Testable in isolation; swappable fallback; no circular imports |
| 7 | DB access in Python | Reuse `DatabaseManager.get_cursor(dictionary=True)` | New connection | Honors dual-engine (MySQL/Postgres); benefits from pool |
| 8 | Graceful degradation | 3 levels, each logs WARNING, NEVER crashes | Hard fail | Scraper uptime > lemma correctness |
| 9 | Original keywords | Preserved in `self.original_keywords` | Discarded after expansion | Backward compat for code reading `matcher.original_keywords`; testability |
| 10 | Permission | `gestionar familias lemas` in `RolesPermisosSeeder`, admin-only | Gate via policy | Consistent with `gestionar sitios web` pattern |
| 11 | Seeder idempotency | `updateOrCreate(['raiz' => ...])` | `insert` or `firstOrCreate` | Can evolve variantes without wiping user edits on `raiz` collision |
| 12 | Livewire validation | `rules()` method, unique-except-on-edit | FormRequest | Consistent with `Sitios.php` (existing pattern in project) |

## Data Flow

```
  Admin UI (Livewire)              Scraper run (Python)
        │                                  │
        ▼                                  ▼
  familias_lemas  ───────────────►  lemma_loader.load_families_from_db()
  (JSON variantes)                         │
                                           ▼
                                    Dict[raiz, Set[variantes]]
                                           │
                                           ▼
                                 KeywordMatcher.__init__(keywords)
                                 ├── expand via families
                                 ├── build regex: \b(v1|v2|v3)\b
                                 └── _init_spacy() (class-level, optional)
                                           │
                                           ▼
                         find_in_text / keyword_in_title (unchanged)
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/YYYY_MM_DD_create_familias_lemas_table.php` | Create | `id`, `raiz` unique 100, `variantes` json, `categoria` 50, `activo` bool default true, timestamps; indexes on `activo`, `categoria` |
| `app/Enums/CategoriaFamilia.php` | Create | Backed string enum: `Designacion`, `Renuncia`, `Crimen` |
| `app/Models/FamiliaLema.php` | Create | `$fillable`, `$casts` (variantes→array, activo→bool, categoria→enum), scopes `active()`, `byCategoria()` |
| `database/seeders/FamiliasLemasSeeder.php` | Create | 33 families via `updateOrCreate` keyed on `raiz` |
| `database/seeders/RolesPermisosSeeder.php` | Modify | Add `gestionar familias lemas` → admin |
| `database/seeders/DatabaseSeeder.php` | Modify | Call `FamiliasLemasSeeder` |
| `app/Livewire/Scraper/FamiliasLemas.php` | Create | CRUD following `Sitios.php` pattern; `$variantesRaw` textarea → parsed on `guardar()` |
| `resources/views/livewire/scraper/familias-lemas.blade.php` | Create | Table + modal following `sitios.blade.php` |
| `routes/web.php` | Modify | `Route::get('/scraper/familias-lemas', FamiliasLemas::class)->middleware('can:gestionar familias lemas')` |
| `resources/views/layouts/app.blade.php` (or nav partial) | Modify | Menu entry gated by `@can('gestionar familias lemas')` |
| `tests/Feature/Livewire/FamiliasLemasTest.php` | Create | CRUD, gate, validation |
| `tests/Unit/Models/FamiliaLemaTest.php` | Create | Casts, scopes |
| `tests/Feature/Seeders/FamiliasLemasSeederTest.php` | Create | Count 33, distribution, idempotency |
| `scripts/scraper_v2.2/requirements.txt` | Modify | `spacy>=3.7.0`, `pytest>=7.4.0` |
| `scripts/scraper_v2.2/pytest.ini` | Create | `testpaths = tests`, `pythonpath = .` |
| `scripts/scraper_v2.2/utils/lemma_loader.py` | Create | `load_families_from_db()` with try/except → `FALLBACK_FAMILIES` |
| `scripts/scraper_v2.2/core/scraper.py` | Modify | `KeywordMatcher.__init__` + `_init_spacy` classmethod; preserve public API |
| `scripts/scraper_v2.2/tests/__init__.py` | Create | Empty |
| `scripts/scraper_v2.2/tests/conftest.py` | Create | `sample_families`, `mock_db_families` fixtures |
| `scripts/scraper_v2.2/tests/test_lemma_loader.py` | Create | DB success, DB failure → fallback, empty rows → fallback |
| `scripts/scraper_v2.2/tests/test_keyword_matcher.py` | Create | ≥10 tests including the 3 critical cases |
| `scripts/scraper_v2.2/tests/test_spacy_integration.py` | Create | spaCy available / spaCy absent graceful path |

## Interfaces / Contracts

### `app/Enums/CategoriaFamilia.php`

```php
<?php
declare(strict_types=1);
namespace App\Enums;

enum CategoriaFamilia: string
{
    case Designacion = 'designacion';
    case Renuncia = 'renuncia';
    case Crimen = 'crimen';
}
```

### Migration Blueprint

```php
Schema::create('familias_lemas', function (Blueprint $table) {
    $table->id();
    $table->string('raiz', 100)->unique();
    $table->json('variantes');
    $table->string('categoria', 50);
    $table->boolean('activo')->default(true);
    $table->timestamps();

    $table->index('activo');
    $table->index('categoria');
});
```

### `FamiliaLema` Model

```php
<?php
declare(strict_types=1);
namespace App\Models;

use App\Enums\CategoriaFamilia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class FamiliaLema extends Model
{
    use HasFactory;

    protected $table = 'familias_lemas';

    protected $fillable = ['raiz', 'variantes', 'categoria', 'activo'];

    protected $casts = [
        'variantes' => 'array',
        'activo' => 'boolean',
        'categoria' => CategoriaFamilia::class,
    ];

    public function scopeActive(Builder $q): void
    {
        $q->where('activo', true);
    }

    public function scopeByCategoria(Builder $q, CategoriaFamilia|string $cat): void
    {
        $value = $cat instanceof CategoriaFamilia ? $cat->value : $cat;
        $q->where('categoria', $value);
    }
}
```

### Livewire `FamiliasLemas` — key shape

```php
public bool $modalAbierto = false;
public ?int $editandoId = null;
public string $raiz = '';
public string $variantesRaw = '';           // textarea, one per line
public string $categoria = '';               // enum value
public bool $activo = true;
public string $busqueda = '';
public string $filtroCategoria = '';
public string $filtroActivo = '';

protected function rules(): array
{
    $unique = $this->editandoId
        ? 'unique:familias_lemas,raiz,'.$this->editandoId
        : 'unique:familias_lemas,raiz';

    return [
        'raiz' => ['required', 'string', 'max:100', $unique],
        'variantesRaw' => ['required', 'string'],
        'categoria' => ['required', Rule::enum(CategoriaFamilia::class)],
        'activo' => ['boolean'],
    ];
}

public function guardar(): void
{
    $data = $this->validate();
    $variantes = collect(explode("\n", $data['variantesRaw']))
        ->map(fn ($v) => trim($v))
        ->filter()
        ->values()
        ->all();

    abort_if(count($variantes) < 1, 422, 'Al menos una variante requerida');

    $payload = [
        'raiz' => $data['raiz'],
        'variantes' => $variantes,
        'categoria' => $data['categoria'],
        'activo' => $data['activo'],
    ];

    $this->editandoId
        ? FamiliaLema::where('id', $this->editandoId)->update($payload)
        : FamiliaLema::create($payload);

    $this->cerrarModal();
    $this->dispatch('notify', mensaje: 'Familia guardada.');
}

#[Computed]
public function categorias(): array
{
    return CategoriaFamilia::cases();
}
```

### Python `lemma_loader.py`

```python
from typing import Dict, Set
from utils.logger import get_logger

logger = get_logger(__name__)

FALLBACK_FAMILIES: Dict[str, Set[str]] = {
    "designar": {"designar", "designación", "designaciones", "designado", "designada"},
    "renunciar": {"renunciar", "renuncia", "renuncias", "renunciado"},
    "detener": {"detener", "detención", "detenido", "detenida"},
    # 5-10 minimal emergency entries
}

def load_families_from_db() -> Dict[str, Set[str]]:
    """Load active families from DB. Falls back to FALLBACK_FAMILIES on error."""
    try:
        from core.database import DatabaseManager
        import json
        with DatabaseManager.get_cursor(dictionary=True) as cursor:
            cursor.execute(
                "SELECT raiz, variantes FROM familias_lemas WHERE activo IS TRUE"
            )
            rows = cursor.fetchall()

        if not rows:
            logger.warning("No active families in DB, using fallback")
            return FALLBACK_FAMILIES

        families: Dict[str, Set[str]] = {}
        for row in rows:
            raiz = row["raiz"].lower()
            raw = row["variantes"]
            variantes = json.loads(raw) if isinstance(raw, str) else raw
            families[raiz] = {str(v).lower() for v in variantes}
        logger.info(f"Loaded {len(families)} lemma families from DB")
        return families
    except Exception as e:
        logger.warning(f"Failed to load families from DB: {e}. Using fallback.")
        return FALLBACK_FAMILIES
```

### Modified `KeywordMatcher.__init__`

```python
class KeywordMatcher:
    """Buscador de keywords con cálculo de relevancia y expansión por familias."""

    _nlp = None
    _spacy_tried = False

    @classmethod
    def _init_spacy(cls) -> None:
        if cls._spacy_tried:
            return
        cls._spacy_tried = True
        try:
            import spacy
            cls._nlp = spacy.load("es_core_news_sm")
            logger.info("spaCy es_core_news_sm loaded")
        except Exception as e:
            cls._nlp = None
            logger.warning(f"spaCy not available: {e}. Using regex-only matching.")

    def __init__(self, keywords: List[str]):
        self._init_spacy()

        from utils.lemma_loader import load_families_from_db
        self.families: Dict[str, Set[str]] = load_families_from_db()

        self.original_keywords = [kw.lower().strip() for kw in keywords if kw.strip()]
        if not self.original_keywords:
            raise ValueError("Se requiere al menos una keyword válida")

        expanded: Set[str] = set()
        for kw in self.original_keywords:
            if kw in self.families:
                expanded.update(self.families[kw])
            else:
                expanded.add(kw)

        self.keywords = sorted(expanded)
        escaped = [re.escape(kw) for kw in self.keywords]
        self.pattern = re.compile(r"\b(" + "|".join(escaped) + r")\b", re.IGNORECASE)
        logger.info(
            f"KeywordMatcher: {len(self.original_keywords)} keywords → "
            f"{len(self.keywords)} variants"
        )
```

All other methods (`find_in_text`, `keyword_in_title`, `extract_context`, `calculate_relevance`) **unchanged** — the expanded regex does the work.

**Important edge case**: `keyword_in_title(title, keyword)` currently rebuilds a regex from the raw `keyword` parameter. Must be updated to expand that keyword via `self.families` if present, otherwise noun variants won't match in per-keyword calls:

```python
def keyword_in_title(self, title: str, keyword: str) -> bool:
    if not title:
        return False
    variants = self.families.get(keyword.lower(), {keyword.lower()})
    escaped = "|".join(re.escape(v) for v in variants)
    return bool(re.search(r"\b(" + escaped + r")\b", title, re.IGNORECASE))
```

Same fix for `extract_context`.

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| PHP Unit | Model casts, scopes, enum cast | `RefreshDatabase`, factory, SQLite in-memory |
| PHP Unit | Seeder count/distribution/idempotency | Run seeder twice, assert count |
| PHP Feature | Livewire CRUD + gate + validation | `Livewire::actingAs($admin)->test(...)`; test 403 for supervisor |
| Python Unit | `load_families_from_db` success path | `monkeypatch` `DatabaseManager.get_cursor` |
| Python Unit | `load_families_from_db` DB failure → fallback | `monkeypatch` to raise; assert returns `FALLBACK_FAMILIES` |
| Python Unit | `load_families_from_db` empty rows → fallback | Mock cursor returns `[]` |
| Python Unit | `KeywordMatcher` expansion with fixture families | `monkeypatch` `lemma_loader.load_families_from_db` |
| Python Unit | **3 critical cases** (designación, designó, designado) | Direct assertions on `keyword_in_title` |
| Python Unit | False-positive prevention ("Designer" ≠ "designar") | Word-boundary assertion |
| Python Unit | Unknown keyword passes through unchanged | Assert regex contains only raw kw |
| Python Unit | Multiple keywords expand independently | `["designar","renunciar"]` → both families |
| Python Unit | spaCy absent graceful path | `monkeypatch` `_init_spacy` to fail; matcher still works |
| Python Unit | spaCy singleton | Two `KeywordMatcher()` instances, assert `_init_spacy` runs once (class flag) |
| Python Unit | `find_in_text` returns matched variant, not raiz | Text `"designó"` → `["designó"]` |

**Target**: ≥15 Python tests (spec NF-TEST-1) + full PHP coverage matching project >85% standard.

## Concrete Critical Test Cases

```python
@pytest.mark.parametrize("title,expected", [
    ("Gobierno designa nuevo ministro", True),
    ("Presidente designó a su gabinete", True),            # REQ-1
    ("Designación de nuevos funcionarios", True),           # REQ-2
    ("Ministro designado deja el cargo", True),             # REQ-3
    ("Designer de modas", False),                           # false-positive guard
    ("El clima está lindo hoy", False),
])
def test_designar_family_matches(mock_db_families, title, expected):
    matcher = KeywordMatcher(["designar"])
    assert matcher.keyword_in_title(title, "designar") is expected
```

## Migration / Rollout

1. `php artisan migrate` — creates table (idempotent)
2. `php artisan db:seed --class=FamiliasLemasSeeder` — seeds 33 families
3. `php artisan db:seed --class=RolesPermisosSeeder` — adds new permission
4. Python venv: `pip install -r requirements.txt && python -m spacy download es_core_news_sm`
5. Restart scraper cron/worker — picks up families automatically

No feature flag needed — graceful degradation IS the safety mechanism. If any step fails, scraper continues exactly as today (regex-only).

## Open Questions

- [ ] Should `keyword_in_title` and `extract_context` be updated to use `self.families`? **Proposed: YES** (see Interfaces section) — otherwise per-keyword calls bypass expansion and REQ-6 scenarios fail. Needs confirmation from sdd-tasks.
- [ ] Should `find_in_text` return the raiz (canonical) alongside the matched variant for relevance scoring? Current spec REQ-13 says return the variant. Keep as-is for backward compat.
- [ ] Does MySQL engine path (`DB_TYPE=mysql`) support `JSON` column parsing in `psycopg2`-style dict rows? `mysql-connector` returns JSON as string — `lemma_loader` handles both via `isinstance(raw, str)` guard.
