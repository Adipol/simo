# Isolated PHP-to-Python preview runtime

Test-only CLI recipe for the explicitly selected isolated image. It does not
replace the application image, change Compose or application configuration, or
provide operational acceptance.

The Dockerfile copies only pure-Python BeautifulSoup 4.14.3, soupsieve 2.8.4 and
`typing_extensions` 4.15.0, including distribution metadata, from the existing
Python test image. No donor interpreter, database driver or HTTP client is copied.
This delivery performs no build, pull, package download or installation. The
recipe's source tags do not establish provenance of an existing combined image.

## Explicit synthetic check

`PreviewRuntimeTest.php` uses the public PHP adapter's real bounded transport and
fixed Python script, not a mock runner. One synthetic HTML row must produce its
exact unit, role and person, coherent canonical/result hashes and `incomplete`
validity. An unreviewed inventory is not approval.

The opt-in is per invocation, never an image or application default:

- absent `PEP_PREVIEW_RUNTIME`: one visible skip, expected exit 0;
- empty or unsupported value: one error before interpreter execution, expected exit 2;
- `PEP_PREVIEW_RUNTIME=isolated`: expected one test with 11 assertions, exit 0.

Use an existing verified immutable image, a read-only checkout at `/work` and
existing read-only dependencies at `/work/vendor`, with `/work` as working directory.
The explicit command is:

```sh
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
    --do-not-cache-result tests/Integration/PepDirectory/PreviewRuntimeTest.php
```

The controlled runtime requires PHP >= 8.4 and `/usr/bin/python3` with the three
versions above. Each Docker invocation must use `--pull=never --rm --read-only
--network=none --cap-drop=ALL --security-opt=no-new-privileges --memory=512m
--pids-limit=128`, a 64 MiB `/tmp` tmpfs, `PYTHONDONTWRITEBYTECODE=1` and a bounded
30-second external timeout. No database, Laravel application bootstrap, HTTP,
browser or live capture is involved.

## Fresh delivery evidence

A fresh read-only metadata inspection exited 0 for existing image
`sha256:ccd3ebc5ad2647fb4ac1a02c4346ec29cd0f8355cb2dc7bc6508bde75e146990`.
It reported a null entrypoint, no preview opt-in default, and this command:

```text
php-fpm8.5 --nodaemonize --fpm-config /etc/php/8.5/fpm/php-fpm.conf
```

The initial verification wrapper exited 1 because it incorrectly expected the
command array to contain only `php-fpm8.5`. It stopped before any runtime
invocation. That failed wrapper and its metadata receipts were preserved. A
separate authorized continuation accepted the exact already-observed command
array, without repeating preparation or inspection. Explicit PHP commands override
that image command; the image and its configuration were not changed.

The continuation executed each pending check once, serially, with separate full
argument, stdout, stderr and exit receipts:

- PHP 8.5.6 met the >= 8.4 requirement, exit 0.
- `/usr/bin/python3` reported Python 3.12.3; imports and exact dependency versions
  4.14.3 / 2.8.4 / 4.15.0 passed, exit 0.
- Absent opt-in: 1 test, 0 assertions, 1 skip, exit 0.
- Empty and invalid opt-ins: each 1 test, 0 assertions, 1 pre-interpreter error,
  exit 2 as expected.
- Isolated opt-in: 1 test, 11 assertions, exit 0 (0.082 seconds).

All six runtime invocations had empty stderr. No runtime check was retried. This
is ordinary verification of a historical port, not newly established RED/GREEN
evidence.

The parent separately reported an initial read-only inspection diagnostic caused
by accessing an absent entrypoint key, followed by a successful corrected
inspection. That initial diagnostic is transcript-only evidence, not a captured
runtime failure. Historical build/test outcomes are not current proof.

Even a successful synthetic check would prove only this isolated CLI path, not
fresh-build provenance, the original PHP image, the active web server, production
process policy, live origin or operational readiness. Existing direct-child
transport/destructor limitations still apply.
