#!/usr/bin/env python3
"""Opt-in synthetic capture -> real extractor/Repository -> owned PostgreSQL proof.

No installer, cleanup, production binding, pytest, or application bootstrap.
All legacy collaborators are explicitly mocked; shadow results are not mocked.
"""
import os
import sys
import unittest

ENGINE = 'PEP_SHADOW_INTEGRATION_ENGINE'


def main():
    # This gate must precede even third-party imports and fixture/setup work.
    if ENGINE not in os.environ:
        class NotEnabled(unittest.TestCase):
            @unittest.skip('PEP_SHADOW_INTEGRATION_ENGINE absent; no imports/setup/connections attempted')
            def test_explicit_opt_in_required(self):
                pass
        unittest.TextTestRunner(stream=sys.stdout, verbosity=2).run(
            unittest.defaultTestLoader.loadTestsFromTestCase(NotEnabled))
        return 0
    if os.environ[ENGINE] != 'pgsql':
        print('GUARD ERROR: PEP_SHADOW_INTEGRATION_ENGINE must be exactly pgsql (including no empty value)', file=sys.stderr)
        return 2
    # No inherited dotenv, DB_*, libpq service, options, password-file or URL settings.
    os.environ.clear()
    os.environ[ENGINE] = 'pgsql'
    sys.dont_write_bytecode = True
    return enabled_main()


