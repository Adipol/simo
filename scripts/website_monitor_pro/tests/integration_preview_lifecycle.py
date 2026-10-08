"""Independent Python process against the PHP-migrated disposable PostgreSQL database."""

from __future__ import annotations

import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from pep_monitor import PEPMonitor
from structured_authorities import extract_structured_authorities

READY = '<main><article><span class="role">Rectora</span><span class="person">Ada Lovelace</span></article></main>'
DUPLICATE = '<main><article><b class="role">Rectora</b><b class="person">Ada Lovelace</b></article><article><b class="role">Rectora</b><b class="person">Ada Lovelace</b></article></main>'
PARTIAL = '<main><article><b class="role">Rectora</b><b class="person">Ada Lovelace</b></article><article><b class="person">Grace Hopper</b></article></main>'
FRESH = '<main><article><span class="role">Rectora</span><span class="person">Grace Hopper</span></article></main>'


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def preview(monitor: PEPMonitor, fuente_id: int) -> dict:
    monitor.db.cursor.execute(
        "SELECT * FROM authority_extraction_previews WHERE fuente_id = %s", (fuente_id,)
    )
    row = monitor.db.cursor.fetchone()
    require(row is not None, "preview row missing")
    return row


def quiet(monitor: PEPMonitor, state: dict) -> None:
    for key in ("ready", "incomplete", "partial", "stale"):
        fuente_id = state[key]
        for table in ("snapshots", "cambios", "revisiones_remocion_autoridades", "log_fuente_runs"):
            monitor.db.cursor.execute(f"SELECT count(*) AS n FROM {table} WHERE fuente_id = %s", (fuente_id,))
            require(monitor.db.cursor.fetchone()["n"] == 0, f"{key} wrote {table}")
        monitor.db.cursor.execute("SELECT ultimo_check FROM fuentes WHERE id = %s", (fuente_id,))
        require(monitor.db.cursor.fetchone()["ultimo_check"] is None, f"{key} set ultimo_check")
    for table in ("log_scripts", "gemini_usage_log"):
        monitor.db.cursor.execute(f"SELECT count(*) AS n FROM {table}")
        require(monitor.db.cursor.fetchone()["n"] == 0, f"preview wrote {table}")


def main(phase: str, state_path: Path) -> None:
    state = json.loads(state_path.read_text())
    monitor = PEPMonitor()
    try:
        if phase == "cycle":
            require(monitor.db.discover_preview_candidates() == [state["ready"], state["incomplete"], state["partial"]], "discovery mismatch")

            def offline(source: dict, *, preview: bool = False) -> tuple[str, str]:
                require(preview, "monitoring fetch attempted")
                return ({state["ready"]: READY, state["incomplete"]: DUPLICATE, state["partial"]: PARTIAL}[source["id"]], "offline_fixture")

            monitor._obtener_html_raw = offline
            monitor.check_all()
            ready = preview(monitor, state["ready"])
            require(ready["status"] == "ready" and len(ready["config_fingerprint"]) == 64, "ready result missing")
            require(ready["authorities_json"] == [{"cargo": "Rectora", "persona": "Ada Lovelace"}], "roster mismatch")
            incomplete = preview(monitor, state["incomplete"])
            require(incomplete["status"] == "incomplete" and incomplete["error_code"] == "duplicate_authority", "duplicate not rejected")
            require(incomplete["authorities_json"] is None, "duplicate roster persisted")
            partial = preview(monitor, state["partial"])
            require(partial["status"] == "incomplete" and partial["error_code"] == "field_scope_invalid" and partial["authorities_json"] is None, "partial roster persisted")
        elif phase == "claim":
            claim = monitor.db.claim_preview(state["stale"])
            require(claim is not None and claim["lease_token"], "claim failed")
            require(preview(monitor, state["stale"])["status"] == "running", "claim not persisted")
            # Token and captured identity live in this disposable source volume only.
            state_path.with_suffix(".claim.json").write_text(json.dumps(claim, default=str))
        elif phase == "old-complete":
            claim = json.loads(state_path.with_suffix(".claim.json").read_text())
            result = extract_structured_authorities(READY, claim["source"]["authority_extraction_config"])
            require(result.status == "ready", "invalid offline fixture")
            require(monitor.db.complete_preview(claim, result) is False, "old claim accepted")
            row = preview(monitor, state["stale"])
            require(row["status"] == "pending" and row["authorities_json"] is None, "old roster persisted")
            require(row["lease_token"] is None, "old claim retained lease")
        elif phase == "fresh":
            def offline(source: dict, *, preview: bool = False) -> tuple[str, str]:
                require(preview and source["id"] == state["stale"], "unexpected network/monitoring fetch")
                return FRESH, "offline_fixture"

            monitor._obtener_html_raw = offline
            monitor.check_all()
            row = preview(monitor, state["stale"])
            require(row["status"] == "ready" and len(row["config_fingerprint"]) == 64, "fresh claim not completed")
            require(row["authorities_json"] == [{"cargo": "Rectora", "persona": "Grace Hopper"}], "old roster retained")
        else:
            raise AssertionError("unknown phase")
        quiet(monitor, state)
        print(f"PASS {phase}")
    finally:
        monitor.http.close()
        monitor.db.cursor.close()
        monitor.db.connection.close()


if __name__ == "__main__":
    try:
        main(sys.argv[1], Path(sys.argv[2]))
    except Exception as exc:
        print(f"FAIL {sys.argv[1]}: {exc}", file=sys.stderr)
        raise
