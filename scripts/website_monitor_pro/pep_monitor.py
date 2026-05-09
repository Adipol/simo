#!/usr/bin/env python3
"""
PEP Monitor - Monitor de cambios en paginas de directorios institucionales.

Filosofia: el sistema NO decide que es relevante.
Detecta cualquier cambio en el texto visible de la pagina, muestra
exactamente que texto entro y que texto salio (diff), y lo guarda.
El usuario decide si el cambio es un nuevo funcionario o no.

Soporta paginas HTML estatico, JS dinamico (Playwright) y PDFs.
Tiene reconexion automatica con backoff cuando no hay internet.
"""

import os
import sys
import csv
import time
import hashlib
import json
import logging
import argparse
import re
import socket
import difflib
import unicodedata
from typing import Optional
from datetime import datetime
from dataclasses import dataclass, field
from logging.handlers import RotatingFileHandler

import urllib3
import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry
from urllib.parse import urlparse

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
import psycopg2
import psycopg2.extras
from pathlib import Path
from dotenv import load_dotenv

# Cargar .env de Laravel (raíz del proyecto), NO un .env del subdirectorio.
# pep_monitor.py vive en scripts/website_monitor_pro/, el .env real está 2 niveles arriba.
# find_dotenv() falla porque encuentra primero un .env vacío en el subdirectorio.
_LARAVEL_ROOT = Path(__file__).resolve().parent.parent.parent
_DOTENV_PATH = _LARAVEL_ROOT / ".env"
load_dotenv(dotenv_path=_DOTENV_PATH if _DOTENV_PATH.is_file() else None)


# ════════════════════════════════════════════════════════════════
# CONFIGURACION
# ════════════════════════════════════════════════════════════════
@dataclass
class Config:
    """Configuracion centralizada."""

    # Base de datos
    DB_HOST: str = os.getenv("DB_HOST", "localhost")
    DB_PORT: int = int(os.getenv("DB_PORT", "5432"))
    DB_USER: str = os.getenv("DB_USER", "postgres")
    DB_PASSWORD: str = os.getenv("DB_PASSWORD", "")
    DB_NAME: str = os.getenv("DB_NAME", "simo")

    # Monitor
    CHECK_INTERVAL: int = int(os.getenv("CHECK_INTERVAL", "3600"))
    REQUEST_TIMEOUT: int = int(os.getenv("REQUEST_TIMEOUT", "20"))
    MAX_RETRIES: int = int(os.getenv("MAX_RETRIES", "3"))

    # Reconexion backoff sin internet: 30 → 60 → 120 → 300s
    RECONNECT_DELAYS: list = field(default_factory=lambda: [30, 60, 120, 300])

    # Archivos de salida
    EXPORT_DIR: str = os.getenv("EXPORT_DIR", "exports")
    ALERT_LOG: str = os.getenv("ALERT_LOG", "alertas_cambios.log")

    # Logging
    LOG_LEVEL: str = os.getenv("LOG_LEVEL", "INFO")
    LOG_FILE: str = os.getenv("LOG_FILE", "pep_monitor.log")
    LOG_MAX_SIZE: int = int(os.getenv("LOG_MAX_SIZE", "10485760"))
    LOG_BACKUP_COUNT: int = int(os.getenv("LOG_BACKUP_COUNT", "5"))


config = Config()


# ════════════════════════════════════════════════════════════════
# LOGGING
# ════════════════════════════════════════════════════════════════
def setup_logging() -> logging.Logger:
    """Logger con salida a consola y archivo rotativo."""
    logger = logging.getLogger("PEPMonitor")
    logger.setLevel(getattr(logging, config.LOG_LEVEL.upper()))

    fmt = logging.Formatter(
        "[%(asctime)s] %(levelname)s - %(message)s", datefmt="%Y-%m-%d %H:%M:%S"
    )

    ch = logging.StreamHandler(sys.stdout)
    ch.setFormatter(fmt)
    logger.addHandler(ch)

    fh = RotatingFileHandler(
        config.LOG_FILE,
        maxBytes=config.LOG_MAX_SIZE,
        backupCount=config.LOG_BACKUP_COUNT,
        encoding="utf-8",
    )
    fh.setFormatter(fmt)
    logger.addHandler(fh)

    return logger


logger = setup_logging()


# ════════════════════════════════════════════════════════════════
# CONECTIVIDAD
# ════════════════════════════════════════════════════════════════
def hay_internet(host: str = "https://www.google.com", timeout: int = 5) -> bool:
    """Prueba conexión HTTP para verificar conectividad."""
    try:
        response = requests.get(host, timeout=timeout)
        return response.status_code == 200
    except Exception:
        return False


def esperar_internet() -> None:
    """
    Bloquea hasta recuperar internet.
    Backoff: 30s → 60s → 120s → 300s (se repite en 300s indefinido).
    """
    delays = config.RECONNECT_DELAYS
    intento = 0
    while not hay_internet():
        delay = delays[min(intento, len(delays) - 1)]
        logger.warning(f"Sin internet. Reintento {intento + 1} en {delay}s...")
        time.sleep(delay)
        intento += 1
    if intento > 0:
        logger.info("Conectividad restaurada. Reanudando monitoreo.")


