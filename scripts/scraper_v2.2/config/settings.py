"""
Configuración centralizada del scraper v2.2.0
Incluye opciones de filtrado y relevancia.
"""

import os
from pathlib import Path
from dataclasses import dataclass, field
from typing import Optional, List
from dotenv import load_dotenv

# Cargar variables de entorno desde .env
load_dotenv()


def _parse_list(env_var: str, default: str) -> List[str]:
    """Parsea una variable de entorno como lista separada por comas."""
    value = os.getenv(env_var, default)
    return [item.strip() for item in value.split(",") if item.strip()]


@dataclass(frozen=True)
class DatabaseConfig:
    """Configuración de la base de datos (MySQL o PostgreSQL)."""

    db_type: str = field(default_factory=lambda: os.getenv("DB_TYPE", "mysql").lower())
    host: str = field(default_factory=lambda: os.getenv("DB_HOST", "localhost"))
    port: int = field(
        default_factory=lambda: int(
            os.getenv(
                "DB_PORT",
                "5432"
                if os.getenv("DB_TYPE", "mysql").lower() == "postgres"
                else "3306",
            )
        )
    )
    user: str = field(default_factory=lambda: os.getenv("DB_USER", "root"))
    password: str = field(default_factory=lambda: os.getenv("DB_PASSWORD", ""))
    database: str = field(default_factory=lambda: os.getenv("DB_NAME", "scraping_db"))
    pool_size: int = field(default_factory=lambda: int(os.getenv("DB_POOL_SIZE", "5")))
    pool_name: str = "scraper_pool"
    connection_timeout: int = 10

    def to_dict(self) -> dict:
        """Retorna configuración como diccionario para mysql.connector."""
        return {
            "host": self.host,
            "port": self.port,
            "user": self.user,
            "password": self.password,
            "database": self.database,
            "connection_timeout": self.connection_timeout,
        }


@dataclass(frozen=True)
class FilterConfig:
    """Configuración de filtrado y relevancia."""

    # Requerir keyword en título para guardar resultado
    require_keyword_in_title: bool = field(
        default_factory=lambda: (
            os.getenv("REQUIRE_KEYWORD_IN_TITLE", "true").lower() == "true"
        )
    )

    # Usar selector de artículo para extraer contenido principal
    use_article_selector: bool = field(
        default_factory=lambda: (
            os.getenv("USE_ARTICLE_SELECTOR", "true").lower() == "true"
        )
    )

    # Patrones de URL a excluir (navegación, categorías, etc.)
    exclude_url_patterns: List[str] = field(
        default_factory=lambda: _parse_list(
            "EXCLUDE_URL_PATTERNS",
            "/tag/,/tags/,/category/,/categorias/,/logout,/login,/register,/page/,/author/,/search,/buscar,/feed,/rss",
        )
    )

    # Patrones de URL que indican que ES un artículo (regex)
    article_url_patterns: List[str] = field(
        default_factory=lambda: _parse_list(
            "ARTICLE_URL_PATTERNS", "/20,/noticia,/articulo,/news/,/post/,/blog/"
        )
    )

    # Selectores CSS para contenido principal (en orden de prioridad)
    article_selectors: List[str] = field(
        default_factory=lambda: _parse_list(
            "ARTICLE_SELECTORS",
            "article,.post-content,.entry-content,.article-content,.article-body,.content-article,main article,.noticia-contenido,.nota-contenido",
        )
    )

    # Selectores CSS para título del artículo (IMPORTANTE: orden de prioridad)
    title_selectors: List[str] = field(
        default_factory=lambda: _parse_list(
            "TITLE_SELECTORS",
            "h1.entry-title,h1.articulo_titulo,h1.post-title,h1.article-title,h1.titulo,h1.title,article h1,.article-header h1,.entry-header h1,[role='heading'][aria-level='1'],h1",
        )
    )

    # Selectores a EXCLUIR del contenido (publicidad, sidebars, etc.)
    exclude_selectors: List[str] = field(
        default_factory=lambda: _parse_list(
            "EXCLUDE_SELECTORS",
            ".sidebar,.advertisement,.ad,.ads,.widget,.related-posts,.comments,.social-share,nav,header,footer,.menu,.nav,.breadcrumb",
        )
    )

    # Longitud mínima de título para considerar válido
    min_title_length: int = field(
        default_factory=lambda: int(os.getenv("MIN_TITLE_LENGTH", "20"))
    )

    # Puntaje mínimo de relevancia para guardar (0-100)
    min_relevance_score: int = field(
        default_factory=lambda: int(os.getenv("MIN_RELEVANCE_SCORE", "30"))
    )

    # MODO RÁPIDO: Usar requests en lugar de Selenium (mucho más rápido)
    # Solo funciona bien si require_keyword_in_title=true
    use_fast_mode: bool = field(
        default_factory=lambda: os.getenv("USE_FAST_MODE", "true").lower() == "true"
    )


@dataclass(frozen=True)
class ScraperConfig:
    """Configuración del scraper."""

    # Timeouts (reducidos para evitar cuelgues)
    page_load_timeout: int = 20
    element_wait_timeout: int = 10

    # Reintentos
    max_retries: int = 3
    retry_delay: float = 2.0
    retry_backoff: float = 2.0

    # Rate Limiting (valores reducidos para mejor UX)
    request_delay_min: float = field(
        default_factory=lambda: float(os.getenv("REQUEST_DELAY_MIN", "0.5"))
    )
    request_delay_max: float = field(
        default_factory=lambda: float(os.getenv("REQUEST_DELAY_MAX", "1.5"))
    )

    # Límites
    links_before_driver_restart: int = 15
    max_concurrent_sites: int = 3
    max_links_per_site: int = field(
        default_factory=lambda: int(os.getenv("MAX_LINKS_PER_SITE", "200"))
    )
    max_scroll_attempts: int = field(
        default_factory=lambda: int(os.getenv("MAX_SCROLL_ATTEMPTS", "5"))
    )
    scroll_wait_seconds: float = field(
        default_factory=lambda: float(os.getenv("SCROLL_WAIT_SECONDS", "1.5"))
    )
    max_url_length: int = 2000

    # Intervalos
    scrape_interval_hours: int = field(
        default_factory=lambda: int(os.getenv("SCRAPE_INTERVAL_HOURS", "2"))
    )

    # Rutas
    chromedriver_path: Optional[str] = field(
        default_factory=lambda: os.getenv("CHROMEDRIVER_PATH")
    )


@dataclass(frozen=True)
class LoggingConfig:
    """Configuración de logging."""

    base_dir: Path = field(default_factory=lambda: Path("logs"))
    level: str = field(default_factory=lambda: os.getenv("LOG_LEVEL", "INFO"))
    max_bytes: int = 10 * 1024 * 1024  # 10 MB
    backup_count: int = 5
    format: str = "%(asctime)s | %(levelname)-8s | %(name)s | %(message)s"
    date_format: str = "%Y-%m-%d %H:%M:%S"


@dataclass(frozen=True)
class Settings:
    """Configuración global de la aplicación."""

    db: DatabaseConfig = field(default_factory=DatabaseConfig)
    scraper: ScraperConfig = field(default_factory=ScraperConfig)
    filter: FilterConfig = field(default_factory=FilterConfig)
    logging: LoggingConfig = field(default_factory=LoggingConfig)

    # Metadatos
    app_name: str = "WebScraper"
    version: str = "2.2.0"
    environment: str = field(
        default_factory=lambda: os.getenv("ENVIRONMENT", "production")
    )


# Instancia global de configuración
settings = Settings()