def enabled_main():
    import builtins
    import contextlib
    from datetime import datetime, timedelta, timezone
    import hashlib
    import io
    import json
    import logging
    import logging.handlers
    from pathlib import Path
    from unittest.mock import MagicMock, patch, call
    import uuid

    root = Path(__file__).resolve().parents[3]
    sys.path.insert(0, str(root / 'scripts/website_monitor_pro'))
    import dotenv
    import psycopg2
    import requests

    target = dict(host='127.0.0.1', port=5432, dbname='pep_chain_tests',
                  user='postgres', password='', connect_timeout=5,
                  options='-c statement_timeout=5000 -c lock_timeout=2000')
    columns = {
        'fuentes': 'id label', 'users': 'id label',
        'pep_directory_sources': 'id identity fuente_id baseline_observation_id version',
        'pep_directory_observations': 'id source_id submission_id capture_identity content_sha256 provenance payload observed_at disposition reasons received_at',
        'pep_directory_events': 'id source_id previous_observation_id current_observation_id event_index payload review_state review_version',
        'pep_directory_reviews': 'id event_id version user_id actor_identity actor_snapshot decided_at reason prior_state new_state',
    }
    connections, denied_calls, receipts = [], [], []
    real_connect = psycopg2.connect

    def deny(*args, **kwargs):
        denied_calls.append('forbidden external operation')
        raise AssertionError('forbidden external operation')

    def connect(*args, **kwargs):
        if args or kwargs != target:
            deny()
        conn = real_connect(**kwargs)
        connections.append(conn)
        # Every new connection must verify its actual server identity before use.
        try:
            with conn.cursor() as cursor:
                cursor.execute('SELECT current_database(), current_user')
                if cursor.fetchone() != (target['dbname'], target['user']):
                    raise ValueError('owned target identity mismatch')
            conn.rollback()
            return conn
        except BaseException:
            conn.close()
            raise

    def digest(value):
        return hashlib.sha256(json.dumps(value, sort_keys=True, ensure_ascii=False,
                                        separators=(',', ':'), default=str).encode()).hexdigest()

    def snapshot(conn):
        """Bounded metadata/preservation reads only; never behavioral assertions."""
        try:
            with conn.cursor() as c:
                c.execute('SET TRANSACTION READ ONLY')
                c.execute("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")
                if [r[0] for r in c.fetchall()] != sorted(columns):
                    raise ValueError('exact six-table owned inventory required')
                c.execute("SELECT table_name,column_name,data_type,is_nullable,column_default,ordinal_position "
                          "FROM information_schema.columns WHERE table_schema='public' ORDER BY table_name,ordinal_position")
                cols = c.fetchall()
                for table, expected in columns.items():
                    if {r[1] for r in cols if r[0] == table} != set(expected.split()):
                        raise ValueError('column inventory mismatch: ' + table)
                c.execute("SELECT t.relname,c.conname,pg_get_constraintdef(c.oid) FROM pg_constraint c "
                          "JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace "
                          "WHERE n.nspname='public' ORDER BY t.relname,c.conname")
                constraints = c.fetchall()
                # Require stable, unique single-column identity on every retained table.
                for table in columns:
                    if not any(r[0] == table and r[2] == 'PRIMARY KEY (id)' for r in constraints):
                        raise ValueError('stable primary key required: ' + table)
                c.execute("SELECT tablename,indexname,indexdef FROM pg_indexes WHERE schemaname='public' ORDER BY tablename,indexname")
                indexes = c.fetchall()
                c.execute("SELECT sequence_name,data_type,start_value,minimum_value,maximum_value,increment,cycle_option "
                          "FROM information_schema.sequences WHERE sequence_schema='public' ORDER BY sequence_name")
                sequences = c.fetchall()
                rows = {}
                for table in sorted(columns):
                    # Identifiers are exclusively the literal allowlist above; bounded fetch.
                    c.execute('SELECT id, row_to_json(t) FROM "' + table + '" t ORDER BY id LIMIT 10001')
                    values = c.fetchall()
                    if len(values) > 10000:
                        raise ValueError('preservation bound exceeded')
                    rows[table] = {str(pk): digest(row) for pk, row in values}
            conn.rollback()
            schema = dict(columns=cols, constraints=constraints, indexes=indexes, sequences=sequences)
            return dict(schema=schema, schema_sha256=digest(schema), rows=rows,
                        fingerprint=digest(rows), counts={t: len(v) for t, v in rows.items()})
        except BaseException:
            conn.rollback()
            raise

    original_open, original_io_open, original_is_file = builtins.open, io.open, Path.is_file

    def safe_open(opener, file, mode='r', *args, **kwargs):
        if isinstance(file, (str, bytes, os.PathLike)):
            path = Path(os.fsdecode(file)).resolve()
            if path.name == '.env' or 'bootstrap/cache' in str(path) or any(f in mode for f in 'wax+'):
                deny()
        return opener(file, mode, *args, **kwargs)

    before = after = None
    control = None
    owned = {}
    code = 2
    run_id = uuid.uuid4().hex
    with contextlib.ExitStack() as stack:
        # libpq uses C sockets, not these Python APIs. It is permitted exclusively
        # through the exact-kwargs wrapper above, installed before monitor import.
        for name in ('socket.socket.connect', 'socket.socket.connect_ex', 'socket.create_connection',
                     'socket.socket.send', 'socket.socket.sendall', 'socket.socket.sendto', 'socket.getaddrinfo',
                     'subprocess.Popen', 'subprocess.run', 'os.system', 'os.fork', 'os.posix_spawn',
                     'requests.sessions.Session.request', 'webbrowser.open'):
            stack.enter_context(patch(name, deny))
        stack.enter_context(patch.object(psycopg2, 'connect', connect))
        stack.enter_context(patch.object(dotenv, 'load_dotenv', return_value=False))
        stack.enter_context(patch.object(dotenv, 'find_dotenv', deny))
        stack.enter_context(patch.object(Path, 'is_file', lambda p: False if p.name == '.env' else original_is_file(p)))
        stack.enter_context(patch.object(logging.handlers, 'RotatingFileHandler', lambda *a, **k: logging.NullHandler()))
        stack.enter_context(patch.object(logging.FileHandler, '_open', lambda self: io.StringIO()))
        stack.enter_context(patch('builtins.open', lambda f, mode='r', *a, **k: safe_open(original_open, f, mode, *a, **k)))
        stack.enter_context(patch('io.open', lambda f, mode='r', *a, **k: safe_open(original_io_open, f, mode, *a, **k)))
        try:
            import pep_monitor as monitor
            import pep_directory_shadow as shadow
            from pep_directory_storage import Repository
            monitor.logger.handlers = [logging.NullHandler()]
            stack.enter_context(patch.object(monitor, 'DatabaseManager', deny))
            stack.enter_context(patch.object(monitor, 'create_http_session', deny))
            control = connect(**target)
            before = snapshot(control)
            print('BEFORE ' + json.dumps({k: before[k] for k in ('counts', 'fingerprint', 'schema_sha256')}, sort_keys=True), flush=True)
            # Parent fixtures are the only direct INSERTs: fresh random positive BIGINT
            # IDs, no conflict update, no sequence manipulation, no retained-row changes.
            for profile in ('dab', 'ibc', 'umsa'):
                fid = (uuid.uuid4().int & ((1 << 62) - 1)) + 1
                owned[profile] = dict(fuente_id=fid, source='synthetic:shadow:' + run_id + ':' + profile)
                with control.cursor() as c:
                    c.execute('INSERT INTO fuentes (id,label) VALUES (%s,%s)', (fid, owned[profile]['source']))
                control.commit()

            fixtures = root / 'scripts/website_monitor_pro/tests/fixtures/pep_directory'
            bodies = {p: (fixtures / (p + '.html')).read_text(encoding='utf-8') for p in owned}
            urls = {'dab': 'https://www.dab.gob.bo/personal-ejecutivo', 'ibc': 'https://www.ibc.gob.bo/nomina.php',
                    'umsa': 'https://www.umsa.bo/web/guest/rectorado'}
            inventories = {'dab': [['GERENCIA GENERAL', 'GERENTE GENERAL'], ['FINANZAS', 'GERENTE DE FINANZAS']],
                           'ibc': [['Dirección Ejecutiva', 'Director Ejecutivo'], ['Administración', 'Jefe de Finanzas']],
                           'umsa': [['Rectorado', 'Rectorado UMSA']]}
            clock_time = datetime(2026, 11, 1, tzinfo=timezone.utc)

            class ClockType(type):
                def __instancecheck__(cls, instance):
                    return isinstance(instance, datetime)

            class Clock(metaclass=ClockType):
                @classmethod
                def now(cls, tz=None):
                    # Return an actual datetime: the lossless codec deliberately
                    # rejects subclasses. Only the wall clock is controlled.
                    return clock_time if tz else clock_time.replace(tzinfo=None)

            stack.enter_context(patch.object(shadow, 'datetime', Clock))
            # The approved image has no lxml (required only by legacy cleaning).
            # This is a legacy mock, NOT extraction or completeness evidence.
            def legacy_lines(html, selector):
                return ([] if not html else ['small'] if html == '<html><body>small</body></html>'
                        else ['synthetic legacy line 1', 'synthetic legacy line 2', 'synthetic legacy line 3'], 'html')
            stack.enter_context(patch.object(monitor, 'limpiar_html', side_effect=legacy_lines))
            print('BOUNDARY legacy cleaner/image/browser/alert/database mocked; real freeze/extract/compare/Observer/Repository/libpq', flush=True)

            class RecordingRepository:
                """Delegation only: no rewritten comparisons, persisted results or receipts."""
                def __init__(self, connection):
                    self.repo = Repository(connection)

                def submit(self, *args, **kwargs):
                    receipt = self.repo.submit(*args, **kwargs)
                    receipts.append(receipt)
                    return receipt

            def observer(profile, revision='synthetic-r1'):
                item = owned[profile]
                binding = dict(fuente_id=item['fuente_id'], requested_url='https://' + profile + '.example.invalid/directory',
                               source=item['source'], profile=profile, accepted_final_url=urls[profile],
                               expected_positions=inventories[profile], reviewer='synthetic-reviewer-' + run_id,
                               evidence_identity='committed-synthetic-fixture', inventory_revision=revision)
                config = dict(bindings=[binding], connection={k: target[k] for k in ('host', 'port', 'dbname', 'user', 'password')})
                return shadow.Observer(config, repository=RecordingRepository)

            repo = Repository(control)

            class Pipeline(unittest.TestCase):
                def feed(self, profile, body, *, process=None, revision='synthetic-r1', kind='html', header=True, fallback=False):
                    nonlocal clock_time
                    clock_time += timedelta(minutes=1)
                    independent = {p: repo.state(v['source']) for p, v in owned.items() if p != profile}
                    previous = repo.history(owned[profile]['source'])
                    n_receipts, n_connections = len(receipts), len(connections)
                    obj = object.__new__(monitor.PEPMonitor)
                    obj._shadow_observer = observer(profile, revision)
                    obj.running = True
                    obj.db = MagicMock(name='legacy_database_mock')
                    obj.db.cursor.execute.side_effect = deny
                    obj.db.get_ultimo_snapshot.return_value = None
                    obj.http = MagicMock(name='synthetic_transport')
                    response = requests.Response()
                    response._content = body.encode('utf-8')
                    response.status_code = 200
                    response.url = urls[profile]
                    response.encoding = 'utf-8'
                    response.headers['Content-Type'] = 'text/html; charset=utf-8' if header else 'text/html'
                    obj.http.get.return_value = response
                    source = dict(id=owned[profile]['fuente_id'], url='https://' + profile + '.example.invalid/directory',
                                  tipo=kind, nombre='Synthetic ' + profile, analizar_imagenes=True)
                    lines, method = monitor.limpiar_html(body, None)
                    text = '\n'.join(lines)
                    hashed = hashlib.sha256(text.encode()).hexdigest()
                    if process == 'no_change':
                        obj.db.get_ultimo_snapshot.return_value = dict(hash=hashed, texto=text)
                    with patch.object(monitor, 'obtener_html_js', return_value=('synthetic browser selected' if fallback else '', 'js')) as browser, \
                         patch.object(monitor, 'extraer_imagenes_html', return_value=[]) as images, \
                         patch.object(monitor, 'mostrar_alerta', side_effect=deny) as alert, \
                         patch.object(monitor, 'guardar_imagen_local', side_effect=deny) as image_write, \
                         patch.object(monitor, 'comparar_imagenes_cascada', side_effect=deny) as cascade, \
                         patch.object(obj, '_cargar_imgs_anterior', return_value=[]) as old_images:
                        if process:
                            obj.procesar_fuente(source)
                            metric = obj.db.registrar_fuente_run.call_args.kwargs
                            self.assertEqual(metric['estado'], process)
                            self.assertIsNone(metric['http_status'])
                            self.assertIsNone(metric['error_mensaje'])
                            self.assertEqual(metric['cambios_detectados'], 0)
                            expected = []
                            if process != 'no_content':
                                expected.append(call.get_ultimo_snapshot(source['id']))
                            if process == 'first_snapshot':
                                expected.append(call.guardar_snapshot(source['id'], hashed, text, method))
                            expected.extend([call.update_ultimo_check(source['id']), call.registrar_fuente_run(**metric)])
                            self.assertEqual(obj.db.mock_calls, expected)
                            self.assertEqual(images.call_count, int(process != 'no_content'))
                            self.assertEqual(old_images.call_count, int(process == 'no_change'))
                        else:
                            result = obj._obtener_html_raw(source)
                            self.assertEqual(result[1], 'js_playwright' if kind == 'js' else 'js_fallback' if fallback else 'html_estatico')
                            self.assertEqual(obj.db.mock_calls, [])
                            images.assert_not_called()
                            old_images.assert_not_called()
                        alert.assert_not_called()
                        image_write.assert_not_called()
                        cascade.assert_not_called()
                        self.assertEqual(browser.call_count, int(kind == 'js' or len(lines) < 3))
                    if kind == 'js':
                        obj.http.get.assert_not_called()
                    else:
                        obj.http.get.assert_called_once_with(source['url'], timeout=monitor.config.REQUEST_TIMEOUT,
                                                             verify=monitor.verify_para_url(source['url']))
                    eligible = header and kind == 'html' and not fallback
                    self.assertEqual(len(receipts) - n_receipts, int(eligible))
                    self.assertEqual(len(connections) - n_connections, int(eligible))
                    self.assertTrue(all(c.closed for c in connections[n_connections:]))
                    history = repo.history(owned[profile]['source'])
                    self.assertEqual(history, previous + ([receipts[-1]] if eligible else []))
                    for p, state in independent.items():
                        self.assertEqual(repo.state(owned[p]['source']), state)
                    if not eligible:
                        return None
                    receipt = receipts[-1]
                    print('RECEIPT ' + json.dumps({k: receipt[k] for k in ('source_id', 'observation_id', 'disposition', 'version', 'baseline_observation_id', 'observed_at')}, sort_keys=True), flush=True)
                    self.assertEqual(receipt['observation']['evidence'], body)
                    self.assertEqual(receipt['provenance']['body_sha256'], hashlib.sha256(body.encode()).hexdigest())
                    self.assertEqual(receipt['observed_at'], clock_time.isoformat())
                    self.assertEqual(receipt['provenance']['binding']['fuente_id'], source['id'])
                    self.assertTrue(receipt['capture_identity'].startswith('static-http:'))
                    return receipt

                def test_01_dab_first_unchanged_replacement_rejected_revision(self):
                    body = bodies['dab']
                    first = self.feed('dab', body, process='first_snapshot')
                    self.assertEqual((first['disposition'], first['events'], first['version']), ('baseline', [], 1))
                    fresh = self.feed('dab', body, process='no_change')
                    self.assertEqual((fresh['disposition'], fresh['events'], fresh['version']), ('compared', [], 2))
                    self.assertNotEqual(first['submission_id'], fresh['submission_id'])
                    self.assertNotEqual(first['capture_identity'], fresh['capture_identity'])
                    self.assertGreater(fresh['observed_at'], first['observed_at'])
                    self.assertEqual(first['content_sha256'], fresh['content_sha256'])
                    self.assertEqual(repo.state(owned['dab']['source'])['baseline'], fresh['observation'])
                    changed_body = body.replace('María Modelo', 'Beatriz Modelo')
                    changed = self.feed('dab', changed_body)
                    self.assertEqual(len(changed['events']), 1)
                    event = changed['events'][0]['payload']
                    self.assertEqual(event['kind'], 'possible_replacement')
                    self.assertEqual([p['person'] for p in event['before_group']], ['María Modelo'])
                    self.assertEqual([p['person'] for p in event['after_group']], ['Beatriz Modelo'])
                    self.assertEqual(event['before']['condition'], 'a.i.')
                    self.assertEqual(event['after']['condition'], 'a.i.')
                    self.assertEqual(event['previous_evidence'], body)
                    self.assertEqual(event['current_evidence'], changed_body)
                    self.assertEqual(event['detected_at'], changed['observed_at'])
                    self.assertIsNone(event['effective_date'])
                    state = repo.state(owned['dab']['source'])
                    for bad, expected, process in [('', 'capture_empty', 'no_content'), (changed_body.replace('</html>', ''), 'capture_incomplete', None)]:
                        rejected = self.feed('dab', bad, process=process)
                        self.assertEqual((rejected['disposition'], rejected['events'], rejected['reasons']), ('rejected', [], [expected]))
                        self.assertEqual(repo.state(owned['dab']['source']), state)
                    revised = self.feed('dab', body, revision='synthetic-r2')
                    self.assertEqual((revised['disposition'], revised['events'], revised['reasons']), ('rebaselined', [], ['revision_boundary']))
                    self.assertNotEqual(revised['observation']['revision'], changed['observation']['revision'])
                    self.assertEqual(repo.state(owned['dab']['source'])['baseline'], revised['observation'])

                def test_02_ibc_group_not_arbitrary_pairs(self):
                    row = '<tr><td>1</td><td>Ana Ejemplo</td><td>Director Ejecutivo<br>Dirección Ejecutiva</td></tr>'
                    before_body = bodies['ibc'].replace(row, row + row.replace('Ana Ejemplo', 'Beatriz Modelo'))
                    first = self.feed('ibc', before_body)
                    self.assertEqual((first['disposition'], first['events']), ('baseline', []))
                    after_body = before_body.replace('Ana Ejemplo', 'Carla Modelo').replace('Beatriz Modelo', 'Diana Modelo')
                    changed = self.feed('ibc', after_body)
                    self.assertEqual(len(changed['events']), 1)
                    event = changed['events'][0]['payload']
                    self.assertEqual((event['kind'], event['unit'], event['role']), ('membership_change', 'Dirección Ejecutiva', 'Director Ejecutivo'))
                    for key in ('before_group', 'outgoing'):
                        self.assertEqual([p['person'] for p in event[key]], ['Ana Ejemplo', 'Beatriz Modelo'])
                    for key in ('after_group', 'incoming'):
                        self.assertEqual([p['person'] for p in event[key]], ['Carla Modelo', 'Diana Modelo'])
                    self.assertIsNone(event['before'])
                    self.assertIsNone(event['after'])
                    self.assertIsNone(event['effective_date'])
                    self.assertEqual(event['condition_changes'], [])
                    self.assertEqual(repo.state(owned['ibc']['source'])['baseline'], changed['observation'])

                def test_03_umsa_encoding_and_ineligible_capture(self):
                    body = bodies['umsa']
                    first = self.feed('umsa', body)
                    self.assertEqual((first['disposition'], first['events']), ('baseline', []))
                    for text in (body.replace('María Modelo', 'MarÃ­a Modelo'), body):
                        fresh = self.feed('umsa', text)
                        self.assertEqual((fresh['disposition'], fresh['events']), ('compared', []))
                        self.assertEqual(repo.state(owned['umsa']['source'])['baseline'], fresh['observation'])
                    state = repo.state(owned['umsa']['source'])
                    self.feed('umsa', body, kind='js')
                    self.feed('umsa', body, header=False)
                    self.feed('umsa', '<html><body>small</body></html>', fallback=True)
                    self.assertEqual(repo.state(owned['umsa']['source']), state)
                    self.assertEqual(denied_calls, [])

            result = unittest.TextTestRunner(stream=sys.stdout, verbosity=2, failfast=True).run(
                unittest.defaultTestLoader.loadTestsFromTestCase(Pipeline))
            code = 0 if result.wasSuccessful() else 1
        except Exception as error:
            import traceback
            print('SETUP/GUARD ERROR: ' + type(error).__name__ + ': ' + str(error), file=sys.stderr)
            traceback.print_exc()
            code = 2
        finally:
            try:
                if before is not None:
                    if control.closed:
                        control = connect(**target)
                    control.rollback()
                    after = snapshot(control)
                    assert before['schema_sha256'] == after['schema_sha256'], 'schema changed'
                    for table, rows in before['rows'].items():
                        for pk, value in rows.items():
                            assert after['rows'][table].get(pk) == value, 'retained row changed: ' + table + '/' + pk
                    additions = {t: sorted(set(after['rows'][t]) - set(before['rows'][t])) for t in columns}
                    expected = {'fuentes': {str(v['fuente_id']) for v in owned.values()}, 'users': set(),
                                'pep_directory_sources': {str(r['source_id']) for r in receipts},
                                'pep_directory_observations': {str(r['observation_id']) for r in receipts},
                                'pep_directory_events': {str(e['event_id']) for r in receipts for e in r['events']},
                                'pep_directory_reviews': set()}
                    for table in columns:
                        assert set(additions[table]) == expected[table], 'unexpected additions: ' + table
                    retained_after = {t: {pk: after['rows'][t][pk] for pk in rows} for t, rows in before['rows'].items()}
                    print('AFTER ' + json.dumps(dict(fingerprint=after['fingerprint'],
                          retained_fingerprint=digest(retained_after), schema_sha256=after['schema_sha256'],
                          counts=after['counts'], additions=additions, owned=owned, run_id=run_id,
                          preexisting_unchanged=True), sort_keys=True), flush=True)
            except BaseException:
                import traceback
                print('PRESERVATION ERROR', file=sys.stderr)
                traceback.print_exc()
                code = 1
            finally:
                for conn in connections:
                    if not conn.closed:
                        conn.close()
                print('CONNECTIONS ' + json.dumps(dict(opened=len(connections), all_closed=all(c.closed for c in connections),
                      forbidden_calls=denied_calls)), flush=True)
                if denied_calls:
                    code = 1
    return code


if __name__ == '__main__':
    sys.exit(main())
