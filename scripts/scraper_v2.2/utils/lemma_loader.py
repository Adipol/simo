"""
Lemma family loader — loads word families from DB with 3-level graceful degradation.

Level 1: Load active families from `familias_lemas` table in DB.
Level 2: If DB fails, use FALLBACK_FAMILIES hardcoded dict.
Level 3: If fallback is empty, original keywords remain unchanged.
"""
import json
from typing import Dict, Set

from utils.logger import get_logger

logger = get_logger(__name__)

# ── Emergency safety net (minimal, 7 most critical families) ─────────────────

FALLBACK_FAMILIES: Dict[str, Set[str]] = {
    "designar": {"designar", "designación", "designaciones", "designado", "designada", "designó"},
    "nombrar": {"nombrar", "nombramiento", "nombrado"},
    "renunciar": {"renunciar", "renuncia", "renuncias", "renunciado", "renunció"},
    "destituir": {"destituir", "destitución", "destituido"},
    "detener": {"detener", "detención", "detenciones", "detenido", "detenida"},
    "investigar": {"investigar", "investigación", "investigaciones", "investigado"},
    "imputar": {"imputar", "imputación", "imputaciones", "imputado", "imputada"},
}

# ── Module-level import with lazy fallback ────────────────────────────────────

try:
    from core.database import DatabaseManager
except Exception:
    DatabaseManager = None  # type: ignore[assignment,misc]


def load_families_from_db() -> Dict[str, Set[str]]:
    """
    Load active lemma families from the `familias_lemas` DB table.

    Returns:
        Dict mapping raiz (str) → set of variant strings.
        Falls back to FALLBACK_FAMILIES if DB is unavailable or empty.
    """
    try:
        if DatabaseManager is None:
            raise RuntimeError("DatabaseManager not available")

        with DatabaseManager.get_cursor(dictionary=True) as cursor:
            cursor.execute(
                "SELECT raiz, variantes FROM familias_lemas WHERE activo IS TRUE"
            )
            rows = cursor.fetchall()

        if not rows:
            logger.warning(
                "No active families found in DB (familias_lemas). Using fallback families."
            )
            return FALLBACK_FAMILIES

        families: Dict[str, Set[str]] = {}
        for row in rows:
            raiz = row["raiz"].lower()
            raw = row["variantes"]
            variantes = json.loads(raw) if isinstance(raw, str) else raw
            families[raiz] = {str(v).lower() for v in variantes}

        logger.info(f"Loaded {len(families)} lemma families from DB")
        return families

    except Exception as e:
        logger.warning(
            f"Failed to load families from DB: {e}. Using fallback families."
        )
        return FALLBACK_FAMILIES
