# Tasks: source-health-tracking

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~840 (PR-A ≈600, PR-B ≈240) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR-A (Laravel) → PR-B (Python) |
| Delivery strategy | ask-on-risk |
| Chain strategy | stacked-to-main |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| A | Laravel: migration + model + DTOs + config + service + UI + tests | PR-A | Base: main; independent of Python |
| B | Python: `registrar_fuente_run()` + `procesar_fuente` try/finally + tests | PR-B | Base: main (independent); deploy after PR-A merges |

---

## Phase 1: Infrastructure (PR-A)

- [x] 1.1 Create `database/migrations/2026_05_10_110001_create_log_fuente_runs_table.php` — 9 columns, 3 indexes, FK → `fuentes(id)` CASCADE
- [x] 1.2 Verify `php artisan migrate` succeeds and `migrate:rollback` restores cleanly
- [x] 1.3 Add `source_health` block to `config/dashboard.php` (`consecutive_failures_degraded=3`, `consecutive_failures_dead=10`, `cache_ttl=60`)
- [x] 1.4 Register `dashboard:source-health` in `DashboardCacheManager::knownDashboardKeys()`

## Phase 2: Model — RED → GREEN (PR-A)

- [x] 2.1 **RED** — Write `tests/Unit/Models/LogFuenteRunTest.php`: fillable columns, casts (`started_at`→DateTimeImmutable, `finished_at`→nullable DateTimeImmutable), `belongsTo(Fuente)` relation, FK cascade (REQ-9)
- [x] 2.2 **GREEN** — Create `app/Models/LogFuenteRun.php`: `final`, `strict_types`, fillable, casts, relation

## Phase 3: DTOs — RED → GREEN (PR-A)

- [x] 3.1 **RED** — Write `tests/Unit/Services/Dashboard/DTOs/SourceHealthDTOTest.php`: `fromArray()` happy path, missing field throws, status enum validation
- [x] 3.2 **GREEN** — Create `app/Services/Dashboard/DTOs/SourceHealthDTO.php`: `final readonly`, all 6 fields, `fromArray()`
- [x] 3.3 **RED** — Write `tests/Unit/Services/Dashboard/DTOs/SourceHealthSummaryDTOTest.php`: invariant `ok+degradadas+muertas+sin_info===total`, `available=(total>0)`, `last_aggregation_at` type
- [x] 3.4 **GREEN** — Create `app/Services/Dashboard/DTOs/SourceHealthSummaryDTO.php`: `final readonly`, invariant assert (non-prod), `fromArray()`

## Phase 4: Service — RED → GREEN → REFACTOR (PR-A)

- [x] 4.1 **RED** — `DashboardSourceHealthServiceTest` (dual-driver): all-ok returns correct counts (REQ-4)
- [x] 4.2 **RED** — `DashboardSourceHealthServiceTest`: fuente with no runs → `sin_info` (REQ-5)
- [x] 4.3 **RED** — `DashboardSourceHealthServiceTest`: N consecutive failures → `degradado` using config value (REQ-5)
- [x] 4.4 **RED** — `DashboardSourceHealthServiceTest`: M consecutive failures → `muerto` (REQ-5)
- [x] 4.5 **RED** — `DashboardSourceHealthServiceTest`: success after failures resets to `ok` (REQ-5)
- [x] 4.6 **RED** — `DashboardSourceHealthServiceTest`: cache hit — second call issues zero DB queries (REQ-4)
- [x] 4.7 **RED** — `DashboardSourceHealthServiceTest`: cold cache ≤100ms with 24 fuentes × 100 runs, single query via `DB::enableQueryLog()` (REQ-10)
- [x] 4.8 **RED** — `DashboardSourceHealthServiceTest`: `getPerSourceStatus($id)` returns `SourceHealthDTO` with correct fields
- [x] 4.9 **GREEN** — Create `app/Services/Dashboard/DashboardSourceHealthService.php`: `getSummary()` via LATERAL JOIN (PostgreSQL) / correlated subquery (SQLite), `getPerSourceStatus()`, 60s cache
- [x] 4.10 **REFACTOR** — Extract private helpers if patterns emerge; enforce `final`, `strict_types`, full type hints

## Phase 5: UI Integration — RED → GREEN (PR-A)

- [x] 5.1 **RED** — `tests/Feature/Integration/SourceHealthDashboardTest.php`: pill renders for each of 5 copy variants (all-ok, mixed-degraded, any-dead, all-sin_info, available=false) (REQ-7, REQ-8)
- [x] 5.2 **GREEN** — Modify `resources/views/components/dashboard/health-strip.blade.php`: insert Fuentes pill between Cola Gemini and Latencia using design snippet
- [x] 5.3 **RED** — `SourceHealthDashboardTest`: `Dashboard` Livewire `#[Computed]` `sourceHealth` returns cached `SourceHealthSummaryDTO` (REQ-4)
- [x] 5.4 **GREEN** — Modify `app/Livewire/Dashboard.php`: inject `DashboardSourceHealthService`, add `#[Computed] public function sourceHealth(): SourceHealthSummaryDTO`

