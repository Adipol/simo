# Scraper title gate before Gemini

T1 provides the disabled-by-default title client/storage; T2 integrates reversible filtering into the Scraper job; T3 adds optional access, authorized manual forwarding and recorded API-attempt status. Relevant/doubtful titles and failures reach deep Gemini; unrelated articles remain stored and unanalysed, without a human review queue. No deployment, activation, historical title backfill or new token reporting is included. Cambio/Pro and Gaceta are unchanged.

## Configuration contract

Application code reads `services.deepseek_title_gate`, separate from `services.gemini`.

| Environment variable | Default | Requirement |
|---|---|---|
| `DEEPSEEK_TITLE_GATE_ENABLED` | `false` | Only boolean `true` in resolved configuration enables readiness |
| `DEEPSEEK_API_KEY` | absent | Server-only printable non-whitespace ASCII bearer credential |
| `DEEPSEEK_TITLE_MODEL` | `deepseek-flash` | Nonempty model identifier, at most 128 ASCII identifier characters |
| `DEEPSEEK_TITLE_ACTIVATION_AFTER_ID` | absent | Explicit nonnegative integer; never inferred from current maximum ID |
| `DEEPSEEK_TITLE_TIMEOUT` | `20` | Integer seconds, 1–20; invalid settings are not ready |

`batch_size` is fixed at `1`. `DeepSeekTitleSettings::configured()` means enabled and structurally configured, **not API success**. Canonical decimal environment strings are accepted for integers; booleans, fractions, negatives and overflow are rejected. No health request is made.

Activation remains unauthorized. The integration requires a deliberate fixed boundary for new articles (`id > activationAfterId`). For example, boundary 100 must not classify article 100 or earlier. Do not automatically reset the boundary when workers restart. Reactivation needs an explicit boundary decision to avoid classifying historical/off-period backlog.

## Frozen client contract

`DeepSeekTitleClient::classify(int $id, string $originalTitle): TitleGateResult` makes one attempt to the fixed HTTPS endpoint `https://api.deepseek.com/chat/completions`. It sends only the exact system prompt and JSON `{id,titulo}` user data, with thinking disabled, JSON-object output, no streaming output, and `max_tokens=6500`. No context, source flags, catalog, labels or API key enters the message body.

The prompt is `resources/prompts/scraper-title-gate.txt`, verified against SHA256 `9d6de7068451427546b895d9c6727d603e771c001e5e6815b2d8dc6186da1ae4` on each call. This digest is also the stored `promptVersion`; changing the asset alone fails open.

| DTO property | Meaning |
|---|---|
| `status` | `relevant`, `doubtful`, `unrelated`, `technical_error`, or `invalid_response` |
| `decision` | Validated decision, otherwise null |
| `evidence`, `reason` | Literal title excerpt ≤180 characters and nonblank reason ≤160 characters; null on failure |
| `errorCode` | Fixed sanitized code, or null on success |
| `model` | Provider-returned actual model identifier on validated success, otherwise null |
| `promptVersion` | Frozen SHA256 |

The prompt requires Spanish reasons; the validator checks structure and length, not semantic language or classification correctness. It accepts exactly one choice with `finish_reason=stop`, one result with exactly the four frozen keys, and an exactly matching integer ID (no coercion). Evidence must match the original title, including repeated spaces. Unrelated is a reversible decision, not permission to discard an article.

TLS verification remains enabled, redirects are disabled, connect/request/read timeouts are bounded to 20 seconds each, and internal retries are absent. HTTP bodies are streamed internally and capped at 256 KiB before JSON decoding; declared oversized bodies can be rejected at headers. A stream-read deadline supplements the HTTP timeout. No strict single wall-clock bound across both header and stream phases is claimed. Transport errors, non-200 responses, malformed responses and unavailable prompt/configuration return failure DTOs rather than raw provider exceptions. There are no new logs, usage statistics, body dumps or persisted credentials.

## Additive storage

`TitleGateResult::toPersistenceAttributes()` returns only the seven `deepseek_title_*` fields, setting `deepseek_title_attempted_at` at mapping time. The caller owns persistence; the client does not write the database. Callers should map immediately after the attempt. The nullable fields are status, reason, evidence, error_code, attempted_at, model and prompt_version. Nullable `deepseek_bypass_at` records an authorized manual bypass. The status index supports pending/unsent queries.

The migration adds no defaults, historical UPDATE or backfill. Existing manual/Gemini/persona state is untouched. Rollback of this migration removes only the newly added fields and their status index; it loses title-attempt history and is not the normal operational disable switch.

## T2 routing and pending work

`ResultadoScraping::pendienteAnalisisScraper()` is the shared job selection, self-chain and Scraper command-count scope:

`gemini_analyzed = false AND (status IS NULL OR status != unrelated OR bypass_at IS NOT NULL)`.

The OR is grouped. Existing archived, discarded and secondary pending articles are not newly excluded. The Pro command branch is untouched. The Resultados pending counter also uses this scope, retaining its existing non-discarded, non-archived and primary-only constraints.

Inside each article lock, the gate re-reads eligibility and skips any row with `gemini_analyzed_at` already set, even if its boolean is false. It never resets existing analysis fields.

| State after fresh read | Route |
|---|---|
| `deepseek_bypass_at` set | No title request; deep Gemini without lexical prefilter; keep the stored title decision |
| Persisted `unrelated`, no bypass | Held, even when the title gate is disabled; no Gemini or manual/persona/discard/archive changes |
| Persisted R/D/technical_error/invalid_response | Reuse decision; deep Gemini without lexical prefilter, regardless of current enable switch |
| No decision, enabled, explicit valid boundary, ID greater than boundary | Classify once and persist title fields before deciding whether to call Gemini |
| Disabled, ID equal to/below boundary, or missing/invalid boundary | Legacy Gemini path with its existing lexical prefilter; no title attempt |

