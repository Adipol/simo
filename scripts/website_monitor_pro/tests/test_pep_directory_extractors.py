"""Offline synthetic captures only; never import the monitor."""
from datetime import datetime, timedelta, timezone
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from pep_directory import Disposition, Kind, Validity, compare
from pep_directory_extractors import Capture, extract

FIXTURES = Path(__file__).parent / 'fixtures' / 'pep_directory'
NOW = datetime(2026, 1, 1, tzinfo=timezone.utc)
URLS = {
    'dab': 'https://www.dab.gob.bo/personal-ejecutivo',
    'ibc': 'https://www.ibc.gob.bo/nomina.php',
    'umsa': 'https://www.umsa.bo/web/guest/rectorado',
}
KEYS = {
    'dab': frozenset({('GERENCIA GENERAL', 'GERENTE GENERAL'),
                      ('FINANZAS', 'GERENTE DE FINANZAS')}),
    'ibc': frozenset({('Dirección Ejecutiva', 'Director Ejecutivo'),
                      ('Administración', 'Jefe de Finanzas')}),
    'umsa': frozenset({('Rectorado', 'Rectorado UMSA')}),
}


class ExtractorTests(unittest.TestCase):
    def body(self, profile):
        return (FIXTURES / (profile + '.html')).read_text(encoding='utf-8')

    def observation(self, profile, body=None, **overrides):
        metadata = dict(status=200, source_url=URLS[profile], observed_at=NOW,
                        expected_positions=KEYS[profile], encoding='utf-8')
        metadata.update(overrides)
        return extract(profile, self.body(profile) if body is None else body,
                       Capture(**metadata))

    def pair(self, profile, body, **overrides):
        baseline = self.observation(profile)
        self.assertEqual(baseline.validity, Validity.VALID)
        self.assertEqual(compare(None, baseline).disposition, Disposition.BASELINE)
        current = self.observation(profile, body, observed_at=NOW + timedelta(days=1),
                                   **overrides)
        return baseline, current, compare(baseline, current)

    def rejected(self, profile, body, validity, **overrides):
        baseline, current, result = self.pair(profile, body, **overrides)
        self.assertEqual(current.validity, validity)
        self.assertEqual(current.positions, ())
        self.assertEqual(result.disposition, Disposition.REJECTED)
        self.assertIs(result.baseline, baseline)
        self.assertEqual(result.events, ())
        self.assertEqual(result.reasons, ('capture_' + validity.value,))
        self.assertEqual(current.evidence, body if isinstance(body, str) else '')

    def test_profiles_keep_original_evidence_and_associations(self):
        for profile in URLS:
            with self.subTest(profile=profile):
                observation = self.observation(profile)
                self.assertEqual(observation.validity, Validity.VALID)
                self.assertEqual(observation.source, profile + ':directory')
                self.assertEqual(observation.revision, 'pilot-html-v2-groups')
                self.assertEqual(observation.observed_at, NOW)
                self.assertEqual(observation.evidence, self.body(profile))
                self.assertEqual({(p.unit, p.role) for p in observation.positions}, KEYS[profile])
                for position in observation.positions:
                    self.assertIn(position.person, ('Ana Ejemplo', 'María Modelo'))
                    self.assertIn(position.person, position.evidence)
                    self.assertIn('<', position.evidence)
                self.assertEqual(compare(None, observation).events, ())

    def test_dab_replacement_retains_condition_and_base_role(self):
        body = self.body('dab').replace('María Modelo', 'Beatriz Modelo')
        baseline, current, result = self.pair('dab', body)
        event = next(e for e in result.events if e.unit == 'FINANZAS')
        self.assertEqual(event.kind, Kind.POSSIBLE_REPLACEMENT)
        self.assertEqual(event.role, 'GERENTE DE FINANZAS')
        self.assertEqual((event.before.person, event.after.person), ('María Modelo', 'Beatriz Modelo'))
        self.assertEqual((event.before.condition, event.after.condition), ('a.i.', 'a.i.'))
        self.assertIn('a.i.', event.after.evidence)
        self.assertEqual(event.previous_evidence, baseline.evidence)
        self.assertEqual(event.current_evidence, body)
        self.assertIs(result.baseline, current)
        self.assertIsNone(event.effective_date)

    def test_dab_condition_change_is_not_role_change(self):
        _, _, result = self.pair('dab', self.body('dab').replace(' a.i.', ''))
        event = next(e for e in result.events if e.unit == 'FINANZAS')
        self.assertEqual(event.kind, Kind.CONDITION_CHANGE)
        self.assertEqual((event.before.condition, event.after.condition), ('a.i.', None))
        self.assertEqual(event.before.role, event.after.role)
        self.assertEqual(event.before.person, event.after.person)

    def test_ibc_name_only_change_preserves_context(self):
        _, _, result = self.pair('ibc', self.body('ibc').replace('Ana Ejemplo', 'Beatriz Modelo'))
        event = next(e for e in result.events if e.role == 'Director Ejecutivo')
        self.assertEqual(event.kind, Kind.POSSIBLE_REPLACEMENT)
        self.assertEqual(event.unit, 'Dirección Ejecutiva')
        self.assertEqual((event.before.person, event.after.person), ('Ana Ejemplo', 'Beatriz Modelo'))
        self.assertIsNone(event.after.condition)
        self.assertIn('Director Ejecutivo<br/>Dirección Ejecutiva', event.after.evidence)

    def test_umsa_reversible_mojibake_never_replaces_person(self):
        for old, new in [('María Modelo', 'MarÃ­a Modelo'), ('MarÃ­a Modelo', 'María Modelo')]:
            before = self.observation('umsa', self.body('umsa').replace('María Modelo', old))
            after = self.observation('umsa', self.body('umsa').replace('María Modelo', new))
            result = compare(before, after)
            self.assertEqual(result.disposition, Disposition.COMPARED)
            self.assertEqual(len(result.events), 1)
            event = result.events[0]
            self.assertEqual(event.kind, Kind.NO_CHANGE)
            self.assertEqual((event.role, event.unit), ('Rectorado UMSA', 'Rectorado'))
            self.assertEqual((event.before.person, event.after.person), (old, new))
            self.assertIn(new, event.after.evidence)

    def test_noise_contacts_images_counters_and_row_order_are_not_events(self):
        for profile in URLS:
            with self.subTest(profile=profile):
                body = self.body(profile).replace('Noticias 21', 'Noticias 999 nueva noticia')
                body = body.replace('contacto@example.invalid 555000', 'otro@example.invalid 555999')
                body = body.replace('portrait.invalid', 'different.invalid')
                if profile == 'dab':
                    start = body.index('<tr><td>1')
                    end = body.index('</tbody>')
                    rows = body[start:end].strip().split('\n')
                    body = body[:start] + '\n'.join(reversed(rows)) + '\n' + body[end:]
                if profile == 'ibc':
                    start = body.index('<div class="card border-dark">')
                    end = body.index('</div><footer>')
                    cards = body[start:end].strip().split('\n<div class="card border-dark">')
                    body = body[:start] + '<div class="card border-dark">' + cards[1] + '\n' + cards[0] + body[end:]
                _, current, result = self.pair(profile, body)
                self.assertEqual(current.validity, Validity.VALID)
                self.assertEqual(len(result.events), len(KEYS[profile]))
                self.assertTrue(all(e.kind == Kind.NO_CHANGE for e in result.events))
                self.assertIs(result.baseline, current)

    def test_strict_explicit_byte_decoding_preserves_accents(self):
        for profile in URLS:
            for encoding in ('utf-8', 'cp1252'):
                with self.subTest(profile=profile, encoding=encoding):
                    text = self.body(profile)
                    observation = self.observation(profile, text.encode(encoding), encoding=encoding)
                    self.assertEqual(observation.validity, Validity.VALID)
                    self.assertEqual(observation.evidence, text)
                    self.assertIn('María Modelo', [p.person for p in observation.positions])
        self.rejected('umsa', b'\xff', Validity.FAILED)
        self.rejected('umsa', b'<html></html>', Validity.FAILED, encoding='unknown-codec')

    def test_bad_status_wrong_source_empty_and_blocked(self):
        for profile in URLS:
            with self.subTest(profile=profile):
                self.rejected(profile, self.body(profile), Validity.FAILED, status=503)
                self.rejected(profile, self.body(profile), Validity.FAILED, source_url='https://wrong.invalid/')
                self.rejected(profile, '', Validity.EMPTY)
                self.rejected(profile, '<html><body>Access denied</body></html>', Validity.BLOCKED)
                self.rejected(profile, self.body(profile).replace('<title>', '<title>Just a moment... '), Validity.BLOCKED)

    def test_partial_missing_selector_and_unreviewed_inventory(self):
        for profile in URLS:
            with self.subTest(profile=profile):
                self.rejected(profile, self.body(profile).split('</body>')[0], Validity.INCOMPLETE)
                self.rejected(profile, '<html><body>Ana Ejemplo Director</body></html>', Validity.INCOMPLETE)
                self.rejected(profile, self.body(profile), Validity.INCOMPLETE, expected_positions=frozenset())
                self.rejected(profile, self.body(profile), Validity.INCOMPLETE,
                              expected_positions=KEYS[profile] | {('Missing', 'Missing')})
                self.rejected(profile, self.body(profile).replace('María Modelo', ''), Validity.INCOMPLETE)
                self.rejected(profile, self.body(profile).replace('</body>', '<a rel="next" href="page2">Next</a></body>'), Validity.INCOMPLETE)

    def test_duplicate_position_or_directory_is_ambiguous(self):
        for profile, selector in [('dab', '<tr><td>1'), ('ibc', '<tr><td>1')]:
            body = self.body(profile)
            start = body.index(selector)
            row = body[start:body.index('</tr>', start) + 5]
            self.rejected(profile, body.replace(row, row + row), Validity.AMBIGUOUS)
        body = self.body('umsa')
        start = body.index('<div class="parte2">')
        block = body[start:body.index('</div>', start) + 6]
        self.rejected('umsa', body.replace(block, block + block), Validity.AMBIGUOUS)

    def test_whole_row_loss_with_complete_envelope_is_rejected(self):
        for profile in ('dab', 'ibc'):
            body = self.body(profile)
            start = body.index('<tr><td>1')
            end = body.index('</tr>', start) + 5
            self.rejected(profile, body[:start] + body[end:], Validity.INCOMPLETE)

    def test_ibc_observed_empty_section_and_duplicate_role_fail_closed(self):
        body = self.body('ibc')
        start = body.index('<tr><td>1')
        row = body[start:body.index('</tr>', start) + 5]
        # An empty table still cannot hide a missing attested role.
        self.rejected('ibc', body.replace(row, ''), Validity.INCOMPLETE)
        # S11: a second distinct occupant is legitimate, not a duplicate record.
        current = self.observation('ibc', body.replace(row, row + row.replace('Ana Ejemplo', 'Beatriz Modelo')))
        self.assertEqual(current.validity, Validity.VALID)
        self.assertEqual(len(current.positions), 3)
        self.assertEqual(compare(None, current).disposition, Disposition.BASELINE)

    def test_malformed_nested_row_is_not_repaired_into_a_capture(self):
        body = self.body('dab').replace('</tr>\n<tr><td>2', '\n<tr><td>2')
        self.rejected('dab', body, Validity.INCOMPLETE)

    def test_broken_headers_units_relationships_and_extra_cells_reject(self):
        self.rejected('dab', self.body('dab').replace('<th>Cargo</th>', '<th>Otro</th>'), Validity.INCOMPLETE)
        self.rejected('dab', self.body('dab').replace('<td>1</td>', '<td>1</td><td>extra</td>'), Validity.INCOMPLETE)
        self.rejected('ibc', self.body('ibc').replace('Director Ejecutivo<br>Dirección Ejecutiva', 'Director Ejecutivo'), Validity.INCOMPLETE)
        self.rejected('ibc', self.body('ibc').replace('Director Ejecutivo<br>Dirección Ejecutiva', 'Director Ejecutivo<br>Otra unidad'), Validity.AMBIGUOUS)
        self.rejected('umsa', self.body('umsa').replace('Rectora UMSA', ''), Validity.INCOMPLETE)

    def test_uncertain_person_encoding_rejected_by_t1_without_losing_baseline(self):
        before, after, result = self.pair('umsa', self.body('umsa').replace('María Modelo', 'Mar\ufffda Modelo'))
        self.assertEqual(result.disposition, Disposition.REJECTED)
        self.assertIs(result.baseline, before)
        self.assertEqual(result.reasons, ('uncertain_person_encoding',))
        self.assertIn('Mar\ufffda Modelo', after.evidence)

    def test_umsa_known_gendered_office_alias_is_one_replacement(self):
        body = self.body('umsa').replace('María Modelo', 'Pedro Pérez').replace('Rectora UMSA', 'Rector UMSA')
        _, current, result = self.pair('umsa', body)
        self.assertEqual(current.validity, Validity.VALID)
        self.assertEqual(len(result.events), 1)
        event = result.events[0]
        self.assertEqual(event.kind, Kind.POSSIBLE_REPLACEMENT)
        self.assertEqual(event.role, 'Rectorado UMSA')
        self.assertEqual((event.before.person, event.after.person), ('María Modelo', 'Pedro Pérez'))
        self.assertIn('Rectora UMSA', event.before.evidence)
        self.assertIn('Rector UMSA', event.after.evidence)
        self.rejected('umsa', body, Validity.INCOMPLETE,
                      expected_positions=frozenset({('Rectorado', 'Rector UMSA')}))

    def test_contact_only_person_cells_reject_without_guessing_name_eligibility(self):
        for profile in URLS:
            original = 'María Modelo' if profile == 'umsa' else 'Ana Ejemplo'
            for contact in ('info@example.invalid', 'https://example.invalid/contact',
                            'www.example.invalid', '+591 (2) 555-0100', '555000',
                            'mailto:info@example.invalid', 'tel:+59125550100',
                            'ftp://example.invalid/file'):
                with self.subTest(profile=profile, contact=contact):
                    self.rejected(profile, self.body(profile).replace(original, contact), Validity.INCOMPLETE)
            for name in ('Ñusta', "Ana O’Neill", "Álex D'Angelo"):
                observation = self.observation(profile, self.body(profile).replace(original, name))
                self.assertEqual(observation.validity, Validity.VALID)
                self.assertIn(name, [p.person for p in observation.positions])

    def test_structurally_empty_ibc_section_requires_intact_table_and_no_missing_inventory(self):
        body = self.body('ibc')
        empty_card = ('<div class="card"><div class="card-header">Unidad Vacía</div>'
                      '<div class="card-body"><table class="table"><thead><tr>'
                      '<th>Ítem</th><th>Nombres y Apellidos</th><th>Cargo/Unidad</th>'
                      '</tr></thead><tbody></tbody></table></div></div>')
        complete = body.replace('</div><footer>', empty_card + '</div><footer>')
        _, current, result = self.pair('ibc', complete)
        self.assertEqual(current.validity, Validity.VALID)
        self.assertTrue(all(e.kind == Kind.NO_CHANGE for e in result.events))
        self.rejected('ibc', complete, Validity.INCOMPLETE,
                      expected_positions=KEYS['ibc'] | {('Unidad Vacía', 'Missing')})
        for broken in (empty_card.replace('<th>Cargo/Unidad</th>', ''),
                       empty_card.replace('<tbody></tbody>', ''),
                       '<div class="card"><div class="card-header">Unidad Vacía</div></div>',
                       empty_card.replace('<tbody></tbody>', '<tbody><td>partial</td></tbody>'),
                       empty_card.replace('<tbody></tbody>', '<tbody>partial</tbody>'),
                       empty_card.replace('<tbody></tbody>', '<tbody><tr><td>1</td></tr></tbody>')):
            self.rejected('ibc', body.replace('</div><footer>', broken + '</div><footer>'), Validity.INCOMPLETE)

    def test_ibc_group_transition_through_public_adapter_and_compare(self):
        body = self.body('ibc')
        start = body.index('<tr><td>1')
        row = body[start:body.index('</tr>', start) + 5]
        before_body = body.replace(row, row + row.replace('Ana Ejemplo', 'Beatriz Modelo'))
        after_body = before_body.replace('Ana Ejemplo', 'Carla Modelo')
        before = self.observation('ibc', before_body)
        after = self.observation('ibc', after_body, observed_at=NOW + timedelta(days=1))
        self.assertEqual(before.validity, Validity.VALID)
        self.assertEqual(after.validity, Validity.VALID)
        result = compare(before, after)
        self.assertEqual(result.disposition, Disposition.COMPARED)
        event = next(e for e in result.events if e.role == 'Director Ejecutivo')
        self.assertEqual(event.kind, Kind.POSSIBLE_REPLACEMENT)
        self.assertEqual(event.unit, 'Dirección Ejecutiva')
        self.assertEqual([p.person for p in event.outgoing], ['Ana Ejemplo'])
        self.assertEqual([p.person for p in event.incoming], ['Carla Modelo'])
        self.assertEqual(len(event.before_group), 2)
        self.assertEqual(len(event.after_group), 2)
        self.assertEqual(event.previous_evidence, before_body)
        self.assertEqual(event.current_evidence, after_body)
        self.assertTrue(all(p.person in p.evidence for p in event.before_group + event.after_group))
        self.assertIs(result.baseline, after)
        duplicate = before_body.replace('Beatriz Modelo', 'Ana Ejemplo')
        self.rejected('ibc', duplicate, Validity.AMBIGUOUS)
        equivalent = before_body.replace('Ana Ejemplo', 'María Ejemplo').replace('Beatriz Modelo', 'MarÃ­a Ejemplo')
        self.rejected('ibc', equivalent, Validity.AMBIGUOUS)

    def test_unknown_profile_is_a_caller_error(self):
        with self.assertRaises(ValueError):
            extract('other', self.body('dab'), Capture(200, URLS['dab'], NOW, KEYS['dab'], 'utf-8'))


if __name__ == '__main__':
    unittest.main()
