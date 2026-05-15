# Exploration: gemini-confidence-persistence

**Change**: `gemini-confidence-persistence`
**Date**: 2026-05-15
**Status**: Root cause confirmed — single missing line in persistence step

---

## Current State

SIMO scrapes news articles and sends them to Gemini Flash for PEP/OPI classification.
The Gemini prompt explicitly requests a `confianza` field (0–100) per persona detected.
`resultados_scraping.gemini_confianza` was added in migration `2026_04_05_000001` but
has been NULL for every row in production since the column was created.

`DescartadosAnalisisService::computeConfianza()` filters `whereNotNull('gemini_confianza')`
and therefore returns zero rows. The CLI section `GEMINI CONFIANZA vs % DESCARTADO HUMANO`
is always empty, and the upcoming `T3 gemini-negative-examples-prompt` change will also
return zero negative-example rows until this is fixed.

---

## Data Flow Trace

```
GeminiFiltroService::procesarRegistro()
    → GeminiPromptBuilder::filtroPEP()        ← instructs Gemini to emit {"confianza": 0-100}
    → GeminiService::sendWithMetadata()        ← returns GeminiResponseDTO
    → FiltroResultadoDTO::fromArray()          ← parses array of PersonaDetectadaDTO
    → PersonaDetectadaDTO::fromArray()         ← maps data['confianza'] → $this->confianza (int)
    → GeminiFiltroService::persistirResultado()

        ↓ PER PERSONA — written to resultado_personas table (ResultadoPersona::create):
        confianza: $persona->confianza   ✅  (stored in resultado_personas.confianza)

        ↓ PARENT RECORD — written to resultados_scraping (record->update):
        'gemini_analyzed'    => true      ✅
        'gemini_analyzed_at' => now()     ✅
        'gemini_is_pep'      => ...       ✅
        'gemini_motivo'      => ...       ✅
        'gemini_confianza'   => ???       ❌  MISSING — never set
```

---

## Root Cause: Hypothesis Matrix Resolution

| # | Hypothesis | Result |
|---|-----------|--------|
| 1 | Gemini returns field under different key | **ELIMINATED** — prompt uses `"confianza":0-100`, Gemini responds with `confianza` per examples in prompt |
| 2 | Response parsing drops the field | **ELIMINATED** — `PersonaDetectadaDTO::fromArray()` correctly maps `data['confianza']` to `$this->confianza` |
| 3 | Persistence step skips `gemini_confianza` | **CONFIRMED** — `GeminiFiltroService::persistirResultado()` calls `$record->update([...])` without `gemini_confianza` |

**Root cause**: Hypothesis 3. The update array in `persistirResultado()` at line 114–119 of
`GeminiFiltroService.php` does not include `'gemini_confianza'`.

---

## Key Evidence

### Gemini prompt schema (GeminiPromptBuilder.php lines 117, 162)
Both `buildDynamicPrompt()` and `buildGenericPrompt()` instruct Gemini:
```
{"personas":[{"nombre":string,"cargo":string|null,"categoria":"PEP"|"OPI",
  "entidad_tipo":"publica"|"privada"|"desconocido"|null,
  "confianza":0-100, "evento":"designacion"|"renuncia"|"crimen"|null,
  "motivo":string}],"motivo_general":string}
```
Gemini IS asked to return `confianza` per persona.

### DTO chain (all correct)
- `PersonaDetectadaDTO::fromArray()` (line 26): `confianza: (int) ($data['confianza'] ?? 0)` ✅
- `FiltroResultadoDTO::fromArray()` builds array of `PersonaDetectadaDTO` ✅
- `ResultadoPersona::create(['confianza' => $persona->confianza, ...])` ✅ (written per-persona)

### Missing persistence (GeminiFiltroService.php lines 114–119)
```php
$record->update([
    'gemini_analyzed'    => true,
    'gemini_analyzed_at' => now(),
    'gemini_is_pep'      => $anyPepPassed,
    'gemini_motivo'      => $dto->motivoGeneral,
    // 'gemini_confianza' is absent — the column stays NULL forever
]);
```

### Model is ready (ResultadoScraping.php)
- `gemini_confianza` is in `$fillable` (line 28) ✅
- `gemini_confianza` is cast to `'integer'` (line 43) ✅
- No mutators or accessors interfere ✅

### Migration (2026_04_05_000001)
- Column exists as `unsignedTinyInteger` nullable ✅
- No migration drift — same column type works on SQLite and Postgres ✅

---

## Semantic Question: Which `confianza` to persist?

The Gemini response returns `confianza` **per persona**, not per article.
`resultados_scraping.gemini_confianza` is a **single column** (INT 0–100) on the article.

Two semantically defensible aggregations:

| Option | Definition | Pros | Cons |
|--------|-----------|------|------|
| **Max** | `max($persona->confianza)` over all personas | Represents "how confident is the best match" — aligns with `gemini_is_pep` logic (OR of threshold passes) | Can be misleading if 1 low-confidence PEP is in a 10-person article |
| **Single persona if exactly 1** | Only write if `count(personas) == 1`, else NULL | Avoids aggregation ambiguity | Leaves most articles NULL; breaks `DescartadosAnalisisService` for multi-persona articles |
| **Best-match persona** | confianza of the persona that drove `anyPepPassed = true` (first threshold-passer) | Semantically correct: "confidence in the PEP classification" | Slightly more complex to extract |

