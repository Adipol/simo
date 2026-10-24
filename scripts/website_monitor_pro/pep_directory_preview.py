"""Bounded offline, unreviewed preview compilation; no transport or trust authority."""
import base64
import codecs
from datetime import datetime
from hashlib import sha256
import json
import re
import sys

from pep_directory_codec import encode_observation
from pep_directory_configurable import DirectoryConfig, extract_configured
from pep_directory_extractors import Capture

MAX_INPUT_BYTES = 2_097_152
MAX_BODY_BYTES = 1_048_576
MAX_OUTPUT_BYTES = 8_388_608  # Includes final newline; evidence is never truncated.
_ERROR = 'preview: invalid input or artifact limit\n'
_HELP = '''Compile one unreviewed preview JSON envelope from UTF-8 stdin to JSON stdout.
Usage: python pep_directory_preview.py [--help]
Required keys: declaration (DirectoryConfig.from_dict), body_base64 (strict base64),
metadata: {status: integer 100..599, source_url: exact declaration URL,
observed_at: aware ISO timestamp, encoding: declared Python text encoding}.
No files, fetching, inventories, approval fields or operational eligibility.
Limits: stdin 2097152 bytes; raw body 1048576 bytes; stdout 8388608 bytes.
Exit 0 means artifact emitted only, NOT qualification. Invalid input/overflow:
exit 2, sanitized stderr, no artifact. Format version is serialization only.
'''


def _require(ok: bool) -> None:
    if not ok:
        raise ValueError('invalid preview input or artifact limit')


def _json_bytes(value: object) -> bytes:
    return json.dumps(value, ensure_ascii=True, allow_nan=False,
                      separators=(',', ':')).encode('ascii')


def _b64(value: bytes) -> str:
    return base64.b64encode(value).decode('ascii')


def _json_tree(value: object, depth: int = 0) -> None:
    # Only plain JSON types; reject control NUL and malformed Unicode before codec.
    _require(depth <= 16)
    if type(value) is dict:
        for key, item in value.items():
            _require(type(key) is str)
            _json_tree(key, depth + 1)
            _json_tree(item, depth + 1)
    elif type(value) is list:
        for item in value:
            _json_tree(item, depth + 1)
    elif type(value) is str:
        _require('\x00' not in value)
        value.encode('utf-8', errors='strict')
    else:
        _require(value is None or type(value) in (int, bool))


def compile_preview(envelope: object) -> dict:
    """Return a detached JSON artifact or raise sanitized ValueError; never I/O.

    Data errors reject the request. Extractor diagnostic outcomes are artifacts,
    always without inventory attestation. Byte identities are Python-produced.
    """
    try:
        _json_tree(envelope)
        serialized = _json_bytes(envelope)
        _require(len(serialized) <= MAX_INPUT_BYTES)
        data = json.loads(serialized)  # detach caller-owned declaration/metadata
        _require(type(data) is dict and set(data) == {'declaration', 'body_base64', 'metadata'})
        config = DirectoryConfig.from_dict(data['declaration'])
        metadata = data['metadata']
        _require(type(metadata) is dict and set(metadata) ==
                 {'status', 'source_url', 'observed_at', 'encoding'})
        _require(type(metadata['status']) is int and 100 <= metadata['status'] <= 599)
        _require(type(metadata['source_url']) is str and metadata['source_url'] == config.url)
        _require(type(metadata['observed_at']) is str and len(metadata['observed_at']) <= 64)
        observed = datetime.fromisoformat(metadata['observed_at'])
        _require(observed.utcoffset() is not None)
        encoding = metadata['encoding']
        _require(type(encoding) is str and bool(re.fullmatch(r'[A-Za-z0-9_.-]{1,64}', encoding)))
        _require(codecs.lookup(encoding)._is_text_encoding)
        encoded = data['body_base64']
        _require(type(encoded) is str and len(encoded) <= 4 * ((MAX_BODY_BYTES + 2) // 3))
        body = base64.b64decode(encoded, validate=True)
        _require(len(body) <= MAX_BODY_BYTES and _b64(body) == encoded)
        capture = Capture(metadata['status'], metadata['source_url'], observed, frozenset(), encoding)
        wire = encode_observation(extract_configured(config, body, capture))
        metadata['observed_at'] = wire['observed_at']  # codec timestamp, including offset
        result_bytes = _json_bytes(wire)  # C1 exact encoding: not sorted, ASCII escaped
        canonical = config.canonical_identity().encode('utf-8')
        artifact = {'format_version': 1,
                    'configuration': {'declaration': data['declaration'],
                                      'canonical_base64': _b64(canonical), 'revision': config.revision},
                    'capture': {'body_base64': encoded, 'content_sha256': sha256(body).hexdigest(),
                                'metadata': metadata},
                    'preview': {'wire': wire, 'result_base64': _b64(result_bytes),
                                'result_sha256': sha256(result_bytes).hexdigest(),
                                'validity': wire['validity']}}
        _require(len(_json_bytes(artifact)) + 1 <= MAX_OUTPUT_BYTES)
        return artifact
    except (ValueError, TypeError, LookupError, OverflowError, RecursionError):
        raise ValueError('invalid preview input or artifact limit') from None


def _pairs(pairs: list) -> dict:
    result = {}
    for key, value in pairs:
        _require(key not in result)
        result[key] = value
    return result


def _constant(value: str) -> None:
    raise ValueError('nonfinite JSON number')


def main() -> int:
    """ONE stdin envelope, ONE preflighted stdout artifact; no filepath API."""
    if sys.argv[1:] == ['--help']:
        sys.stdout.write(_HELP)
        return 0
    try:
        _require(not sys.argv[1:])
        raw = sys.stdin.buffer.read(MAX_INPUT_BYTES + 1)
        _require(len(raw) <= MAX_INPUT_BYTES)
        data = json.loads(raw.decode('utf-8'), object_pairs_hook=_pairs, parse_constant=_constant)
        output = _json_bytes(compile_preview(data)) + b'\n'
        _require(len(output) <= MAX_OUTPUT_BYTES)
    except (ValueError, TypeError, LookupError, OverflowError, RecursionError):
        sys.stderr.write(_ERROR)
        return 2
    sys.stdout.buffer.write(output)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
