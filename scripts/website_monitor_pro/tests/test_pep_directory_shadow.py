"""Synthetic observer wiring + baseline differential; not independent inventory/PG proof."""
import contextlib
from datetime import datetime, timezone
import hashlib
import io
import json
import logging
import os
from pathlib import Path
import sys
from types import ModuleType
from unittest.mock import MagicMock, patch

import pytest
import requests
import pep_monitor as candidate

URL = 'https://directory.example.invalid/authorities'
FINAL = 'https://www.umsa.bo/web/guest/rectorado'
SOURCE = dict(id=901, url=URL, tipo='html', nombre='Synthetic directory', analizar_imagenes=True)
BODY = (Path(__file__).parent / 'fixtures/pep_directory/umsa.html').read_bytes()
NOW = datetime(2026, 1, 2, tzinfo=timezone.utc)


class Clock(datetime):
    @classmethod
    def now(cls, tz=None):
        return NOW if tz else NOW.replace(tzinfo=None)


def response(body=BODY, status=200):
    result = requests.Response()
    result._content = body
    result.status_code = status
    result.url = FINAL
    result.headers['Content-Type'] = 'text/html; charset=utf-8'
    result.encoding = 'utf-8'
    return result


def binding():
    return dict(fuente_id=901, requested_url=URL, source='synthetic:directory', profile='umsa',
                accepted_final_url=FINAL, expected_positions=[['Rectorado', 'Rectorado UMSA']],
                reviewer='synthetic-reviewer', evidence_identity='synthetic-inventory-evidence',
                inventory_revision='synthetic-r1')


def configuration():
    return dict(bindings=[binding()], connection=dict(host='db.example.invalid', port=5432,
                dbname='synthetic', user='synthetic', password='NEVER-LOG-THIS'))


def monitor(module=candidate, observer=None):
    obj = object.__new__(module.PEPMonitor)
    obj.db = MagicMock()
    obj.db.get_ultimo_snapshot.return_value = None
    obj.db.guardar_cambio.return_value = 88
    obj.db.cursor.fetchone.return_value = {'id': 7}
    obj.http = MagicMock()
    obj.http.get.return_value = response()
    obj.running = True
    if observer is not None:
        obj._shadow_observer = observer
    return obj


def test_injected_observer_freezes_before_cleaning():
    observer = MagicMock()
    with patch.object(candidate, 'DatabaseManager'), patch.object(candidate, 'create_http_session'):
        obj = candidate.PEPMonitor(shadow_observer=observer)
    obj.http = MagicMock()
    resp = response()
    obj.http.get.return_value = resp
    def clean(html, selector):
        resp._content = b'changed after text read'
        resp.url = 'https://wrong.example.invalid/'
        return ['one', 'two', 'three'], 'html'
    with patch.object(candidate, 'limpiar_html', side_effect=clean):
        result = obj._obtener_html_raw(SOURCE)
    assert result == (BODY.decode(), 'html_estatico')
    capture = observer.call_args.args[0]
    assert capture.body == BODY and capture.final_url == FINAL
    assert capture.requested_url == URL and capture.fuente_id == 901
    assert capture.encoding == 'utf-8' and capture.observed_at.utcoffset() is not None
    assert capture.status == 200 and capture.charset_provenance == 'content-type'
    obj.http.get.assert_called_once()


def test_default_off_no_shadow_import_or_config_read():
    obj = monitor()
    original_import = __import__
    def guarded(name, *args, **kwargs):
        assert name != 'pep_directory_shadow'
        return original_import(name, *args, **kwargs)
    with patch.dict(os.environ, {}, clear=True), patch('builtins.__import__', side_effect=guarded), \
         patch.object(candidate, 'limpiar_html', return_value=(['a', 'b', 'c'], 'html')):
        assert obj._obtener_html_raw(SOURCE)[1] == 'html_estatico'


def test_enabled_environment_selector_and_failure_containment(caplog):
    obj = monitor()
    observer = MagicMock()
    with patch.dict(os.environ, {'PEP_DIRECTORY_SHADOW_CONFIG': 'synthetic-config.json'}), \
         patch('pep_directory_shadow.load_observer', return_value=observer) as load, \
         patch.object(candidate, 'limpiar_html', return_value=(['a', 'b', 'c'], 'html')):
        obj._obtener_html_raw(SOURCE)
        load.assert_called_once_with('synthetic-config.json')
        observer.assert_called_once()
    with patch.dict(os.environ, {'PEP_DIRECTORY_SHADOW_CONFIG': 'synthetic-config.json'}), \
         patch('pep_directory_shadow.load_observer', side_effect=ValueError('NEVER-LOG-THIS')), \
         patch.object(candidate, 'limpiar_html', return_value=(['a', 'b', 'c'], 'html')):
        assert monitor()._obtener_html_raw(SOURCE)[1] == 'html_estatico'
    assert 'NEVER-LOG-THIS' not in caplog.text
    assert 'pep_directory_shadow' in caplog.text


