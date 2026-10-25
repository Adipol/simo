# Manual directory capture

The configuration panel can register an existing eligible Fuente, request a bounded server-side capture, preview its mapping and explicitly confirm or revoke that configuration. Confirmation does not activate detection or the monitor.

## Disabled by default

`pep_directory.ui_enabled` and `pep_directory.capture_enabled` default to `false`. A configured `PEP_DIRECTORY_PREVIEW_INTERPRETER` alone enables neither. The interpreter must be an absolute server-owned path. Source URLs, eligibility and actor permissions are checked against persisted state; caller-supplied transport metadata is not trusted.

`captureSource()` refuses caller-owned transactions. It checks state before fetching and rechecks the original binding/version and actor afterward. Network work must not run while database locks are held. Transaction-overlay tests cannot establish successful multi-connection capture behavior.

## Transport contract

The Python CLI accepts exactly one JSON object with `url`. It refuses duplicate or extra fields, credentials, fragments, control characters, unsupported schemes, redirects and non-public DNS addresses. One validated address is pinned; Host/SNI retains the original hostname. HTTPS uses system certificate verification; HTTP is explicitly recorded as unencrypted. This is application-level address filtering, not a network sandbox.

No retries, cookies or proxies are used. Responses must use identity encoding; body size is limited to 1 MiB and declared lengths must match. The POSIX CLI imposes an eight-second alarm; PHP adds bounded process supervision. Failed, signalled or partial outputs are never accepted.

Normal success exits 0. Refusal exits 2 with empty stdout and exactly:

```text
capture: request refused or transport failed
```

`--help` does not fetch. The result includes URL identity, status, charset, timestamp, canonical base64 body, SHA-256 and peer/TLS metadata. PHP validates the complete frame before persistence; failures become `CaptureException` with `Directory capture failed.`

## Verification boundary

The consolidated offline Python checks passed 86 tests and 203 subtests; 19 opt-in database tests were skipped, not accepted. DNS/socket/HTTP capture tests use synthetic collaborators, not live TLS evidence. PHP unit checks passed 34 tests/824 assertions. Explicit synthetic PHP-to-Python preview passed 2 tests/20 assertions using an existing image, without network or database access.

Real multi-connection V2 admission and successful network-boundary persistence are not established by those checks. No production qualification, live capture, automatic activation or database migration readiness is implied. Historical diagnostic fixtures and saved local-panel replay are deliberately excluded.
