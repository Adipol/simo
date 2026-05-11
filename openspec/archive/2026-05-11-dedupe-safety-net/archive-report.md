# Archive Report: dedupe-safety-net

**Archived**: 2026-05-11
**Status**: COMPLETED (software) / PENDING (ops apply on VPS)
**PR**: https://github.com/Adipol/simo/pull/17
**Branch**: feature/dedupe-safety-net (not yet merged at archive time)
**Commits**: 1dd4eac, b8a4119, 6ed4776, f2aae06, 89ba033 (5 total)

## Outcome

The dedupe-safety-net SDD delivered a scheduled safety net that closes a critical gap in deduplication coverage. The Python scraper (scripts/scraper_v2.2/core/database.py) writes `resultados_scraping` rows via raw SQL, bypassing the Eloquent observer that would normally dispatch `DedupeArticulosJob`. As a result, 97 historical rows with no dedup processing accumulated in production. The fix: a new `dedupe_processed_at TIMESTAMP NULL` column, a per-5-minute dispatcher command (`simo:dedupar-pendientes`), and a dedicated supervisor worker (`simo-dedupe-worker`) isolated from the Gemini queue to prevent starvation.

**Software delivery**: All 6 REQs and 16 SCNs shipped and verified green. 13 new tests added (5 command, 7 service, 1 config default). Zero regressions. PR #17 is ready to merge once the user approves. The implementation mirrors the proven `simo:analizar-gemini` pattern and reuses existing config (`services.dedupe.enabled` was already wired at `config/services.php:38-40`).

**Ops delivery**: Not yet deployed. The user will merge PR #17 to main, then execute VPS deployment steps documented in DEPLOY.md (copy supervisor block, apply migrations, start worker). Archive captures the SDD trail before merge so documentation is in place when they execute manual ops.

## Decisions log

1. **Worker isolation**: Dedicated `simo-dedupe-worker` supervisor process (NOT shared with Gemini). Rationale: 133 pre-existing Gemini failures prove shared workers would starve dedupe under API saturation.

2. **Column design**: Single `dedupe_processed_at TIMESTAMP NULL` (NOT dual bool+timestamp like Gemini). Rationale: Timestamp alone is sufficient; partial index `WHERE dedupe_processed_at IS NULL` is efficient for pending-row queries.

3. **Timestamp placement**: Stamp OUTSIDE transaction, in BOTH paths (post-tx success AND pre-tx early-exit). Rationale: Timestamp is a processing receipt, not part of cluster invariant. Avoids extending lock window. Idempotency is guaranteed by `ShouldBeUnique`.

4. **Per-run dispatch cap**: No cap (`--limit`). Rationale: Dispatch is cheap DB inserts + queue enqueue ops. Add cap only if volume grows 100×. Designed for future scalability.

5. **Config key reuse**: Leveraged pre-existing `services.dedupe.enabled` at `config/services.php:38-40`. Rationale: Discovered during design phase — saved one task and avoided config duplication.

6. **Logging channel**: Reuse `Log::channel('gemini')`. Rationale: Consistency with service line 77. Separate dedupe channel would be unjustified churn.

7. **Test location**: Inline migration test in `tests/Feature/Services/Dedupe/DedupeArticulosServiceTest.php`. Rationale: File already requires PostgreSQL + RefreshDatabase; no dedicated Feature/Database/ folder exists in project.

8. **PR scope**: Single PR with `size:exception`. Estimated ~410 lines, actual ~479 added. Rationale: Indivisible capability; split would obscure the full pattern (command + service + migration + schedule + supervisor docs). Review focus remains clear.

9. **Job dispatch pattern**: Per-row `DedupeArticulosJob::dispatch($id)` (NOT aggregate). Rationale: Job is already designed per-row with `ShouldBeUnique` + 300s lock. Matches dedup algorithm topology.

10. **Idempotency layers**: (1) `dedupe_processed_at` flag (cross-run), (2) `ShouldBeUnique` (intra-window), (3) service `lockForUpdate` + re-check (row-level). Rationale: Defense-in-depth against re-dispatch from any failure mode.

## Metrics

| Metric | Value |
|---|---|
| REQs | 6/6 PASS |
| SCN coverage | 16/16 |
| New tests | 13 |
| Tests passing | 13/13 |
| Regressions | 0 |
| Lines added | ~479 |
| Lines removed | 0 |
| Files changed | 9 |
| PR size category | size:exception (~479 lines vs ~413 estimated; semantically indivisible) |
| Pre-existing baseline failures | 15 (unrelated: Profile, Example, Seeder, DashboardHealth, Gemini SSL — same on main) |
| SDD cycle phases | explore, propose, spec, design, tasks, apply, verify, archive (8 phases) |

