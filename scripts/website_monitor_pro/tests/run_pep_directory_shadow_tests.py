#!/usr/bin/env python3
"""Offline, pre-collection isolation for the dormant capture seam (no PG proof)."""
import argparse
import builtins
import contextlib
import io
import logging
import logging.handlers
import os
from pathlib import Path
import sys
from unittest.mock import patch


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-monitor', required=True, help='Parent-exported read-only baseline source')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[3]
    evidence = root / 'storage/app/pep-directory-shadow-check'
    evidence.mkdir(parents=True, exist_ok=True)
    sys.dont_write_bytecode = True
    os.environ['PYTEST_DISABLE_PLUGIN_AUTOLOAD'] = '1'
    os.environ.pop('PEP_DIRECTORY_SHADOW_CONFIG', None)
    os.environ['PEP_SHADOW_TEST_BASE'] = str(Path(args.base_monitor).resolve())
    sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

    def denied(*args, **kwargs):
        raise AssertionError('external operation blocked by shadow test isolation')

    original_open = builtins.open
    original_io_open = io.open
    original_is_file = Path.is_file

    def safe_open(opener, file, mode='r', *args, **kwargs):
        if isinstance(file, (str, bytes, os.PathLike)):
            target = Path(os.fsdecode(file)).resolve()
            if target.name == '.env' or 'bootstrap/cache' in str(target):
                return denied()
            if any(flag in mode for flag in 'wax+') and not target.is_relative_to(evidence):
                return denied()
        return opener(file, mode, *args, **kwargs)

    import dotenv
    import requests
    import psycopg2
    output = io.StringIO()
    stdout, stderr = io.StringIO(), io.StringIO()

    class Tee:
        def __init__(self, stream, buffer):
            self.stream = stream
            self.buffer = buffer
        def write(self, text):
            output.write(text)
            self.buffer.write(text)
            return self.stream.write(text)
        def flush(self):
            self.stream.flush()
        def __getattr__(self, name):
            return getattr(self.stream, name)

    with contextlib.ExitStack() as stack:
        for target in ('socket.socket.connect', 'socket.socket.connect_ex', 'socket.create_connection',
                       'socket.socket.sendto', 'socket.socket.sendall', 'socket.socket.send',
                       'socket.getaddrinfo', 'subprocess.Popen', 'subprocess.run', 'os.system', 'os.fork', 'os.posix_spawn',
                       'requests.sessions.Session.request', 'psycopg2.connect'):
            stack.enter_context(patch(target, denied))
        stack.enter_context(patch.object(dotenv, 'load_dotenv', return_value=False))
        stack.enter_context(patch.object(Path, 'is_file', lambda p: False if p.name == '.env' else original_is_file(p)))
        stack.enter_context(patch.object(logging.handlers, 'RotatingFileHandler', lambda *a, **k: logging.NullHandler()))
        stack.enter_context(patch.object(logging.FileHandler, '_open', lambda self: io.StringIO()))
        stack.enter_context(patch('builtins.open', lambda f, mode='r', *a, **k: safe_open(original_open, f, mode, *a, **k)))
        stack.enter_context(patch('io.open', lambda f, mode='r', *a, **k: safe_open(original_io_open, f, mode, *a, **k)))
        import pytest
        tests = Path(__file__).resolve().parent
        with contextlib.redirect_stdout(Tee(sys.stdout, stdout)), contextlib.redirect_stderr(Tee(sys.stderr, stderr)):
            code = pytest.main(['-v', '--capture=sys', '-c', '/dev/null', '--noconftest', '-p', 'no:cacheprovider',
                                *[str(tests / name) for name in (
                                    'test_pep_directory_shadow.py', 'test_fuente_runs.py',
                                    'test_pep_directory.py', 'test_pep_directory_extractors.py',
                                    'test_pep_directory_codec.py')]])
    index = 1
    while (evidence / f'validation-{index}.txt').exists():
        index += 1
    (evidence / f'validation-{index}.txt').write_text(output.getvalue() + f'\npytest exit: {int(code)}\n', encoding='utf-8')
    (evidence / f'validation-{index}.stdout.txt').write_text(stdout.getvalue(), encoding='utf-8')
    (evidence / f'validation-{index}.stderr.txt').write_text(stderr.getvalue(), encoding='utf-8')
    return int(code)


if __name__ == '__main__':
    sys.exit(main())