@pytest.mark.parametrize('mode', ['pdf', 'js', 'fallback', 'http_error', 'connection'])
def test_non_static_never_submits(mode, caplog):
    observer = MagicMock()
    obj = monitor(observer=observer)
    source = dict(SOURCE)
    if mode in ('pdf', 'js'):
        source['tipo'] = mode
    if mode == 'http_error':
        obj.http.get.return_value = response(status=503)
    if mode == 'connection':
        obj.http.get.side_effect = requests.ConnectionError('synthetic')
    with patch.object(candidate, 'limpiar_html', side_effect=[(['a'], 'html'), (['a', 'b'], 'html')]), \
         patch.object(candidate, 'obtener_html_js', return_value=('browser synthetic', 'js')):
        if mode == 'connection':
            with pytest.raises(requests.ConnectionError):
                obj._obtener_html_raw(source)
        else:
            result = obj._obtener_html_raw(source)
            assert result[1] == dict(pdf='pdf', js='js_playwright', fallback='js_fallback', http_error='error_http')[mode]
    observer.assert_not_called()
    if mode in ('js', 'fallback'):
        assert 'pep_directory_shadow' in caplog.text


@pytest.mark.parametrize('header', [None, 'text/html', 'text/html; charset=unknown-codec',
                                  'text/html; charset=utf-8; charset=cp1252'])
def test_bad_charset_is_shadow_only_and_never_guesses(header):
    observer = MagicMock()
    obj = monitor(observer=observer)
    if header is None:
        obj.http.get.return_value.headers.clear()
    else:
        obj.http.get.return_value.headers['Content-Type'] = header
    with patch.object(candidate, 'limpiar_html', return_value=(['a', 'b', 'c'], 'html')):
        assert obj._obtener_html_raw(SOURCE) == (BODY.decode(), 'html_estatico')
    observer.assert_not_called()


@pytest.mark.parametrize('previous', [None, 'same', 'different'])
def test_observation_precedes_first_unchanged_and_changed_legacy_exits(previous):
    observer = MagicMock()
    obj = monitor(observer=observer)
    lines = ['one', 'two', 'three']
    if previous:
        text = '\n'.join(lines) if previous == 'same' else 'previous synthetic text'
        obj.db.get_ultimo_snapshot.return_value = dict(hash=hashlib.sha256(text.encode()).hexdigest(), texto=text)
    obj.db.cursor.fetchall.return_value = []
    def submitted(capture):
        obj.db.get_ultimo_snapshot.assert_not_called()
        obj.db.update_ultimo_check.assert_not_called()
    observer.side_effect = submitted
    with patch.object(candidate, 'limpiar_html', return_value=(lines, 'html')), \
         patch.object(candidate, 'extraer_imagenes_html', return_value=[]), patch.object(candidate, 'mostrar_alerta'):
        obj.procesar_fuente(SOURCE)
    observer.assert_called_once()
    obj.http.get.assert_called_once()
    run = obj.db.registrar_fuente_run.call_args.kwargs
    assert run['http_status'] is None and run['error_mensaje'] is None
    assert run['estado'] == {None: 'first_snapshot', 'same': 'no_change', 'different': 'success'}[previous]


def test_empty_static_reaches_real_adapter_and_mock_repository_before_legacy_exit():
    from pep_directory_shadow import Observer
    from pep_directory import Validity
    repo = MagicMock()
    observer = Observer(configuration(), connect=MagicMock(), repository=MagicMock(return_value=repo))
    obj = monitor(observer=observer)
    obj.http.get.return_value = response(b'')
    with patch.object(candidate, 'limpiar_html', return_value=([], 'html')), \
         patch.object(candidate, 'obtener_html_js', return_value=('', 'js')):
        obj.procesar_fuente(SOURCE)
    assert repo.submit.call_args.args[0].validity == Validity.EMPTY
    assert obj.db.registrar_fuente_run.call_args.kwargs['estado'] == 'no_content'
    obj.http.get.assert_called_once()


