# Isolated PEP monitor test runtime

Test-only recipe for a Python 3.12 runtime with pinned dependencies. Only
`tests/Fixtures/PepDirectory/python-shadow-runtime` may be used as the build
context, never the repository root. Keep application files, credentials, dotenv
files, private data and evidence out of that context.

The base image is pinned by digest; dependencies are pinned in `requirements.txt`.
The recipe permits binary wheels only, disables implicit dependency resolution
and source builds, and includes a dependency compatibility check. It does not
include pytest or browser/PDF runtimes.

Library availability or successful imports do not prove safe monitor bootstrap
or monitor–adapter–PostgreSQL integration. Importing `pep_monitor` requires
pre-import dotenv/log isolation and separate authorization; it is not a harmless
library check.

This delivery authorizes no fresh build, pull, dependency download, installation,
container launch, network or database access, or monitor import. No fresh build,
download execution, recipe-to-image provenance or integrated behavior is proven
here. Existing-image runtime checks and independent assessment remain separate.
No application activation, live capture or deployment is authorized.

This historical recipe port has no meaningful behavioral RED test: verification
is limited to structural checks and Git source comparisons, not runtime proof.
