"""Synthetic API and actual stdin/stdout CLI contract; no external services."""
import base64
from copy import deepcopy
from hashlib import sha256
import json
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
import pep_directory_preview as preview
from pep_directory_codec import decode_observation
from pep_directory_configurable import DirectoryConfig


def request(body=None):
    return {'declaration': {
        'source': 'synthetic-c1', 'url': 'https://example.invalid/directory',
        'directory': 'table', 'record': 'tr', 'columns': 2,
        'fields': {'unit': {'constant': 'Synthetic unit'},
                   'role': {'column': 0}, 'person': {'column': 1}}},
        'body_base64': base64.b64encode(body if body is not None else
            b'<html><body><table><tr><td>Director</td><td>Synthetic Alpha</td></tr></table></body></html>').decode(),
        'metadata': {'status': 200, 'source_url': 'https://example.invalid/directory',
                     'observed_at': '2026-10-18T12:00:00Z', 'encoding': 'utf-8'}}


class PreviewTests(unittest.TestCase):
    def cli(self, value=None, raw=None, args=()):
        return subprocess.run([sys.executable, '-B', str(ROOT / 'pep_directory_preview.py'), *args],
                              input=raw if raw is not None else json.dumps(value).encode(),
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=8, check=False)

    def integrity(self, artifact, envelope):
        config, capture, result = (artifact[k] for k in ('configuration', 'capture', 'preview'))
        self.assertEqual(config['declaration'], envelope['declaration'])
        canonical = base64.b64decode(config['canonical_base64'], validate=True)
        self.assertEqual(canonical.decode(), DirectoryConfig.from_dict(envelope['declaration']).canonical_identity())
        self.assertEqual(config['revision'], 'configured-html-v1:' + sha256(canonical).hexdigest())
        body = base64.b64decode(capture['body_base64'], validate=True)
        self.assertEqual(body, base64.b64decode(envelope['body_base64']))
        self.assertEqual(capture['content_sha256'], sha256(body).hexdigest())
        wire_bytes = base64.b64decode(result['result_base64'], validate=True)
        self.assertEqual(json.loads(wire_bytes), result['wire'])
        self.assertEqual(result['result_sha256'], sha256(wire_bytes).hexdigest())
        self.assertEqual(result['validity'], 'incomplete')
        self.assertEqual(capture['metadata']['observed_at'], result['wire']['observed_at'])
        decode_observation(result['wire'])
        self.assertNotIn('approved', artifact)
        return result['wire']

    def test_api_deterministic_original_bytes_and_c1_compatibility(self):
        envelope = request()
        original = deepcopy(envelope)
        artifact = preview.compile_preview(envelope)
        self.assertEqual(artifact, preview.compile_preview(envelope))
        self.assertEqual(envelope, original)
        wire = self.integrity(artifact, envelope)
        self.assertEqual(wire['positions'][0]['person'], 'Synthetic Alpha')
        self.assertEqual(wire['observed_at'], '2026-10-18T12:00:00+00:00')
        # Frozen C1 synthetic bytes/hash; no PHP canonical recreation.
        self.assertEqual(artifact['preview']['result_sha256'], 'd50dc93cbce699b02d99a6dd6938c761f4eb06659fcf6d34e1612535729195e8')

    def test_cli_exact_success_and_help(self):
        envelope = request()
        child = self.cli(envelope)
        self.assertEqual((child.returncode, child.stderr), (0, b''))
        self.assertEqual(child.stdout, (json.dumps(preview.compile_preview(envelope), ensure_ascii=True, separators=(',', ':')) + '\n').encode())
        self.integrity(json.loads(child.stdout), envelope)
        help_result = self.cli(raw=b'', args=('--help',))
        self.assertEqual((help_result.returncode, help_result.stderr), (0, b''))
        self.assertIn(b'stdin', help_result.stdout)
        self.assertIn(b'unreviewed', help_result.stdout)

    def test_body_and_mapping_identity_and_offset(self):
        a = preview.compile_preview(request())
        changed = request(base64.b64decode(request()['body_base64']).replace(b'Alpha', b'Beta'))
        changed['metadata']['observed_at'] = '2026-10-18T14:00:00+02:00'
        b = preview.compile_preview(changed)
        self.assertEqual(a['configuration'], b['configuration'])
        self.assertNotEqual(a['capture']['content_sha256'], b['capture']['content_sha256'])
        self.assertNotEqual(a['preview']['result_sha256'], b['preview']['result_sha256'])
        self.assertEqual(b['capture']['metadata']['observed_at'], '2026-10-18T14:00:00+02:00')
        changed['declaration']['fields']['unit']['constant'] = 'Other'
        self.assertNotEqual(a['configuration']['revision'], preview.compile_preview(changed)['configuration']['revision'])

    def test_diagnostics_never_vacancies_or_qualification(self):
        cases = [(b'', {}, 'empty'), (b'bad', {'status': 503}, 'failed'),
                 (b'<html><body><table></table></body></html>', {}, 'incomplete'),
                 (b'<html><body><table></table><table></table></body></html>', {}, 'ambiguous'),
                 (b'\xff', {}, 'failed')]
        for body, metadata, validity in cases:
            with self.subTest(validity=validity):
                value = request(body)
                value['metadata'].update(metadata)
                artifact = preview.compile_preview(value)
                self.assertEqual(artifact['preview']['validity'], validity)
                self.assertEqual(artifact['preview']['wire']['positions'], [])
                child = self.cli(value)
                self.assertEqual((child.returncode, child.stderr), (0, b''))
                self.assertEqual(json.loads(child.stdout), artifact)
        cards = request(b'<html><body><div id="d"><article><b>Chief</b><i>Ada Example</i></article></div></body></html>')
        cards['declaration'].update(directory='#d', record='article')
        del cards['declaration']['columns']
        cards['declaration']['fields'].update(role={'selector': 'b'}, person={'selector': 'i'})
        self.assertEqual(len(preview.compile_preview(cards)['preview']['wire']['positions']), 1)

    def test_invalid_input_api_and_cli_no_echo(self):
        invalid = []
        for location, key in [(None, 'filename'), (None, 'approved'), ('metadata', 'expected_positions'),
                              ('metadata', 'reviewed'), ('declaration', 'approver')]:
            value = request()
            (value if location is None else value[location])[key] = 'SECRET_SENTINEL'
            invalid.append(value)
        for key, value in [('status', True), ('status', 99), ('encoding', []),
                           ('source_url', 'https://other.invalid/'), ('observed_at', '2026-01-01'),
                           ('observed_at', None)]:
            envelope = request()
            envelope['metadata'][key] = value
            invalid.append(envelope)
        for body in ['!!!!', 'YQ', 'YR==', 2, 'A' * 1398108]:
            envelope = request()
            envelope['body_base64'] = body
            invalid.append(envelope)
        invalid.extend([[], None, {'declaration': {}}])
        for value in invalid:
            with self.subTest(value_type=type(value).__name__):
                with self.assertRaises(ValueError):
                    preview.compile_preview(value)
                child = self.cli(value)
                self.assertEqual((child.returncode, child.stdout, child.stderr),
                                 (2, b'', b'preview: invalid input or artifact limit\n'))
        for raw in [b'{', b'{} {}', b'{"x":1,"x":2}', b'{"x":NaN}', b'\xff', b'[' * 10000,
                    b' ' * (2_097_152 + 1)]:
            child = self.cli(raw=raw)
            self.assertEqual((child.returncode, child.stdout, child.stderr),
                             (2, b'', b'preview: invalid input or artifact limit\n'))

    def test_output_preflight_and_request_bound(self):
        with patch.object(preview, 'MAX_OUTPUT_BYTES', 10):
            with self.assertRaises(ValueError):
                preview.compile_preview(request())
        # Escaped evidence expands on the wire; reject before ANY stdout write.
        expanded = request(b'<html><body>' + b'\x01' * 700_000 + b'</body></html>')
        with self.assertRaises(ValueError):
            preview.compile_preview(expanded)
        child = self.cli(expanded)
        self.assertEqual((child.returncode, child.stdout, child.stderr),
                         (2, b'', b'preview: invalid input or artifact limit\n'))
        huge = request()
        huge['declaration']['source'] = 'x' * 2_097_152
        with self.assertRaises(ValueError):
            preview.compile_preview(huge)


if __name__ == '__main__':
    unittest.main()