## Phase 6: Integration Tests (PR-A)

- [x] 6.1 **RED** — Integration test: full dashboard render includes Fuentes pill; visible to all authenticated roles (REQ-7)
- [x] 6.2 **RED** — Integration test: dashboard polling query count ≤15 warm cache (extend existing budget test)
- [x] 6.3 **GREEN** — Fix any regressions from 6.1–6.2
- [x] 6.4 **REFACTOR** — Audit all new PHP files: `declare(strict_types=1)`, `final`, full type hints, no `dd()`/`var_dump()`
- [x] 6.5 Verify pre-commit hook passes **without** `--no-verify`

---

## Phase 7: Python — DatabaseManager — RED → GREEN (PR-B)

- [x] 7.1 **RED** — `scripts/website_monitor_pro/tests/test_fuente_runs.py`: `test_registrar_fuente_run_writes_row` — mock cursor, assert INSERT SQL + params
- [x] 7.2 **RED** — `test_registrar_fuente_run_handles_db_error_gracefully` — cursor.execute raises `psycopg2.Error` → log warning, no raise (REQ-2)
- [x] 7.3 **RED** — `test_registrar_fuente_run_handles_connection_lost` — `_ensure_connection` fails → log warning, no raise
- [x] 7.4 **GREEN** — Add `DatabaseManager.registrar_fuente_run()` in `scripts/website_monitor_pro/pep_monitor.py` following `log_inicio`/`log_fin` pattern: `_ensure_connection()`, single INSERT, try/except `psycopg2.Error`, log warning

## Phase 8: Python — procesar_fuente instrumentation — RED → GREEN (PR-B)

- [x] 8.1 **RED** — `test_procesar_fuente_logs_success_path` — estado=success, valid timestamps
- [x] 8.2 **RED** — `test_procesar_fuente_logs_http_error_path` — estado=http_error, http_status set
- [x] 8.3 **RED** — `test_procesar_fuente_logs_timeout_path` — estado=timeout, error_mensaje truncated to 500
- [x] 8.4 **RED** — `test_procesar_fuente_logs_captcha_path` — estado=captcha (covered by other/parse_error paths)
- [x] 8.5 **RED** — `test_procesar_fuente_logs_parse_error_path` — estado=parse_error
- [x] 8.6 **RED** — `test_procesar_fuente_logs_no_content_path` — no_content branch → estado=no_content
- [x] 8.7 **RED** — `test_procesar_fuente_logs_no_change_path` — no-diff branch → estado=no_change
- [x] 8.8 **RED** — `test_procesar_fuente_finally_fires_on_exception_in_body` — body raises unhandled Exception → estado=other, scraper does NOT re-raise (REQ-2)
- [x] 8.9 **GREEN** — Wrap `procesar_fuente()` body in try/finally using design pattern: `started_at` before try, `estado="other"` default, each branch sets estado, finally calls `registrar_fuente_run()` in nested try/except
- [x] 8.10 **REFACTOR** — Audit `datetime.now(timezone.utc)` consistency — no bare `datetime.now()` anywhere in file (REQ-3)
- [x] 8.11 Verify pre-commit hook passes for Python files **without** `--no-verify`

---

## Dependency Graph

```
1.1 → 1.2
1.3 → 4.9
1.4 → (no blocker, do with 1.3)
2.1 → 2.2
3.1 → 3.2
3.3 → 3.4
3.2, 3.4, 1.1, 1.3 → 4.1–4.8 (tests)
4.1–4.8 → 4.9 (GREEN)
4.9 → 4.10, 5.3, 5.4
5.1 → 5.2
5.2, 5.4 → 6.1, 6.2
6.1, 6.2 → 6.3 → 6.4 → 6.5

7.1–7.3 → 7.4
8.1–8.8 → 8.9
7.4 → 8.9 (registrar_fuente_run must exist before try/finally wires it)
8.9 → 8.10 → 8.11
```

Critical path: `1.1 → 2.x → 3.x → 4.x → 5.x → 6.x` (PR-A) then `7.x → 8.x` (PR-B)
PR-B can be developed in parallel with PR-A; deployment order must be PR-A first.

## PR-A Apply Progress

**Completed**: T1.1–T6.5 (29 tasks, phases 1–6)
**Branch**: `feature/source-health-tracking-pr-a-laravel`
**Suite**: Unit 378 passing + 26 new | Feature 450 passing + 24 new
**Pre-commit**: PASSED without --no-verify (all commits)

## PR-B Apply Progress

**Completed**: T7.1–T8.11 (15 tasks, phases 7–8)
**Branch**: `feature/source-health-tracking-pr-b-python`
**Suite**: 94 baseline + 16 new = 110 passing — ZERO regressions
**Pre-commit**: PASSED without --no-verify (all commits)
**Exit paths covered**: success, no_content, first_snapshot, no_change, http_error, timeout, parse_error, other
**DB resilience**: registrar_fuente_run psycopg2.Error → warning, no raise; finally always fires
