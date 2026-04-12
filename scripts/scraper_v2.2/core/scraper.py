"""
Motor principal del scraper v2.2.0
Con filtrado inteligente: prioriza título, extrae contenido principal, excluye navegación.
Incluye MODO RÁPIDO con requests (mucho más veloz que Selenium).
"""

import random
import re
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from dataclasses import dataclass, field
from datetime import datetime
from enum import IntEnum
from typing import Dict, List, Optional, Set, Tuple
from urllib.parse import urljoin, urlparse

# Modo rápido (requests + bs4)
import requests
from bs4 import BeautifulSoup

# Modo completo (Selenium)
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.remote.webdriver import WebDriver
from selenium.common.exceptions import (
    TimeoutException,
    WebDriverException,
    NoSuchElementException,
    StaleElementReferenceException,
)

try:
    import spacy as spacy
except ImportError:
    spacy = None  # type: ignore[assignment]

from config.settings import settings
from core.database import ScrapingRepository
from core.webdriver_manager import WebDriverManager
from utils.logger import get_logger

logger = get_logger(__name__)

# Headers para requests (evitar bloqueos)
REQUEST_HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8",
    "Accept-Language": "es-ES,es;q=0.9,en;q=0.8",
}


class RelevanceLevel(IntEnum):
    """Niveles de relevancia para resultados."""

    NONE = 0  # No encontrado
    PAGE_ONLY = 20  # Solo en página (sidebar, ads, etc.)
    CONTENT = 50  # En contenido principal del artículo
    TITLE = 80  # En título del artículo
    TITLE_AND_CONTENT = 100  # En título Y contenido


@dataclass
class ScrapingResult:
    """Resultado de una operación de scraping."""

    url: str
    keyword: str
    sitio_id: int
    titulo: Optional[str] = None
    contexto: Optional[str] = None
    relevance_score: int = 0
    found_in_title: bool = False
    found_in_content: bool = False
    success: bool = True
    error: Optional[str] = None


@dataclass
class ScrapingStats:
    """Estadísticas de una sesión de scraping."""

    start_time: datetime = field(default_factory=datetime.now)
    sites_processed: int = 0
    links_processed: int = 0
    links_skipped: int = 0  # URLs filtradas
    results_found: int = 0
    high_relevance: int = 0  # Resultados con keyword en título
    errors: int = 0

    @property
    def duration_seconds(self) -> float:
        return (datetime.now() - self.start_time).total_seconds()

    def __str__(self) -> str:
        return (
            f"Sitios: {self.sites_processed}, Links: {self.links_processed}, "
            f"Saltados: {self.links_skipped}, Resultados: {self.results_found} "
            f"(alta relevancia: {self.high_relevance}), Errores: {self.errors}, "
            f"Duración: {self.duration_seconds:.1f}s"
        )


class URLFilter:
    """Filtro de URLs para identificar artículos vs páginas de navegación."""

    def __init__(self):
        self.exclude_patterns = settings.filter.exclude_url_patterns
        self.article_patterns = settings.filter.article_url_patterns

    def is_navigation_url(self, url: str) -> bool:
        """Detecta si es una URL de navegación (categoría, tag, etc.)."""
        url_lower = url.lower()

        for pattern in self.exclude_patterns:
            if pattern.lower() in url_lower:
                return True

        return False

    def looks_like_article(self, url: str) -> bool:
        """Detecta si la URL parece ser un artículo."""
        url_lower = url.lower()

        # Verificar patrones de artículo
        for pattern in self.article_patterns:
            if pattern.lower() in url_lower:
                return True

        # Heurística: URLs con fecha (ej: /2024/01/15/)
        if re.search(r"/\d{4}/\d{2}/", url_lower):
            return True

        # Heurística: URLs largas con guiones (slugs de artículos)
        path = urlparse(url).path
        slug_parts = path.strip("/").split("/")
        if slug_parts:
            last_part = slug_parts[-1]
            # Si tiene más de 3 guiones y más de 20 chars, probablemente es artículo
            if last_part.count("-") >= 3 and len(last_part) > 20:
                return True

        return False

    def should_process(self, url: str) -> Tuple[bool, str]:
        """
        Determina si una URL debe procesarse.
        Retorna (debe_procesar, razón).
        """
        if self.is_navigation_url(url):
            return False, "URL de navegación"

        # Si require_keyword_in_title está activo, preferir URLs que parezcan artículos
        if settings.filter.require_keyword_in_title:
            if not self.looks_like_article(url):
                # No rechazar, pero marcar como baja prioridad
                return True, "Posible página general"

        return True, "OK"


