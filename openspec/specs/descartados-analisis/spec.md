# descartados-analisis Specification

**Change origin**: `feedback-loop-from-descartados` · **Date**: 2026-05-14

## Purpose

Aggregation engine over `resultados_scraping` discarded rows. Computes scraping precision
metrics, per-keyword and per-sitio discard breakdowns, temporal drift, and Gemini-confidence
correlation. Exposes a `getNegativeExamples()` seam for future T3 auto-feedback.

## Glossary

| Term | Definition |
|---|---|
| **Descartado** | Row with `descartado = true` — implicit negative label set by operator |
| **Relevante** | Row with `relevante = true` — explicit positive label set by operator |
| **Labeled row** | Row where `relevante = true` OR `descartado = true` (unlabeled = excluded) |
| **Precision real** | `relevantes / (relevantes + descartados)` as a percentage |
| **Drift** | Change in `% descartado` between 0–30d window and 30–60d window |
| **Negative example** | A descartado row used as context signal (basis for future T3) |

---

## Requirements

### Requirement: REQ-1 — Precision General

The system MUST calculate overall scraping precision over labeled rows in `resultados_scraping`.
Unlabeled rows (neither `relevante` nor `descartado`) MUST be excluded from the calculation.
Rows that are both `relevante = true` AND archived MUST count as relevantes.
Results MUST include the raw counts (N) alongside every percentage.

#### Scenario: Sufficient data — precision computed

- GIVEN `resultados_scraping` has ≥10 labeled rows (any mix of relevantes and descartados)
- WHEN the system computes precision general
- THEN it returns `precision_pct = relevantes / (relevantes + descartados) * 100` rounded to 1 decimal
- AND it includes `total_relevantes`, `total_descartados`, and `total_labeled` counts

#### Scenario: Insufficient data — guard triggered

- GIVEN `resultados_scraping` has fewer than 10 labeled rows
- WHEN the system computes precision general
- THEN it returns a "datos insuficientes" indicator instead of a percentage
- AND it reports the current labeled count so the operator knows the gap

#### Scenario: Unlabeled rows excluded

- GIVEN rows with `relevante = null` AND `descartado = false` exist in `resultados_scraping`
- WHEN the system computes precision general
- THEN those rows do NOT contribute to the numerator or denominator

#### Scenario: Archived-and-relevant rows count as relevantes

- GIVEN a row has `relevante = true` (regardless of archive status)
- WHEN the system computes precision general
- THEN that row counts as a relevante

---

### Requirement: REQ-2 — Ranking de Keywords por % Descartado

The system MUST report per-keyword breakdown ordered by descending `% descartado`.
Keywords with fewer than the configured minimum sample (default N=5) MUST be excluded
to prevent noise from small-N outliers. The threshold MUST be configurable.

#### Scenario: Keywords with sufficient sample appear in ranking

- GIVEN a keyword has ≥5 total rows in `resultados_scraping` (within the analysis window)
- WHEN the system computes the keyword ranking
- THEN that keyword appears with `total`, `descartados`, `relevantes`, and `pct_descartado`
- AND the ranking is ordered by `pct_descartado` DESC

#### Scenario: Keywords below minimum sample excluded

- GIVEN a keyword has fewer than the configured minimum-sample rows
- WHEN the system computes the keyword ranking
- THEN that keyword does NOT appear in the ranked list

#### Scenario: Minimum sample threshold is configurable

- GIVEN the caller sets `min_sample = 10`
- WHEN the system computes the keyword ranking
- THEN only keywords with ≥10 rows appear (not the default 5)

---

### Requirement: REQ-3 — Ranking de Sitios por % Descartado

The system MUST report per-sitio breakdown (joined with `sitios_web` for display name),
ordered by descending `% descartado`, with the same minimum-sample guard as REQ-2.

#### Scenario: Sitios with sufficient sample appear in ranking

- GIVEN a `sitio_id` has ≥5 total rows in `resultados_scraping`
- WHEN the system computes the sitio ranking
- THEN that sitio appears with its `nombre` (from `sitios_web`), `total`, `descartados`, and `pct_descartado`
- AND the ranking is ordered by `pct_descartado` DESC

#### Scenario: Sitios below minimum sample excluded

- GIVEN a `sitio_id` has fewer than the configured minimum-sample rows
- WHEN the system computes the sitio ranking
- THEN that sitio does NOT appear in the ranked list

---

### Requirement: REQ-4 — Drift Temporal por Keyword

The system MUST compare the `% descartado` for each keyword between the last 30 days
(current window) and the 30–60 days prior (previous window).
A drift ≥ +10 percentage points MUST be flagged as an alert.
If the previous window has no data for a keyword, drift MUST render as "N/D".

#### Scenario: Both periods have data — drift computed

- GIVEN a keyword has rows in both the current (0–30d) and previous (30–60d) windows
- WHEN the system computes drift
- THEN it returns `delta_ppt = pct_descartado_current - pct_descartado_previous` for that keyword
- AND delta is rounded to 1 decimal point

