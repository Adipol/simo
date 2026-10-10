"""Physical source-row lock probe against the disposable M3d1 PostgreSQL database.

Covers one source-lock/claim schedule, not every schedule or PHP locking.
"""

from __future__ import annotations

import json
import multiprocessing as mp
import os
import queue
import sys
import time
from pathlib import Path

import psycopg2

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from pep_monitor import DatabaseManager


def connect():
    return psycopg2.connect(
        host=os.environ["DB_HOST"], port=os.environ["DB_PORT"],
        dbname=os.environ["DB_NAME"], user=os.environ["DB_USER"],
        password=os.environ["DB_PASSWORD"], connect_timeout=10,
    )


def holder(source_id: int, messages, release, locked) -> None:
    connection = None
    try:
        connection = connect()
        with connection.cursor() as cursor:
            cursor.execute("SELECT pg_backend_pid()")
            pid = cursor.fetchone()[0]
            cursor.execute("SELECT id FROM fuentes WHERE id = %s FOR UPDATE", (source_id,))
            if cursor.fetchone() != (source_id,):
                raise AssertionError("source row missing")
            messages.put(("holder", pid))
            locked.set()
            if not release.wait(40):
                raise TimeoutError("source lock release timed out")
            connection.commit()
    except BaseException as exc:
        messages.put(("holder_error", repr(exc)))
        raise
    finally:
        if connection is not None:
            if not connection.closed:
                connection.rollback()
            connection.close()


def claimant(source_id: int, messages, start) -> None:
    manager = None
    try:
        manager = DatabaseManager()
        manager.cursor.execute("SELECT pg_backend_pid() AS pid")
        messages.put(("claimant", manager.cursor.fetchone()["pid"]))
        if not start.wait(30):
            raise TimeoutError("claim start timed out")
        claim = manager.claim_preview(source_id)
        messages.put(("claim", claim["lease_token"] if claim is not None else None))
    except BaseException as exc:
        messages.put(("claimant_error", repr(exc)))
        raise
    finally:
        if manager is not None:
            manager.cursor.close()
            manager.connection.close()


def receive(messages, expected: str, timeout: float = 30):
    try:
        label, value = messages.get(timeout=timeout)
    except queue.Empty as exc:
        raise AssertionError(f"timed out waiting for {expected}") from exc
    if label != expected:
        raise AssertionError(f"expected {expected}, got {label}: {value}")
    return value


def row(cursor, source_id: int):
    cursor.execute(
        "SELECT status, attempts, lease_token, lease_expires_at "
        "FROM authority_extraction_previews WHERE fuente_id = %s", (source_id,),
    )
    return cursor.fetchone()


def main(state_path: Path) -> None:
    state = json.loads(state_path.read_text())
    source_id = state["stale"]
    if type(source_id) is not int or source_id < 1:
        raise AssertionError("invalid stale source id")
    observer = connect()
    observer.autocommit = True
    ctx = mp.get_context("spawn")
    messages = ctx.Queue()
    release = ctx.Event()
    locked = ctx.Event()
    start = ctx.Event()
    a = ctx.Process(target=holder, args=(source_id, messages, release, locked), name="source-lock-holder")
    b = ctx.Process(target=claimant, args=(source_id, messages, start), name="preview-claimant")
    try:
        with observer.cursor() as cursor:
            if row(cursor, source_id) != ("pending", 0, None, None):
                raise AssertionError("current stale source preview must be pending with zero attempts and no lease")
            cursor.execute(
                "SELECT f.authority_extraction_revision, f.url, p.source_revision, p.source_url "
                "FROM fuentes f JOIN authority_extraction_previews p ON p.fuente_id = f.id "
                "WHERE f.id = %s", (source_id,),
            )
            identity = cursor.fetchone()
            if identity is None or identity[0] is None or identity[0] != identity[2] or identity[1] != identity[3]:
                raise AssertionError("stale source preview identity is not current")
            a.start()
            if not locked.wait(30):
                raise AssertionError("holder did not acquire source lock")
            a_pid = receive(messages, "holder")
            b.start()
            b_pid = receive(messages, "claimant")
            cursor.execute("SELECT pg_backend_pid()")
            observer_pid = cursor.fetchone()[0]
            if len({a_pid, b_pid, observer_pid}) != 3:
                raise AssertionError("holder, claimant and observer must have distinct PostgreSQL backends")
            start.set()
            deadline = time.monotonic() + 20
            observed = False
            while time.monotonic() < deadline:
                cursor.execute("SELECT pg_blocking_pids(%s)", (b_pid,))
                if a_pid in cursor.fetchone()[0]:
                    observed = True
                    break
                if not b.is_alive():
                    raise AssertionError("claimant exited before physical blocking was observed")
                time.sleep(0.05)
            if not observed:
                raise AssertionError("PG did not report holder blocking claimant before deadline")
            if not a.is_alive() or not b.is_alive():
                raise AssertionError("both processes must be alive during observed blocking")
            if row(cursor, source_id) != ("pending", 0, None, None):
                raise AssertionError("preview changed before source lock release")
            release.set()
            token = receive(messages, "claim")
            b.join(timeout=15)
            a.join(timeout=15)
            if a.exitcode != 0 or b.exitcode != 0 or not isinstance(token, str) or not token:
                raise AssertionError(f"holder/claimant failed: {a.exitcode=} {b.exitcode=} {token=}")
            status, attempts, persisted, expiry = row(cursor, source_id)
            if (status, attempts, persisted) != ("running", 1, token) or expiry is None:
                raise AssertionError("single claim lease not persisted after release")
            cursor.execute(
                "SELECT count(*) FROM authority_extraction_previews "
                "WHERE fuente_id = %s AND status = 'running' AND lease_token = %s "
                "AND lease_expires_at > NOW()", (source_id, token),
            )
            if cursor.fetchone()[0] != 1:
                raise AssertionError("exactly one live running lease required")
            competitor = DatabaseManager()
            try:
                if competitor.claim_preview(source_id) is not None:
                    raise AssertionError("competing claim overwrote live lease")
            finally:
                competitor.cursor.close()
                competitor.connection.close()
            if row(cursor, source_id) != ("running", 1, token, expiry):
                raise AssertionError("live lease changed after competing claim")
            print("PASS physical source-lock claim: distinct backends, pg_blocking_pids, single lease")
    finally:
        release.set()
        start.set()
        for process in (a, b):
            if process.pid is not None:
                process.join(timeout=3)
                if process.is_alive():
                    process.terminate()
                    process.join(timeout=5)
        messages.close()
        messages.join_thread()
        observer.close()


if __name__ == "__main__":
    try:
        main(Path(sys.argv[1]))
    except Exception as exc:
        print(f"FAIL physical source-lock claim: {exc}", file=sys.stderr)
        raise