class ContentExtractor:
    """Extractor de contenido principal de páginas."""

    def __init__(self):
        self.article_selectors = settings.filter.article_selectors
        self.exclude_selectors = settings.filter.exclude_selectors

    def _remove_unwanted_elements(self, driver: WebDriver) -> None:
        """Elimina elementos no deseados del DOM (ads, sidebars, etc.)."""
        for selector in self.exclude_selectors:
            try:
                elements = driver.find_elements(By.CSS_SELECTOR, selector)
                for element in elements:
                    driver.execute_script("arguments[0].remove();", element)
            except (WebDriverException, StaleElementReferenceException):
                continue

    def get_article_content(self, driver: WebDriver) -> Tuple[str, bool]:
        """
        Extrae el contenido principal del artículo.
        Retorna (contenido, es_contenido_articulo).
        """
        if not settings.filter.use_article_selector:
            return self._get_body_content(driver), False

        # Primero remover elementos no deseados
        try:
            self._remove_unwanted_elements(driver)
        except WebDriverException:
            pass

        # Intentar encontrar contenido de artículo con selectores
        for selector in self.article_selectors:
            try:
                elements = driver.find_elements(By.CSS_SELECTOR, selector)
                if elements:
                    # Tomar el elemento con más texto
                    best_element = max(elements, key=lambda e: len(e.text))
                    content = best_element.text.strip()
                    if len(content) > 100:  # Contenido mínimo significativo
                        logger.debug(f"Contenido extraído con selector: {selector}")
                        return content, True
            except (WebDriverException, StaleElementReferenceException):
                continue

        # Fallback: contenido del body
        logger.debug("No se encontró selector de artículo, usando body")
        return self._get_body_content(driver), False

    def _get_body_content(self, driver: WebDriver) -> str:
        """Extrae todo el contenido del body."""
        try:
            body = driver.find_element(By.TAG_NAME, "body")
            return body.text
        except (NoSuchElementException, WebDriverException):
            return ""

    def get_title(self, driver: WebDriver) -> Optional[str]:
        """Extrae el título del artículo usando múltiples estrategias."""

        # 1. Usar selectores de configuración
        for selector in settings.filter.title_selectors:
            try:
                element = driver.find_element(By.CSS_SELECTOR, selector)
                title = element.text.strip()
                if title and len(title) >= settings.filter.min_title_length:
                    logger.debug(f"Título encontrado con selector: {selector}")
                    return title
            except (NoSuchElementException, WebDriverException):
                continue

        # 2. Buscar por role="heading" aria-level="1"
        try:
            element = driver.find_element(
                By.CSS_SELECTOR, '[role="heading"][aria-level="1"]'
            )
            title = element.text.strip()
            if title and len(title) >= settings.filter.min_title_length:
                logger.debug(f"Título encontrado con role=heading aria-level=1")
                return title
        except (NoSuchElementException, WebDriverException):
            pass

        # 3. Buscar h1 dentro de article
        try:
            element = driver.find_element(By.CSS_SELECTOR, "article h1")
            title = element.text.strip()
            if title and len(title) >= settings.filter.min_title_length:
                logger.debug(f"Título encontrado: article h1")
                return title
        except (NoSuchElementException, WebDriverException):
            pass

        # 4. Buscar h1 dentro de main
        try:
            element = driver.find_element(By.CSS_SELECTOR, "main h1")
            title = element.text.strip()
            if title and len(title) >= settings.filter.min_title_length:
                logger.debug(f"Título encontrado: main h1")
                return title
        except (NoSuchElementException, WebDriverException):
            pass

        # 5. Primer h1 de la página
        try:
            element = driver.find_element(By.TAG_NAME, "h1")
            title = element.text.strip()
            if title and len(title) >= settings.filter.min_title_length:
                logger.debug(f"Título encontrado: primer h1")
                return title
        except (NoSuchElementException, WebDriverException):
            pass

        # 6. Fallback: título de la página
        try:
            title = driver.title
            if title:
                # Limpiar sufijos comunes como "| Nombre del Sitio"
                title = re.split(r"\s*[\|\-–—]\s*", title)[0].strip()
                if len(title) >= settings.filter.min_title_length:
                    logger.debug(f"Título extraído de <title>: {title[:50]}...")
                    return title
        except WebDriverException:
            pass

        return None


