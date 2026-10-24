"""Pure shared-fixture contract tests; no application or database imports."""
import copy
import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[3]
CONTRACT = ROOT / 'tests/Fixtures/PepDirectory/schema-contracts/contract.json'
MODULE = Path(__file__).with_name('pep_directory_schema_contract.py')


class SchemaContractTest(unittest.TestCase):
    def validator(self):
        self.assertTrue(MODULE.is_file(), 'public validate(snapshot) API must exist')
        spec = importlib.util.spec_from_file_location('contract_validator', MODULE)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        self.assertTrue(callable(getattr(module, 'validate', None)))
        return module.validate

    def test_malformed_fixed_contract_is_unavailable(self):
        validate = self.validator()
        original = json.loads(CONTRACT.read_text())
        cases = [('null_sections', {'v1': None, 'v2_design': None}, None),
                 ('null_root', None, None), ('empty_root', {}, None)]
        for section in ('v1', 'v2_design'):
            for label, value in (('null', None), ('scalar', 'broken'), ('empty', {})):
                broken = copy.deepcopy(original)
                broken[section] = value
                cases.append((section + '_' + label, broken, value))
            broken = copy.deepcopy(original)
            del broken[section]
            cases.append((section + '_missing', broken, None))
            for part in ('tables', 'triggers', 'functions', 'version', 'owner', 'schema'):
                broken = copy.deepcopy(original)
                del broken[section][part]
                cases.append((section + '_missing_' + part, broken, broken[section]))
            broken = copy.deepcopy(original)
            del broken[section]['tables']['fuentes']['columns']['label']
            cases.append((section + '_truncated_columns', broken, broken[section]))
        for label, manifest, snapshot in cases:
            with self.subTest(case=label):
                before = copy.deepcopy(snapshot)
                with patch.object(Path, 'read_text', return_value=json.dumps(manifest)):
                    result = validate(snapshot)
                self.assertEqual(result, 'contract_unavailable', label)
                self.assertEqual(snapshot, before)
        # Fault injection never changes the genuine fixed file or normal outcomes.
        self.assertEqual(validate(original['v1']), 'v1')
        self.assertEqual(validate(original['v2_design']), 'unattested_v2')

    def test_public_api(self):
        self.validator()

    def test_shared_inventory_and_fail_closed(self):
        validate = self.validator()
        contract = json.loads(CONTRACT.read_text())
        v1, v2 = contract['v1'], contract['v2_design']
        self.assertEqual(len(v1['tables']), 6)
        self.assertEqual(len(v2['tables']), 10)
        self.assertEqual(v1['tables']['fuentes']['columns'].keys(), {'id', 'label'})
        before = copy.deepcopy(v1)
        self.assertEqual(validate(v1), 'v1')
        self.assertEqual(v1, before)
        self.assertEqual(validate(v2), 'unattested_v2')
        cases = [None, [], 'v1', {'version': 'v1'}, {'manifest_path': str(CONTRACT)},
                 dict(v1, version='v2'), dict(v1, owner='other'),
                 dict(v1, triggers={'unexpected': {}}), dict(v1, functions={'body': 'anything'})]
        # Each inventory dimension is independently required, including empty metadata.
        for section in ('tables', 'triggers', 'functions', 'owner', 'schema'):
            changed = copy.deepcopy(v1)
            del changed[section]
            cases.append(changed)
        for section in ('columns', 'constraints', 'indexes'):
            for operation in ('missing', 'extra', 'altered'):
                changed = copy.deepcopy(v1)
                entries = changed['tables']['pep_directory_sources'][section]
                key = next(iter(entries))
                if operation == 'missing':
                    del entries[key]
                elif operation == 'extra':
                    entries['unknown'] = entries[key]
                else:
                    entries[key] = None
                cases.append(changed)
        for position, replacement in ((0, 'integer'), (1, True), (2, '1')):
            changed = copy.deepcopy(v1)
            changed['tables']['pep_directory_sources']['columns']['version'][position] = replacement
            cases.append(changed)
        for table in v1['tables']:
            changed = copy.deepcopy(v1)
            del changed['tables'][table]
            cases.append(changed)
        hybrid = copy.deepcopy(v1)
        hybrid['tables']['fuentes'] = v2['tables']['fuentes']
        cases.extend([hybrid, dict(v2, functions={}), dict(v2, triggers={})])
        for section, name in (('triggers', 'pep_fuente_invalidate'), ('functions', 'pep_invalidate_source')):
            changed = copy.deepcopy(v2)
            changed[section][name]['owner' if section == 'functions' else 'enabled'] = 'unexpected'
            cases.append(changed)
        extra = copy.deepcopy(v1)
        extra['tables']['unknown'] = extra['tables']['users']
        cases.append(extra)
        for snapshot in cases:
            with self.subTest(snapshot_type=type(snapshot).__name__):
                original = copy.deepcopy(snapshot)
                self.assertEqual(validate(snapshot), 'schema_mismatch')
                self.assertEqual(snapshot, original)
        # No version or path override parameters, even pointing at the genuine file.
        for keyword in ('version', 'manifest_path'):
            with self.assertRaises(TypeError):
                validate(v1, **{keyword: 'v1'})
        self.assertEqual(validate(dict(reversed(list(v1.items())))), 'v1')

    def test_prospective_design_is_not_attestation(self):
        validate = self.validator()
        design = json.loads(CONTRACT.read_text())['v2_design']
        v1 = json.loads(CONTRACT.read_text())['v1']
        for table, metadata in v1['tables'].items():
            for section, entries in metadata.items():
                for key, value in entries.items():
                    self.assertEqual(design['tables'][table][section][key], value)
        self.assertIsNone(design['functions']['pep_invalidate_source']['body_sha256'])
        self.assertEqual(design['tables']['pep_directory_sources']['columns']['configurable'], ['boolean', False, 'false'])
        self.assertEqual(validate(design), 'unattested_v2')
        altered = copy.deepcopy(design)
        altered['functions']['pep_invalidate_source']['body_sha256'] = '0' * 64
        self.assertEqual(validate(altered), 'schema_mismatch')
        # bool is not an integer in normalized JSON metadata.
        altered = json.loads(CONTRACT.read_text())['v1']
        altered['tables']['fuentes']['columns']['id'][1] = 0
        self.assertEqual(validate(altered), 'schema_mismatch')
