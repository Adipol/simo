# Exploration: spaCy Lemmatization for Python Scraper

## Topic
Add spaCy lemmatization to `KeywordMatcher` so keyword "designar" matches article titles containing "designación", "designó", "designado", etc.

---

## 1. Current State

### How KeywordMatcher Works (lines 285-376 of `core/scraper.py`)

```python
class KeywordMatcher:
    def __init__(self, keywords: List[str]):
        escaped = [re.escape(kw) for kw in self.keywords]
        self.pattern = re.compile(r"\b(" + "|".join(escaped) + r")\b", re.IGNORECASE)
```

**What it does**:
- Takes a list of keywords (e.g., `["designar", "nombrar"]`)
- Builds a regex pattern: `\b(designar|nombrar)\b` with word boundaries
- Matches EXACT forms only — case-insensitive

**Why it fails for verb/noun variants**:
- `\bdesignar\b` matches `"designar"` but NOT `"designó"`, `"designación"`, `"designado"`
- The `\b` boundary matches the position between a word char and non-word char
- "designó" ends with "ó" (a word char), so `\bdesignó\b` would try to match that literal string
- There's no morphological analysis — it's pure string/regex matching

### Real-world failure example

| Article title | Keyword "designar" | Result |
|--------------|-------------------|--------|
| "Gobierno designa nuevo ministro" | ✅ Matches | OK |
| "Presidente designó a su gabinete" | ❌ No match | **BUG** |
| "Designación de nuevos funcionarios" | ❌ No match | **BUG** |
| "Ministro designado deja el cargo" | ❌ No match | **BUG** |

### Keywords come from PostgreSQL

```python
# database.py line 206-256
def get_keywords(pais: str = None, categoria: str = None) -> List[str]:
    query = """SELECT DISTINCT pc.keyword FROM palabras_clave pc WHERE ..."""
```

Only the raw `keyword` field is fetched — no variants, no lemmas.

---

## 2. Problem Statement: The Verb↔Noun Morphology Gap

### What spaCy DOES handle

spaCy's Spanish model (`es_core_news_sm`) lemmatizes verb conjugations to their infinitive:

```
"designó"     → "designar"
"designaron"  → "designar"
"designando"  → "designar"
"designado"   → "designar"
"nombramos"   → "nombrar"
```

### What spaCy DOES NOT handle (THE HARD PROBLEM)

spaCy does NOT connect related nouns to their verb roots:

```
"designación" → "designación"   (stays as noun, doesn't become "designar")
"nombramiento"→ "nombramiento"  (doesn't become "nombrar")
"renuncia"    → "renuncia"      (doesn't become "renunciar")
"destitución" → "destitución"  (doesn't become "destituir")
```

**This is the core problem**. Pure lemmatization won't solve verb↔noun matching.

### Why this matters for PEP/OPI detection

| Keyword | Related forms we WANT to match |
|---------|-------------------------------|
| designar | designación, designaciones, designado, designada |
| nombrar | nombramiento, nombramientos |
| renunciar | renuncia, renuncias |
| destituir | destitución, destituciones |
| detener | detención, detenidos, detained |
| acusar | acusación, acusado |
| elegir | elección, elecciones |

---

## 3. spaCy Integration Approach

### Model selection

| Model | Size | Memory | Speed | Accuracy |
|-------|------|--------|-------|----------|
| `es_core_news_sm` | 13 MB | ~50-100 MB | Fast | Good enough for lemmas |
| `es_core_news_md` | 42 MB | ~150-200 MB | Medium | Better NER+lemmas |
| `es_core_news_lg` | 541 MB | ~500-800 MB | Slow | Best quality |

**Recommendation**: `es_core_news_sm` — smallest model sufficient for lemmatization only.

### Installation

```bash
# In the existing venv
pip install spacy
python -m spacy download es_core_news_sm
```

### Lemmatization pattern

```python
import spacy

nlp = spacy.load("es_core_news_sm")

def lemmatize_text(text: str) -> List[str]:
    doc = nlp(text.lower())
    return [token.lemma_ for token in doc if token.is_alpha and not token.is_stop]
```

### Processing strategy

**Option A**: Lemmatize keywords ONCE on startup, then regex match
- Pros: Fast at runtime, lemmas computed once
- Cons: Can't capture noun↔verb relationships

**Option B**: Lemmatize text at search time
- Pros: Captures all morphological variants
- Cons: 10-50ms per page × 200 pages = 2-10s extra per run

**Option C**: Hybrid — lemmatize titles (small), use regex for content
- Pros: Good balance
- Cons: Inconsistent behavior

**Recommendation**: Option B for titles (where it matters most), with fallback to regex for content in fast mode.