# IMPLEMENTATION NOTE: keyword_in_title() and extract_context() expand keywords via self.families — see design section REQ-6
class KeywordMatcher:
    """Buscador de keywords con cálculo de relevancia y expansión por familias."""

    _nlp = None
    _spacy_tried: bool = False

    @classmethod
    def _init_spacy(cls) -> None:
        """Load spaCy model es_core_news_sm once at class level (singleton)."""
        if cls._spacy_tried:
            return
        cls._spacy_tried = True
        try:
            if spacy is None:
                raise ImportError("spacy not installed")
            cls._nlp = spacy.load("es_core_news_sm")
            logger.info("spaCy es_core_news_sm loaded successfully")
        except Exception as e:
            cls._nlp = None
            logger.warning(f"spaCy not available: {e}. Using regex-only matching.")

    def __init__(self, keywords: List[str]):
        self._init_spacy()

        from utils.lemma_loader import load_families_from_db
        self.families: Dict[str, Set[str]] = load_families_from_db()

        self.original_keywords = [kw.lower().strip() for kw in keywords if kw.strip()]
        if not self.original_keywords:
            raise ValueError("Se requiere al menos una keyword válida")

        # Expand keywords via families dict
        expanded: Set[str] = set()
        for kw in self.original_keywords:
            if kw in self.families:
                expanded.update(self.families[kw])
            else:
                expanded.add(kw)

        self.keywords = sorted(expanded)

        # Patrón para búsqueda (expandido con todas las variantes)
        escaped = [re.escape(kw) for kw in self.keywords]
        self.pattern = re.compile(r"\b(" + "|".join(escaped) + r")\b", re.IGNORECASE)
        logger.info(
            f"KeywordMatcher: {len(self.original_keywords)} keywords → "
            f"{len(self.keywords)} variants"
        )

    def find_in_text(self, text: str) -> List[str]:
        """Encuentra todas las keywords presentes en el texto."""
        if not text:
            return []

        found = set()
        for match in self.pattern.finditer(text):
            found.add(match.group(1).lower())

        return list(found)

    def keyword_in_title(self, title: str, keyword: str) -> bool:
        """Verifica si una keyword específica (o sus variantes) está en el título."""
        if not title:
            return False
        # Expand keyword via families before building regex
        variants = self.families.get(keyword.lower(), {keyword.lower()})
        escaped = "|".join(re.escape(v) for v in variants)
        return bool(re.search(r"\b(?:" + escaped + r")\b", title, re.IGNORECASE))

    def extract_context(self, text: str, keyword: str) -> str:
        """Extrae contexto alrededor de la keyword (o sus variantes)."""
        if not text:
            return ""

        # Expand keyword via families before building regex
        variants = self.families.get(keyword.lower(), {keyword.lower()})
        escaped_vars = "|".join(re.escape(v) for v in variants)
        pattern = re.compile(r"\b(?:" + escaped_vars + r")\b", re.IGNORECASE)
        match = pattern.search(text)

        if not match:
            return ""

        # Extraer contexto inteligente
        start = max(0, match.start() - 150)
        end = min(len(text), match.end() + 150)

        # Ajustar a límites de oración
        sentence_chars = ".!?"

        for i in range(match.start() - 1, start, -1):
            if text[i] in sentence_chars:
                start = i + 1
                break

        for i in range(match.end(), end):
            if text[i] in sentence_chars:
                end = i + 1
                break

        contexto = text[start:end].strip()

        if start > 0:
            contexto = "..." + contexto
        if end < len(text):
            contexto = contexto + "..."

        if len(contexto) > 400:
            contexto = contexto[:400] + "..."

        return contexto

    def calculate_relevance(
        self, keyword: str, title: Optional[str], content: str, is_article_content: bool
    ) -> Tuple[int, bool, bool]:
        """
        Calcula el puntaje de relevancia.
        Retorna (score, found_in_title, found_in_content).
        """
        in_title = self.keyword_in_title(title, keyword) if title else False
        in_content = bool(self.find_in_text(content))

        if in_title and in_content:
            score = RelevanceLevel.TITLE_AND_CONTENT
        elif in_title:
            score = RelevanceLevel.TITLE
        elif in_content and is_article_content:
            score = RelevanceLevel.CONTENT
        elif in_content:
            score = RelevanceLevel.PAGE_ONLY
        else:
            score = RelevanceLevel.NONE

        return score, in_title, in_content