def frozen_capture(**kwargs):
    from pep_directory_shadow import FrozenCapture
    fields = dict(body=BODY, fuente_id=901, requested_url=URL, final_url=FINAL,
                  status=200, encoding='utf-8', charset_provenance='content-type', observed_at=NOW)
    fields.update(kwargs)
    return FrozenCapture(**fields)


def test_real_adapter_mock_repository_binding_and_unique_submission():
    from pep_directory_shadow import Observer
    conn = MagicMock()
    repo = MagicMock()
    observer = Observer(configuration(), connect=MagicMock(return_value=conn), repository=MagicMock(return_value=repo))
    observer(frozen_capture())
    observer(frozen_capture())
    first, second = repo.submit.call_args_list
    obs = first.args[0]
    assert obs.source == 'synthetic:directory' and obs.positions[0].person == 'María Modelo'
    assert obs.revision.startswith('pilot-html-v2-groups:')
    assert first.kwargs['submission_id'] != second.kwargs['submission_id']
    assert first.kwargs['capture_identity'] != second.kwargs['capture_identity']
    assert first.kwargs['provenance']['requested_url'] == URL
    assert first.kwargs['provenance']['final_url'] == FINAL
    assert 'NEVER-LOG-THIS' not in str(first)
    assert conn.close.call_count == 2
    call = observer.connect.call_args.kwargs
    assert call['connect_timeout'] > 0
    assert 'statement_timeout=' in call['options'] and 'lock_timeout=' in call['options']


@pytest.mark.parametrize('change', ['reviewer', 'inventory_revision', 'evidence_identity', 'requested_url'])
def test_qualification_change_rebaselines(change):
    from pep_directory_shadow import Observer
    revisions = []
    for suffix in ('a', 'b'):
        config = configuration()
        config['bindings'][0][change] += suffix
        repo = MagicMock()
        obj = Observer(config, connect=MagicMock(), repository=MagicMock(return_value=repo))
        cap = frozen_capture(requested_url=config['bindings'][0]['requested_url'])
        obj(cap)
        revisions.append(repo.submit.call_args.args[0].revision)
    assert revisions[0] != revisions[1]


@pytest.mark.parametrize('mutate', [
    lambda c: c['bindings'].append(binding()),
    lambda c: c['bindings'][0].update(fuente_id=True),
    lambda c: c['bindings'][0].update(expected_positions=[['Rectorado', 'Rectorado UMSA']] * 2),
    lambda c: c['bindings'][0].update(reviewer=''),
    lambda c: c['bindings'][0].update(profile='unknown'),
    lambda c: c['bindings'][0].update(accepted_final_url=URL),
    lambda c: c.update(connection={}),
    lambda c: c['bindings'].append(dict(binding(), fuente_id=902)),
])
def test_invalid_binding_refused_before_connection(mutate):
    from pep_directory_shadow import Observer
    config = configuration()
    mutate(config)
    connect = MagicMock()
    with pytest.raises(ValueError):
        Observer(config, connect=connect)
    connect.assert_not_called()


@pytest.mark.parametrize('fields', [dict(fuente_id=902), dict(requested_url=FINAL), dict(final_url=URL),
                                  dict(encoding=None), dict(encoding='unknown-codec'), dict(observed_at=NOW.replace(tzinfo=None))])
def test_capture_mismatch_or_unevidenced_charset_never_connects(fields):
    from pep_directory_shadow import Observer
    connect = MagicMock()
    observer = Observer(configuration(), connect=connect)
    with pytest.raises(ValueError):
        observer(frozen_capture(**fields))
    connect.assert_not_called()


@pytest.mark.parametrize('failure', ['extract', 'connect', 'submit', 'metadata'])
def test_shadow_failures_do_not_escape_into_legacy(failure, caplog):
    from pep_directory_shadow import Observer
    conn = MagicMock()
    repo = MagicMock()
    connect = MagicMock(return_value=conn)
    observer = Observer(configuration(), connect=connect, repository=MagicMock(return_value=repo))
    obj = monitor(observer=observer)
    if failure == 'connect':
        connect.side_effect = ValueError('NEVER-LOG-THIS')
    if failure == 'submit':
        repo.submit.side_effect = ValueError('NEVER-LOG-THIS')
    if failure == 'metadata':
        obj.http.get.return_value.headers['Content-Type'] = 'text/html; charset=unknown-codec'
    with contextlib.ExitStack() as stack:
        stack.enter_context(patch.object(candidate, 'limpiar_html', return_value=(['a', 'b', 'c'], 'html')))
        if failure == 'extract':
            stack.enter_context(patch('pep_directory_shadow.extract', side_effect=ValueError('NEVER-LOG-THIS')))
        assert obj._obtener_html_raw(SOURCE)[1] == 'html_estatico'
    assert 'NEVER-LOG-THIS' not in caplog.text
    if failure == 'submit':
        conn.close.assert_called_once()