---

## 4. Storage Decision: Where Do Lemma Families Live?

### Option A: Hardcoded Python dictionary (NO DB change)

```python
# utils/lemma_families.py
LEMMA_FAMILIES = {
    "designar": {"designar", "designación", "designaciones", "designado", "designada"},
    "nombrar": {"nombrar", "nombramiento", "nombramientos"},
    "renunciar": {"renunciar", "renuncia", "renuncias"},
    # ... etc
}
```

- **Pros**: Simple, no migration needed, fast lookup
- **Cons**: Not editable without code deploy, must be maintained manually

### Option B: New DB table `familias_lemas`

```sql
CREATE TABLE familias_lemas (
    id SERIAL PRIMARY KEY,
    raiz VARCHAR(100) NOT NULL,           -- "designar"
    variante VARCHAR(100) NOT NULL,        -- "designación"
    categoria VARCHAR(50),                 -- "PEP" or "OPI"
    UNIQUE(raiz, variante)
);

CREATE INDEX idx_familias_raiz ON familias_lemas(raiz);
```

- **Pros**: Editable via UI, can add new families on-the-fly
- **Cons**: Extra migration, more complex query logic

### Option C: Extend `palabras_clave` with `variantes TEXT[]`

```sql
ALTER TABLE palabras_clave ADD COLUMN variantes TEXT[];
UPDATE palabras_clave SET variantes = ARRAY['designación', 'designaciones'] WHERE keyword = 'designar';
```

- **Pros**: Single table, PostgreSQL native array
- **Cons**: Migration, array operations are less flexible

### Option D: New `familias_lemas` + pointer from `palabras_clave`

```sql
ALTER TABLE palabras_clave ADD COLUMN familia_id INTEGER REFERENCES familias_lemas(id);
```

- **Pros**: Clean normalization, easiest to query
- **Cons**: Two-table join, more complex

### Recommendation

**Option B (new `familias_lemas` table)** for v2, with **Option A (hardcoded)** as v1 seed data.

Rationale:
1. The lemma families list is FINITE and stable for PEP/OPI domain
2. Hardcoded dict works as proof-of-concept v1
3. DB table enables UI editing later without code changes
4. Hybrid approach: scraper uses hardcoded dict, but admin can add via UI

---

## 5. Approaches Comparison

### Approach 1: Modify KeywordMatcher in-place (inline lemmatization)

Add spaCy to `KeywordMatcher.__init__`, lemmatize both keywords and text at match time.

```python
class KeywordMatcher:
    def __init__(self, keywords: List[str]):
        self.nlp = spacy.load("es_core_news_sm")
        self.lemma_families = LEMMA_FAMILIES  # or load from DB
        
        # Build expanded keyword set from families
        expanded = set()
        for kw in keywords:
            if kw in self.lemma_families:
                expanded.update(self.lemma_families[kw])
            else:
                expanded.add(kw)
        
        escaped = [re.escape(k) for k in expanded]
        self.pattern = re.compile(r"\b(" + "|".join(escaped) + r")\b", re.IGNORECASE)
```

- **Pros**: Minimal code change, backward compatible
- **Cons**: SpaCy loads in every KeywordMatcher instance
- **Complexity**: Low

### Approach 2: Two-stage matcher

Stage 1: Lemmatize title → collect all lemma forms
Stage 2: Match against expanded keyword family regex

```python
def _expand_keywords(self, keywords: List[str]) -> Set[str]:
    """Expand keywords to include all family members."""
    expanded = set()
    for kw in keywords:
        if kw in LEMMA_FAMILIES:
            expanded.update(LEMMA_FAMILIES[kw])
        else:
            # Also lemmatize the keyword itself
            doc = self.nlp(kw)
            lemmas = {t.lemma_ for t in doc if t.is_alpha}
            expanded.update(lemmas)
    return expanded
```

- **Pros**: Clean separation, testable
- **Cons**: Slightly more complex flow
- **Complexity**: Medium

### Approach 3: Full spaCy semantic matching

Replace regex with spaCy `PhraseMatcher` or semantic similarity.

```python
from spacy.matcher import PhraseMatcher

def find_in_text(self, text: str) -> List[str]:
    doc = self.nlp(text.lower())
    matches = self.phrase_matcher(doc)
    return [doc[start:end].text for match_id, start, end in matches]
```

- **Pros**: Handles morphology automatically, more powerful
- **Cons**: Slower, spaCy model required, more memory
- **Complexity**: High

### Recommendation

**Approach 1** for v1 (in-place modification with hardcoded families), evolve to **Approach 2** if DB storage is added.

---