**Recommendation**: use **max confianza across all personas** when at least one persona exists,
or `0` when `$dto->personas` is empty. This aligns with `gemini_is_pep` being true when ANY
persona passes the threshold, and gives `DescartadosAnalisisService` a single numeric field
to work with without changing its query contract.

If the article has zero personas (pre-filtro path), `gemini_confianza` should remain NULL
(no Gemini call was made → no confianza to report).

---

## Pre-filtro Path

`PreFiltroService::shouldAnalyzeWithGemini()` can short-circuit before Gemini is called.
In that path, `gemini_confianza` stays NULL — which is semantically correct (no Gemini
analysis was performed). `DescartadosAnalisisService` already filters by
`whereNotNull('gemini_confianza')` so these rows are correctly excluded.

---

## Backfill Opportunity

Because the DTO chain is intact and `confianza` is stored correctly in `resultado_personas`
for all analyzed articles (per-persona table), it IS possible to backfill
`resultados_scraping.gemini_confianza` retroactively from existing `resultado_personas` rows.

```sql
UPDATE resultados_scraping rs
SET gemini_confianza = (
    SELECT MAX(rp.confianza)
    FROM resultado_personas rp
    WHERE rp.resultado_scraping_id = rs.id
)
WHERE rs.gemini_analyzed = true
  AND rs.gemini_confianza IS NULL
  AND EXISTS (
      SELECT 1 FROM resultado_personas rp2
      WHERE rp2.resultado_scraping_id = rs.id
  );
```

This is a **separate concern** (a one-time backfill command or migration), but it means
the 160 already-analyzed production rows can be recovered immediately without re-analyzing.
Flag this explicitly for the proposal phase.

---

## Affected Files

| File | Role | Change needed? |
|------|------|----------------|
| `app/Services/Gemini/GeminiFiltroService.php` | **Root cause** — `persistirResultado()` missing `gemini_confianza` | YES — add 1 line |
| `app/Services/Gemini/DTOs/FiltroResultadoDTO.php` | Parses personas array | NO — correct |
| `app/Services/Gemini/DTOs/PersonaDetectadaDTO.php` | Maps `confianza` field | NO — correct |
| `app/Services/Gemini/GeminiPromptBuilder.php` | Instructs Gemini schema | NO — already requests confianza |
| `app/Models/ResultadoScraping.php` | fillable + cast | NO — already ready |
| `database/migrations/2026_04_05_000001_add_gemini_fields_to_resultados_scraping_table.php` | Column definition | NO — column exists |
| `tests/Feature/Gemini/GeminiFiltroServiceTest.php` | Existing tests | ADD test — no current test asserts gemini_confianza on resultados_scraping |

---

## Minimum Change

1. **Add 1 line** to `GeminiFiltroService::persistirResultado()`:
   ```php
   $record->update([
       'gemini_analyzed'    => true,
       'gemini_analyzed_at' => now(),
       'gemini_is_pep'      => $anyPepPassed,
       'gemini_motivo'      => $dto->motivoGeneral,
       'gemini_confianza'   => $dto->personas !== [] ? max(array_map(fn($p) => $p->confianza, $dto->personas)) : null,
   ]);
   ```

2. **Add test** to `GeminiFiltroServiceTest.php` asserting `gemini_confianza` is populated.

3. **Separately** (optional T3 blocker): write a one-time backfill Artisan command or
   migration that fills `gemini_confianza` for existing rows using `resultado_personas.confianza`.

---

## Risks

- **Aggregation semantics**: choosing MAX vs. first-passer vs. single-persona may affect
  future analytics. Document the choice explicitly in proposal/spec.
- **Pre-filtro rows**: must NOT get a confianza value (they were never analyzed). Guard
  is already implicit (they return before `persistirResultado` is called).
- **GeminiAnalisisService is unrelated**: that service writes to `cambios`, not
  `resultados_scraping`. Its `gemini_confianza` gap (if any) is out of scope.
- **Backfill and forward fix are independent**: the forward fix (1 line) starts populating
  for new analyses; the backfill covers historical rows. They can ship in the same PR
  or separately — recommend same PR for atomicity.
- **400-line budget**: fix is ~5 lines of production code + 1 test. Budget risk: LOW.

---

## Approaches

### Approach A: Minimal inline fix (recommended)
Add the missing `gemini_confianza` assignment directly in `persistirResultado()`.
- Pros: 1 line change, zero interface changes, backward compatible, backfill feasible
- Cons: none
- Effort: Low

### Approach B: Add `maxConfianza()` to FiltroResultadoDTO
Extract the aggregation into a DTO method for reusability.
- Pros: semantics documented in DTO, testable in isolation
- Cons: 5 more lines, DTO change
- Effort: Low–Medium

**Recommendation**: Approach A. The aggregation is simple enough that it doesn't need its
own DTO method. If the semantics become complex later (e.g. weighted average), extract then.

---

## Ready for Proposal
**Yes.** Root cause is confirmed, minimum change is clear, risks are low, test path is
well-established (pattern exists in GeminiFiltroServiceTest). Proposal should document:
1. The 1-line fix
2. The backfill opportunity (separate Artisan command)
3. New test(s) required
4. Aggregation semantics decision (MAX)
