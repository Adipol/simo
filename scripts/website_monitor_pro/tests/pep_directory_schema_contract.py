"""Exact owned-fixture metadata matching, not database introspection."""
import hashlib
import json
from pathlib import Path

_CONTRACT = Path(__file__).resolve().parents[3] / 'tests/Fixtures/PepDirectory/schema-contracts/contract.json'
# Pin the complete approved structure, not merely decodable JSON or table counts.
_CONTRACT_SHA256 = '48d59444f9d9a045ad1293558a8bbd12f3d111c10fd79945b3e41806b4d901a7'


def _matches(expected, actual):
    # Walk only the fixed expected tree: no traversal/serialization of unknown input.
    if type(expected) is not type(actual):
        return False
    if type(expected) is dict:
        return (expected.keys() == actual.keys()
                and all(_matches(value, actual[key]) for key, value in expected.items()))
    if type(expected) is list:
        return (len(expected) == len(actual)
                and all(_matches(value, actual[i]) for i, value in enumerate(expected)))
    return expected == actual


def validate(snapshot):
    """Return v1, unattested_v2, schema_mismatch, or contract_unavailable.

    Only complete normalized JSON metadata for owned test fixtures is supported.
    No supplied version/path, SQL execution, introspection, or input mutation.
    """
    try:
        raw = _CONTRACT.read_text(encoding='utf-8')
        contract = json.loads(raw)
    except (OSError, ValueError):
        return 'contract_unavailable'
    if type(contract) is not dict or set(contract) != {'format', 'v1', 'v2_design'}:
        return 'contract_unavailable'
    for key, version, count in (('v1', 'v1', 6), ('v2_design', 'v2', 10)):
        section = contract[key]
        if (type(section) is not dict
                or set(section) != {'version', 'schema', 'owner', 'tables', 'triggers', 'functions'}
                or section['version'] != version
                or type(section['tables']) is not dict or len(section['tables']) != count
                or type(section['triggers']) is not dict or type(section['functions']) is not dict):
            return 'contract_unavailable'
    # Refuse nested omissions too; do not trust malformed expected == malformed input.
    if hashlib.sha256(raw.encode('utf-8')).hexdigest() != _CONTRACT_SHA256:
        return 'contract_unavailable'
    if _matches(contract['v1'], snapshot):
        return 'v1'
    if _matches(contract['v2_design'], snapshot):
        return 'unattested_v2'  # Never an installed-schema success.
    return 'schema_mismatch'
