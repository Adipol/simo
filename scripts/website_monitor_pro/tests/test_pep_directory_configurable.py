"""Synthetic public-API checks; no monitor, application, network or database."""
from copy import deepcopy
from dataclasses import FrozenInstanceError, replace
from datetime import datetime, timezone
import sys
from pathlib import Path
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from pep_directory import Disposition, Kind, Observation, Position, Validity, compare
from pep_directory_extractors import Capture
try:
    from pep_directory_configurable import DirectoryConfig, extract_configured
except ModuleNotFoundError as error:
    if error.name != 'pep_directory_configurable':
        raise
    DirectoryConfig = extract_configured = None


def mapping():
    return {
        'source': 'synthetic-council:directory',
        'url': 'https://council.example/directory',
        'directory': '#directory', 'record': 'tbody > tr', 'columns': 3,
        'fields': {'unit': {'constant': 'North'},
                   'role': {'column': 0}, 'person': {'column': 1},
                   'condition': {'column': 2}},
    }


def page(rows):
    return '<html><body><table id="directory"><tbody>' + ''.join(
        '<tr>' + ''.join('<td>' + cell + '</td>' for cell in row) + '</tr>'
        for row in rows) + '</tbody></table></body></html>'


ROWS = [('Legal', 'Ada Example', 'titular'), ('Audit', 'Bela Example', 'a.i.')]


