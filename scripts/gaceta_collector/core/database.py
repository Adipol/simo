"""
PostgreSQL repository for the gaceta collector.

GacetaRepository wraps psycopg2 and provides:
- get_ultimo_gaceta_id(pais) — incremental cursor
- upsert_norma(norma_dict)   — idempotent INSERT ON CONFLICT DO NOTHING RETURNING id
- insert_eventos(...)         — bulk insert of extracted eventos
- update_estado_extraccion()  — set extraction state after processing

Connection is injected at construction time (dependency injection for testability).
All DB errors are allowed to propagate — the caller (main.py) handles them.
"""
from datetime import date
from typing import Optional


class GacetaRepository:
    """Repository layer for gaceta_normas and gaceta_eventos_pep tables."""

    def __init__(self, conn) -> None:
        """
        Args:
            conn: An open psycopg2 connection (autocommit or explicit).
        """
        self._conn = conn

    # ── public API ────────────────────────────────────────────────────────────

    def get_ultimo_gaceta_id(self, pais: str) -> Optional[int]:
        """
        Return MAX(gaceta_id_externo) for the given country, or None if no rows exist.
        Used as the incremental cursor.
        """
        with self._conn.cursor() as cur:
            cur.execute(
                "SELECT MAX(gaceta_id_externo) FROM gaceta_normas WHERE pais = %s",
                (pais,),
            )
            row = cur.fetchone()
        if row is None:
            return None
        return row[0]  # None when table is empty

    def upsert_norma(self, norma: dict) -> Optional[int]:
        """
        Insert a norma row idempotently.

        Uses INSERT … ON CONFLICT (pais, gaceta_id_externo) DO NOTHING RETURNING id.
        Returns the new row id, or None if the row already existed.
        """
        sql = """
            INSERT INTO gaceta_normas (
                pais,
                gaceta_id_externo,
                numero_decreto,
                tipo_norma,
                sumario,
                pdf_url,
                fecha_publicacion,
                edicion,
                estado_extraccion
            )
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
            ON CONFLICT (pais, gaceta_id_externo) DO NOTHING
            RETURNING id
        """
        params = (
            norma["pais"],
            norma["gaceta_id_externo"],
            norma.get("numero_decreto"),
            norma.get("tipo_norma"),
            norma.get("sumario"),
            norma.get("pdf_url"),
            norma.get("fecha_publicacion"),
            norma.get("edicion"),
            norma.get("estado_extraccion", "pendiente"),
        )
        with self._conn.cursor() as cur:
            cur.execute(sql, params)
            row = cur.fetchone()
        return row[0] if row else None

    def insert_eventos(self, norma_id: int, pais: str, eventos: list) -> int:
        """
        Insert extracted appointment events for a norma.

        pais is denormalized from the parent norma — NOT re-derived.
        Returns the count of rows actually inserted.
        """
        if not eventos:
            return 0

        sql = """
            INSERT INTO gaceta_eventos_pep (
                gaceta_norma_id,
                pais,
                persona_nombre,
                persona_nombre_normalizado,
                cargo,
                cargo_categoria,
                entidad,
                tipo_evento,
                interino,
                estado_revision
            )
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        """
        inserted = 0
        with self._conn.cursor() as cur:
            for ev in eventos:
                cur.execute(
                    sql,
                    (
                        norma_id,
                        pais,
                        ev["persona_nombre"],
                        ev["persona_nombre_normalizado"],
                        ev["cargo"],
                        ev.get("cargo_categoria"),
                        ev.get("entidad"),
                        ev.get("tipo_evento", "designacion"),
                        ev.get("interino", False),
                        ev.get("estado_revision", "pendiente"),
                    ),
                )
                inserted += 1
        return inserted

    def update_estado_extraccion(self, norma_id: int, estado: str) -> None:
        """Update the estado_extraccion column on an existing norma row."""
        with self._conn.cursor() as cur:
            cur.execute(
                "UPDATE gaceta_normas SET estado_extraccion = %s WHERE id = %s",
                (estado, norma_id),
            )
