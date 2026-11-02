# Scraper title gate: foundation only

T1 adds a disabled-by-default DeepSeek title client and nullable storage. **It does not filter the running pipeline.** No deployment, activation, historical processing, token reporting or Gemini behavior change is included.

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

Activation remains unauthorized. The later integration must require a deliberate fixed boundary for new articles (`id > activationAfterId`). For example, boundary 100 must not classify article 100 or earlier. Do not automatically reset the boundary when workers restart. Reactivation needs an explicit boundary decision to avoid classifying historical/off-period backlog.

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

`TitleGateResult::toPersistenceAttributes()` returns only the seven `deepseek_title_*` fields, setting `deepseek_title_attempted_at` at mapping time. The caller owns persistence; the client does not write the database. Callers should map immediately after the attempt. The nullable fields are status, reason, evidence, error_code, attempted_at, model and prompt_version. Nullable `deepseek_bypass_at` is reserved for the later authorized bypass. The status index supports future pending/unsent queries.

The migration adds no defaults, historical UPDATE or backfill. Existing manual/Gemini/persona state is untouched. Rollback of this migration removes only the newly added fields and their status index; it loses title-attempt history and is not the normal operational disable switch.

## Deferred integration

- **T2:** shared eligibility/count query, per-record locking, persist-before-Gemini retries, R/D/error → deep Gemini, retained unrelated articles, lexical-prefilter bypass only on agreed routes, legacy/disabled and Cambio/Gaceta preservation. No exactly-once guarantee across process death; queue lease/timeout still needs integration validation.
- **T3:** authorized manual bypass, escaped unsent view, configuration and stored last-attempt health. A key alone must never be displayed as verified healthy.
- **T4:** independent verification, broader regressions and activation/rollback review. Isolated SQLite evidence does not establish PostgreSQL production compatibility.

## Offline verification

The task-owned `.scratch/deepseek-gate-tests/check.py` creates immutable named source snapshots and verifies main-checkout Composer lock/package compatibility before mounting vendor read-only. Receipts include commands, manifest hashes, outputs and exit codes. Docker runs without networking, with read-only mounts, non-root UID/GID 999, sanitized environment and tmpfs runtime paths. A guard proves effective SQLite `:memory:` configuration and PDO target before migrations. No host environment files, cached application configuration or storage database is copied. RED snapshots intentionally lack the implementation; GREEN and regression snapshots must match the reviewed source hashes.