# ════════════════════════════════════════════════════════════════
# BASE DE DATOS
# ════════════════════════════════════════════════════════════════
class DatabaseManager:
    """PostgreSQL con reconexion automatica y esquema orientado a seguimiento de cambios."""

    def __init__(self):
        self.connection = None
        self.cursor = None
        self._connect()
        self._verify_tables()

    def _connect(self) -> None:
        try:
            self.connection = psycopg2.connect(
                host=config.DB_HOST,
                port=config.DB_PORT,
                user=config.DB_USER,
                password=config.DB_PASSWORD,
                dbname=config.DB_NAME,
                connect_timeout=10,
            )
            self.connection.autocommit = True
            self.cursor = self.connection.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
            logger.info("Conexion a BD establecida (PostgreSQL)")
        except psycopg2.Error as e:
            logger.error(f"Error conectando a BD: {e}")
            raise

    def _ensure_connection(self) -> None:
        try:
            if self.connection is None or self.connection.closed:
                logger.warning("Conexion a BD perdida, reconectando...")
                self._connect()
        except psycopg2.Error:
            self._connect()

    def _verify_tables(self) -> None:
        """Verifica que las tablas necesarias existen (creadas por Laravel migrations)."""
        self._ensure_connection()
        tablas_requeridas = ["fuentes", "snapshots", "cambios"]
        faltantes = []

        self.cursor.execute("""
            SELECT table_name FROM information_schema.tables 
            WHERE table_schema = 'public'
        """)
        tablas_existentes = {row["table_name"] for row in self.cursor.fetchall()}

        for tabla in tablas_requeridas:
            if tabla not in tablas_existentes:
                faltantes.append(tabla)

        if faltantes:
            raise RuntimeError(
                f"Tablas no encontradas: {', '.join(faltantes)}. "
                f"Ejecuta las migrations de Laravel primero: php artisan migrate"
            )

        logger.info("Tablas verificadas correctamente")

    # ── Fuentes ──────────────────────────────────────────────────
    def add_fuente(
        self,
        url: str,
        nombre: str,
        pais: str,
        organismo: str,
        nivel: str = "nacional",
        tipo: str = "html",
        selector_css: Optional[str] = None,
    ) -> Optional[int]:
        self._ensure_connection()
        try:
            self.cursor.execute(
                """INSERT INTO fuentes (url, nombre, pais, organismo, nivel, tipo, selector_css)
                   VALUES (%s, %s, %s, %s, %s, %s, %s)
                   ON CONFLICT (url) DO NOTHING
                   RETURNING id""",
                (url, nombre, pais, organismo, nivel, tipo, selector_css),
            )
            row = self.cursor.fetchone()
            if row:
                fid = row["id"]
                logger.info(f"Fuente registrada [{fid}]: {nombre} - {url}")
                return fid
            else:
                logger.warning(f"URL ya registrada: {url}")
                return None
        except psycopg2.Error as e:
            logger.error(f"Error registrando fuente: {e}")
            return None

    def remove_fuente(self, url: str) -> bool:
        self._ensure_connection()
        self.cursor.execute("UPDATE fuentes SET activo = FALSE WHERE url = %s", (url,))
        ok = self.cursor.rowcount > 0
        if ok:
            logger.info(f"Fuente desactivada: {url}")
        return ok

    def get_fuentes_activas(self) -> list[dict]:
        self._ensure_connection()
        self.cursor.execute(
            """SELECT id, url, nombre, pais, organismo, nivel, tipo,
                      selector_css, ultimo_check, analizar_imagenes
               FROM fuentes WHERE activo = TRUE
               ORDER BY pais, organismo"""
        )
        return self.cursor.fetchall()

    def list_fuentes(self) -> list[dict]:
        self._ensure_connection()
        self.cursor.execute(
            """SELECT f.id, f.url, f.nombre, f.pais, f.organismo,
                      f.nivel, f.tipo, f.activo, f.ultimo_check,
                      0 as total_cambios
               FROM fuentes f
               ORDER BY f.pais, f.organismo"""
        )
        return self.cursor.fetchall()

    def update_ultimo_check(self, fuente_id: int) -> None:
        self._ensure_connection()
        self.cursor.execute(
            "UPDATE fuentes SET ultimo_check = NOW() WHERE id = %s", (fuente_id,)
        )

    # ── Snapshots ────────────────────────────────────────────────
    def get_ultimo_snapshot(self, fuente_id: int) -> Optional[dict]:
        """Obtiene el snapshot mas reciente de una fuente."""
        self._ensure_connection()
        self.cursor.execute(
            """SELECT hash, texto, fecha FROM snapshots
               WHERE fuente_id = %s ORDER BY fecha DESC LIMIT 1""",
            (fuente_id,),
        )
        return self.cursor.fetchone()

    def guardar_snapshot(
        self, fuente_id: int, hash_: str, texto: str, metodo: str
    ) -> None:
        self._ensure_connection()
        self.cursor.execute(
            """INSERT INTO snapshots (fuente_id, hash, texto, metodo)
               VALUES (%s, %s, %s, %s)""",
            (fuente_id, hash_, texto, metodo),
        )

    # ── Cambios ───────────────────────────────────────────────────
    def guardar_cambio(
        self,
        fuente_id: int,
        hash_anterior: str,
        hash_nuevo: str,
        lineas_quitadas: int,
        lineas_nuevas: int,
        diff_texto: str,
        posibles_peps: str,
        *,
        imagenes: Optional[list[dict]] = None,
    ) -> Optional[int]:
        """
        Inserta un cambio detectado en la tabla cambios.

        Args:
            imagenes: si se provee y no está vacía, se serializa como JSON
                      en la columna imagenes_cambio_json. Si None o [] el
                      campo queda NULL (se puede actualizar después via UPDATE).

        Returns:
            ID del cambio insertado, o None si falla.
        """
        self._ensure_connection()
        imagenes_json = json.dumps(imagenes) if imagenes else None
        self.cursor.execute(
            """INSERT INTO cambios
               (fuente_id, hash_anterior, hash_nuevo, lineas_quitadas,
                lineas_nuevas, diff_texto, posibles_peps, imagenes_cambio_json)
               VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
               RETURNING id""",
            (
                fuente_id,
                hash_anterior,
                hash_nuevo,
                lineas_quitadas,
                lineas_nuevas,
                diff_texto,
                posibles_peps,
                imagenes_json,
            ),
        )
        row = self.cursor.fetchone()
        return row["id"] if row else None

    def actualizar_imagenes_cambio(
        self, cambio_id: int, imagenes: list[dict]
    ) -> None:
        """Actualiza imagenes_cambio_json de un cambio ya insertado."""
        self._ensure_connection()
        self.cursor.execute(
            "UPDATE cambios SET imagenes_cambio_json = %s WHERE id = %s",
            (json.dumps(imagenes), cambio_id),
        )

    def get_historial(self, fuente_id: int, limite: int = 20) -> list[dict]:
        self._ensure_connection()
        self.cursor.execute(
            """SELECT c.id, c.fecha, c.lineas_quitadas, c.lineas_nuevas,
                      c.posibles_peps, c.diff_texto
               FROM cambios c
               WHERE c.fuente_id = %s
               ORDER BY c.fecha DESC LIMIT %s""",
            (fuente_id, limite),
        )
        return self.cursor.fetchall()

    def get_cambio_detalle(self, cambio_id: int) -> Optional[dict]:
        self._ensure_connection()
        self.cursor.execute(
            """SELECT c.*, f.nombre, f.organismo, f.pais, f.url
               FROM cambios c
               JOIN fuentes f ON f.id = c.fuente_id
               WHERE c.id = %s""",
            (cambio_id,),
        )
        return self.cursor.fetchone()

    def get_stats(self) -> dict:
        self._ensure_connection()
        self.cursor.execute("SELECT COUNT(*) as total FROM fuentes WHERE activo = TRUE")
        total_fuentes = self.cursor.fetchone()["total"]

        self.cursor.execute(
            """SELECT COUNT(*) as total FROM cambios
               WHERE fecha >= NOW() - INTERVAL '30 days'"""
        )
        cambios_mes = self.cursor.fetchone()["total"]

        self.cursor.execute(
            """SELECT COUNT(*) as total FROM cambios
               WHERE fecha >= NOW() - INTERVAL '7 days'"""
        )
        cambios_semana = self.cursor.fetchone()["total"]

        self.cursor.execute(
            """SELECT f.nombre, f.organismo, f.pais,
                      MAX(c.fecha) as ultimo_cambio,
                      COUNT(c.id) as total_cambios
               FROM fuentes f
               LEFT JOIN cambios c ON c.fuente_id = f.id
               GROUP BY f.id
               ORDER BY ultimo_cambio DESC"""
        )
        por_fuente = self.cursor.fetchall()

        return {
            "total_fuentes": total_fuentes,
            "cambios_semana": cambios_semana,
            "cambios_mes": cambios_mes,
            "por_fuente": por_fuente,
        }

    def close(self) -> None:
        if self.cursor:
            self.cursor.close()
        if self.connection and not self.connection.closed:
            self.connection.close()
            logger.info("Conexion a BD cerrada")

    # ── Log de ejecuciones ───────────────────────────────────────
    def log_inicio(self) -> Optional[int]:
        """Registra inicio de ejecucion en log_scripts. Devuelve el ID del registro."""
        self._ensure_connection()
        try:
            self.cursor.execute(
                """INSERT INTO log_scripts (script, estado, inicio)
                   VALUES ('pep_monitor', 'iniciado', NOW())
                   RETURNING id"""
            )
            row = self.cursor.fetchone()
            return row["id"] if row else None
        except psycopg2.Error as e:
            logger.warning(f"No se pudo registrar inicio en log_scripts: {e}")
            return None

    def log_fin(
        self,
        log_id: int,
        estado: str,
        items_procesados: int = 0,
        items_resultado: int = 0,
        errores: int = 0,
        mensaje_error: Optional[str] = None,
    ) -> None:
        """Actualiza el registro de log_scripts al finalizar."""
        if log_id is None:
            return
        self._ensure_connection()
        try:
            self.cursor.execute(
                """UPDATE log_scripts
                   SET estado = %s,
                       fin = NOW(),
                       duracion_segundos = EXTRACT(EPOCH FROM (NOW() - inicio)),
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
                    mensaje_error[:500] if mensaje_error else None,
                    log_id,
                ),
            )
        except psycopg2.Error as e:
            logger.warning(f"No se pudo actualizar log_scripts: {e}")


# ════════════════════════════════════════════════════════════════
# LIMPIEZA DE CONTENIDO
# ════════════════════════════════════════════════════════════════
# Patrones de texto dinamico que generan falsos positivos
# (fechas, contadores, timestamps, tokens CSRF, etc.)
_PATRONES_RUIDO = [
    re.compile(r"\b\d{1,2}[/-]\d{1,2}[/-]\d{2,4}\b"),  # fechas dd/mm/yyyy
    re.compile(r"\b\d{4}[/-]\d{1,2}[/-]\d{1,2}\b"),  # fechas yyyy-mm-dd
    re.compile(r"\b\d{1,2}:\d{2}(:\d{2})?\s*(am|pm)?\b", re.I),  # horas
    re.compile(
        r"\b(lunes|martes|miercoles|jueves|viernes|"
        r"sabado|domingo|monday|tuesday|wednesday|"
        r"thursday|friday|saturday|sunday)\b",
        re.I,
    ),
    re.compile(
        r"\b(enero|febrero|marzo|abril|mayo|junio|julio|"
        r"agosto|septiembre|octubre|noviembre|diciembre|"
        r"january|february|march|april|june|july|august|"
        r"september|october|november|december)\b",
        re.I,
    ),
    re.compile(r"[a-f0-9]{32,}"),  # hashes/tokens
    re.compile(r"\bvisitas?:?\s*\d+\b", re.I),  # contadores visitas
    re.compile(r"\bcopyright\s*©?\s*\d{4}\b", re.I),
]


def limpiar_html(
    html: str, selector_css: Optional[str] = None
) -> tuple[list[str], str]:
    """
    Extrae el texto visible de una pagina HTML.
    Elimina scripts, estilos, menus, footer, fechas dinamicas.

    Retorna (lista_de_lineas_limpias, metodo_usado).
    Cada linea representa un elemento de texto unico y no vacio.
    """
    try:
        from bs4 import BeautifulSoup
    except ImportError:
        logger.error("Instala beautifulsoup4: pip install beautifulsoup4 lxml")
        return [], "error_dependencia"

    soup = BeautifulSoup(html, "lxml")

    # Eliminar todo lo que no es contenido relevante
    for tag in soup(
        [
            "script",
            "style",
            "noscript",
            "nav",
            "footer",
            "header",
            "iframe",
            "svg",
            "meta",
            "link",
            "aside",
            "form",
        ]
    ):
        tag.decompose()

    # Si el usuario especifico un selector CSS, usar solo esa area
    if selector_css:
        area = soup.select(selector_css)
        if area:
            # Reemplazar soup por solo el area de interes
            from bs4 import BeautifulSoup as BS

            contenido = " ".join(str(el) for el in area)
            soup = BS(contenido, "lxml")

    # Extraer todas las cadenas de texto visibles
    textos_raw = []
    for texto in soup.stripped_strings:
        linea = " ".join(texto.split()).strip()
        if len(linea) < 2:
            continue
        # Filtrar ruido dinamico
        es_ruido = False
        for patron in _PATRONES_RUIDO:
            if patron.search(linea):
                es_ruido = True
                break
        if not es_ruido:
            textos_raw.append(linea)

    # Deduplicar manteniendo orden (menus repetidos, etc.)
    vistos = set()
    lineas = []
    for t in textos_raw:
        if t.lower() not in vistos:
            vistos.add(t.lower())
            lineas.append(t)

    metodo = f"html_selector({selector_css})" if selector_css else "html_estatico"
    return lineas, metodo


def limpiar_pdf(url: str, session: requests.Session) -> tuple[list[str], str]:
    """Descarga un PDF y extrae sus lineas de texto visibles."""
    try:
        import pdfplumber
        import io
    except ImportError:
        logger.error("Instala pdfplumber: pip install pdfplumber")
        return [], "error_dependencia"

    try:
        resp = session.get(url, timeout=30, verify=verify_para_url(url))
        resp.raise_for_status()
        lineas = []
        with pdfplumber.open(io.BytesIO(resp.content)) as pdf:
            for pagina in pdf.pages:
                texto = pagina.extract_text() or ""
                for linea in texto.split("\n"):
                    linea = " ".join(linea.split()).strip()
                    if len(linea) >= 2:
                        lineas.append(linea)
        return lineas, "pdf"
    except Exception as e:
        logger.error(f"Error extrayendo PDF {url}: {e}")
        return [], "error_pdf"


def obtener_html_js(url: str) -> tuple[str, str]:
    """Renderiza una pagina con Playwright y retorna el HTML completo."""
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        logger.error(
            "Instala playwright: pip install playwright && playwright install chromium"
        )
        return "", "error_dependencia"

    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            page = browser.new_page()
            page.set_extra_http_headers(
                {"User-Agent": "Mozilla/5.0 (compatible; PEPMonitor/2.0)"}
            )
            page.goto(url, wait_until="domcontentloaded", timeout=45000)
            page.wait_for_timeout(4000)
            html = page.content()
            browser.close()
        return html, "js_playwright"
    except Exception as e:
        logger.error(f"Error Playwright en {url}: {e}")
        return "", "error_playwright"


# ════════════════════════════════════════════════════════════════
# HEURISTICA: DETECCION DE POSIBLES NOMBRES
# ════════════════════════════════════════════════════════════════
_PALABRAS_CARGO = {
    "director",
    "gerente",
    "ministro",
    "presidente",
    "secretario",
    "viceministro",
    "vicepresidente",
    "jefe",
    "coordinador",
    "titular",
    "sindico",
    "tecnico",
    "auxiliar",
    "analista",
    "unidad",
    "area",
    "departamento",
    "division",
    "nacional",
    "regional",
    "general",
    "ejecutivo",
    "juridico",
    "administrativo",
    "financiero",
    "planificacion",
    "subgerente",
    "subministro",
    "vocal",
    "consejero",
    "asesor",
    "auditor",
    "contralor",
    "fiscal",
    "procurador",
    "defensor",
    "superintendente",
    "intendente",
    "comisario",
    "comandante",
}


# Etiquetas comunes de UI / navegacion que NUNCA forman parte de un nombre.
# Si alguna palabra de la linea aparece aca, se descarta como posible nombre.
# Sin acentos: la comparacion se hace tras normalizar con _normalizar_palabra.
_PALABRAS_UI_NO_PEP = {
    # Verbos de UI
    "ver", "volver", "cerrar", "abrir", "descargar", "imprimir",
    "buscar", "compartir", "enviar", "guardar", "editar",
    "eliminar", "cancelar", "aceptar", "confirmar", "leer",
    "ingresar", "salir", "click", "clic", "pulsar",
    # Sustantivos / secciones de UI
    "comunicado", "comunicados", "memoria", "memorias",
    "transmision", "transmisiones", "documento", "documentos",
    "archivo", "archivos", "informacion", "detalle", "detalles",
    "resumen", "categoria", "categorias", "seccion", "secciones",
    "pagina", "paginas", "inicio", "portal", "sitio",
    "contacto", "contactos", "servicio", "servicios",
    "producto", "productos", "evento", "eventos",
    "noticia", "noticias", "formulario", "formularios",
    "requisito", "requisitos", "proceso", "procesos",
    "manual", "manuales", "guia", "guias",
    "ayuda", "soporte", "login", "logout", "usuario",
    "contrasena", "password", "menu", "principal",
    # Encabezados de seccion institucional
    "nomina", "nominas", "autoridad", "autoridades",
    "funcionario", "funcionarios", "puesto", "puestos",
    "cargo", "cargos", "denominacion", "nomenclatura",
    "directorio", "staff", "personal", "equipo",
    "organigrama", "plantilla", "plantel",
    # Dias (rara vez son nombres en espanol)
    "lunes", "martes", "miercoles", "jueves",
    "viernes", "sabado", "domingo",
    # Navegacion
    "anterior", "siguiente", "atras", "adelante",
    "mas", "aqui", "aca", "alla",
    "todos", "todas", "ninguno", "ninguna",
}


def _normalizar_palabra(palabra: str) -> str:
    """Quita acentos y pasa a minusculas para comparacion robusta."""
    palabra = palabra.lower()
    palabra = unicodedata.normalize("NFKD", palabra)
    return "".join(c for c in palabra if not unicodedata.combining(c))


def parece_nombre_persona(linea: str) -> bool:
    """
    Heuristica simple: detecta si una linea podria ser un nombre de persona.
    NO es definitivo — solo es una pista para el usuario.

    Criterios:
    - Entre 2 y 6 palabras
    - Al menos 2 palabras de 3+ letras
    - Menos del 40% de palabras son terminos de cargo conocidos
    - Ninguna palabra coincide con etiquetas de UI conocidas
    - No tiene numeros
    - No tiene caracteres especiales como @, /, :, =
    """
    if not linea or len(linea) < 5:
        return False
    if any(c in linea for c in ["@", "/", ":", "=", "(", ")", "[", "]", "."]):
        return False
    if re.search(r"\d", linea):
        return False

    palabras = linea.lower().split()
    if not (2 <= len(palabras) <= 6):
        return False

    palabras_largas = [p for p in palabras if len(p) >= 3]
    if len(palabras_largas) < 2:
        return False

    matches_cargo = sum(1 for p in palabras if p in _PALABRAS_CARGO)
    if len(palabras) > 0 and matches_cargo / len(palabras) > 0.4:
        return False

    palabras_norm = [_normalizar_palabra(p) for p in palabras]
    if any(p in _PALABRAS_UI_NO_PEP for p in palabras_norm):
        return False

    return True


# ════════════════════════════════════════════════════════════════
# MOTOR DE DIFF
# ════════════════════════════════════════════════════════════════
def calcular_diff(lineas_anterior: list[str], lineas_nuevo: list[str]) -> dict:
    """
    Compara dos listas de lineas de texto y retorna el diff estructurado.

    Retorna dict con:
      - quitadas:      lineas que desaparecieron
      - nuevas:        lineas que aparecieron
      - diff_texto:    string completo con formato - / +
      - posibles_peps: lineas nuevas o quitadas que parecen nombres
    """
    quitadas = []
    nuevas = []

    # difflib.ndiff compara linea a linea
    diff = list(difflib.ndiff(lineas_anterior, lineas_nuevo))

    for linea in diff:
        if linea.startswith("- "):
            quitadas.append(linea[2:].strip())
        elif linea.startswith("+ "):
            nuevas.append(linea[2:].strip())

    # Construir texto del diff para guardar y mostrar
    diff_texto_partes = []
    if quitadas:
        diff_texto_partes.append("=== ELIMINADO ===")
        diff_texto_partes.extend(f"- {l}" for l in quitadas)
    if nuevas:
        diff_texto_partes.append("=== NUEVO ===")
        diff_texto_partes.extend(f"+ {l}" for l in nuevas)
    diff_texto = "\n".join(diff_texto_partes)

    # Heuristica: marcar lineas que parecen nombres de persona
    candidatos_pep = []
    for linea in quitadas:
        if parece_nombre_persona(linea):
            candidatos_pep.append(f"[SALIO?] {linea}")
    for linea in nuevas:
        if parece_nombre_persona(linea):
            candidatos_pep.append(f"[ENTRO?] {linea}")

    return {
        "quitadas": quitadas,
        "nuevas": nuevas,
        "diff_texto": diff_texto,
        "posibles_peps": "\n".join(candidatos_pep),
    }


# ════════════════════════════════════════════════════════════════
# ALERTA EN CONSOLA Y ARCHIVO
# ════════════════════════════════════════════════════════════════
def mostrar_alerta(fuente: dict, diff: dict, cambio_id: int) -> None:
    """
    Muestra el diff en consola con formato claro
    y lo guarda en alertas_cambios.log.
    """
    nombre = fuente.get("nombre") or fuente.get("organismo") or fuente["url"]
    sep = "=" * 65
    fecha_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    lineas_alerta = [
        "",
        sep,
        f"  CAMBIO DETECTADO — {fecha_str}",
        f"  Fuente   : {nombre}",
        f"  Pais     : {fuente.get('pais', '-')}",
        f"  Organismo: {fuente.get('organismo', '-')}",
        f"  URL      : {fuente['url']}",
        f"  ID cambio: #{cambio_id}",
        sep,
    ]

    if diff["quitadas"]:
        lineas_alerta.append(f"\n  TEXTO ELIMINADO ({len(diff['quitadas'])} lineas):")
        for linea in diff["quitadas"]:
            lineas_alerta.append(f"    - {linea}")

    if diff["nuevas"]:
        lineas_alerta.append(f"\n  TEXTO NUEVO ({len(diff['nuevas'])} lineas):")
        for linea in diff["nuevas"]:
            lineas_alerta.append(f"    + {linea}")

    if diff["posibles_peps"]:
        lineas_alerta.append(
            "\n  POSIBLES CAMBIOS DE PERSONAL (heuristica, verificar):"
        )
        for linea in diff["posibles_peps"].split("\n"):
            lineas_alerta.append(f"    >> {linea}")

    lineas_alerta.append(sep)
    texto_alerta = "\n".join(lineas_alerta)

    # Imprimir en consola
    print(texto_alerta)

    # Guardar en alertas_cambios.log
    try:
        with open(config.ALERT_LOG, "a", encoding="utf-8") as f:
            f.write(texto_alerta + "\n")
    except Exception as e:
        logger.error(f"Error escribiendo alerta en archivo: {e}")


# ════════════════════════════════════════════════════════════════
# HTTP CLIENT
# ════════════════════════════════════════════════════════════════

def _ssl_skip_hosts() -> set[str]:
    """
    Lee la lista de hosts donde se acepta certificado SSL inválido.
    Configurable via env SSL_VERIFY_SKIP_HOSTS (CSV).

    Default vacío: TODOS los sitios verifican SSL estrictamente. Solo agregar
    hosts cuando el scraper falle con 'certificate verify failed' y el operador
    confirme que el certificado del sitio es legítimamente autofirmado/expirado
    (caso típico: portales gubernamentales legacy).
    """
    raw = os.getenv("SSL_VERIFY_SKIP_HOSTS", "").strip()
    if not raw:
        return set()
    return {h.strip().lower() for h in raw.split(",") if h.strip()}


def verify_para_url(url: str, skip_hosts: Optional[set[str]] = None) -> bool:
    """
    Decide si un request a `url` debe verificar SSL.

    Returns True (verifica) salvo que el host esté en `skip_hosts`.
    El parámetro skip_hosts permite override en tests; en runtime usa la env var.
    """
    hosts = skip_hosts if skip_hosts is not None else _ssl_skip_hosts()
    if not hosts:
        return True
    host = (urlparse(url).hostname or "").lower()
    return host not in hosts


def create_http_session() -> requests.Session:
    """Sesion HTTP con reintentos y User-Agent de navegador."""
    session = requests.Session()
    retry = Retry(
        total=config.MAX_RETRIES,
        backoff_factor=1,
        status_forcelist=[429, 500, 502, 503, 504],
        allowed_methods=["GET", "HEAD"],
    )
    adapter = HTTPAdapter(max_retries=retry)
    session.mount("http://", adapter)
    session.mount("https://", adapter)
    session.headers.update(
        {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
            "AppleWebKit/537.36 (KHTML, like Gecko) "
            "Chrome/120.0.0.0 Safari/537.36"
        }
    )
    # SSL verification activada por default (seguro). Cada call site usa
    # verify_para_url(url) para decidir si saltar la verificación según
    # SSL_VERIFY_SKIP_HOSTS — typically solo dominios .gob.bo legacy.
    session.verify = True
    return session


# ════════════════════════════════════════════════════════════════
# IMAGENES — EXTRACCION, CASCADA Y PERSISTENCIA
# ════════════════════════════════════════════════════════════════

# MIME types soportados para análisis multimodal (Gemini 2.5-flash)
_MIME_SOPORTADOS = {
    "image/png",
    "image/jpeg",
    "image/webp",
    "image/gif",
}

# Mapa de extensión → MIME
_EXT_A_MIME: dict[str, str] = {
    ".png": "image/png",
    ".jpg": "image/jpeg",
    ".jpeg": "image/jpeg",
    ".webp": "image/webp",
    ".gif": "image/gif",
    ".bmp": "image/bmp",
    ".tiff": "image/tiff",
    ".tif": "image/tiff",
    ".avif": "image/avif",
    ".heic": "image/heic",
    ".heif": "image/heif",
}


class ImagenStorageError(Exception):
    """Error al guardar imagen a disco (permisos, disco lleno, etc.)."""
    pass


def extraer_imagenes_html(html: str, base_url: str) -> list[dict]:
    """
    Extrae todas las imágenes de un HTML y resuelve sus URLs absolutas.

    Args:
        html: HTML completo de la página.
        base_url: URL base para resolver URLs relativas.

    Returns:
        Lista de dicts: [{src, src_absoluto, mime_hint}]
        - src: valor original del atributo src
        - src_absoluto: URL absoluta resuelta
        - mime_hint: MIME type deducido de la extensión (o None)
    """
    from urllib.parse import urljoin, urlparse
    from bs4 import BeautifulSoup

    soup = BeautifulSoup(html, "lxml")
    resultado: list[dict] = []
    vistos: set[str] = set()

    for img in soup.find_all("img"):
        src = img.get("src") or ""
        src = src.strip()

        # Filtrar src vacíos y data URIs inline
        if not src:
            continue
        if src.lower().startswith("data:"):
            continue

        # Resolver URL absoluta
        src_absoluto = urljoin(base_url, src)

        # Dedup: si la misma URL absoluta ya apareció (logos, sprites, sociales
        # repetidos en el HTML), no la procesamos de nuevo.
        if src_absoluto in vistos:
            continue
        vistos.add(src_absoluto)

        # Deducir MIME desde extensión de la URL (sin query params)
        parsed = urlparse(src_absoluto)
        path_sin_query = parsed.path.lower()
        mime_hint: Optional[str] = None
        for ext, mime in _EXT_A_MIME.items():
            if path_sin_query.endswith(ext):
                mime_hint = mime
                break

        resultado.append({
            "src": src,
            "src_absoluto": src_absoluto,
            "mime_hint": mime_hint,
        })

    return resultado


def comparar_imagenes_cascada(
    imgs_actual: list[dict],
    imgs_anterior: list[dict],
    session: requests.Session,
    max_image_bytes: int = 5 * 1024 * 1024,
) -> tuple[list[dict], list[dict]]:
    """
    Determina qué imágenes cambiaron usando una cascada de 3 niveles:
      L1: URL nueva → descargar siempre
      L2: URL conocida, ETag o Content-Length difieren → GET + SHA-256 compare
      L3: URL conocida, metadata idéntica → skip (no GET)

    Filtros pre-download:
      - Content-Length > max_image_bytes → skip
      - MIME no soportado (ej: SVG) → skip

    Args:
        imgs_actual: output de extraer_imagenes_html para la página actual.
        imgs_anterior: filas de snapshot_imagenes (dicts con src, sha256,
                       content_length, etag, last_modified, mime_type).
        session: requests.Session para HEAD y GET.
        max_image_bytes: límite por imagen en bytes (default 5MB).

    Returns:
        - imgs_a_analizar: imágenes que cambiaron, con bytes descargados.
        - imgs_metadata: metadata completa para upsert en snapshot_imagenes.
    """
    # Índice de imgs_anterior por src para lookup O(1)
    anterior_por_src: dict[str, dict] = {
        img["src"]: img for img in imgs_anterior
    }

    imgs_a_analizar: list[dict] = []
    imgs_metadata: list[dict] = []

    for img in imgs_actual:
        src_abs = img["src_absoluto"]

        # ── HEAD request ───────────────────────────────────────────
        try:
            head_resp = session.head(src_abs, timeout=15, allow_redirects=True, verify=verify_para_url(src_abs))
            head_headers = head_resp.headers
        except requests.exceptions.RequestException as e:
            logger.warning(f"HEAD fallido para {src_abs}: {e} — saltando imagen")
            continue

        # ── Leer metadata del HEAD ──────────────────────────────────
        content_type = head_headers.get("Content-Type", "").split(";")[0].strip().lower()
        content_length_str = head_headers.get("Content-Length")
        content_length: Optional[int] = int(content_length_str) if content_length_str else None
        etag = head_headers.get("ETag")
        last_modified = head_headers.get("Last-Modified")

        # ── Filtro: MIME no soportado ──────────────────────────────
        # Solo filtramos si el servidor realmente nos dice el MIME
        if content_type and content_type not in _MIME_SOPORTADOS:
            logger.warning(f"MIME no soportado ({content_type}) para {src_abs} — saltando")
            continue

        # ── Filtro: tamaño excede límite ───────────────────────────
        if content_length is not None and content_length > max_image_bytes:
            logger.warning(
                f"Imagen muy grande ({content_length} bytes > {max_image_bytes}) "
                f"para {src_abs} — saltando"
            )
            continue

        # ── Determinar nivel de cascada ────────────────────────────
        prev = anterior_por_src.get(src_abs)

        if prev is None:
            # L1: URL nueva → descargar siempre
            nivel = 1
            debe_descargar = True
        else:
            # Comparar ETag y Content-Length
            etag_cambio = etag is not None and etag != prev.get("etag")
            length_cambio = (
                content_length is not None
                and content_length != prev.get("content_length")
            )
            if etag_cambio or length_cambio:
                # L2: metadata cambió → GET + SHA-256 compare
                nivel = 2
                debe_descargar = True
            else:
                # L3: sin cambios detectables → skip
                nivel = 3
                debe_descargar = False

        if debe_descargar:
            # ── GET y SHA-256 ─────────────────────────────────────
            try:
                get_resp = session.get(src_abs, timeout=30, verify=verify_para_url(src_abs))
                image_bytes = get_resp.content
            except requests.exceptions.RequestException as e:
                logger.warning(f"GET fallido para {src_abs}: {e} — saltando imagen")
                continue

            sha256_nuevo = hashlib.sha256(image_bytes).hexdigest()

            # Determinar MIME real: preferir Content-Type del HEAD,
            # fallback a mime_hint desde extensión
            mime_real = content_type if content_type in _MIME_SOPORTADOS else img.get("mime_hint")

            entry_metadata: dict = {
                "src": src_abs,
                "sha256": sha256_nuevo,
                "content_length": content_length if content_length is not None else len(image_bytes),
                "etag": etag,
                "last_modified": last_modified,
                "mime_type": mime_real,
            }
            imgs_metadata.append(entry_metadata)

            # Para L2: solo añadir a_analizar si el SHA cambió realmente
            sha256_anterior = prev.get("sha256") if prev else None
            if sha256_anterior is None or sha256_nuevo != sha256_anterior:
                entry_analizar = dict(entry_metadata)
                entry_analizar["bytes"] = image_bytes
                entry_analizar["src_absoluto"] = src_abs
                imgs_a_analizar.append(entry_analizar)
            else:
                logger.debug(f"SHA-256 sin cambio para {src_abs} (nivel {nivel}) — no se analiza")

        else:
            # L3: sin cambio → metadata para actualizar ultima_vez_visto
            mime_real = content_type if (content_type and content_type in _MIME_SOPORTADOS) else prev.get("mime_type")
            entry_metadata = {
                "src": src_abs,
                "sha256": prev.get("sha256"),
                "content_length": content_length if content_length is not None else prev.get("content_length"),
                "etag": etag if etag else prev.get("etag"),
                "last_modified": last_modified if last_modified else prev.get("last_modified"),
                "mime_type": mime_real,
            }
            imgs_metadata.append(entry_metadata)

    return imgs_a_analizar, imgs_metadata


def guardar_imagen_local(
    image_bytes: bytes,
    cambio_id: int,
    idx: int,
    mime_type: Optional[str],
) -> str:
    """
    Guarda bytes de imagen a disco y retorna el path RELATIVO al storage.

    El path relativo retornado (ej: 'img_cambios/42_0.png') es el que se
    persiste en cambios.imagenes_cambio_json y que Laravel resuelve con
    storage_path('app/' . $path).

    El directorio de destino se configura con LARAVEL_STORAGE_PATH
    (default: '../../storage/app' relativo al script).

    Args:
        image_bytes: contenido binario de la imagen.
        cambio_id: ID del cambio en la tabla cambios.
        idx: índice de la imagen (0-based) para el nombre de archivo.
        mime_type: MIME type para determinar extensión.

    Returns:
        Path relativo al storage: 'img_cambios/{cambio_id}_{idx}.{ext}'

    Raises:
        ImagenStorageError: si no se puede escribir a disco.
    """
    # Determinar extensión desde MIME
    mime_a_ext: dict[str, str] = {
        "image/png": ".png",
        "image/jpeg": ".jpg",
        "image/webp": ".webp",
        "image/gif": ".gif",
        "image/bmp": ".bmp",
        "image/tiff": ".tiff",
        "image/avif": ".avif",
        "image/heic": ".heic",
        "image/heif": ".heif",
    }
    ext = mime_a_ext.get(mime_type or "", ".bin")

    nombre_archivo = f"{cambio_id}_{idx}{ext}"
    path_relativo = f"img_cambios/{nombre_archivo}"

    # Resolver directorio absoluto
    # LARAVEL_STORAGE_PATH: path absoluto o relativo al script
    script_dir = os.path.dirname(os.path.abspath(__file__))
    storage_base = os.getenv(
        "LARAVEL_STORAGE_PATH",
        os.path.join(script_dir, "..", "..", "storage", "app"),
    )
    directorio = os.path.join(storage_base, "img_cambios")

    try:
        os.makedirs(directorio, exist_ok=True)
        path_absoluto = os.path.join(directorio, nombre_archivo)
        with open(path_absoluto, "wb") as f:
            f.write(image_bytes)
        logger.debug(f"Imagen guardada: {path_absoluto}")
    except OSError as e:
        raise ImagenStorageError(
            f"No se pudo guardar imagen {nombre_archivo}: {e}"
        ) from e

    return path_relativo


def registrar_snapshot_imagenes(
    cursor,
    snapshot_id: int,
    fuente_id: int,
    imagenes_metadata: list[dict],
) -> None:
    """
    Upsert en snapshot_imagenes para todas las imágenes del ciclo actual.

    Usa ON CONFLICT (fuente_id, src) DO UPDATE para mantener metadata
    actualizada incluso cuando la imagen no cambió (actualiza ultima_vez_visto).

    Args:
        cursor: psycopg2 cursor con autocommit.
        snapshot_id: ID del snapshot recién creado.
        fuente_id: ID de la fuente.
        imagenes_metadata: lista de dicts con {src, sha256, content_length,
                           etag, last_modified, mime_type}.
    """
    if not imagenes_metadata:
        return

    for img in imagenes_metadata:
        cursor.execute(
            """
            INSERT INTO snapshot_imagenes
                (snapshot_id, fuente_id, src, sha256, content_length,
                 etag, last_modified, mime_type, ultima_vez_visto)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, NOW())
            ON CONFLICT (fuente_id, src) DO UPDATE SET
                snapshot_id    = EXCLUDED.snapshot_id,
                sha256         = EXCLUDED.sha256,
                content_length = EXCLUDED.content_length,
                etag           = EXCLUDED.etag,
                last_modified  = EXCLUDED.last_modified,
                mime_type      = EXCLUDED.mime_type,
                ultima_vez_visto = NOW()
            """,
            (
                snapshot_id,
                fuente_id,
                img["src"],
                img.get("sha256"),
                img.get("content_length"),
                img.get("etag"),
                img.get("last_modified"),
                img.get("mime_type"),
            ),
        )


# ════════════════════════════════════════════════════════════════
# MONITOR PRINCIPAL
# ════════════════════════════════════════════════════════════════
class PEPMonitor:
    """Orquesta descarga, limpieza, comparacion y alerta por fuente."""

    def __init__(self):
        self.db = DatabaseManager()
        self.http = create_http_session()
        self.running = True

    def _cargar_imgs_anterior(self, fuente_id: int) -> list[dict]:
        """Carga metadata de imágenes previas desde snapshot_imagenes."""
        self.db._ensure_connection()
        try:
            self.db.cursor.execute(
                """SELECT src, sha256, content_length, etag, last_modified, mime_type
                   FROM snapshot_imagenes
                   WHERE fuente_id = %s""",
                (fuente_id,),
            )
            return [dict(row) for row in self.db.cursor.fetchall()]
        except Exception as e:
            logger.warning(f"No se pudo leer snapshot_imagenes para fuente {fuente_id}: {e}")
            return []

    def _obtener_html_raw(self, fuente: dict) -> tuple[str, str]:
        """
        Retorna el HTML crudo (sin limpiar) junto con el método usado.
        Necesario para extraer imágenes antes de limpiar el texto.
        """
        url = fuente["url"]
        tipo = fuente.get("tipo", "html")
        selector = fuente.get("selector_css")

        if tipo == "pdf" or url.lower().endswith(".pdf"):
            # PDF no tiene HTML — retornar vacío
            return "", "pdf"

        if tipo == "js":
            html, metodo_js = obtener_html_js(url)
            return html, f"js_playwright"

        try:
            resp = self.http.get(url, timeout=config.REQUEST_TIMEOUT, verify=verify_para_url(url))
            resp.raise_for_status()
            html = resp.text
            # Fallback JS si el HTML tiene poco contenido
            lineas_test, _ = limpiar_html(html, selector)
            if len(lineas_test) < 3:
                html_js, _ = obtener_html_js(url)
                if html_js:
                    lineas_js, _ = limpiar_html(html_js, selector)
                    if len(lineas_js) > len(lineas_test):
                        return html_js, "js_fallback"
            return html, "html_estatico"
        except requests.ConnectionError:
            raise
        except requests.RequestException as e:
            logger.error(f"Error HTTP en {url}: {e}")
            return "", "error_http"

    def procesar_fuente(self, fuente: dict) -> None:
        """
        Flujo completo para una fuente:
        1. Descargar HTML crudo
        2. Extraer imágenes del HTML (multimoda)
        3. Limpiar texto y comparar con snapshot anterior
        4. Cascada de imágenes para detectar cambios visuales
        5. Si hay cambio (texto o imagen): guardar diff, imágenes, alertar
        6. Actualizar snapshot + snapshot_imagenes
        """
        url = fuente["url"]
        fuente_id = fuente["id"]
        nombre = fuente.get("nombre") or fuente.get("organismo") or url
        logger.info(f"Verificando: {nombre}")

        # ── 8.4a: Obtener HTML crudo ───────────────────────────────
        try:
            html_raw, metodo_raw = self._obtener_html_raw(fuente)
        except requests.ConnectionError:
            logger.warning(f"Sin conexion al verificar: {nombre}")
            return

        # Para PDFs o errores sin HTML, fallback al flujo original de líneas
        if not html_raw:
            if fuente.get("tipo") == "pdf" or url.lower().endswith(".pdf"):
                lineas_nuevas, metodo = limpiar_pdf(url, self.http)
                html_raw = ""
            else:
                logger.warning(f"Sin contenido extraido de: {nombre}")
                self.db.update_ultimo_check(fuente_id)
                return
            imgs_actual: list[dict] = []
        else:
            # Limpiar texto desde el HTML crudo
            lineas_nuevas, metodo = limpiar_html(html_raw, fuente.get("selector_css"))
            # Extraer imágenes SOLO si la fuente tiene analizar_imagenes=true.
            # Si no, las fotos decorativas (retratos, logos) se ignoran y no
            # alimentan a Gemini multimodal — evita falsos positivos y ahorra tokens.
            if fuente.get("analizar_imagenes"):
                imgs_actual = extraer_imagenes_html(html_raw, url)
            else:
                imgs_actual = []

        if not lineas_nuevas:
            logger.warning(f"Sin contenido extraido de: {nombre}")
            self.db.update_ultimo_check(fuente_id)
            return

        # ── Texto: hash y snapshot anterior ───────────────────────
        texto_nuevo = "\n".join(lineas_nuevas)
        hash_nuevo = hashlib.sha256(texto_nuevo.encode("utf-8")).hexdigest()
        snapshot_anterior = self.db.get_ultimo_snapshot(fuente_id)

        if snapshot_anterior is None:
            # Primera vez — guardar estado inicial sin alertar
            self.db.guardar_snapshot(fuente_id, hash_nuevo, texto_nuevo, metodo)
            logger.info(
                f"[INICIO] Estado inicial guardado: {nombre} "
                f"({len(lineas_nuevas)} lineas, metodo: {metodo})"
            )
            self.db.update_ultimo_check(fuente_id)
            return

        # ── 8.4a: Cascada de imágenes ──────────────────────────────
        max_image_bytes = int(
            os.getenv("GEMINI_MULTIMODAL_MAX_IMAGE_BYTES", str(5 * 1024 * 1024))
        )
        imgs_anterior = self._cargar_imgs_anterior(fuente_id)
        imgs_a_analizar: list[dict] = []
        imgs_metadata: list[dict] = []

        if imgs_actual and html_raw:
            try:
                imgs_a_analizar, imgs_metadata = comparar_imagenes_cascada(
                    imgs_actual, imgs_anterior, self.http, max_image_bytes
                )
            except Exception as e:
                logger.error(f"Error en cascada de imágenes para {nombre}: {e}")
                imgs_a_analizar = []
                imgs_metadata = []

        # ── Determinar si hay cambio real ──────────────────────────
        texto_cambio = snapshot_anterior["hash"] != hash_nuevo
        hay_diff_texto = False
        diff: dict = {}

        if texto_cambio:
            lineas_anteriores = snapshot_anterior["texto"].split("\n")
            diff = calcular_diff(lineas_anteriores, lineas_nuevas)
            hay_diff_texto = bool(diff.get("quitadas") or diff.get("nuevas"))

        hay_cambio_imagen = bool(imgs_a_analizar)

        # Si no hay ningún cambio real → skip
        if not hay_diff_texto and not hay_cambio_imagen:
            if texto_cambio:
                logger.debug(f"[OK] Reordenamiento sin contenido nuevo: {nombre}")
            else:
                logger.debug(f"[OK] Sin cambios: {nombre}")
            # Actualizar snapshot si el hash cambió
            if texto_cambio:
                self.db.guardar_snapshot(fuente_id, hash_nuevo, texto_nuevo, metodo)
            # Actualizar metadata de imágenes (ultima_vez_visto) si hubo imágenes
            if imgs_metadata:
                snapshot_row = self.db.get_ultimo_snapshot(fuente_id)
                snap_id = None
                if snapshot_row:
                    self.db._ensure_connection()
                    self.db.cursor.execute(
                        "SELECT id FROM snapshots WHERE fuente_id = %s ORDER BY fecha DESC LIMIT 1",
                        (fuente_id,),
                    )
                    snap_row = self.db.cursor.fetchone()
                    snap_id = snap_row["id"] if snap_row else None
                if snap_id:
                    registrar_snapshot_imagenes(
                        self.db.cursor, snap_id, fuente_id, imgs_metadata
                    )
            self.db.update_ultimo_check(fuente_id)
            return

        # ── Hay cambio real: insertar en cambios ───────────────────
        if not diff:
            # Cambio solo de imagen, sin diff de texto
            diff = {"quitadas": [], "nuevas": [], "diff_texto": "", "posibles_peps": ""}

        cambio_id = self.db.guardar_cambio(
            fuente_id=fuente_id,
            hash_anterior=snapshot_anterior["hash"],
            hash_nuevo=hash_nuevo,
            lineas_quitadas=len(diff.get("quitadas", [])),
            lineas_nuevas=len(diff.get("nuevas", [])),
            diff_texto=diff.get("diff_texto", ""),
            posibles_peps=diff.get("posibles_peps", ""),
            # imagenes: None por ahora — se actualiza después del guardado a disco
        )

        if cambio_id is None:
            logger.error(f"Fallo al insertar cambio para {nombre}")
            self.db.update_ultimo_check(fuente_id)
            return

        # ── 8.4b: Guardar imágenes a disco ─────────────────────────
        entries_imagenes: list[dict] = []
        for idx, img in enumerate(imgs_a_analizar):
            try:
                path_relativo = guardar_imagen_local(
                    img["bytes"], cambio_id, idx, img.get("mime_type")
                )
                entries_imagenes.append({
                    "path": path_relativo,
                    "src_original": img["src_absoluto"],
                    "sha256": img["sha256"],
                    "mime_type": img.get("mime_type"),
                })
            except ImagenStorageError as e:
                logger.error(f"No se pudo guardar imagen idx={idx} para cambio #{cambio_id}: {e}")
                # Continuar con las demás imágenes

        # ── Actualizar imagenes_cambio_json si hay entries ─────────
        if entries_imagenes:
            try:
                self.db.actualizar_imagenes_cambio(cambio_id, entries_imagenes)
                logger.info(
                    f"[CAMBIO #{cambio_id}] {len(entries_imagenes)} imagen(es) guardadas"
                )
            except Exception as e:
                logger.error(f"Error actualizando imagenes_cambio_json para #{cambio_id}: {e}")

        # ── Mostrar alerta ─────────────────────────────────────────
        if hay_diff_texto:
            mostrar_alerta(fuente, diff, cambio_id)
            logger.warning(
                f"[CAMBIO #{cambio_id}] {nombre}: "
                f"+{len(diff.get('nuevas', []))} nuevas, "
                f"-{len(diff.get('quitadas', []))} eliminadas"
                + (f", {len(entries_imagenes)} img" if entries_imagenes else "")
            )
        elif hay_cambio_imagen:
            logger.warning(
                f"[CAMBIO #{cambio_id}] {nombre}: "
                f"solo imágenes ({len(entries_imagenes)} nueva(s))"
            )

        # ── 8.4c: Registrar snapshot_imagenes ──────────────────────
        # Actualizar snapshot de texto
        self.db.guardar_snapshot(fuente_id, hash_nuevo, texto_nuevo, metodo)

        # Obtener el ID del snapshot recién creado
        try:
            self.db._ensure_connection()
            self.db.cursor.execute(
                "SELECT id FROM snapshots WHERE fuente_id = %s ORDER BY fecha DESC LIMIT 1",
                (fuente_id,),
            )
            snap_row = self.db.cursor.fetchone()
            snap_id = snap_row["id"] if snap_row else None
        except Exception as e:
            logger.error(f"No se pudo obtener snapshot_id para fuente {fuente_id}: {e}")
            snap_id = None

        if snap_id and imgs_metadata:
            try:
                registrar_snapshot_imagenes(
                    self.db.cursor, snap_id, fuente_id, imgs_metadata
                )
            except Exception as e:
                logger.error(f"Error registrando snapshot_imagenes para #{cambio_id}: {e}")

        self.db.update_ultimo_check(fuente_id)

    def check_all(self) -> None:
        """Procesa todas las fuentes activas. Registra inicio/fin en log_scripts."""
        fuentes = self.db.get_fuentes_activas()
        if not fuentes:
            logger.warning(
                "No hay fuentes registradas. Usa: python pep_monitor.py add <url>"
            )
            return

        log_id = self.db.log_inicio()
        logger.info(f"Verificando {len(fuentes)} fuente(s)... [log_id={log_id}]")

        cambios_encontrados = 0
        errores_count = 0
        error_msg: Optional[str] = None

        try:
            for fuente in fuentes:
                if not self.running:
                    break
                if not hay_internet():
                    esperar_internet()
                try:
                    antes = self.db.get_ultimo_snapshot(fuente["id"])
                    self.procesar_fuente(fuente)
                    despues = self.db.get_ultimo_snapshot(fuente["id"])
                    # Detectar si hubo un cambio (hash distinto o nuevo snapshot)
                    if antes is None or (despues and antes["hash"] != despues["hash"]):
                        cambios_encontrados += 1
                except Exception as e:
                    errores_count += 1
                    error_msg = str(e)
                    logger.error(f"Error procesando fuente {fuente.get('url')}: {e}")

            self.db.log_fin(
                log_id,
                estado="completado",
                items_procesados=len(fuentes),
                items_resultado=cambios_encontrados,
                errores=errores_count,
                mensaje_error=error_msg,
            )
        except Exception as e:
            self.db.log_fin(
                log_id,
                estado="error",
                items_procesados=len(fuentes),
                items_resultado=cambios_encontrados,
                errores=errores_count + 1,
                mensaje_error=str(e),
            )
            raise

    def _get_config_from_db(self) -> dict:
        """Lee configuración desde config_scripts (la UI de Laravel).
        Fallback a .env si la tabla no existe o falla."""
        try:
            self.db._ensure_connection()
            self.db.cursor.execute(
                "SELECT habilitado, intervalo_minutos, hora_inicio, hora_fin, dias_semana "
                "FROM config_scripts WHERE script = 'pep_monitor' LIMIT 1"
            )
            row = self.db.cursor.fetchone()
            if row:
                return {
                    "habilitado": row["habilitado"],
                    "intervalo_segundos": int(row["intervalo_minutos"]) * 60,
                    "hora_inicio": row.get("hora_inicio"),
                    "hora_fin": row.get("hora_fin"),
                    "dias_semana": row.get("dias_semana"),
                }
        except Exception as e:
            logger.warning(f"No se pudo leer config_scripts: {e}. Usando .env")
        # Fallback a .env
        return {
            "habilitado": True,
            "intervalo_segundos": config.CHECK_INTERVAL,
            "hora_inicio": None,
            "hora_fin": None,
            "dias_semana": None,
        }

    def _en_ventana_horaria(self, cfg: dict) -> bool:
        """Verifica si estamos dentro de la ventana horaria configurada en la UI."""
        from datetime import time as dtime

        ahora = datetime.now()

        # Verificar días activos
        dias = cfg.get("dias_semana")
        if dias:
            dias_activos = [int(d) for d in str(dias).split(",") if d.strip()]
            if dias_activos and ahora.isoweekday() not in dias_activos:
                return False

        # Verificar ventana horaria
        hora_ini = cfg.get("hora_inicio")
        hora_fin = cfg.get("hora_fin")
        if hora_ini and hora_fin:
            if hasattr(hora_ini, "total_seconds"):
                s = int(hora_ini.total_seconds())
                hora_ini = dtime(s // 3600, (s % 3600) // 60)
            if hasattr(hora_fin, "total_seconds"):
                s = int(hora_fin.total_seconds())
                hora_fin = dtime(s // 3600, (s % 3600) // 60)
            hora_actual = ahora.time().replace(second=0, microsecond=0)
            if not (hora_ini <= hora_actual <= hora_fin):
                return False

        return True

    def run(self) -> None:
        """Loop infinito con reconexion automatica. Lee config desde DB (UI de Laravel)."""
        logger.info("=" * 65)
        logger.info("PEP Monitor iniciado")
        logger.info(f"Fallback intervalo (.env): {config.CHECK_INTERVAL}s")
        logger.info("Configuracion real se lee de config_scripts (UI Laravel)")
        logger.info("=" * 65)

        try:
            while self.running:
                # Leer config desde DB en cada ciclo (permite cambios en caliente)
                cfg = self._get_config_from_db()
                intervalo = cfg["intervalo_segundos"]

                if not cfg["habilitado"]:
                    logger.info("PEP Monitor DESHABILITADO desde la UI. Esperando 60s...")
                    time.sleep(60)
                    continue

                if not self._en_ventana_horaria(cfg):
                    logger.info("Fuera de ventana horaria. Esperando 60s...")
                    time.sleep(60)
                    continue

                if not hay_internet():
                    esperar_internet()

                self.check_all()

                minutos = intervalo // 60
                logger.info(
                    f"Ciclo completado. "
                    f"Proxima verificacion en {minutos} min ({intervalo}s)..."
                )
                time.sleep(intervalo)
        except KeyboardInterrupt:
            logger.info("Interrupcion recibida, cerrando...")
        finally:
            self.stop()

    def stop(self) -> None:
        self.running = False
        self.db.close()
        self.http.close()
        logger.info("Monitor detenido")


# ════════════════════════════════════════════════════════════════
# EXPORTACION CSV
# ════════════════════════════════════════════════════════════════
class Exportador:
    """Exporta historial de cambios a CSV."""

    def __init__(self, db: DatabaseManager):
        self.db = db
        os.makedirs(config.EXPORT_DIR, exist_ok=True)

    def exportar_cambios(
        self, fuente_id: Optional[int] = None, pais: Optional[str] = None
    ) -> str:
        """Exporta el historial de cambios a un CSV."""
        self.db._ensure_connection()
        where = "WHERE 1=1"
        params = []
        if fuente_id:
            where += " AND c.fuente_id = %s"
            params.append(fuente_id)
        if pais:
            where += " AND f.pais = %s"
            params.append(pais)

        self.db.cursor.execute(
            f"""SELECT c.id, c.fecha, f.nombre, f.organismo, f.pais, f.url,
                       c.lineas_nuevas, c.lineas_quitadas, c.posibles_peps
                FROM cambios c
                JOIN fuentes f ON f.id = c.fuente_id
                {where}
                ORDER BY c.fecha DESC""",
            params,
        )
        datos = self.db.cursor.fetchall()

        fecha_str = datetime.now().strftime("%Y%m%d_%H%M%S")
        sufijo = f"_{pais}" if pais else ""
        ruta = os.path.join(config.EXPORT_DIR, f"cambios{sufijo}_{fecha_str}.csv")

        with open(ruta, "w", newline="", encoding="utf-8-sig") as f:
            writer = csv.DictWriter(
                f,
                fieldnames=[
                    "id",
                    "fecha",
                    "nombre",
                    "organismo",
                    "pais",
                    "url",
                    "lineas_nuevas",
                    "lineas_quitadas",
                    "posibles_peps",
                ],
            )
            writer.writeheader()
            writer.writerows(datos)

        logger.info(f"Exportado: {ruta} ({len(datos)} cambios)")
        return ruta


# ════════════════════════════════════════════════════════════════
# CLI
# ════════════════════════════════════════════════════════════════
def main() -> None:
    parser = argparse.ArgumentParser(
        description="PEP Monitor — Detecta cambios en paginas institucionales",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Ejemplos:
  python pep_monitor.py add https://entel.bo/directorio --nombre "ENTEL Directorio" --pais Bolivia --organismo ENTEL
  python pep_monitor.py run
  python pep_monitor.py check
  python pep_monitor.py list
  python pep_monitor.py history <id_fuente>
  python pep_monitor.py ver <id_cambio>
  python pep_monitor.py export --pais Bolivia
  python pep_monitor.py stats
        """,
    )

    sub = parser.add_subparsers(dest="command")

    # run
    sub.add_parser("run", help="Inicia el monitor en loop continuo")

    # add
    p_add = sub.add_parser("add", help="Registra una URL para monitorear")
    p_add.add_argument("url")
    p_add.add_argument(
        "--nombre", required=True, help="Nombre descriptivo (ej: 'ENTEL - Directorio')"
    )
    p_add.add_argument("--pais", required=True)
    p_add.add_argument("--organismo", required=True)
    p_add.add_argument(
        "--nivel",
        choices=[
            "nacional",
            "regional",
            "municipal",
            "judicial",
            "legislativo",
            "otro",
        ],
        default="nacional",
    )
    p_add.add_argument("--tipo", choices=["html", "pdf", "js"], default="html")
    p_add.add_argument(
        "--selector",
        help="Selector CSS para aislar el area de interes (opcional)",
        default=None,
    )

    # remove
    p_rm = sub.add_parser("remove", help="Desactiva una fuente")
    p_rm.add_argument("url")

    # list
    sub.add_parser("list", help="Lista todas las fuentes registradas")

    # check
    sub.add_parser("check", help="Verificacion unica sin loop")

    # history
    p_hist = sub.add_parser(
        "history", help="Muestra historial de cambios de una fuente"
    )
    p_hist.add_argument("fuente_id", type=int, help="ID de la fuente (ver con 'list')")
    p_hist.add_argument("--limite", type=int, default=20)

    # ver
    p_ver = sub.add_parser("ver", help="Muestra el diff completo de un cambio")
    p_ver.add_argument("cambio_id", type=int, help="ID del cambio (ver con 'history')")

    # export
    p_exp = sub.add_parser("export", help="Exporta historial de cambios a CSV")
    p_exp.add_argument("--pais", default=None)
    p_exp.add_argument(
        "--fuente", type=int, default=None, help="ID de fuente especifica"
    )

    # stats
    sub.add_parser("stats", help="Estadisticas globales")

    args = parser.parse_args()

    # ── Ejecutar ─────────────────────────────────────────────────
    if args.command == "run":
        monitor = PEPMonitor()
        monitor.run()

    elif args.command == "add":
        db = DatabaseManager()
        fid = db.add_fuente(
            url=args.url,
            nombre=args.nombre,
            pais=args.pais,
            organismo=args.organismo,
            nivel=args.nivel,
            tipo=args.tipo,
            selector_css=args.selector,
        )
        db.close()
        sys.exit(0 if fid else 1)

    elif args.command == "remove":
        db = DatabaseManager()
        ok = db.remove_fuente(args.url)
        db.close()
        if not ok:
            print(f"URL no encontrada: {args.url}")
        sys.exit(0 if ok else 1)

    elif args.command == "list":
        db = DatabaseManager()
        fuentes = db.list_fuentes()
        db.close()
        if not fuentes:
            print("No hay fuentes registradas.")
            return
        print(
            f"\n{'ID':<4} {'Nombre':<35} {'Pais':<12} {'Tipo':<6} "
            f"{'Cambios':<8} {'Ultimo check':<20} {'Activo'}"
        )
        print("-" * 95)
        for f in fuentes:
            activo = "SI" if f["activo"] else "NO"
            ult = str(f["ultimo_check"])[:16] if f["ultimo_check"] else "Nunca"
            print(
                f"{f['id']:<4} {str(f['nombre'])[:34]:<35} "
                f"{str(f['pais']):<12} {str(f['tipo']):<6} "
                f"{f['total_cambios']:<8} {ult:<20} {activo}"
            )

    elif args.command == "check":
        monitor = PEPMonitor()
        monitor.check_all()
        monitor.stop()

    elif args.command == "history":
        db = DatabaseManager()
        # Obtener nombre de fuente
        db._ensure_connection()
        db.cursor.execute(
            "SELECT nombre, organismo, pais, url FROM fuentes WHERE id = %s",
            (args.fuente_id,),
        )
        fuente = db.cursor.fetchone()
        if not fuente:
            print(f"Fuente {args.fuente_id} no encontrada")
            db.close()
            sys.exit(1)

        cambios = db.get_historial(args.fuente_id, args.limite)
        db.close()

        print(f"\nHistorial de cambios: {fuente['nombre']} ({fuente['pais']})")
        print(f"URL: {fuente['url']}\n")

        if not cambios:
            print("Sin cambios registrados aun.")
            return

        print(
            f"{'ID':<6} {'Fecha':<20} {'+Nuevas':<10} {'-Quitadas':<12} Posibles PEPs"
        )
        print("-" * 80)
        for c in cambios:
            peps_resumen = (c["posibles_peps"] or "")[:50].replace("\n", " | ")
            print(
                f"{c['id']:<6} {str(c['fecha'])[:19]:<20} "
                f"{c['lineas_nuevas']:<10} {c['lineas_quitadas']:<12} "
                f"{peps_resumen}"
            )
        print(f"\nUsa 'python pep_monitor.py ver <ID>' para ver el diff completo")

    elif args.command == "ver":
        db = DatabaseManager()
        cambio = db.get_cambio_detalle(args.cambio_id)
        db.close()
        if not cambio:
            print(f"Cambio #{args.cambio_id} no encontrado")
            sys.exit(1)

        print(f"\n{'=' * 65}")
        print(f"  Cambio #{cambio['id']} — {cambio['fecha']}")
        print(f"  Fuente   : {cambio['nombre']}")
        print(f"  Organismo: {cambio['organismo']} ({cambio['pais']})")
        print(f"  URL      : {cambio['url']}")
        print(f"{'=' * 65}")
        print(f"\n{cambio['diff_texto'] or '(sin diff guardado)'}")

        if cambio["posibles_peps"]:
            print(f"\n{'─' * 65}")
            print("  POSIBLES CAMBIOS DE PERSONAL (heuristica):")
            for linea in cambio["posibles_peps"].split("\n"):
                print(f"    >> {linea}")

    elif args.command == "export":
        db = DatabaseManager()
        exp = Exportador(db)
        ruta = exp.exportar_cambios(args.fuente, args.pais)
        db.close()
        print(f"Exportado: {ruta}")

    elif args.command == "stats":
        db = DatabaseManager()
        stats = db.get_stats()
        db.close()
        print("\n=== PEP Monitor — Estadisticas ===\n")
        print(f"  Fuentes activas        : {stats['total_fuentes']}")
        print(f"  Cambios ultimos 7 dias : {stats['cambios_semana']}")
        print(f"  Cambios ultimos 30 dias: {stats['cambios_mes']}")
        if stats["por_fuente"]:
            print("\n  Por fuente:")
            print(f"  {'Nombre':<35} {'Pais':<12} {'Cambios':<8} Ultimo cambio")
            print("  " + "-" * 70)
            for f in stats["por_fuente"]:
                ult = str(f["ultimo_cambio"])[:16] if f["ultimo_cambio"] else "Nunca"
                print(
                    f"  {str(f['nombre'])[:34]:<35} "
                    f"{str(f['pais']):<12} "
                    f"{f['total_cambios']:<8} {ult}"
                )
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
