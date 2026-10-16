# UMSA authority extraction pilot

## Intent and boundaries
Local implementation authorized for `https://www.umsa.bo/web/guest/rectorado` only. Originally developed on `feat/umsa-authority-extraction` from `5258c761773087d7200699019edbe55158c414ba`; delivery now includes local commits below. Existing `.gitignore` change remains excluded. No push, PR, deploy, VPS access, secrets or host runtime installs authorized. General source coverage and general encoding remediation remain out of scope.
Official DOM mu8tho4a2qg87z contains one explicit Rectorado header (person/role), not an institutional roster. Historical biography/menu/contact are not current-authority evidence.

## Implemented acceptance
- Single complete unambiguous profile header; incomplete/duplicate structures preserve baseline and record parse_error.
- First/invalid/legacy baseline stores coherent hash/text/method/roster and emits no appointment, including next identical run.
- Header-only comparison; cosmetic Ph.D./PhD identity-neutral/display preserved. Canonical replacement/cargo events and no text candidates; existing source/removal safeguards preserved.
- UI allows UMSA and Divi, rejects unknown identifiers. Exact-URL migration activates only NULL/empty extractor; rollback preserves later different configuration and non-target sources.

## Route and delivery
Organic delegated work, one writer; TDD enabled by openspec/config.yaml strict_tdd:true. Runtime checks used disposable Docker with nonsecret code. Python tests network-none; PHP isolated PostgreSQL17/pg_trgm, Composer and Node24/Vite. No production behavior claim.
Forecast320–450 authored lines was advisory. Delivery is now split into local commits: W5a `addb29805b6bd2a0349442a7afd68bbacbc62bd5` (UMSA Python adapter, monitor and tests, following generic monitor W4a `f593ad4d6806770cb0c133ae404f52fb67c111b9` and W4b `9773d4b9015bb3e65208d85334d009ed1c8502e6`); W5b `4426d013c9f2ae754d4332bfee5a27d28d487b6b` (exact-URL activation migration/tests); W5c `08e5c82609fcf1c49e806bb07336d2b81fb2da5c` (UI option, allowlist and tests). Generic preview UI W5d `bc02470e5bd04a490356f9d196430962eb330416` and cross-runtime probes W6a `cc16c8dd3fd6d170331e7279e189fd0d47741c57` followed. Local commits do not authorize push, PR or deployment.

## Tasks
- [x] U1: Select source and inspect official DOM.
- [x] U2: Establish Docker test wrappers.
- [x] U3: Implement Python extraction/baseline and verify independently.
- [x] U4: Implement Laravel allowlist/option/migration/tests and verify.
- [x] U4a: Repair isolated PHP test image and verify modules.
- [x] U5: Candidate assessment fallback, independent verification, parent-requested spot checks and final structural readback.

## Verification evidence
U3 writer initial RED6/28pass then34/212pass. Independent check found3 gaps; correction RED3/33pass then36/214pass. Independent mu92s2uq-3-uwzm confirmed214pass, closed legacy next-run noise, malformed baseline and status contract gaps, cleanup confirmed. Historical RED is writer-only evidence.
U4 initial setup failures were not RED. Separate diagnosis fixed missing pdo_pgsql/intl and malformed probe. Writer mu9sr7n5-1-y86z then observed expected RED3 failures/12assertions, implemented five files. Its final check blocked on mistyped image digest, not tests.
Independent mu9sywgo-2-1wn7 verified correct pinned image and full scoped wrapper: exit0, Composer/npm/Vite passed, Pint4files passed, focused8tests with24assertions/no failures. All8 warned because intentionally isolated /work/.env is absent; not claimed pre-existing. The full Laravel suite was not run at this earlier U4 check. git diff --check passed. Final source review found no blocking U4 issue.
Native assessment unavailable(empty output), RDD off: applied fail-closed independent-verification plan, not native review approval. Independent runs include parent-requested command spot checks. Parent final readback confirmed only intended application changes, exact migration predicates, expected untracked fixtures/tests and preserved unrelated .gitignore.

## Resources and next step
No task containers/volumes/networks remain per independent checks. Derived test image simo-umsa-php:local retained intentionally: sha256:33664f0836340f1cdf06a937227ea2926bc72f93806410f97d1727cd7c897173, seven modules verified. No host dependency installs.
Implementation committed locally, not deployed. At W6a `cc16c8d`, independent Docker verification passed Python focused 162/full 340 and full PostgreSQL 17 PHPUnit **1,391 tests / 4,175 assertions (exit 0)**, plus Pint on four files and Vite; PHPUnit reported 1 skipped, 1 risky zero-assertion test and 2 metadata deprecations. See [feature task logs](configurable-authority-extraction.md) for exact run paths and limits. These checks do not establish browser behavior, live URL access or deployment. Next: seek separate authorization before publication or deployment; confirm production source URL/configuration before activation. First successful UMSA run establishes baseline; only later observed differences become events. No historical appointment reconstruction or automatic activation of other sources.