## 6. Performance Analysis

### Memory footprint

| Component | RAM usage |
|-----------|-----------|
| spaCy model (`es_core_news_sm`) | ~50-100 MB |
| Python process base | ~30-50 MB |
| Existing scraper (Selenium + requests) | ~100-200 MB |
| **Total with spaCy** | ~180-350 MB |

### Latency impact

| Operation | Without spaCy | With spaCy |
|-----------|---------------|------------|
| Title lemmatization (1 title) | 0 ms | 10-30 ms |
| Content lemmatization (1 page) | 0 ms | 30-80 ms |
| Full site (200 links) | ~60s | ~70-80s |

### Mitigation strategies

1. **Load spaCy model ONCE** at module level (not per-instance)
2. **Fast mode**: Lemmatize only titles, skip content in fast mode
3. **Graceful degradation**: If spaCy fails to load, fall back to regex-only matching
4. **Lazy load**: Try to import spaCy; if it fails, log warning and continue

---

## 7. Testing Strategy (Critical Gap)

### Current state: NO Python tests exist

This is a **major risk**. Without tests, we can't verify:
- Lemmatization correctness
- KeywordMatcher behavior
- Graceful degradation

### Recommended approach

```bash
# Add to requirements.txt
pytest>=7.4.0
pytest-asyncio>=0.21.0  # if async code
```

### Tests to write

```python
# tests/test_lemma_families.py
def test_verb_conjugations():
    """designar → designar (infinitive)"""
    assert lemmatize("designó") == "designar"

def test_noun_variant_not_lemmatized():
    """designación stays as designación (spaCy limitation)"""
    assert lemmatize("designación") == "designación"

def test_family_expansion():
    """KeywordMatcher expands families"""
    matcher = KeywordMatcher(["designar"])
    # Should match "designación"
    assert matcher.keyword_in_title("Designación de nuevo ministro", "designar")

def test_graceful_degradation():
    """If spaCy fails, falls back to regex"""
    # Mock spacy to raise ImportError
    # Verify scraper still works with regex-only matching
```

### Test data needed

```python
TEST_CASES = [
    # (text, expected_match, keyword)
    ("Gobierno designa nuevo ministro", True, "designar"),
    ("Presidente designó a su gabinete", True, "designar"),  # verb past tense
    ("Designación de nuevos funcionarios", True, "designar"),  # noun
    ("Ministro designado deja el cargo", True, "designar"),  # past participle
    ("Renuncia del primer ministro", True, "renunciar"),  # noun from verb
    ("El gobierno destituyó al director", True, "destituir"),  # verb past tense
]
```

---

## 8. Risks

### Risk 1: False positives
Expanding keyword families increases recall but may decrease precision.

Example: "elección" (election) could match "designar" family if we add too many variants.

**Mitigation**: Carefully curate family lists, test with real data.

### Risk 2: spaCy model loading failure
If spaCy model is missing or corrupted, scraper crashes.

**Mitigation**: Wrap model loading in try/except, fall back to regex-only if spaCy unavailable.

```python
try:
    import spacy
    nlp = spacy.load("es_core_news_sm")
    SPACY_AVAILABLE = True
except OSError:
    logger.warning("spaCy model not found, using regex-only matching")
    SPACY_AVAILABLE = False
```

### Risk 3: Memory pressure
Running spaCy in a memory-constrained environment (VPS with 512MB RAM) could cause OOM.

**Mitigation**: Use smallest model (`es_core_news_sm`), load once, monitor memory.

### Risk 4: No tests = regressions
Without tests, future changes could break lemmatization silently.

**Mitigation**: Add pytest before implementing. At minimum, test the family expansion logic.

### Risk 5: Noun↔verb gap not solved
Even with spaCy, "designación" won't match "designar" without explicit family mapping.

**Mitigation**: Accept this limitation, use hardcoded families to bridge the gap.

---

## 9. Scope Boundaries

### IN for v1
- ✅ Add spaCy `es_core_news_sm` to requirements.txt
- ✅ Add `LEMMA_FAMILIES` hardcoded dict in `utils/lemma_families.py`
- ✅ Modify `KeywordMatcher` to expand keywords via families
- ✅ Graceful degradation if spaCy unavailable
- ✅ Basic pytest tests for lemma expansion

### OUT for v1
- ❌ DB migration for `familias_lemas` table (v2)
- ❌ UI for editing lemma families (v2)
- ❌ Changing `resultados_scraping.keyword` field semantics (it stores the MATCHED form, not the original keyword)
- ❌ Full content lemmatization in fast mode
- ❌ Integration with Laravel side