#### Scenario: Drift alert when noise increases significantly

- GIVEN a keyword's `delta_ppt` is ≥ 10
- WHEN the system evaluates the drift result
- THEN it marks that keyword's drift as "alerta — ruido aumenta"

#### Scenario: No previous-period data — N/D rendered

- GIVEN a keyword has rows in the current window but zero rows in the 30–60d window
- WHEN the system computes drift
- THEN it returns "N/D" for that keyword's delta instead of a computed value

---

### Requirement: REQ-5 — Correlación Confianza Gemini vs % Descartado

The system MUST bucket rows by `gemini_confianza` and report the human discard rate per bucket.
When a bucket has a human discard rate below 20%, the system MUST emit a filter recommendation.

#### Scenario: Buckets computed for all confidence ranges

- GIVEN rows with `gemini_confianza IS NOT NULL` exist in `resultados_scraping`
- WHEN the system computes confidence correlation
- THEN it returns four buckets: `90-100`, `70-89`, `50-69`, `<50`
- AND each bucket shows `total`, `descartados`, and `pct_descartado`

#### Scenario: Filter recommendation when discard rate is low

- GIVEN a bucket has `pct_descartado < 20%`
- WHEN the system evaluates confidence results
- THEN it emits a recommendation: "Threshold de filtro automático sugerido: ≥ {bucket_min}"

#### Scenario: Bucket with no rows omitted

- GIVEN no rows fall within a specific confidence bucket
- WHEN the system computes confidence correlation
- THEN that bucket is omitted from the result (not shown as 0/0)

---

### Requirement: REQ-6 — Caché Consistente con TTL=300s

The system MUST cache all heavy aggregation queries using `Cache::remember()` with TTL=300s.
Both the CLI command and the Livewire dashboard MUST consume the same cached results.
A cache-bypass mechanism MUST be available for on-demand fresh analysis.

#### Scenario: CLI uses cached results by default

- GIVEN the CLI command runs without `--no-cache`
- WHEN the service is invoked
- THEN results are served from cache if the cache key is warm (TTL not expired)

#### Scenario: Cache bypass for fresh analysis

- GIVEN the CLI command runs with `--no-cache`
- WHEN the service is invoked
- THEN the cache is bypassed and a fresh DB query is executed

#### Scenario: CLI and UI always agree on numbers

- GIVEN both CLI and UI call `DescartadosAnalisisService`
- WHEN both are invoked within the same 300s cache window
- THEN they return identical metric values

---

### Requirement: REQ-7 — Seam getNegativeExamples para T3

The service MUST expose `getNegativeExamples(int $limit = 10): Collection` as a seam
for future T3 auto-feedback. This method MUST NOT be called by T1 or T2 today.
Tests MUST verify the method exists and returns the expected data structure.

#### Scenario: Method returns high-confidence descartados

- GIVEN descartados with `gemini_confianza >= 70` and `gemini_motivo IS NOT NULL` exist
- WHEN `getNegativeExamples(10)` is called
- THEN it returns up to 10 rows ordered by `gemini_confianza` DESC
- AND each row includes `id`, `keyword`, `titulo`, `contexto`, `gemini_motivo`, `gemini_categoria`

#### Scenario: Method is structurally sound (T3 seam contract)

- GIVEN the service is instantiated
- WHEN `getNegativeExamples()` is called with no arguments
- THEN it returns a `Collection` (not null, not an array)
- AND calling it from T1 or T2 code paths is absent

---

### Requirement: REQ-8 — Migration de Índice en sitio_id

The system MUST add a btree index on `resultados_scraping.sitio_id` to support
per-sitio aggregation queries. The migration MUST be reversible and MUST NOT lock
the table in production PostgreSQL deployments.

#### Scenario: Index created without table lock on PostgreSQL

- GIVEN the database driver is `pgsql`
- WHEN the migration runs
- THEN `CREATE INDEX CONCURRENTLY` is used (no table lock during deploy)

#### Scenario: Index created on SQLite (test environment)

- GIVEN the database driver is `sqlite`
- WHEN the migration runs
- THEN a standard `CREATE INDEX` is used (CONCURRENTLY not supported by SQLite)

#### Scenario: Migration is reversible

- GIVEN the migration has been applied
- WHEN `migrate:rollback` is executed
- THEN the index is dropped with no data loss

---

## Out of Scope

| ID | Excluded |
|---|---|
| OUT-1 | T3 — auto-feedback to Gemini prompt with negative examples |
| OUT-2 | New columns on `resultados_scraping` |
| OUT-3 | Modifying scrapers (Python or Laravel) |
| OUT-4 | ML pipeline or auto-threshold tuning |
| OUT-5 | Notifications/alerts (Slack/Discord/email) |
| OUT-6 | Purge of old descartados |
| OUT-7 | Export of analysis output (CSV/PDF) |
| OUT-8 | Reports beyond the 30d–60d drift window (weekly, annual, etc.) |
| OUT-9 | Comparison across multiple operators (single-operator system) |
