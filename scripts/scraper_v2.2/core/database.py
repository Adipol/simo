"""
Gestor de base de datos v2.2.0
Soporte dual: MySQL y PostgreSQL.
Motor activo según DB_TYPE en .env (mysql | postgres).
"""

import os
from contextlib import contextmanager
from datetime import datetime
from typing import Generator, List, Optional, Set

from config.settings import settings
from utils.logger import get_logger

logger = get_logger(__name__)

# ── Motor de base de datos ────────────────────────────────────────────────────
_DB_TYPE = settings.db.db_type  # "mysql" o "postgres"

if _DB_TYPE == "postgres":
    import psycopg2
    import psycopg2.extras
    import psycopg2.pool as _pg_pool

    _DbError = psycopg2.Error
else:
    import mysql.connector
    from mysql.connector import Error as _DbError
    from mysql.connector.pooling import MySQLConnectionPool

# ── Constantes ───────────────────────────────────────────────────────────────

# Tamaño maximo del mensaje de error en la columna mensaje_error de log_scripts.
# Debe coincidir con el tamano definido en la migracion del schema.
_MAX_ERROR_MSG_LEN: int = 500

# ── Helpers de dialecto SQL ───────────────────────────────────────────────────


def _sql_insert_or_ignore(table: str, columns: str, values_placeholder: str) -> str:
    """
    Devuelve INSERT que ignora duplicados según el motor.

    Para resultados_scraping en PostgreSQL, usamos ON CONFLICT (url, categoria)
    DO NOTHING — explicito desde migracion 000004 que define el UNIQUE constraint
    (url, categoria). La forma explicita es mas robusta que ON CONFLICT DO NOTHING
    generico y documenta la intencion de dedup.
    """
    if _DB_TYPE == "postgres":
        if table == "resultados_scraping":
            return (
                f"INSERT INTO {table} ({columns}) VALUES ({values_placeholder}) "
                "ON CONFLICT (url, categoria) DO NOTHING"
            )
        return (
            f"INSERT INTO {table} ({columns}) VALUES ({values_placeholder}) "
            "ON CONFLICT DO NOTHING"
        )
    return f"INSERT IGNORE INTO {table} ({columns}) VALUES ({values_placeholder})"


def _sql_insert_returning_id(table: str, columns: str, values_placeholder: str) -> str:
    """INSERT que ignora duplicados y retorna el id del nuevo registro."""
    base = _sql_insert_or_ignore(table, columns, values_placeholder)
    if _DB_TYPE == "postgres":
        return base + " RETURNING id"
    return base


def _sql_date_filter(field: str) -> str:
    """Fragmento WHERE para 'field >= hace N días' (parámetro %s = días)."""
    if _DB_TYPE == "postgres":
        return f"{field} >= NOW() - %s * INTERVAL '1 day'"
    return f"{field} >= DATE_SUB(NOW(), INTERVAL %s DAY)"


# ── DatabaseManager ───────────────────────────────────────────────────────────