class ConfigurableTests(unittest.TestCase):
    def config(self, data=None):
        self.assertIsNotNone(DirectoryConfig, 'DirectoryConfig public API must exist')
        return DirectoryConfig.from_dict(mapping() if data is None else data)

    def capture(self, **changes):
        capture = Capture(200, mapping()['url'], datetime(2026, 1, 1, tzinfo=timezone.utc),
                          frozenset({('North', 'Legal'), ('North', 'Audit')}), 'utf-8')
        return replace(capture, **changes)

    def extract(self, body=None, data=None, capture=None):
        config = self.config(data)
        self.assertTrue(callable(extract_configured), 'extract_configured must exist')
        return extract_configured(config, page(ROWS) if body is None else body,
                                  self.capture() if capture is None else capture)

    def rejected(self, body, validity=Validity.INCOMPLETE, data=None, capture=None):
        baseline = self.extract()
        current = self.extract(body, data, capture)
        self.assertEqual(current.validity, validity)
        self.assertEqual(current.positions, ())
        result = compare(baseline, current)
        self.assertEqual(result.disposition, Disposition.REJECTED)
        self.assertIs(result.baseline, baseline)
        self.assertEqual(result.events, ())
        return current

    def test_opt_in_role_condition_preserves_identity_and_evidence(self):
        data = mapping()
        del data['fields']['condition']
        opted = dict(data, role_condition='ai_suffix')
        self.assertNotEqual(self.config(data).revision, self.config(opted).revision)
        self.assertNotIn('role_condition', self.config(data).canonical_identity())
        plain = self.extract(page([('Legal', 'Ada a.i. Example', ''), ROWS[1]]), opted)
        marked = self.extract(page([('Legal A.I.', 'Ada a.i. Example', ''), ROWS[1]]), opted)
        p = marked.positions[0]
        self.assertEqual((p.unit, p.role, p.person, p.condition),
                         ('North', 'Legal', 'Ada a.i. Example', 'A.I.'))
        self.assertIn('<td>Legal A.I.</td>', p.evidence)
        self.assertIsNone(plain.positions[0].condition)
        for before, after in [(plain, marked), (marked, plain)]:
            self.assertEqual({e.kind for e in compare(before, after).events},
                             {Kind.NO_CHANGE, Kind.CONDITION_CHANGE})
        omitted = self.extract(page([('Legal a.i.', 'Ada Example', ''), ROWS[1]]), data,
                               self.capture(expected_positions=frozenset()))
        self.assertEqual((omitted.positions[0].role, omitted.positions[0].condition), ('Legal a.i.', None))
        for role in ['Legal a.i. Deputy', 'Legal AI', 'Legal a.i', 'Legal (a.i.)', 'a.i.']:
            result = self.extract(page([(role, 'Ada Example', '')]), opted,
                                  self.capture(expected_positions=frozenset()))
            self.assertEqual((result.positions[0].role, result.positions[0].condition), (role, None))
        duplicate = self.extract(page([('Legal', 'Ada Example', ''), ('Legal a.i.', 'Ada Example', '')]), opted)
        self.assertEqual(duplicate.positions, ())
        self.assertEqual(duplicate.validity, Validity.AMBIGUOUS)

    def test_role_condition_rejects_conflicts_and_invalid_options(self):
        data = mapping()
        with self.assertRaises(ValueError):
            self.config(dict(data, role_condition='ai_suffix'))
        del data['fields']['condition']
        for option in [None, True, 1, [], {}, '', 'AI_SUFFIX', 'other']:
            with self.subTest(option=option), self.assertRaises(ValueError):
                self.config(dict(data, role_condition=option))
        with self.assertRaises(ValueError):
            self.config(dict(data, role_condition='ai_suffix', unknown=True))

    def test_full_observation_and_evidence(self):
        body = page(ROWS)
        result = self.extract(body)
        self.assertEqual(result, Observation(mapping()['source'], self.config().revision,
                         self.capture().observed_at, Validity.VALID,
                         (Position('North', 'Legal', 'Ada Example', 'titular',
                                   '<tr><td>Legal</td><td>Ada Example</td><td>titular</td></tr>'),
                          Position('North', 'Audit', 'Bela Example', 'a.i.',
                                   '<tr><td>Audit</td><td>Bela Example</td><td>a.i.</td></tr>')), body))
        baseline = compare(None, result)
        self.assertEqual(baseline.disposition, Disposition.BASELINE)
        self.assertEqual(baseline.events, ())

    def test_comparison_body_change_revision_order_and_condition(self):
        before = self.extract()
        reordered = self.extract(page(list(reversed(ROWS))))
        self.assertEqual(reordered.revision, before.revision)
        self.assertEqual({e.kind for e in compare(before, reordered).events}, {Kind.NO_CHANGE})
        changed = self.extract(page([('Legal', 'Cora Example', 'titular'), ROWS[1]]))
        self.assertEqual(changed.revision, before.revision)
        self.assertEqual(compare(before, changed).events[1].kind, Kind.POSSIBLE_REPLACEMENT)
        condition = self.extract(page([('Legal', 'Ada Example', 'suplente'), ROWS[1]]))
        self.assertEqual(compare(before, condition).events[1].kind, Kind.CONDITION_CHANGE)

    def test_canonical_immutable_config_and_revision_boundaries(self):
        data = mapping()
        config = self.config(data)
        reverse = dict(reversed(list(data.items())))
        reverse['fields'] = dict(reversed(list(data['fields'].items())))
        self.assertEqual(config.canonical_identity(), self.config(reverse).canonical_identity())
        self.assertEqual(config.revision, self.config(reverse).revision)
        data['fields']['person']['column'] = 0
        self.assertEqual(config, self.config())  # no retained mutable dictionary
        with self.assertRaises(FrozenInstanceError):
            config.source = 'other'
        altered = mapping()
        altered['record'] = 'table > tbody > tr'
        changed = self.extract(data=altered)
        self.assertNotEqual(changed.revision, self.extract().revision)
        self.assertEqual(compare(self.extract(), changed).disposition, Disposition.REBASELINED)
        for key, value in [('source', 'another:directory'), ('url', 'https://other.example/list')]:
            altered = mapping()
            altered[key] = value
            self.assertNotEqual(config.revision, self.config(altered).revision)

    def test_strict_configuration_rejections(self):
        bad = [None, [], {}, dict(mapping(), unknown=True), dict(mapping(), columns=True),
               dict(mapping(), url='file:///tmp/list'), dict(mapping(), record='tr:has(td)'),
               dict(mapping(), record='tr, article'), dict(mapping(), section=''),
               dict(mapping(), source=' ')]
        for field in ({'column': True}, {'column': -1}, {'column': 3},
                      {'selector': '.x', 'constant': 'x'}, {'selector': '.x', 'scope': 'elsewhere'},
                      {'constant': 'Ada Example'}, {'selector': 'x' * 129}):
            data = mapping()
            data['fields']['person'] = field
            bad.append(data)
        data = mapping()
        data['fields']['person'] = {'column': 0}
        bad.append(data)
        for data in bad:
            with self.subTest(data=data), self.assertRaises(ValueError):
                self.assertIsNotNone(DirectoryConfig)
                DirectoryConfig.from_dict(data)

    def test_sectioned_table_and_context_evidence(self):
        data = mapping()
        data.update(directory='#directory', section='section')
        data['fields']['unit'] = {'scope': 'section', 'selector': 'h2'}
        body = '<html><body><main id="directory">' + ''.join(
            '<section><h2>' + unit + '</h2><table><tbody><tr><td>Lead</td><td>'
            + name + '</td><td>titular</td></tr></tbody></table></section>'
            for unit, name in [('East', 'Dara Example'), ('West', 'Ena Example')]
        ) + '</main></body></html>'
        capture = self.capture(expected_positions=frozenset({('East', 'Lead'), ('West', 'Lead')}))
        result = self.extract(body, data, capture)
        self.assertEqual(result.validity, Validity.VALID)
        self.assertEqual([(p.unit, p.person) for p in result.positions],
                         [('East', 'Dara Example'), ('West', 'Ena Example')])
        self.assertIn('<h2>East</h2>', result.positions[0].evidence)
        self.rejected(body.replace('<h2>East</h2>', '<h2>East</h2><h2>Other</h2>'),
                      Validity.AMBIGUOUS, data, capture)

    def test_nested_cards_multiple_holders_no_zip_or_name_cleanup(self):
        data = {'source': 'synthetic-foundation:people', 'url': mapping()['url'],
                'directory': '#directory', 'record': 'article',
                'fields': {'unit': {'scope': 'directory', 'selector': 'h1'},
                           'role': {'selector': '.details > h2'},
                           'person': {'selector': '.details > p'}}}
        def cards(names):
            return '<html><body><main id="directory"><h1>North</h1>' + ''.join(
                '<article><div class="details"><h2>Legal</h2><p>' + n + '</p></div></article>'
                for n in names) + '</main></body></html>'
        capture = self.capture(expected_positions=frozenset({('North', 'Legal')}))
        before = self.extract(cards(['Ada Example a.i.', 'Bela Example']), data, capture)
        after = self.extract(cards(['Bela Example', 'Cora Example', 'Dara Example']), data, capture)
        self.assertEqual(before.validity, Validity.VALID)
        self.assertEqual(before.positions[0].person, 'Ada Example a.i.')
        self.assertIsNone(before.positions[0].condition)
        event, = compare(before, after).events
        self.assertEqual(event.kind, Kind.MEMBERSHIP_CHANGE)
        self.assertEqual([p.person for p in event.outgoing], ['Ada Example a.i.'])
        self.assertEqual([p.person for p in event.incoming], ['Cora Example', 'Dara Example'])
        self.rejected(cards(['Ada Example', 'Ada Example']), Validity.AMBIGUOUS, data, capture)
        self.rejected(cards(['Ada Example']).replace('</article>', '<article><div class="details">'
                      '<h2>Legal</h2><p>Bela Example</p></div></article></article>'),
                      Validity.AMBIGUOUS, data, capture)

    def test_structural_failures_never_qualify_partial_subset(self):
        body = page(ROWS)
        for altered in [page(ROWS[:1]), page([('Legal', '', 'titular'), ROWS[1]]),
                        page([('', 'Ada Example', 'titular'), ROWS[1]]), page([]),
                        body.replace('<td>Legal', '<td colspan="2">Legal'),
                        body.replace('<td>Legal', '<td rowspan="1">Legal'),
                        body.replace('</body></html>', ''),
                        body.replace('</body>', '<a rel="next">More</a></body>')]:
            with self.subTest(body=altered):
                self.rejected(altered)
        self.rejected(body.replace('</body>', body + '</body>'), Validity.AMBIGUOUS)
        self.rejected(body.replace('Ada Example', 'Ada Example</td><td>Extra'))

    def test_relative_fields_missing_duplicate_and_overlapping(self):
        data = mapping()
        data.pop('columns')
        data['fields'] = {'unit': {'constant': 'North'}, 'person': {'selector': 'td.person'},
                          'role': {'selector': 'td.role'}}
        body = page(ROWS).replace('<td>Legal', '<td class="role">Legal').replace(
            '<td>Audit', '<td class="role">Audit').replace('<td>Ada', '<td class="person">Ada').replace(
            '<td>Bela', '<td class="person">Bela')
        self.assertEqual(self.extract(body, data).validity, Validity.VALID)
        self.rejected(body.replace('class="person"', 'class="role"'), data=data)
        self.rejected(body.replace('<td>titular', '<td class="role">titular'),
                      Validity.AMBIGUOUS, data)
        overlap = deepcopy(data)
        overlap['fields']['role'] = {'selector': 'td'}
        self.rejected(body, Validity.AMBIGUOUS, overlap)

    def test_overlapping_sections_and_unassigned_records(self):
        data = mapping()
        data['directory'] = '#outer'
        data['section'] = 'section'
        body = '<html><body><main id="outer"><section>' + page(ROWS).split('<body>')[1].split(
            '</body>')[0] + '</section></main></body></html>'
        self.assertEqual(self.extract(body, data).validity, Validity.VALID)
        nested = body.replace('<section>', '<section><section>').replace('</section>', '</section></section>')
        self.rejected(nested, Validity.AMBIGUOUS, data)
        stray = body.replace('</main>', '<table><tbody><tr><td>Legal</td><td>Cora Example</td>'
                             '<td>titular</td></tr></tbody></table></main>')
        self.rejected(stray, Validity.AMBIGUOUS, data)
        borrowed = deepcopy(data)
        borrowed['fields']['unit'] = {'selector': 'h2', 'scope': 'directory'}
        self.rejected(body.replace('<section>', '<section><h2>North</h2>'),
                      Validity.AMBIGUOUS, borrowed)

    def test_duplicate_field_nodes_and_core_encoding_qualification(self):
        data = {'source': 'synthetic-board:directory', 'url': mapping()['url'],
                'directory': '#directory', 'record': 'article',
                'fields': {'unit': {'constant': 'North'}, 'role': {'selector': '.role'},
                           'person': {'selector': '.person'}}}
        capture = self.capture(expected_positions=frozenset({('North', 'Legal')}))
        body = '<html><body><main id="directory"><article><div class="role">Legal'
        body += '<span class="person">Ada Example</span></div></article></main></body></html>'
        self.rejected(body, Validity.AMBIGUOUS, data, capture)
        self.rejected(page(ROWS).replace('Ada Example', 'Broken \ufffd Example'))
        self.rejected(page(ROWS).replace('Ada Example', 'person@example.invalid'))
        self.rejected(page(ROWS).replace('<td>Legal', '<td class="a" class="b">Legal'))
        for original, current in [('María Example', 'MarÃ­a Example'), ('MarÃ­a Example', 'María Example')]:
            before = self.extract(page([('Legal', original, 'titular'), ROWS[1]]))
            after = self.extract(page([('Legal', current, 'titular'), ROWS[1]]))
            self.assertEqual({e.kind for e in compare(before, after).events}, {Kind.NO_CHANGE})
            self.assertEqual(after.positions[0].person, current)

    def test_irrelevant_duplicate_attributes_preserve_original_input(self):
        baseline = self.extract()
        for outside in ['<button class="btn" class="icon-color">Menu</button>',
                        '<footer id="one" id="two">Footer</footer>']:
            with self.subTest(outside=outside):
                body = page(ROWS).replace('<body>', '<body>' + outside)
                original = body.encode()
                result = self.extract(original)
                self.assertEqual(result.validity, Validity.VALID)
                self.assertEqual(result.positions, baseline.positions)
                self.assertEqual(result.evidence.encode(), original)

    def test_relevant_duplicate_attributes_fail_closed(self):
        body = page(ROWS)
        cases = [
            body.replace('id="directory"', 'id="directory" id="other"'),
            body.replace('<tr>', '<tr class="a" class="b">', 1),
            body.replace('<td>', '<td id="a" id="b">', 1),
            body.replace('<body>', '<body class="a" class="b">'),
            # Last-value parsing hides a second possible directory.
            body.replace('</body>', '<table id="directory" id="other"></table></body>'),
            body.replace('</body>', '<div id="directory" id="other"></div></body>'),
            # Duplicates must not hide global completeness/blocking guards either.
            body.replace('</body>', '<nav class="pagination" class="menu"></nav></body>'),
            body.replace('</body>', '<div id="captcha" id="other"></div></body>'),
            body.replace('</body>', '<a rel="next" rel="prev">More</a></body>'),
            body.replace('</body>', '<a rel="NEXT" rel="prev">More</a></body>'),
        ]
        for altered in cases:
            with self.subTest(body=altered):
                result = self.rejected(altered)
                self.assertEqual(result.evidence, altered)
        data = mapping()
        data['directory'] = '.chosen > table.views-table'
        selected = body.replace('<table id="directory">',
                                '<div class="chosen"><table class="views-table">').replace(
                                    '</table>', '</table></div>')
        self.assertEqual(self.extract(selected, data).validity, Validity.VALID)
        # An ancestor alternative can expose an otherwise hidden candidate.
        alternate = '<div class="chosen" class="other"><table class="views-table"></table></div>'
        self.rejected(selected.replace('</body>', alternate + '</body>'), data=data)
        alternate = '<table class="views-table" class="other"></table>'
        self.rejected(selected.replace('</body>', alternate + '</body>'), data=data)

    def test_metadata_decode_unreviewed_inventory_and_resource_bounds(self):
        body = page(ROWS)
        for changes in [{'status': 403}, {'status': True}, {'source_url': 'https://other.example/'},
                        {'observed_at': datetime(2026, 1, 1)}, {'encoding': 'unknown-codec'}]:
            self.rejected(body.encode(), Validity.FAILED, capture=self.capture(**changes))
        result = self.rejected(b'\xff', Validity.FAILED)
        self.assertEqual(result.evidence, '')
        self.rejected('', Validity.EMPTY)
        self.rejected('<html><body><h1>Access denied</h1></body></html>', Validity.BLOCKED)
        for expected in [frozenset(), None, {('North', 'Legal')}]:
            result = self.extract(body, capture=self.capture(expected_positions=expected))
            self.assertEqual(result.validity, Validity.INCOMPLETE)
            self.assertEqual(len(result.positions), 2)  # inspectable, never approval
            self.assertEqual(compare(self.extract(), result).disposition, Disposition.REJECTED)
        self.rejected(body + ' ' * 1_048_576)
        self.rejected('<html><body>' + '<div>' * 65 + '</div>' * 65 + '</body></html>')
        self.rejected(page([ROWS[0]] * 1001))
        self.rejected(body.replace('</body>', '<br>' * 20_000 + '</body>'))


if __name__ == '__main__':
    unittest.main()
