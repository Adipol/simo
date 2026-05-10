# Proposal: Source Health Tracking

**Change**: `source-health-tracking`
**Phase**: propose
**Date**: 2026-05-10
**Artifact store**: hybrid (engram + openspec/)

---

## Intent

With 24 active fuentes, a silently failing source (server down, captcha, HTML changed, permanent 404) goes undetected. The scraper keeps running but coverage silently degrades. The operator only finds out when someone reports "we haven't seen anything from fuente X in months." This change makes per-source failure state **visible on the dashboard in real time**.

---

## Scope

### In Scope

- `log_fuente_runs` migration — 9 fields, 3 indexes, FK → `fuentes` CASCADE
- `LogFuenteRun` Eloquent model + `Fuente::logFuenteRuns()` hasMany relationship
- Python `DatabaseManager::registrar_fuente_run()` — psycopg2 raw insert, same pattern as `log_scripts`
- Python `PEPMonitor::procesar_fuente()` — try/finally wrapping all 6+ exit paths
- `DashboardSourceHealthService` — aggregate query, cache TTL 60s, key `dashboard:source-health`
- `FuenteHealthDTO` + `SourceHealthSummaryDTO` — status derivation: `sin_info | ok | degradado | muerto`
- `config/dashboard.php` — `source_health` block with configurable thresholds
- Health-strip: new pill "Fuentes: 22 ok / 2 degradadas" (or "Recolectando datos…" on no data)
- `Dashboard` Livewire component: inject `DashboardSourceHealthService` via `#[Computed]`
- PHPUnit tests: dual-driver SQLite/PG, parameterized threshold assertions
- Python pytest: `test_fuente_runs.py` with mocked DB, simulated exception paths

### Out of Scope

- Active alerts (Slack/email/webhook) → future SDD `source-health-alerts`
- Per-source drill-down panel/modal → deferred post 2–3 weeks of real data
- Auto-disable of dead fuentes → separate SDD
- Admin "Test connection" button → nice-to-have, not essential
- Retroactive data — first days post-deploy show "Recolectando datos…" (expected, communicated)
- Changes to scraping logic itself — instrumentation only

---

## Capabilities

### New Capabilities

- `source-health-tracking`: Per-source run logging and aggregate health status derivation for the dashboard health strip

### Modified Capabilities

- None — `dashboard-health` (pipeline-level) is not modified; source health is a separate service

---

## Approach

**Python side**: Wrap `procesar_fuente()` body in try/finally. Capture `started_at = datetime.utcnow()` before the try, set `resultado` and `http_status` via local vars at each exit path, call `registrar_fuente_run()` in finally. Direct psycopg2 insert to `log_fuente_runs` — mirrors `log_scripts` pattern exactly.

**Laravel side**: `DashboardSourceHealthService` queries the last N rows per fuente from `log_fuente_runs`, computes consecutive failures in PHP, derives status per fuente, aggregates into `SourceHealthSummaryDTO`. Status derivation is at read time (no materialized column) — cached at 60s. Thresholds from `config('dashboard.source_health.*')`.

**UI**: Add one pill to the existing `<x-dashboard.health-strip>` component. No new page, no new modal for this SDD.

---

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/{ts}_create_log_fuente_runs_table.php` | New | Table with 9 fields, 3 indexes |
| `app/Models/LogFuenteRun.php` | New | Eloquent model, no timestamps (immutable rows) |
| `app/Models/Fuente.php` | Modified | Add `logFuenteRuns()` hasMany |
| `app/Services/Dashboard/DashboardSourceHealthService.php` | New | Aggregate queries + status derivation |
| `app/Services/Dashboard/DTOs/FuenteHealthDTO.php` | New | Per-source status DTO |
| `app/Services/Dashboard/DTOs/SourceHealthSummaryDTO.php` | New | Aggregate summary DTO |
| `config/dashboard.php` | Modified | Add `source_health` block with threshold keys |
| `resources/views/components/dashboard/health-strip.blade.php` | Modified | Add source health pill |
| `app/Livewire/Dashboard.php` | Modified | Inject service via `#[Computed]` |
| `scripts/website_monitor_pro/database.py` | Modified | Add `registrar_fuente_run()` + `_verify_tables()` |
| `scripts/website_monitor_pro/pep_monitor.py` | Modified | try/finally in `procesar_fuente()` |
| `tests/Feature/Services/Dashboard/DashboardSourceHealthServiceTest.php` | New | PHPUnit dual-driver |
| `scripts/website_monitor_pro/tests/test_fuente_runs.py` | New | pytest with mocked DB |

---

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| try/finally misses an exit path in `procesar_fuente()` | Med | pytest must simulate each of the 6+ exit paths individually |
| Deploy order violated (Python restarts before migrate) | Med | Documented as mandatory step: `migrate` → `supervisorctl restart`. Task checklist enforces order. |
| UTC inconsistency (`datetime.now()` vs `datetime.utcnow()`) | Low | Code review + pytest assertion on UTC format; use `datetime.now(timezone.utc)` exclusively |
| "Sin info" UX confusion on first days | Low | Pill shows "Recolectando datos…" when `sin_info_count == total`; same pattern as latency/quota |
| PHPUnit SQLite dialect gap for window queries | Low | Dual-driver test setup already established; fallback to PG-only for complex window functions |

---

## Rollback Plan

1. `php artisan migrate:rollback` — drops `log_fuente_runs` (no FK constraints on other tables; CASCADE on `fuentes` side)
2. Revert `pep_monitor.py` and `database.py` to previous commit → `supervisorctl restart simo-runner`
3. Revert `health-strip.blade.php` and `Dashboard.php` to remove pill
4. No data loss on rollback — `fuentes` and existing tables are untouched

---

## Dependencies

- Migration must run BEFORE Python restart — non-negotiable deploy order
- `config/dashboard.php` must exist (already does from `redesign-dashboard` SDD)
- psycopg2 already installed in the Python environment (used by `DatabaseManager`)

---

## Success Criteria

- [ ] `log_fuente_runs` records exactly 1 row per `procesar_fuente()` call — verified by integration test
- [ ] Status derivation respects config thresholds — verified by parameterized PHPUnit test
- [ ] Python try/finally captures ALL exit paths — verified by pytest with simulated exceptions for each path
- [ ] Dashboard pill visible in health-strip: "Fuentes: N ok / M degradadas" (or "Recolectando datos…")
- [ ] Zero queries in `render()` — all data via `#[Computed]` + cached service
- [ ] Pre-commit hook passes without `--no-verify`
- [ ] PHPUnit suite ≥ 776 passing tests + new tests (no regression)
- [ ] Existing Python tests (`test_runner.py`, `test_cascade.py`, etc.) remain green
