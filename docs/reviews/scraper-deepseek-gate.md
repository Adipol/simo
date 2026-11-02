# DeepSeek Scraper title gate review chain

Issue: https://github.com/Adipol/simo/issues/143
Strategy: feature-branch chain; tracker is draft/no-merge until reviewed children are integrated.

## Review order
1. Validated title client, disabled configuration and nullable persistence.
2. Guarded routing and shared pending eligibility.
3. Held-title access, authorized manual bypass and factual status.
4. Compatibility coverage and verification evidence.

The tracker starts with this document only so each child targets an actual ancestor.
Source commits are copied onto this documentation-only base; original implementation history remains untouched.
Each copied work unit must have an identical diff to its original work unit.

## Evidence and limits
Full local PHP 8.5/SQLite run: 1482 tests, 4619 assertions, zero failures/errors, 81 skipped and one incomplete.
Focused PostgreSQL: 267 tests/1267 assertions; PHP 8.4/SQLite: 266/1221 before compatibility coverage.
Offline asset build and six-group PostgreSQL differential passed; independent evidence review accepted.
These are local results, not remote CI approval or evidence of classifier accuracy.

## Publication boundary
Both test-pgsql and test-sqlite must pass before merge. Intermediate feature branches do not trigger the existing PR-to-main workflow automatically.
No merge, production migration, activation, deployment or manual CI dispatch is authorized by publication.
Only the final tracker closes #143 when eventually merged; child PRs reference it without closing.
