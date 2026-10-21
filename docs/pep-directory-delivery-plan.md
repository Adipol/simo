# PEP directory delivery plan

Refs #111.

Deliver directory-based PEP role transitions as a reviewed feature-branch chain.
This document is the integration tracker, not evidence of operational readiness.
All delivery PRs stay draft; this plan does not authorize merge or deployment.

## Review path

1. Review this tracker on `feat/pep-directory-integration` against `main`.
2. Review `feat/pep-directory-01-core` against the integration tracker.
3. Review holder-group support as the next dependent unit.
4. Prepare later units individually, with their own scope and verification.

The first child targets the feature integration branch, not `main`.
Each later child targets its immediate predecessor until integration advances.
Keep the tracker draft and marked **do not merge** while the chain is incomplete.
Use nonclosing `Refs #111` references throughout the chain.
Publication and CI results do not imply permission to merge or activate anything.

## First unit: isolated comparison core

The first child contains only the pure Python comparison module, its tests,
and its public contract documentation. It changes no existing entrypoint.

- Compare institutional role identities and preserve observation evidence.
- Keep behavior, tests, and contract documentation in one cohesive review unit.
- Retain the accepted 548-line scope: 188 implementation, 240 tests, 120 docs.
- Verify the delivered source with the focused 16-test offline suite.

This initial contract intentionally rejects duplicate holders for a role.
It is not the final multi-holder behavior and must not be presented as such.

## Next unit and later delivery

Holder-group support follows separately: compare outgoing and incoming groups
without inventing individual replacement pairings when several people change.

Extraction, persistence, review interfaces, and integration follow only as
individually prepared and reviewed units, not as an implied completed bundle.
Keep each unit's tests and relevant documentation with its behavior.
Do not shrink code or omit checks merely to meet an advisory review size.

## CI and acceptance gates

Current CI automatic PR and push triggers cover `main` only; the workflow also
supports `workflow_dispatch`. Child feature PRs therefore require manual CI
through that dispatch mechanism, not an assumed automatic green check.

Local focused checks are bounded evidence, not a substitute for full CI.
Record the actual CI outcome for each published candidate before advancing.
Browser acceptance remains pending; component tests do not establish it.

## Operational and privacy boundaries

Activation stays off: this delivery does not enable the panel, capture,
automatic monitoring, or production processing.
No merge, deployment, database migration, or runtime change is implied.
Keep private captures, receipts, credentials, task logs, and personal data out
of public commits and PR descriptions; use synthetic test evidence only.

Before any adoption, require explicit authorization and separately establish
browser acceptance, source qualification, and operational readiness.