class WebScraper:
    """Scraper principal con filtrado inteligente."""

    def __init__(self, keywords: List[str]):
        self.keyword_matcher = KeywordMatcher(keywords)
        self.url_filter = URLFilter()
        self.content_extractor = ContentExtractor()
        self.processed_urls: Set[str] = set()
        self.processed_pairs: Set[tuple] = set()
        self.stats = ScrapingStats()
        self.pais_actual: Optional[str] = None  # Se establece desde scheduler
        self.categoria_actual: Optional[str] = None  # Se establece desde scheduler

    def initialize(self) -> None:
        """Carga URLs ya procesadas desde la base de datos."""
        self.processed_urls = ScrapingRepository.get_processed_urls()
        self.processed_pairs = ScrapingRepository.get_processed_url_keyword_pairs()
        logger.info(f"Cargadas {len(self.processed_urls)} URLs ya procesadas")

    def _is_valid_url(self, url: str, base_domain: str) -> bool:
        """Verifica si una URL es válida para procesar."""
        if not url:
            return False

        invalid_prefixes = ("#", "javascript:", "mailto:", "tel:", "data:", "file:")
        if any(url.startswith(prefix) for prefix in invalid_prefixes):
            return False

        if len(url) > settings.scraper.max_url_length:
            return False

        try:
            parsed = urlparse(url)

            if parsed.scheme and parsed.scheme not in ("http", "https"):
                return False

            if parsed.netloc and base_domain not in parsed.netloc:
                return False

            non_html_extensions = (
                ".pdf",
                ".jpg",
                ".jpeg",
                ".png",
                ".gif",
                ".webp",
                ".svg",
                ".zip",
                ".rar",
                ".exe",
                ".dmg",
                ".mp3",
                ".mp4",
                ".avi",
                ".doc",
                ".docx",
                ".xls",
                ".xlsx",
                ".ppt",
                ".pptx",
            )
            if parsed.path.lower().endswith(non_html_extensions):
                return False

            return True

        except (ValueError, AttributeError) as e:
            logger.debug(f"URL inválida '{url[:50]}...': {e}")
            return False

    def _get_links(self, driver: WebDriver, website: dict) -> List[str]:
        """Extrae enlaces válidos de la página, priorizando artículos."""
        base_url = website["url"]
        base_domain = urlparse(base_url).netloc
        selector = website.get("selector_links")

        try:
            # Contar enlaces antes del scroll
            initial_elements = driver.find_elements(By.TAG_NAME, "a")
            logger.debug(f"Enlaces antes de scroll: {len(initial_elements)}")

            # Hacer scroll para cargar más contenido (lazy loading)
            # MAX_SCROLL_ATTEMPTS y SCROLL_WAIT_SECONDS configurables desde .env
            logger.debug("Haciendo scroll para cargar contenido dinamico...")
            last_height = driver.execute_script("return document.body.scrollHeight")

            for scroll_attempt in range(settings.scraper.max_scroll_attempts):
                driver.execute_script("window.scrollTo(0, document.body.scrollHeight);")
                time.sleep(settings.scraper.scroll_wait_seconds)

                new_height = driver.execute_script("return document.body.scrollHeight")
                if new_height == last_height:
                    logger.debug(
                        f"  Scroll detenido en intento {scroll_attempt + 1}: sin nuevo contenido"
                    )
                    break
                last_height = new_height

            # Volver arriba
            driver.execute_script("window.scrollTo(0, 0);")
            time.sleep(1)

            if selector:
                elements = driver.find_elements(By.CSS_SELECTOR, selector)
                logger.info(f"Usando selector personalizado: {selector}")
            else:
                elements = driver.find_elements(By.TAG_NAME, "a")

            logger.info(f"Total elementos <a> encontrados: {len(elements)}")

            article_links = []
            other_links = []

            for element in elements:
                try:
                    href = element.get_attribute("href")
                    if href:
                        full_url = urljoin(base_url, href)
                        if self._is_valid_url(full_url, base_domain):
                            # Clasificar URL
                            should_process, reason = self.url_filter.should_process(
                                full_url
                            )
                            if should_process:
                                if self.url_filter.looks_like_article(full_url):
                                    article_links.append(full_url)
                                else:
                                    other_links.append(full_url)
                            else:
                                self.stats.links_skipped += 1

                except StaleElementReferenceException:
                    continue

            # Priorizar artículos, luego otros enlaces
            combined = article_links + other_links
            all_links = list(
                dict.fromkeys(combined)
            )  # Eliminar duplicados manteniendo orden

            duplicates_removed = len(combined) - len(all_links)
            logger.info(
                f"Enlaces encontrados: {len(article_links)} artículos, {len(other_links)} otros"
            )
            if duplicates_removed > 0:
                logger.info(f"Duplicados eliminados: {duplicates_removed}")
            logger.info(f"Enlaces únicos: {len(all_links)}")

            # Mostrar primeros enlaces para diagnóstico
            if all_links:
                logger.info(f"Primeros 10 enlaces: ")
                for i, link in enumerate(all_links[:10]):
                    logger.info(f"  {i + 1}. {link[:80]}...")

            return all_links

        except WebDriverException as e:
            logger.warning(f"Error extrayendo enlaces de {base_url}: {e}")
            return []

    def _apply_rate_limit(self) -> None:
        """Aplica delay aleatorio entre requests."""
        delay = random.uniform(
            settings.scraper.request_delay_min, settings.scraper.request_delay_max
        )
        time.sleep(delay)

    def _process_link_fast(self, link: str, website: dict) -> List[ScrapingResult]:
        """
        MODO RÁPIDO: Procesa un enlace usando requests (sin Selenium).
        Mucho más rápido (~1-2 seg vs ~30-60 seg).
        Solo extrae título, ideal para require_keyword_in_title=true.
        """
        results = []

        if link in self.processed_urls:
            return results

        try:
            self._apply_rate_limit()

            # Hacer request HTTP (mucho más rápido que Selenium)
            # timeout=(connect, read) segun buenas practicas de requests
            response = requests.get(
                link, headers=REQUEST_HEADERS, timeout=(5, 20), allow_redirects=True
            )
            response.raise_for_status()

            # Parsear HTML
            soup = BeautifulSoup(response.text, "html.parser")

            # Eliminar elementos que no son contenido principal
            for unwanted in soup.select(
                "nav, header, footer, aside, .sidebar, .menu, .navigation, .breadcrumb"
            ):
                unwanted.decompose()

            # Extraer título - múltiples estrategias
            title = None
            method_used = ""

            # 1. Buscar por selectores CSS configurados
            for selector in settings.filter.title_selectors:
                try:
                    element = soup.select_one(selector)
                    if element and element.get_text(strip=True):
                        title = element.get_text(strip=True)
                        if len(title) >= settings.filter.min_title_length:
                            method_used = f"selector: {selector}"
                            break
                        title = None
                except Exception:
                    continue

            # 2. Buscar por meta og:title (muy confiable)
            if not title:
                og_title = soup.find("meta", property="og:title")
                if og_title and og_title.get("content"):
                    title = og_title["content"].strip()
                    if len(title) >= settings.filter.min_title_length:
                        method_used = "meta og:title"

            # 3. Buscar por meta twitter:title
            if not title:
                twitter_title = soup.find("meta", attrs={"name": "twitter:title"})
                if twitter_title and twitter_title.get("content"):
                    title = twitter_title["content"].strip()
                    if len(title) >= settings.filter.min_title_length:
                        method_used = "meta twitter:title"

            # 4. Buscar por role="heading" aria-level="1"
            if not title:
                heading = soup.find(attrs={"role": "heading", "aria-level": "1"})
                if heading and heading.get_text(strip=True):
                    title = heading.get_text(strip=True)
                    if len(title) >= settings.filter.min_title_length:
                        method_used = "role=heading"

            # 5. Buscar h1 dentro de article o main
            if not title:
                for container in [
                    "article",
                    "main",
                    ".post",
                    ".article",
                    ".content",
                    ".entry",
                ]:
                    container_el = soup.select_one(container)
                    if container_el:
                        h1 = container_el.find("h1")
                        if h1 and h1.get_text(strip=True):
                            title = h1.get_text(strip=True)
                            if len(title) >= settings.filter.min_title_length:
                                method_used = f"h1 en {container}"
                                break
                            title = None

            # 6. Buscar cualquier h1 (pero evitar los que son nombres de categorías)
            if not title:
                h1_elements = soup.find_all("h1")
                for h1 in h1_elements:
                    h1_text = h1.get_text(strip=True)
                    # Evitar títulos muy cortos (probablemente categorías)
                    if len(h1_text) >= 25:  # Un título real tiene más de 25 caracteres
                        title = h1_text
                        method_used = "h1 largo"
                        break

            # 7. Fallback: tag <title> limpio
            if not title:
                title_tag = soup.find("title")
                if title_tag:
                    title = title_tag.get_text(strip=True)
                    # Limpiar sufijos como "| Nombre del Sitio"
                    title = re.split(r"\s*[\|\-–—]\s*", title)[0].strip()
                    if len(title) >= settings.filter.min_title_length:
                        method_used = "tag <title>"

            if title:
                # Mostrar título más largo para diagnóstico
                logger.info(f"    Título: {title[:80]}...")
                if method_used:
                    logger.debug(f"    (método: {method_used})")
            else:
                logger.info(f"    Título: (no encontrado)")
                self.processed_urls.add(link)
                return results

            # Buscar keywords en título
            title_keywords = self.keyword_matcher.find_in_text(title)

            # LOG de diagnóstico: mostrar qué keywords se buscaron
            if not title_keywords:
                logger.debug(f"    Sin coincidencias en: {title[:80]}")

            # Extraer contenido del artículo para contexto de Gemini
            article_content = ""
            if title_keywords:
                for sel in settings.filter.article_selectors:
                    try:
                        el = soup.select_one(sel)
                        if el:
                            article_content = el.get_text(separator=" ", strip=True)
                            break
                    except Exception:
                        continue
                if not article_content:
                    # Fallback: texto del body completo
                    body = soup.find("body")
                    if body:
                        article_content = body.get_text(separator=" ", strip=True)
                # Limpiar espacios múltiples
                article_content = re.sub(r"\s+", " ", article_content).strip()

            for keyword in title_keywords:
                if (link, keyword) in self.processed_pairs:
                    continue

                # Construir contexto real: título + contenido (max 500 chars)
                contexto_real = title
                if article_content:
                    contexto_real = f"{title}\n\n{article_content[:500]}"

                # En modo rápido, si está en título = relevancia alta
                results.append(
                    ScrapingResult(
                        url=link,
                        keyword=keyword,
                        sitio_id=website["id"],
                        titulo=title,
                        contexto=contexto_real,
                        relevance_score=RelevanceLevel.TITLE,
                        found_in_title=True,
                        found_in_content=False,
                    )
                )

                self.processed_pairs.add((link, keyword))
                self.stats.high_relevance += 1

                logger.info(f"    [OK] '{keyword}' en TITULO")

            self.processed_urls.add(link)

        except requests.Timeout:
            logger.warning(f"    ⏱️ Timeout en {link[:50]}...")
        except requests.RequestException as e:
            logger.debug(f"    Error HTTP: {e}")
        except Exception as e:
            logger.debug(f"    Error: {e}")

        return results

    def _process_link(
        self, driver: WebDriver, link: str, website: dict, retries: Optional[int] = None
    ) -> List[ScrapingResult]:
        """Procesa un enlace con filtrado inteligente."""
        if retries is None:
            retries = settings.scraper.max_retries

        results = []

        if link in self.processed_urls:
            return results

        for attempt in range(retries):
            try:
                self._apply_rate_limit()

                driver.get(link)
                WebDriverWait(driver, settings.scraper.element_wait_timeout).until(
                    EC.presence_of_element_located((By.TAG_NAME, "body"))
                )

                # Extraer título
                title = self.content_extractor.get_title(driver)
                if title:
                    logger.info(f"    Título: {title[:60]}...")
                else:
                    logger.info(f"    Título: (no encontrado)")

                # Extraer contenido principal
                content, is_article = self.content_extractor.get_article_content(driver)

                # Buscar keywords
                found_keywords = self.keyword_matcher.find_in_text(content)

                # También buscar en título
                if title:
                    title_keywords = self.keyword_matcher.find_in_text(title)
                    found_keywords = list(set(found_keywords + title_keywords))

                for keyword in found_keywords:
                    if (link, keyword) in self.processed_pairs:
                        continue

                    # Calcular relevancia
                    score, in_title, in_content = (
                        self.keyword_matcher.calculate_relevance(
                            keyword, title, content, is_article
                        )
                    )

                    # Filtrar por relevancia mínima
                    if score < settings.filter.min_relevance_score:
                        logger.debug(
                            f"Resultado descartado (relevancia {score}): '{keyword}' en {link[:50]}..."
                        )
                        continue

                    # Si se requiere keyword en título, verificar
                    if settings.filter.require_keyword_in_title and not in_title:
                        logger.debug(
                            f"Resultado descartado (no está en título): '{keyword}' en {link[:50]}..."
                        )
                        continue

                    # Extraer contexto
                    contexto = self.keyword_matcher.extract_context(content, keyword)

                    results.append(
                        ScrapingResult(
                            url=link,
                            keyword=keyword,
                            sitio_id=website["id"],
                            titulo=title,
                            contexto=contexto,
                            relevance_score=score,
                            found_in_title=in_title,
                            found_in_content=in_content,
                        )
                    )

                    self.processed_pairs.add((link, keyword))

                    if in_title:
                        self.stats.high_relevance += 1

                self.processed_urls.add(link)
                break

            except TimeoutException:
                if attempt < retries - 1:
                    delay = settings.scraper.retry_delay * (
                        settings.scraper.retry_backoff**attempt
                    )
                    logger.warning(
                        f"Timeout en {link}, reintento {attempt + 1}/{retries}"
                    )
                    time.sleep(delay)
                else:
                    results.append(
                        ScrapingResult(
                            url=link,
                            keyword="",
                            sitio_id=website["id"],
                            success=False,
                            error="Timeout",
                        )
                    )

            except WebDriverException as e:
                logger.error(f"Error WebDriver en {link}: {e}")
                results.append(
                    ScrapingResult(
                        url=link,
                        keyword="",
                        sitio_id=website["id"],
                        success=False,
                        error=str(e)[:200],
                    )
                )
                break

            except Exception as e:
                logger.error(f"Error inesperado en {link}: {type(e).__name__}: {e}")
                results.append(
                    ScrapingResult(
                        url=link,
                        keyword="",
                        sitio_id=website["id"],
                        success=False,
                        error=f"{type(e).__name__}: {str(e)[:150]}",
                    )
                )
                break

        return results

    def process_website(self, website: dict) -> List[ScrapingResult]:
        """Procesa un sitio web completo."""
        results = []
        url = website["url"]
        nombre = website.get("nombre", url)

        logger.info(f"Procesando sitio: {nombre}")
        driver = None

        try:
            driver = WebDriverManager.create_driver()

            # Primera carga
            logger.info(f"Cargando página: {url}")
            driver.get(url)
            WebDriverWait(driver, settings.scraper.element_wait_timeout).until(
                EC.presence_of_element_located((By.TAG_NAME, "a"))
            )

            # REFRESH para sitios con carga dinámica (como eju.tv)
            logger.info("Refrescando página para cargar contenido completo...")
            time.sleep(2)  # Esperar un poco antes del refresh
            driver.refresh()
            time.sleep(3)  # Esperar a que cargue después del refresh

            WebDriverWait(driver, settings.scraper.element_wait_timeout).until(
                EC.presence_of_element_located((By.TAG_NAME, "a"))
            )

            links = self._get_links(driver, website)

            # Filtrar ya procesados
            already_processed = [l for l in links if l in self.processed_urls]
            links_to_process = [l for l in links if l not in self.processed_urls]

            logger.info(f"Enlaces únicos: {len(links)}")
            if already_processed:
                logger.info(f"Ya procesados (saltados): {len(already_processed)}")

            # Aplicar límite
            max_links = settings.scraper.max_links_per_site
            if len(links_to_process) > max_links:
                logger.info(f"Limitando a {max_links} enlaces")
                links_to_process = links_to_process[:max_links]

            logger.info(f"Total enlaces a procesar: {len(links_to_process)}")
            logger.info("=" * 40)

            if not links_to_process:
                logger.info("No hay enlaces nuevos para procesar")

            # Decidir qué modo usar
            use_fast = (
                settings.filter.use_fast_mode
                and settings.filter.require_keyword_in_title
            )

            if use_fast:
                logger.info("Usando MODO RAPIDO (requests)")
                # En modo rápido, cerramos Selenium y usamos requests
                self._safe_quit_driver(driver)
                driver = None

                for index, link in enumerate(links_to_process):
                    logger.info(f"[{index + 1}/{len(links_to_process)}] {link[:65]}...")
                    link_results = self._process_link_fast(link, website)
                    results.extend(link_results)
                    self.stats.links_processed += 1
            else:
                logger.info("Usando modo Selenium (completo)")
                for index, link in enumerate(links_to_process):
                    logger.info(f"[{index + 1}/{len(links_to_process)}] {link[:65]}...")

                    if (
                        index > 0
                        and index % settings.scraper.links_before_driver_restart == 0
                    ):
                        logger.info(f"Reiniciando driver...")
                        self._safe_quit_driver(driver)
                        driver = WebDriverManager.create_driver()

                    link_results = self._process_link(driver, link, website)
                    results.extend(link_results)
                    self.stats.links_processed += 1

                    # Mostrar si encontró algo
                    found = [r for r in link_results if r.success and r.keyword]
                    if found:
                        for r in found:
                            status = "TITULO" if r.found_in_title else "contenido"
                            logger.info(f"    [OK] '{r.keyword}' en {status}")

            self.stats.sites_processed += 1

        except TimeoutException:
            logger.error(f"Timeout al cargar sitio principal: {url}")
            self.stats.errors += 1

        except WebDriverException as e:
            logger.error(f"Error WebDriver en sitio {url}: {e}")
            self.stats.errors += 1

        except Exception as e:
            logger.error(f"Error inesperado en {url}: {type(e).__name__}: {e}")
            self.stats.errors += 1

        finally:
            self._safe_quit_driver(driver)

        return results

    def _safe_quit_driver(self, driver: Optional[WebDriver]) -> None:
        """Cierra el driver de forma segura."""
        if driver:
            try:
                driver.quit()
            except WebDriverException:
                pass

    def run(self, websites: List[dict]) -> ScrapingStats:
        """Ejecuta el scraping en todos los sitios."""
        self.initialize()

        pais_display = self.pais_actual or "TODOS"
        cat_display = self.categoria_actual or "TODAS"
        logger.info(
            f"Iniciando scraping de {len(websites)} sitios - País: {pais_display} | Categoría: {cat_display}"
        )
        logger.info(f"Keywords a buscar: {self.keyword_matcher.keywords}")
        logger.info(
            f"Filtros activos: require_title={settings.filter.require_keyword_in_title}, "
            f"min_relevance={settings.filter.min_relevance_score}"
        )

        for website in websites:
            # Obtener país del sitio web o usar el configurado
            pais = website.get("pais") or self.pais_actual or "BO"

            results = self.process_website(website)

            # Guardar resultados exitosos
            successful_results = [
                {
                    "url": r.url,
                    "keyword": r.keyword,
                    "sitio_id": r.sitio_id,
                    "titulo": r.titulo,
                    "contexto": r.contexto,
                    "relevance_score": r.relevance_score,
                    "found_in_title": r.found_in_title,
                    "pais": pais,
                    "categoria": self.categoria_actual,
                }
                for r in results
                if r.success and r.keyword
            ]

            if successful_results:
                try:
                    inserted = ScrapingRepository.save_results_batch(successful_results)
                    self.stats.results_found += inserted
                except Exception as e:
                    logger.error(f"Error guardando resultados: {e}")
                    self.stats.errors += 1

            self.stats.errors += sum(1 for r in results if not r.success)

        # Registrar ejecución
        try:
            ScrapingRepository.log_scrape_execution(
                sitios_procesados=self.stats.sites_processed,
                resultados_encontrados=self.stats.results_found,
                errores=self.stats.errors,
                duracion_segundos=self.stats.duration_seconds,
            )
        except Exception as e:
            logger.error(f"Error registrando ejecución: {e}")

        logger.info(f"Scraping completado. {self.stats}")

        return self.stats


