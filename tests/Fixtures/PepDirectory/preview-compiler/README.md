# Synthetic compiler output

`request.json` contains invented labels and example.invalid; no captures, people,
user IDs or database IDs were copied. The intentionally fragmentary HTML produces
an `incomplete` diagnostic. Exit zero means an artifact, never qualification.

`artifact.json` is a committed historical synthetic producer fixture, ported
verbatim with the request. Its historical generation is not repeated or attested
by this delivery. Hash equality establishes byte integrity, not provenance,
approval or capture origin. No fixture-generation command or historical runtime
receipt is presented as current proof.

The compiler suite reads these bytes and uses an internal closure-fake transport
runner. It does not launch Python. Decoding this fixture is compatibility proof,
not same-runtime PHP-to-Python execution or application readiness.

## Real isolated PHP transport fixtures

The plain PHPUnit transport suite executes **only** `PHP_BINARY` with these fixed
scripts, inside a read-only, network-none PHP test container:

- `success.php`: bounded stdin echo, ten stderr bytes, exit 0.
- `nonzero.php`: seven stdout bytes, six stderr bytes, exit 7.
- `duplex.php`: at most 256 reads of 8 KiB, interleaved output and stderr.
- `exact-caps.php`: exactly 8 MiB stdout and 8 KiB stderr.
- `stdout-overflow.php`: at most 8 MiB + 8 KiB stdout.
- `stderr-overflow.php`: at most 8 KiB + 1 stderr byte.
- `early-close.php`: closes stdin and emits a fixed partial marker.
- `slow.php`: active hashing bounded by one second and one million iterations;
  the transport test imposes a shorter 0.1-second deadline and observes exit 137.

None writes files, opens listeners, uses a network, sleeps indefinitely or spawns
descendants. Tests use at most two-second execution deadlines and transport
cleanup adds a bounded direct-child reap allowance of 0.5 seconds. No PHP fixture
launches Python. These are isolated direct-child transport tests, not application
runtime probes or descendant containment. Unsupported kernel paths and implicit
PHP process-resource destruction do not have an unconditional deadline guarantee.

The transport inherits the server environment. Python `-E -s` flags are not a
sandbox; the interpreter and argv must remain trusted server inputs. Output and
hashes are not approval or evidence of origin.
