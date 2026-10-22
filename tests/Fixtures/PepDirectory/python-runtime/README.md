# Isolated Python PostgreSQL test runtime

Test-only prerequisite for directory persistence checks. It does not replace the
PHP runtime, install host packages, change production requirements or authorize
writes to existing databases. The Dockerfile pins the official Python 3.12 base
digest and installs only `psycopg2-binary==2.9.12`, already a project dependency.

## Build boundary

When explicitly authorized, run from the repository root:

```sh
docker build --pull=false --tag simo-pep-directory-test:py312-psycopg2912 tests/Fixtures/PepDirectory/python-runtime
```

Use this directory as the entire context, never the repository root. No environment
files, captures, credentials or database dumps belong in the context. A build can
need network access for the pinned base and PyPI artifact; permission to execute an
existing image is not permission to download or install dependencies.

## Execution boundary

Record and use the immutable image ID with `--pull=never`, `--read-only`, dropped
capabilities and a read-only checkout mounted at `/work`. Pure tests use
`--network=none`. A database probe may join only a newly provisioned, explicitly
authorized test PostgreSQL container's network namespace after verifying its exact
ID, owner, image, no external network, no published ports and no host data volumes.
Independently verify the selected database; a name alone does not prove ownership.
Never import the live monitor, load project environment files, run producers,
change server networking or connect to original/diagnostic/reference databases.

Pure comparison check inside that runtime:

```sh
python -m unittest discover -s scripts/website_monitor_pro/tests -p test_pep_directory.py
```

The runtime does not include BeautifulSoup; it does not claim adapter-suite coverage.
A read-only driver smoke should verify `current_database()` and the expected table
inventory, then roll back. It must not install a schema or mutate stored data.
A working driver does not establish transactional persistence or a reproducible
fresh build; those are separate checks. No production activation is included.

## Current delivery verification

The existing immutable image was executed without rebuilding or downloading.
Python 3.12.15 and psycopg2-binary 2.9.12 were confirmed; 26 pure comparison tests
passed with no network (exit 0). The read-only driver transaction confirmed the
exact independently owned synthetic target and zero public tables, then rolled
back (exit 0). No schema or data was installed. Fresh build reproducibility and
BeautifulSoup-dependent adapter checks were not performed for this runtime.