For example, boundary 100 classifies article 101 but not 100. An enabled gate with a valid boundary but a missing key persists `technical_error/not_configured` for new articles and goes directly to Gemini: invalid readiness must not silently reinstate the lexical prefilter. Missing/invalid activation is invalid configuration and makes no active title calls. Readiness does not prove API health.

`GeminiFiltroService::analizarLote(Collection $records, bool $bypassLexicalPrefilter = false)` adds only an explicit lexical bypass. Existing callers retain the default. Deep prompts, PEP-only DTO filtering, thresholds, usage logging and result persistence remain unchanged; DeepSeek does not write Gemini usage logs. `ScraperTitleGate::process(int $id)` owns the locked route, including stored manual bypasses; the manual-send service authorizes and persists that marker before dispatch, without calling the title client again.

## Concurrency and operational prerequisites

- Every job-selected article, including legacy ones, uses `Cache::lock('scraper-title-gate:'.$id, $lease)`. Use a shared cache store with working atomic locks across workers; local array stores only demonstrate behavior in isolated tests.
- The per-record lease is `max(360, 3 * titleTimeout + geminiTextTimeout + 60)` seconds: strictly above the existing job timeout of 300 seconds and a conservative title transport/deep-call budget. Gemini text transport uses `services.gemini.timeout` (default 45 seconds), one HTTP request and no internal retry; job retry/backoff remains unchanged. Keep configured HTTP budgets below the job timeout. The lease covers one record, not an entire batch.
- Active gating with a valid boundary uses the fixed batch size of one, including missing-key fail-open cases. Disabled/missing-boundary paths retain the legacy cap of ten. Persisted decisions still govern those batches.
- Busy locks skip network calls. Remaining eligible work self-chains using the existing Flash delay, with a minimum of one second to prevent immediate busy-lock loops. Lock-backend exceptions propagate normally; there is no unlocked fallback.
- **Existing deployment prerequisite:** queue `retry_after` must exceed the job's 300-second timeout (the job documents 360 seconds). The database/Redis defaults in `config/queue.php` remain 90 seconds and are not repaired here. Verify the effective lease and worker timeout before any later activation; no queue/deployment settings were changed or inspected live.
- No database transaction spans a network call. Normal retries reuse the persisted title decision. This is **not exactly-once execution across process death**: a crash after a provider response but before persistence can repeat that request; a crash during Gemini persistence can retain existing partial-write limitations. The manual bypass uses the same article mutex and a conditional state update.

Operational disable affects only future unclassified articles: it neither releases stored unrelated decisions nor erases history. Reactivation requires an explicit boundary choice; do not automatically classify off-period backlog. Independent broader/PostgreSQL verification remains T4; any later deployment or activation requires separate authorization. Isolated SQLite evidence does not establish production compatibility.

## Optional access and manual forwarding

In Resultados Scraper, select **“No enviadas por DeepSeek”** (`filtroDeepSeek=held` in the URL). This shows unrelated, unanalysed articles without a bypass or analysis timestamp, including secondary sources. Only the implicit analyzed/primary defaults are lifted; explicit search, country, category, read, relevance, discard/archive and Gemini filters still intersect. Conflicting filters can therefore produce an empty list. The default view is unchanged, pagination resets when the option changes, and the existing export uses the same filtered query. This is optional access, not a required human-review queue. Reasons and evidence are escaped when rendered.

**“Enviar a Gemini”** requires `gestionar resultados`, checked on the server as well as the button. The service validates the ID, requires enabled/configured Gemini, takes the same article lock, and conditionally sets only `deepseek_bypass_at` for an eligible held article. It preserves the title decision, manual flags, analysis data and personas. Repeated clicks, already-analyzed/ineligible articles and busy locks cannot schedule a second bypass. A busy article can be retried later; unavailable Gemini leaves it unchanged.

A successful action reports **“Análisis programado en Gemini; se procesará con los pendientes.”** This schedules the existing global Scraper job, not an immediate analysis or guaranteed priority. If dispatch fails, the marker stays durable and the UI reports that scheduling failed; the existing periodic scanner can recover the now-eligible article. Recovery depends on that scanner and the queue operating normally. Manual forwarding skips both another DeepSeek request and the lexical prefilter, but retains Gemini's PEP-only analysis and confidence thresholds.

## Recorded API status

The DeepSeek indicator separates disabled/not-configured/ready configuration from persisted attempt history. **“Configuración lista (no prueba disponibilidad actual)”** never means a successful live health check. With valid configuration but no recorded attempts it shows **“Configurada; aún sin llamadas verificadas”**.

The latest recorded attempt determines **“Última llamada validada”**, **“Último intento fallido”**, or unknown status. A malformed response is labeled **“Respuesta no válida.”** The most recent validated-call timestamp is shown separately as historical evidence, so an older success cannot mask a newer failure. Attempt timestamps reflect DTO persistence mapping, not a live probe. No HTTP request, credential, raw provider error or new token statistic enters this UI; existing shared Gemini telemetry remains unchanged.

## Offline verification

The task-owned `.scratch/deepseek-gate-tests/check.py` creates immutable named source snapshots and verifies main-checkout Composer lock/package compatibility before mounting vendor read-only. Receipts include commands, manifest hashes, outputs and exit codes. Docker runs without networking, with read-only mounts, non-root UID/GID 999, sanitized environment and tmpfs runtime paths. A guard proves effective SQLite `:memory:` configuration and PDO target before migrations. No host environment files, cached application configuration or storage database is copied. RED snapshots intentionally lack the implementation; GREEN and regression snapshots must match the reviewed source hashes.