def test_repository_construction_failure_still_closes_owned_connection():
    from pep_directory_shadow import Observer
    connection = MagicMock()
    factory = MagicMock(side_effect=ValueError('idle check failed'))
    observer = Observer(configuration(), connect=MagicMock(return_value=connection), repository=factory)
    with pytest.raises(ValueError):
        observer(frozen_capture())
    connection.close.assert_called_once()


def test_loader_rejects_duplicate_json_keys_without_connection():
    from pep_directory_shadow import load_observer
    with patch.object(Path, 'open', return_value=io.StringIO('{"bindings": [], "bindings": []}')):
        with pytest.raises(ValueError):
            load_observer('synthetic.json')


@pytest.fixture(scope='module')
def baseline():
    path = os.environ.get('PEP_SHADOW_TEST_BASE')
    if not path:
        pytest.fail('Use isolated runner with parent-exported baseline')
    module = ModuleType('pep_monitor_baseline')
    module.__file__ = candidate.__file__  # Canonical path context, not the scratch export directory.
    sys.modules[module.__name__] = module
    exec(compile(Path(path).read_text(), candidate.__file__, 'exec'), module.__dict__)
    return module


def exercise(module, case, selector):
    obj = monitor(module)
    source = dict(SOURCE)
    obj.http.get.return_value = response(b'' if case == 'empty' else BODY, 503 if case == 'http_error' else 200)
    lines = ['Ana Modelo', 'Unidad ficticia', 'Cargo ficticio']
    if case != 'first':
        old = ['Beatriz Modelo', 'Unidad ficticia', 'Cargo ficticio'] if case == 'changed' else list(lines)
        if case == 'reordered':
            old.reverse()
        text = '\n'.join(old)
        obj.db.get_ultimo_snapshot.return_value = dict(hash=hashlib.sha256(text.encode()).hexdigest(), texto=text)
    if case in ('js', 'pdf'):
        source['tipo'] = case
    obj.db.cursor.fetchall.return_value = []
    effects = {}
    stdout, stderr = io.StringIO(), io.StringIO()
    with contextlib.ExitStack() as stack:
        stack.enter_context(contextlib.redirect_stdout(stdout))
        stack.enter_context(contextlib.redirect_stderr(stderr))
        log = stack.enter_context(patch.object(module, 'logger'))
        stack.enter_context(patch.dict(os.environ, {'PEP_DIRECTORY_SHADOW_CONFIG': selector} if selector else {}, clear=True))
        stack.enter_context(patch.object(module, 'datetime', Clock))
        clean = stack.enter_context(patch.object(module, 'limpiar_html', return_value=(lines, 'html')))
        if case == 'fallback':
            clean.side_effect = [(['a'], 'html'), (lines, 'html'), (lines, 'html')]
        image = dict(bytes=b'synthetic image', src_absoluto='https://image.example.invalid/a.png',
                     sha256='synthetic', mime_type='image/png')
        for name, value in [('obtener_html_js', ('browser synthetic', 'js')), ('limpiar_pdf', (lines, 'pdf')),
                            ('extraer_imagenes_html', [image] if case == 'image' else []), ('mostrar_alerta', None),
                            ('comparar_imagenes_cascada', ([image], [dict(src='synthetic')]) if case == 'image' else ([], [])),
                            ('guardar_imagen_local', 'synthetic.png'),
                            ('registrar_snapshot_imagenes', None)]:
            effects[name] = stack.enter_context(patch.object(module, name, return_value=value))
        stack.enter_context(patch('pep_directory_shadow.load_observer', side_effect=ValueError('sanitized')) if selector == 'failing' else
                            patch('pep_directory_shadow.load_observer', return_value=MagicMock()))
        obj.procesar_fuente(source)
    # Compare transport ownership by semantic identity, not MagicMock address/equality.
    effect_calls = {name: [(c[0], tuple('owned-http-session' if arg is obj.http else
                                      'legacy-cursor' if arg is obj.db.cursor else arg for arg in c.args), c.kwargs)
                           for c in mock.mock_calls] for name, mock in effects.items()}
    legacy_logs = [c for c in log.mock_calls if not (c.args and str(c.args[0]).startswith('pep_directory_shadow:'))]
    return (obj.db.mock_calls, obj.http.mock_calls, effect_calls, legacy_logs, stdout.getvalue(), stderr.getvalue())