## Files delivered

| File | Role |
|---|---|
| `database/migrations/2026_05_10_110002_add_dedupe_processed_at_to_resultados_scraping_table.php` | NEW: Adds `dedupe_processed_at` column + partial index |
| `app/Models/ResultadoScraping.php` | MOD: Fillable + datetime cast for new column |
| `app/Services/Dedupe/DedupeArticulosService.php` | MOD: Stamp timestamp in both paths (Path A early-exit, Path B post-tx) |
| `tests/Feature/Services/Dedupe/DedupeArticulosServiceTest.php` | MOD: 4 new tests (migration, Path A, Path B, null guard) |
| `app/Console/Commands/DeduparPendientes.php` | NEW: Safety-net dispatcher command with kill switch |
| `tests/Feature/Dedupe/DeduparPendientesCommandTest.php` | NEW: 5 tests (NULL dispatch, kill switch, no rows, log, queue) |
| `tests/Feature/Dedupe/DedupeConfigDefaultTest.php` | NEW: 1 test for SCN-5.1 config default regression guard |
| `routes/console.php` | MOD: Schedule entry `simo:dedupar-pendientes` every 5 min |
| `DEPLOY.md` | MOD: New `[program:simo-dedupe-worker]` supervisor block + ops checklist |
| `openspec/specs/dedupe-safety-net/spec.md` | CANONICAL: Capability spec (synced, first formal spec for dedupe) |

## Ops actions still required (post-merge)

These steps are the user's responsibility after merging PR #17 to `main`. Documented in DEPLOY.md for reference.

1. **Pull merged feature**: `git pull origin main` on VPS (after PR #17 merges)
2. **Run migration**: `php artisan migrate --force` (creates column + partial index)
3. **Copy supervisor block**: Open `DEPLOY.md`, copy the `[program:simo-dedupe-worker]` section to `/etc/supervisor/conf.d/simo-dedupe-worker.conf`
4. **Reload supervisor**: `sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start simo-dedupe-worker`
5. **Verify process**: `ps aux | grep "queue:work" | grep dedupe` — should show running process
6. **Watch first run**: Tail logs, confirm first scheduled cycle (next 5-min mark) dispatches >0 jobs
7. **Verify cluster formation**: Query `SELECT COUNT(*) FROM resultados_scraping WHERE secundario_de IS NOT NULL` — should be >0 after first full dedupe cycle (if duplicates exist in the 97 historical rows)

## Lessons learned

- **Safety-net pattern for externally-written rows**: When an external runtime (Python scraper) writes to your database bypassing ORM observers, a periodic dispatcher is the architectural solution. This pattern is now codified in SIMO and can be replicated for any future job that depends on external row insertion.

- **Config waiting to be wired**: `services.dedupe.enabled` existed at `config/services.php:38-40` for months before anyone read it. Always audit existing config for latent features. Saved us from duplicating a key.

- **Partial indices for pending-row queries**: Using `WHERE dedupe_processed_at IS NULL` in both the query and the index (pgsql only) ensures O(1) lookups even when most rows have been processed. Boolean flags + timestamps are not as clean.

- **Dedicated workers prevent queue starvation**: With 133 pre-existing Gemini failures, a shared worker would have been a bottleneck. Isolation is cheap (supervisor config change) and prevents unexpected coupling.

## Related work / future hooks

- If any future job depends on externally-written rows, replicate this pattern. Consider a generic `SafetyNetCommand` base class if a third instance appears.
- If pending row count grows beyond ~10k on first run, implement `chunk()` in the command query (design already noted this for later).
- Consider adding a `queue:monitor` helper command to track pending dedupe jobs in failed_jobs (suggested follow-up).

## Engram Observations (Source of Truth)

| Artifact | Observation ID |
|----------|---------------|
| Explore | #860 |
| Proposal | #861 |
| Spec | #862 |
| Design | #863 |
| Tasks | #864 |
| Apply-progress | #866 |
| Verify-report | #868 |
| Archive-report | (this document — saved to Engram `sdd/dedupe-safety-net/archive-report`) |

## References

- PR #17: https://github.com/Adipol/simo/pull/17
- Verify verdict: APPROVED (engram #868)
- Design rationale: engram #863
- Spec (first formal capability spec): engram #862 + `openspec/specs/dedupe-safety-net/spec.md`
- Bug analysis (97 rows, 0 deduped): engram #860 (explore phase)
- Deploy guide: DEPLOY.md (updated with supervisor block and ops checklist)