class ParallelWebScraper(WebScraper):
    """Scraper con procesamiento paralelo de sitios."""

    def __init__(self, keywords: List[str], max_workers: int = 3):
        super().__init__(keywords)
        self.max_workers = max_workers

    def run(self, websites: List[dict]) -> ScrapingStats:
        """Ejecuta el scraping en paralelo."""
        self.initialize()

        logger.info(f"Iniciando scraping paralelo ({self.max_workers} workers)")

        all_results = []

        with ThreadPoolExecutor(max_workers=self.max_workers) as executor:
            future_to_website = {
                executor.submit(self.process_website, website): website
                for website in websites
            }

            for future in as_completed(future_to_website):
                website = future_to_website[future]
                try:
                    results = future.result()
                    all_results.extend(results)
                except Exception as e:
                    logger.error(f"Error en {website.get('nombre')}: {e}")
                    self.stats.errors += 1

        # Guardar resultados
        successful_results = [
            {
                "url": r.url,
                "keyword": r.keyword,
                "sitio_id": r.sitio_id,
                "titulo": r.titulo,
                "contexto": r.contexto,
                "relevance_score": r.relevance_score,
                "found_in_title": r.found_in_title,
            }
            for r in all_results
            if r.success and r.keyword
        ]

        if successful_results:
            try:
                inserted = ScrapingRepository.save_results_batch(successful_results)
                self.stats.results_found = inserted
            except Exception as e:
                logger.error(f"Error guardando resultados: {e}")
                self.stats.errors += 1

        self.stats.errors += sum(1 for r in all_results if not r.success)

        try:
            ScrapingRepository.log_scrape_execution(
                sitios_procesados=self.stats.sites_processed,
                resultados_encontrados=self.stats.results_found,
                errores=self.stats.errors,
                duracion_segundos=self.stats.duration_seconds,
            )
        except Exception as e:
            logger.error(f"Error registrando ejecución: {e}")

        logger.info(f"Scraping completado. {self.stats}")

        return self.stats