@pytest.mark.parametrize('selector', ['', 'enabled', 'failing'])
@pytest.mark.parametrize('case', ['first', 'unchanged', 'changed', 'reordered', 'empty', 'http_error', 'pdf', 'js', 'fallback', 'image'])
def test_process_differential(baseline, case, selector):
    assert exercise(candidate, case, selector) == exercise(baseline, case, selector)


@pytest.mark.parametrize('selector', ['', 'enabled', 'failing'])
@pytest.mark.parametrize('case', ['static', 'empty', 'http_error', 'pdf', 'js', 'fallback', 'connection'])
def test_public_capture_tuple_and_transport_differential(baseline, case, selector):
    def run(module):
        obj = monitor(module)
        source = dict(SOURCE, tipo=case if case in ('pdf', 'js') else 'html')
        obj.http.get.return_value = response(b'' if case == 'empty' else BODY, 503 if case == 'http_error' else 200)
        if case == 'connection':
            obj.http.get.side_effect = requests.ConnectionError('synthetic connection')
        with patch.dict(os.environ, {'PEP_DIRECTORY_SHADOW_CONFIG': selector} if selector else {}, clear=True), \
             patch('pep_directory_shadow.load_observer', side_effect=ValueError('sanitized') if selector == 'failing' else None,
                   return_value=MagicMock()), \
             patch.object(module, 'limpiar_html', side_effect=[(['one'], 'html'), (['one', 'two'], 'html')]
                          if case == 'fallback' else None, return_value=(['one', 'two', 'three'], 'html')), \
             patch.object(module, 'obtener_html_js', return_value=('synthetic browser', 'js')) as browser, \
             patch.object(module, 'logger'):
            try:
                result = obj._obtener_html_raw(source)
            except requests.ConnectionError:
                result = ('exception', 'ConnectionError')
        return result, obj.http.mock_calls, browser.mock_calls
    assert run(candidate) == run(baseline)


@pytest.mark.parametrize('selector', ['', 'enabled', 'failing'])
@pytest.mark.parametrize('args', [[], ['--help'], ['run'], ['check'],
    ['add', URL, '--nombre', 'Synthetic', '--pais', 'Synthetic', '--organismo', 'Synthetic',
     '--nivel', 'otro', '--tipo', 'js', '--selector', '#synthetic'],
    ['remove', URL], ['list'], ['history', '901', '--limite', '3'], ['ver', '88'],
    ['export', '--pais', 'Synthetic', '--fuente', '901'], ['stats']])
def test_cli_differential(baseline, args, selector):
    def run(module):
        stdout, stderr = io.StringIO(), io.StringIO()
        db = MagicMock()
        db.list_fuentes.return_value = []
        db.cursor.fetchone.return_value = None
        db.get_cambio_detalle.return_value = None
        db.get_stats.return_value = dict(total_fuentes=0, cambios_semana=0, cambios_mes=0, por_fuente=[])
        exporter = MagicMock()
        exporter.exportar_cambios.return_value = 'synthetic.csv'
        with patch.object(sys, 'argv', ['pep_monitor.py', *args]), \
             patch.dict(os.environ, {'PEP_DIRECTORY_SHADOW_CONFIG': selector} if selector else {}, clear=True), \
             patch.object(module, 'DatabaseManager', return_value=db), \
             patch.object(module.PEPMonitor, 'run'), patch.object(module.PEPMonitor, 'check_all'), \
             patch.object(module, 'create_http_session'), patch.object(module, 'Exportador', return_value=exporter), \
             contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
            code = 0
            try:
                module.main()
            except SystemExit as error:
                code = error.code
        return stdout.getvalue(), stderr.getvalue(), code, db.mock_calls, exporter.mock_calls
    assert run(candidate) == run(baseline)


def test_check_all_order_counts_log_fin_differential(baseline):
    def run(module):
        obj = monitor(module)
        obj.db.get_fuentes_activas.return_value = [dict(SOURCE), dict(SOURCE, id=902)]
        obj.db.log_inicio.return_value = 12
        obj.db.get_ultimo_snapshot.return_value = None
        obj.procesar_fuente = MagicMock()
        with patch.object(module, 'hay_internet', return_value=True):
            obj.check_all()
        return obj.db.mock_calls, obj.procesar_fuente.mock_calls
    assert run(candidate) == run(baseline)