class DatabaseManager:
    """Gestor de conexiones con pooling (MySQL y PostgreSQL)."""

    _pool = None

    @classmethod
    def initialize_pool(cls) -> None:
        """Inicializa el pool de conexiones."""
        if cls._pool is not None:
            return

        try:
            if _DB_TYPE == "postgres":
                cls._pool = _pg_pool.ThreadedConnectionPool(
                    minconn=1,
                    maxconn=settings.db.pool_size,
                    host=settings.db.host,
                    port=settings.db.port,
                    user=settings.db.user,
                    password=settings.db.password,
                    dbname=settings.db.database,
                    connect_timeout=settings.db.connection_timeout,
                )
            else:
                cls._pool = MySQLConnectionPool(
                    pool_name=settings.db.pool_name,
                    pool_size=settings.db.pool_size,
                    pool_reset_session=True,
                    **settings.db.to_dict(),
                )
            logger.info(
                f"Pool de conexiones inicializado ({_DB_TYPE.upper()}): "
                f"{settings.db.pool_size} conexiones a "
                f"{settings.db.host}/{settings.db.database}"
            )
        except _DbError as e:
            logger.error(f"Error al crear pool de conexiones: {e}")
            raise

    @classmethod
    def verify_tables(cls) -> None:
        """
        Verifica que las tablas necesarias existen.
        Las tablas son creadas por Laravel migrations — este script no las crea.
        Lanza RuntimeError si alguna tabla falta.
        """
        tablas_requeridas = [
            "sitios_web",
            "familias_lemas",
            "resultados_scraping",
            "log_ejecuciones",
        ]
        try:
            with cls.get_cursor(dictionary=True) as cursor:
                if _DB_TYPE == "postgres":
                    cursor.execute("SELECT tablename FROM pg_tables WHERE schemaname = 'public'")
                    tablas_existentes = {row["tablename"] for row in cursor.fetchall()}
                else:
                    cursor.execute("SHOW TABLES")
                    tablas_existentes = {list(row.values())[0] for row in cursor.fetchall()}
                faltantes = [t for t in tablas_requeridas if t not in tablas_existentes]
                if faltantes:
                    raise RuntimeError(
                        f"Tablas no encontradas: {', '.join(faltantes)}. "
                        f"Ejecuta las migrations de Laravel primero: php artisan migrate"
                    )
            logger.info("Tablas verificadas correctamente")
        except RuntimeError:
            raise
        except Exception as e:
            logger.error(f"Error verificando tablas: {e}")
            raise

    @classmethod
    def health_check(cls) -> bool:
        """Verifica que la conexión a la BD funcione."""
        try:
            with cls.get_cursor() as cursor:
                cursor.execute("SELECT 1")
                cursor.fetchone()
                return True
        except _DbError as e:
            logger.error(f"Health check fallido: {e}")
            return False
        except Exception as e:
            logger.error(f"Error inesperado en health check: {e}")
            return False

    @classmethod
    @contextmanager
    def get_connection(cls) -> Generator:
        """Context manager para obtener una conexión del pool."""
        cls.initialize_pool()
        connection = None
        try:
            if _DB_TYPE == "postgres":
                connection = cls._pool.getconn()
            else:
                connection = cls._pool.get_connection()
            yield connection
        except _DbError as e:
            logger.error(f"Error de conexión: {e}")
            raise
        finally:
            if connection:
                if _DB_TYPE == "postgres":
                    cls._pool.putconn(connection)
                else:
                    if connection.is_connected():
                        connection.close()

    @classmethod
    @contextmanager
    def get_cursor(cls, dictionary: bool = False) -> Generator:
        """Context manager para obtener cursor con commit automático."""
        with cls.get_connection() as connection:
            if _DB_TYPE == "postgres":
                factory = psycopg2.extras.RealDictCursor if dictionary else None
                cursor = connection.cursor(cursor_factory=factory)
            else:
                cursor = connection.cursor(dictionary=dictionary)
            try:
                yield cursor
                connection.commit()
            except _DbError as e:
                connection.rollback()
                logger.error(f"Error en transacción, rollback realizado: {e}")
                raise
            finally:
                cursor.close()

    @classmethod
    def close_pool(cls) -> None:
        """Cierra todas las conexiones del pool."""
        if cls._pool:
            if _DB_TYPE == "postgres":
                cls._pool.closeall()
            cls._pool = None
            logger.info("Pool de conexiones cerrado")


# ── ScrapingRepository ────────────────────────────────────────────────────────


