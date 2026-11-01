# PEP-only analysis for Resultados Scraper

New scraper analyses accept only people categorized as `PEP`. OPI-only responses no longer create people or activate `gemini_is_pep`; mixed responses retain only PEP people. Existing analyzed records are neither deleted nor reclassified.

## Behavior

| Response | Stored people | `gemini_is_pep` |
|----------|---------------|-----------------|
| OPI only | None | False |
| OPI + PEP above the configured threshold | PEP only | True |
| OPI + PEP below the configured threshold | PEP only, `threshold_passed=false` | False |
| Empty people array | None | False |

Both catalog-backed and generic news prompts request PEP-only output. Legacy `OPI-crimen` search categories remain visible as source metadata but cannot relax the PEP criteria. Article text is passed through unchanged, including any OPI wording.

The scraper service filters the parsed people before existing persistence, normalization, and confidence aggregation. It leaves the provider response and shared DTO validation unchanged. The configured confidence threshold still applies; no new event-type restriction is introduced. Provider token usage is counted even when every returned person is discarded. No additional raw response is stored.

## Scope boundaries

- Cambios PEP uses separate text/multimodal analysis prompts, which are unchanged.
- Gaceta ingestion and review behavior are unchanged.
- Already-analyzed scraper records retain their existing idempotency guard.
- No historical backfill, identity validation, prefilter changes, extra model calls, grouping, or deployment is included.

## Verification

The focused tests are `ScraperPepOnlyPromptTest` and `ScraperPepOnlyTest`. They exercise both prompt branches and the public scraper service pipeline with mocked provider responses. Existing Gemini, Cambios PEP, and Gaceta regressions should run in an isolated test database; never use an application database or live Gemini calls for verification.