### Backward compatibility
- Existing `resultados_scraping.keyword` field continues to store what was found (e.g., "designación")
- The `palabras_clave.keyword` field still holds the root form (e.g., "designar")
- No breaking changes to DB schema

---

## 10. Integration with KeywordMatcher Interface

### Current interface to preserve

```python
class KeywordMatcher:
    def find_in_text(self, text: str) -> List[str]
    def keyword_in_title(self, title: str, keyword: str) -> bool
    def extract_context(self, text: str, keyword: str) -> str
    def calculate_relevance(self, keyword, title, content, is_article_content) -> Tuple[int, bool, bool]
```

### Changes needed

1. `__init__`: Load spaCy (once), load families, expand keyword regex
2. `find_in_text`: Same regex matching, no change needed (expansion is in regex)
3. `keyword_in_title`: Same behavior, regex expanded at init time

### Key insight

The regex pattern itself is expanded at `__init__` time. If `keyword="designar"` expands to family `{"designar", "designación", "designaciones", "designado"}`, the regex becomes:

```
\b(designar|designación|designaciones|designado)\b
```

So the rest of the class methods don't need to change — they just match against a bigger pattern.

---

## 11. Seed Data for Lemma Families

```python
LEMMA_FAMILIES: Dict[str, Set[str]] = {
    # Designations (positive events)
    "designar": {"designar", "designación", "designaciones", "designado", "designada"},
    "nombrar": {"nombrar", "nombramiento", "nombramientos", "nombrado", "nombrada"},
    "posesionar": {"posesionar", "posesión", "toma de posesión"},
    "asumir": {"asumir", "asunción"},
    "juramentar": {"juramentar", "juramento"},
    "elegir": {"elegir", "elección", "elecciones", "elegido", "elegida"},
    "ratificar": {"ratificar", "ratificación"},
    "confirmar": {"confirmar", "confirmación"},
    "incorporar": {"incorporar", "incorporación"},
    
    # Departures (negative events)
    "renunciar": {"renunciar", "renuncia", "renuncias", "renunciado"},
    "destituir": {"destituir", "destitución", "destituciones", "destituido"},
    "cesar": {"cesar", "cese", "ceses", "cesado"},
    "remover": {"remover", "remoción", "removido"},
    "reemplazar": {"reemplazar", "reemplazo", "reemplazado"},
    "sustituir": {"sustituir", "sustitución", "sustituido"},
    "dejar": {"dejar el cargo", "dejó el cargo", "deja el cargo"},
    "suceder": {"suceder", "sucesión", "sucesor", "sucesora"},
    
    # OPI (crime/investigation)
    "detener": {"detener", "detención", "detenciones", "detenido", "detenidos", "detenida"},
    "imputar": {"imputar", "imputación", "imputaciones", "imputado", "imputada"},
    "acusar": {"acusar", "acusación", "acusado", "acusada"},
    "procesar": {"procesar", "proceso", "procesado", "procesada"},
    "investigar": {"investigar", "investigación", "investigado", "investigada"},
    "allanar": {"allanar", "allanamiento", "allanamientos"},
    "estafar": {"estafar", "estafa", "estafador", "estafadores"},
    "corromper": {"corromper", "corrupción", "corrupto", "corrupta"},
    "fraudar": {"fraude", "fraudulento", "fraudulenta"},
    "lavar": {"lavado", "lavado de activos", "lavado de dinero", "lavar"},
    "malversar": {"malversar", "malversación", "malversado"},
    "narcotraficar": {"narcotráfico", "narcotraficante", "narcotraficantes"},
    "traficar": {"traficar", "tráfico", "traficante", "traficantes"},
    "asesinar": {"asesinar", "asesinato", "asesino", "asesina"},
    "secuestrar": {"secuestrar", "secuestro", "secuestrado", "secuestrada"},
    "extorsionar": {"extorsionar", "extorsión", "extorsionador", "extorsionadores"},
}
```

---

## Summary

| Question | Answer |
|----------|--------|
| **Spanish model?** | `es_core_news_sm` (13 MB, ~50-100 MB RAM) |
| **Verb conjugations?** | ✅ spaCy handles "designó"→"designar" |
| **Noun↔verb mapping?** | ❌ spaCy CANNOT do this — needs explicit families |
| **Storage?** | Hardcoded dict v1, DB table v2 |
| **Integration?** | Modify KeywordMatcher in-place |
| **Backward compat?** | ✅ No schema changes |
| **Tests?** | ❌ **CRITICAL GAP** — must add pytest |
| **Deployment impact?** | +50-100 MB RAM, ~10-20s extra per run |
| **Graceful degradation?** | ✅ Yes — fall back to regex if spaCy unavailable |