class ScrapingRepository:
    """Repositorio para operaciones de scraping."""

    @staticmethod
    def get_keywords(categoria: str = None) -> List[str]:
        """
        Obtiene las raíces de lemas activos desde familias_lemas.

        Los lemas son universales para todos los países hispanohablantes.
        """
        with DatabaseManager.get_cursor(dictionary=True) as cursor:
            conditions = ["fl.activo IS TRUE"]
            params = []

            if categoria:
                conditions.append("fl.categoria = %s")
                params.append(categoria)
                logger.info(f"Filtrando lemas para categoría: {categoria}")

            query = f"""
                SELECT DISTINCT fl.raiz
                FROM familias_lemas fl
                WHERE {" AND ".join(conditions)}
                ORDER BY fl.raiz
            """

            cursor.execute(query, params)
            rows = cursor.fetchall()
            keywords = [row["raiz"] for row in rows]

            logger.info(f"Lemas activos cargados: {len(keywords)}")
            if keywords:
                for i in range(0, len(keywords), 10):
                    grupo = keywords[i : i + 10]
                    logger.info(
                        f"  Keywords [{i + 1}-{i + len(grupo)}]: {', '.join(grupo)}"
                    )

            return keywords

    @staticmethod
    def get_categorias_activas() -> List[str]:
        """Obtiene las categorías que tienen lemas activos."""
        with DatabaseManager.get_cursor(dictionary=True) as cursor:
            cursor.execute("""
                SELECT DISTINCT categoria
                FROM familias_lemas
                WHERE activo IS TRUE AND categoria IS NOT NULL
                ORDER BY categoria
            """)
            return [row["categoria"] for row in cursor.fetchall()]

    @staticmethod
    def get_websites(pais: str = None) -> List[dict]:
        """Obtiene los sitios web activos desde la base de datos."""
        with DatabaseManager.get_cursor(dictionary=True) as cursor:
            if pais:
                cursor.execute(
                    """
                    SELECT id, url, nombre, selector_links, pais
                    FROM sitios_web
                    WHERE activo IS TRUE AND pais = %s
                    ORDER BY nombre
                """,
                    (pais,),
                )
                logger.info(f"Filtrando sitios para país: {pais}")
            else:
                cursor.execute("""
                    SELECT id, url, nombre, selector_links, pais
                    FROM sitios_web
                    WHERE activo IS TRUE
                    ORDER BY pais, nombre
                """)

            websites = cursor.fetchall()
            logger.info(f"Sitios web cargados: {len(websites)}")
            return websites

    @staticmethod
    def save_result(
        url: str,
        keyword: str,
        sitio_id: int,
        titulo: Optional[str] = None,
        contexto: Optional[str] = None,
        relevance_score: int = 0,
        found_in_title: bool = False,
        pais: str = "BO",
        categoria: str = None,
    ) -> Optional[int]:
        """
        Guarda un resultado de scraping.
        Retorna el ID del registro o None si ya existía.
        """
        _COLS = (
            "url, keyword, sitio_id, pais, categoria, titulo, contexto, "
            "fecha_encontrado, relevance_score, found_in_title"
        )
        _VALS = "%s, %s, %s, %s, %s, %s, %s, %s, %s, %s"
        sql = _sql_insert_returning_id("resultados_scraping", _COLS, _VALS)

        params = (
            url,
            keyword,
            sitio_id,
            pais,
            categoria,
            titulo,
            contexto,
            datetime.now(),
            int(relevance_score),
            found_in_title,
        )

        with DatabaseManager.get_cursor() as cursor:
            cursor.execute(sql, params)

            if _DB_TYPE == "postgres":
                row = cursor.fetchone()
                if row:
                    result_id = row[0]
                    relevance_text = (
                        "📌 EN TÍTULO"
                        if found_in_title
                        else f"(score: {relevance_score})"
                    )
                    cat_display = categoria or "?"
                    logger.info(
                        f"[{pais}/{cat_display}] Resultado guardado: "
                        f"'{keyword}' {relevance_text} en {url[:50]}..."
                    )
                    return result_id
                else:
                    logger.debug(
                        f"Resultado duplicado ignorado: '{keyword}' en {url[:50]}..."
                    )
                    return None
            else:
                if cursor.rowcount > 0:
                    result_id = cursor.lastrowid
                    relevance_text = (
                        "📌 EN TÍTULO"
                        if found_in_title
                        else f"(score: {relevance_score})"
                    )
                    cat_display = categoria or "?"
                    logger.info(
                        f"[{pais}/{cat_display}] Resultado guardado: "
                        f"'{keyword}' {relevance_text} en {url[:50]}..."
                    )
                    return result_id
                else:
                    logger.debug(
                        f"Resultado duplicado ignorado: '{keyword}' en {url[:50]}..."
                    )
                    return None

    @staticmethod
    def get_paises_activos() -> List[dict]:
        """Obtiene la lista de países activos."""
        with DatabaseManager.get_cursor(dictionary=True) as cursor:
            cursor.execute("""
                SELECT codigo, nombre
                FROM paises
                WHERE activo IS TRUE
                ORDER BY nombre
            """)
            return cursor.fetchall()

    @staticmethod
    def save_results_batch(results: List[dict]) -> int:
        """
        Guarda múltiples resultados en una sola transacción.
        Retorna la cantidad de registros insertados.
        """
        if not results:
            return 0

        _COLS = (
            "url, keyword, sitio_id, pais, categoria, titulo, contexto, "
            "fecha_encontrado, relevance_score, found_in_title"
        )
        _VALS = "%s, %s, %s, %s, %s, %s, %s, %s, %s, %s"
        sql = _sql_insert_or_ignore("resultados_scraping", _COLS, _VALS)

        data = [
            (
                r["url"],
                r["keyword"],
                r["sitio_id"],
                r.get("pais", "BO"),
                r.get("categoria"),
                r.get("titulo"),
                r.get("contexto"),
                datetime.now(),
                int(r.get("relevance_score", 0)),
                r.get("found_in_title", False),
            )
            for r in results
        ]

        with DatabaseManager.get_cursor() as cursor:
            if _DB_TYPE == "postgres":
                inserted = 0
                for row in data:
                    cursor.execute(sql, row)
                    inserted += max(cursor.rowcount, 0)
            else:
                cursor.executemany(sql, data)
                inserted = cursor.rowcount

            high_relevance = sum(1 for r in results if r.get("found_in_title", False))
            pais = results[0].get("pais", "BO") if results else "BO"
            cat = results[0].get("categoria", "?") if results else "?"
            logger.info(
                f"[{pais}/{cat}] Batch guardado: {inserted}/{len(results)} resultados "
                f"({high_relevance} de alta relevancia)"
            )
            return inserted

    @staticmethod
    def url_already_processed(url: str, keyword: str) -> bool:
        """
        Verifica si una URL ya fue procesada para una keyword.
        Tambien retorna True si la URL fue descartada (no reinsertar).
        """
        with DatabaseManager.get_cursor() as cursor:
            cursor.execute(
                """
                SELECT 1
                FROM resultados_scraping
                WHERE url = %s AND keyword = %s
                LIMIT 1
            """,
                (url, keyword),
            )
            return cursor.fetchone() is not None

    @staticmethod
    def url_descartada(url: str) -> bool:
        """Verifica si una URL fue descartada por el usuario (no reinsertar)."""
        with DatabaseManager.get_cursor() as cursor:
            cursor.execute(
                "SELECT 1 FROM resultados_scraping WHERE url = %s AND descartado IS TRUE LIMIT 1",
                (url,),
            )
            return cursor.fetchone() is not None

    @staticmethod
    def get_processed_urls(days: int = 30) -> Set[str]:
        """
        Obtiene URLs procesadas en los ultimos N dias.
        Incluye URLs descartadas (sin limite de dias) para no reinsertarlas.
        """
        date_filter = _sql_date_filter("fecha_encontrado")
        with DatabaseManager.get_cursor() as cursor:
            # URLs recientes (activas o no descartadas)
            cursor.execute(
                f"SELECT DISTINCT url FROM resultados_scraping WHERE {date_filter} AND (descartado IS FALSE OR descartado IS NULL)",
                (days,),
            )
            urls = {row[0] for row in cursor.fetchall()}

            # URLs descartadas (sin limite de tiempo — nunca reinsertar)
            cursor.execute(
                "SELECT DISTINCT url FROM resultados_scraping WHERE descartado IS TRUE"
            )
            descartadas = {row[0] for row in cursor.fetchall()}
            urls = urls | descartadas

            logger.debug(
                f"URLs procesadas (ultimos {days} dias): {len(urls) - len(descartadas)} | Descartadas: {len(descartadas)}"
            )
            return urls

    @staticmethod
    def get_processed_url_categoria_pairs(days: int = 30) -> Set[tuple]:
        """
        Obtiene pares (url, categoria) procesados en los ultimos N dias.

        Reemplaza get_processed_url_keyword_pairs() — la clave de dedup
        ahora es (url, categoria), alineada con el UNIQUE constraint de la
        migracion 000004 (add_unique_url_categoria_to_resultados_scraping).

        SELECT DISTINCT url, categoria colapsa multiples keywords del mismo
        (url, categoria) en una sola entrada, evitando intentos redundantes
        de insercion en el mismo ciclo de scraping.

        Filas legacy con categoria IS NULL aparecen como (url, None) — se
        incluyen para no reintentar scraping de esas URLs.
        """
        date_filter = _sql_date_filter("fecha_encontrado")
        with DatabaseManager.get_cursor() as cursor:
            cursor.execute(
                f"SELECT DISTINCT url, categoria FROM resultados_scraping WHERE {date_filter}",
                (days,),
            )
            pairs = {(row[0], row[1]) for row in cursor.fetchall()}
            logger.debug(f"Pares URL-categoria (ultimos {days} dias): {len(pairs)}")
            return pairs

    @staticmethod
    def log_scrape_execution(
        sitios_procesados: int,
        resultados_encontrados: int,
        errores: int,
        duracion_segundos: float,
    ) -> None:
        """Registra la ejecución del scraping."""
        with DatabaseManager.get_cursor() as cursor:
            cursor.execute(
                """
                INSERT INTO log_ejecuciones
                (fecha_ejecucion, sitios_procesados, resultados_encontrados,
                 errores, duracion_segundos)
                VALUES (%s, %s, %s, %s, %s)
            """,
                (
                    datetime.now(),
                    sitios_procesados,
                    resultados_encontrados,
                    errores,
                    duracion_segundos,
                ),
            )
            logger.info(
                f"Ejecución registrada: {sitios_procesados} sitios, "
                f"{resultados_encontrados} resultados, {errores} errores"
            )

    @staticmethod
    def get_results_summary(days: int = 7) -> List[dict]:
        """Obtiene resumen de resultados de los últimos N días."""
        date_filter = _sql_date_filter("fecha_encontrado")
        with DatabaseManager.get_cursor(dictionary=True) as cursor:
            cursor.execute(
                f"""
                SELECT
                    DATE(fecha_encontrado) as fecha,
                    COUNT(*) as total,
                    COUNT(DISTINCT keyword) as keywords_distintas,
                    COUNT(DISTINCT sitio_id) as sitios_distintos,
                    SUM(CASE WHEN found_in_title IS TRUE THEN 1 ELSE 0 END) as en_titulo
                FROM resultados_scraping
                WHERE {date_filter}
                GROUP BY DATE(fecha_encontrado)
                ORDER BY fecha DESC
            """,
                (days,),
            )
            return cursor.fetchall()

    @staticmethod
    def get_high_relevance_results(days: int = 7, limit: int = 50) -> List[dict]:
        """Obtiene resultados de alta relevancia (keyword en título)."""
        date_filter = _sql_date_filter("r.fecha_encontrado")
        with DatabaseManager.get_cursor(dictionary=True) as cursor:
            cursor.execute(
                f"""
                SELECT
                    r.id,
                    r.url,
                    r.keyword,
                    r.titulo,
                    r.contexto,
                    r.fecha_encontrado,
                    r.relevance_score,
                    s.nombre AS sitio_nombre
                FROM resultados_scraping r
                LEFT JOIN sitios_web s ON r.sitio_id = s.id
                WHERE r.found_in_title IS TRUE
                AND {date_filter}
                ORDER BY r.fecha_encontrado DESC
                LIMIT %s
            """,
                (days, limit),
            )
            return cursor.fetchall()

    @staticmethod
    def log_scraper_inicio() -> Optional[int]:
        """Registra inicio del scraper en log_scripts. Devuelve el ID del registro."""
        try:
            with DatabaseManager.get_cursor() as cursor:
                if _DB_TYPE == "postgres":
                    cursor.execute(
                        """INSERT INTO log_scripts (script, estado, inicio)
                           VALUES ('scraper', 'iniciado', NOW()) RETURNING id"""
                    )
                    row = cursor.fetchone()
                    return row[0] if row else None
                else:
                    cursor.execute(
                        """INSERT INTO log_scripts (script, estado, inicio)
                           VALUES ('scraper', 'iniciado', NOW())"""
                    )
                    return cursor.lastrowid
        except Exception as e:
            logger.warning(f"No se pudo registrar inicio en log_scripts: {e}")
            return None

    @staticmethod
    def log_scraper_fin(
        log_id: Optional[int],
        estado: str,
        items_procesados: int = 0,
        items_resultado: int = 0,
        errores: int = 0,
        mensaje_error: Optional[str] = None,
    ) -> None:
        """Actualiza el registro de log_scripts al finalizar."""
        if log_id is None:
            return
        try:
            with DatabaseManager.get_cursor() as cursor:
                duracion_sql = (
                    "EXTRACT(EPOCH FROM (NOW() - inicio))::integer"
                    if _DB_TYPE == "postgres"
                    else "TIMESTAMPDIFF(SECOND, inicio, NOW())"
                )
                cursor.execute(
                    f"""UPDATE log_scripts
                       SET estado = %s,
                           fin = NOW(),
                           duracion_segundos = {duracion_sql},
                           items_procesados = %s,
                           items_resultado = %s,
                           errores = %s,
                           mensaje_error = %s
                       WHERE id = %s""",
                    (
                        estado,
                        items_procesados,
                        items_resultado,
                        errores,
                        mensaje_error[:_MAX_ERROR_MSG_LEN] if mensaje_error else None,
                        log_id,
                    ),
                )
        except Exception as e:
            logger.warning(f"No se pudo actualizar log_scripts: {e}")
