# DeepSeek review and CI corrections

Scope: resolve the two published-review blockers, retain historical data, update the affected chain and verify it. No merge to main or deployment.

## Specs
S1. User approved: “¿Autorizás corregir ambos, agregar las pruebas necesarias, actualizar las ramas afectadas y ejecutar nuevamente el CI? **Sin tocar datos históricos, fusionar ni desplegar.**” Response: “autorizo”. Correct the queue-starvation finding without changing historical records, and isolate the historical migration test from observer-driven execution while columns are deliberately absent.
S2. Preserve existing authorized title-gate behavior and shared sibling paths; publish only fast-forward updates to affected review branches and run fresh CI for their exact commits. No changes to main, activation or production data.

## Tasks
- F1 [done] S1-S2 | inline | Reproduce both failures in isolated state, apply minimal fixes and run focused/regression checks. | commit: pending
- F2 [done] S1-S2 | independent verifier | Review correction and verification evidence. | commit: pending
- F3 [in_progress] S2 | inline | Commit, propagate to PR146–148 without force pushes and confirm fresh CI. | commit: pending

## Log
L1. User request: “autorizo”. Preceding proposal: “Son **dos problemas distintos**:\n\n1. **Bloqueo de cola:** una noticia inconsistente puede impedir que avancen las demás.\n2. **Fallo del CI:** la prueba de migración elimina temporalmente las columnas, pero un observer ejecuta el análisis antes de restaurarlas.\n\n#145 pasó completo; #146–#148 fallaron en PostgreSQL.\n\n¿Autorizás corregir ambos, agregar las pruebas necesarias, actualizar las ramas afectadas y ejecutar nuevamente el CI? **Sin tocar datos históricos, fusionar ni desplegar.**”
L2. Baseline PR146 b298e4f564e76cbc3bdc889d575f56d9e8276dc4. Dedicated registered worktree storage/app/scraper-deepseek-fixes. Existing original candidate and failed receipts preserved. Harness reuses reviewed fail-closed container guards, fresh snapshots/databases and fake HTTP; no environment files or live providers.

L3. RED job22: PostgreSQL2 tests/2 assertions, one missing-column error and one queue-progress failure. GREEN jobs23/24: PostgreSQL17 and SQLite each254tests/1102assertions PASS, cleanup accepted, source snapshots564 files. Minimal scope timestamp exclusion and historical fixture withoutEvents applied; historic attributes preserved by test. F1 still awaits sibling-command differential and docs; F2 independent assessment required by item3 shared scope, RDD off/native unassessable. No publication of corrections yet.

L4. Supplemental differential job25 stopped at its strict stderr gate before launching the current variant. Baseline command returned0 and one DIFFERENTIAL_RESULT (Pro:1 pending, one Pro job, no Flash job, historical row unchanged), but stderr contained the migration INFO message `M3a dedup: no duplicate (url, categoria) rows found — nothing to delete.` This is a harness setup-log separation failure, not a demonstrated product regression or successful differential. Failed receipt preserved; no retry. Independent verification mvztdf66-n-8ytz remains running once on its separate fresh PG fixture. F1/F2/F3 not complete; no correction commit/push/CI yet.

L5. User: “autorizo para separar esas salidas y ejecutar un único par nuevo; después continúo con la publicación y el CI ya\n autorizados”. Prepare R2 helper copies only: setup logs retained separately, real Symfony stdout/stderr capture reused; original failed files untouched. Independent verifier final S1 PASS, no P1/P2 regression; S2 needs differential/publication/CI. Independent PG254/1102, matching snapshots/cleanup accepted. Focused stderr contains existing Vite permission-page logs, not frontend proof.

L6. Authorized differential R2 job26 PASS exit0: one fresh baseline/current pair, separate owned PostgreSQL databases, setup output/logs retained separately, actual Symfony stdout/stderr capture. Exact matched observable: exit0, stdout `Pro: 1 pendientes\n`, stderr empty, one Pro job, zero Flash jobs, historical record unchanged. Both cleanup accepted. Failed first attempt remains intact. Independent narrow-fix review accepted S1/no P1/P2, and parent differential completes local preservation proof. F1/F2 complete; F3 now committing and propagating, remote CI remains pending.
