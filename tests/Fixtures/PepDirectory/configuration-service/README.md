# Synthetic stored candidate

`candidate.json` is an exact historical synthetic fixture. The Python recipe below documents its intended derivation; it was not rerun for this delivery. Byte hashes establish export identity, not trusted producer provenance.

Reproduction body (reference only, never live HTTP):

```python
import json
from datetime import datetime
from hashlib import sha256
from pep_directory_configurable import DirectoryConfig, extract_configured
from pep_directory_extractors import Capture
from pep_directory_codec import encode_observation

d = {"source": "synthetic-c1", "url": "https://example.invalid/directory",
     "directory": "table", "record": "tr", "columns": 2,
     "fields": {"unit": {"constant": "Synthetic unit"},
                "role": {"column": 0}, "person": {"column": 1}}}
c = DirectoryConfig.from_dict(d)
body = "<html><body><table><tr><td>Director</td><td>Synthetic Alpha</td></tr></table></body></html>"
cap = Capture(200, c.url, datetime.fromisoformat("2026-10-18T12:00:00+00:00"),
              frozenset(), "utf-8")
w = encode_observation(extract_configured(c, body.encode(), cap))
result = json.dumps(w, ensure_ascii=True, separators=(",", ":"))
print(c.canonical_identity(), c.revision, w, result,
      sha256(body.encode()).hexdigest(), sha256(result.encode()).hexdigest())
```

Fixture revision: `configured-html-v1:ab787410a9df3660580344461d8671aba22448336ca30bbce12397c9885bd7a3`.
Fixture body SHA256: `bfffa215e5c1d7ec2aa87107aea889a35688b55674ff4ffca051704d5a87ed0b`.
Fixture result SHA256: `d50dc93cbce699b02d99a6dd6938c761f4eb06659fcf6d34e1612535729195e8`.
The wire is `incomplete` WITH one position because no reviewed inventory was
provided. Structural rejection instead has no positions. This does not establish
trusted origin or capture completeness beyond the existing extractor contract.
The fixture represents stored input from the future trusted bridge; test-only
SQL is not an ingestion API. Names/HTML/URL are synthetic.

Current candidate verification used this unchanged fixture in one PostgreSQL
`ConfigurationServiceTest` run: PHP 8.5.6 / PHPUnit 11.5.55, 8 tests / 705 assertions,
exit 0, empty stderr. Full retained rows and schema objects matched before/after;
sequence values were not compared or reset. The initial Docker 125 mount failure
occurred before PHP and remains recorded separately; execution resumed only after
explicit authorization for an empty vendor mountpoint. No fixture regeneration,
dependency installation or trusted-producer attestation is implied.
