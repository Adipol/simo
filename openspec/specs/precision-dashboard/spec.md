# precision-dashboard Specification

**Change origin**: `feedback-loop-from-descartados` · **Date**: 2026-05-14

## Purpose

Admin-only `/admin/precision` Livewire page that consumes `descartados-analisis` and renders
Chart.js visualizations with automatic 5-minute refresh. Provides the same metrics as the
CLI command (T1) through a visual interface (T2).

## Glossary

See `openspec/specs/descartados-analisis/spec.md` — same domain terms apply.

---

## Requirements

### Requirement: REQ-1 — Ruta Protegida y Acceso por Rol

The system MUST serve `/admin/precision` exclusively to authenticated users with the
`gestionar resultados` permission. Unauthenticated requests MUST redirect to login.
Authenticated users without the permission MUST receive a 403 response.

#### Scenario: Admin accesses dashboard successfully

- GIVEN an authenticated user with `gestionar resultados` permission
- WHEN they navigate to `/admin/precision`
- THEN the page renders with HTTP 200

#### Scenario: Operador receives 403

- GIVEN an authenticated user without `gestionar resultados` permission (e.g., `operador` role)
- WHEN they navigate to `/admin/precision`
- THEN the response status is 403 (Forbidden)

#### Scenario: Unauthenticated user redirected

- GIVEN no authenticated session
- WHEN a request is made to `/admin/precision`
- THEN the response redirects to the login route

---

### Requirement: REQ-2 — Cuatro Gráficos Chart.js

The dashboard MUST render four Chart.js visualizations covering the four metric domains:
precision histórica (line chart), top keywords por % descartado (bar chart),
top sitios por % descartado (bar chart), and confianza Gemini vs % descartado humano (bar chart).

#### Scenario: Four charts rendered with sufficient data

- GIVEN the system has ≥10 labeled rows
- WHEN an authorized user views `/admin/precision`
- THEN the view contains four distinct `<canvas>` elements with Chart.js initialization
- AND each chart is populated with data from `DescartadosAnalisisService`

#### Scenario: Chart data from service matches CLI output

- GIVEN both CLI (T1) and UI (T2) consume the same `DescartadosAnalisisService`
- WHEN both are invoked within the same cache window
- THEN the numbers shown in the charts EQUAL the numbers printed by the command

---

### Requirement: REQ-3 — Auto-Refresh cada 5 Minutos

The dashboard MUST automatically refresh its data every 300 seconds using Livewire polling.
The refresh interval MUST match the cache TTL (300s) to avoid stale data.

#### Scenario: Wire poll directive present

- GIVEN the `PrecisionDashboard` Livewire component is rendered
- WHEN the view is inspected
- THEN it contains a `wire:poll.300s` directive

#### Scenario: Data refreshes after poll interval

- GIVEN the dashboard is open and the cache TTL has expired
- WHEN the `wire:poll.300s` trigger fires
- THEN the component re-renders with fresh data from the service

---

### Requirement: REQ-4 — Botón "Refrescar Ahora"

The dashboard MUST provide a manual refresh action that invalidates the cache and
fetches fresh data immediately, without waiting for the 300s poll cycle.

#### Scenario: Manual refresh invalidates cache

- GIVEN an authorized user is viewing `/admin/precision`
- WHEN they trigger the "Refrescar ahora" action
- THEN the service cache is cleared
- AND the dashboard re-renders with freshly computed data

---

### Requirement: REQ-5 — Mensaje de Datos Insuficientes

When the system has fewer than 10 globally labeled rows, the dashboard MUST display a
descriptive message instead of empty or misleading charts. Charts MUST NOT be rendered
until the global minimum is met.

#### Scenario: Insufficient data message shown

- GIVEN `resultados_scraping` has fewer than 10 labeled rows
- WHEN an authorized user views `/admin/precision`
- THEN the view displays a message indicating the system needs more labeled data
- AND no Chart.js canvas elements are rendered

#### Scenario: Sufficient data — message hidden, charts visible

- GIVEN `resultados_scraping` has ≥10 labeled rows
- WHEN an authorized user views `/admin/precision`
- THEN the insufficient-data message is NOT displayed
- AND the four charts are rendered

---

### Requirement: REQ-6 — Comando CLI simo:analizar-descartados

The system MUST provide `simo:analizar-descartados` artisan command that produces
an ASCII-formatted report from the same `DescartadosAnalisisService` used by the dashboard.
The command MUST support filtering flags and a "Recomendaciones automáticas" summary section.

#### Scenario: Default run — full 30-day report

- GIVEN no flags are passed
- WHEN `php artisan simo:analizar-descartados` runs
- THEN it exits with code 0
- AND outputs aligned ASCII tables for: precision general, keyword ranking, sitio ranking, drift, confianza buckets
- AND ends with a "Recomendaciones automáticas" section

#### Scenario: Flag --dias=N changes analysis window

- GIVEN the flag `--dias=60` is passed
- WHEN the command runs
- THEN it analyzes rows from the last 60 days instead of the default 30

#### Scenario: Flag --categoria=X filters by category

- GIVEN the flag `--categoria=PEP-designacion` is passed
- WHEN the command runs
- THEN only rows with `categoria = 'PEP-designacion'` are included in all metrics

#### Scenario: Flag --keyword=X produces single-keyword detail

- GIVEN the flag `--keyword=renuncia` is passed
- WHEN the command runs
- THEN the output focuses on analysis specific to the keyword "renuncia"

#### Scenario: Flag --min-sample=N overrides threshold

- GIVEN the flag `--min-sample=10` is passed
- WHEN the command runs
- THEN keywords and sitios with fewer than 10 rows are excluded from rankings

#### Scenario: Flag --no-cache forces fresh query

- GIVEN the flag `--no-cache` is passed
- WHEN the command runs
- THEN the service cache is bypassed and DB is queried directly

#### Scenario: Insufficient global data outputs warning

- GIVEN `resultados_scraping` has fewer than 10 labeled rows
- WHEN the command runs
- THEN it exits with code 0
- AND outputs a "datos insuficientes" warning with the current labeled count
- AND does NOT output metric tables

#### Scenario: Automatic recommendations emitted when thresholds exceeded

- GIVEN a keyword's `pct_descartado` exceeds 80% with N≥5
- WHEN the command runs
- THEN the "Recomendaciones automáticas" section contains an action suggestion for that keyword

---

## Out of Scope

| ID | Excluded |
|---|---|
| OUT-1 | T3 — auto-feedback to Gemini prompt |
| OUT-2 | Changing the descartar/archivar UX |
| OUT-3 | Notifications/alerts (Slack/Discord/email) |
| OUT-4 | Export of report output (CSV/PDF) |
| OUT-5 | Embedding precision metrics into the existing `/dashboard` page |
