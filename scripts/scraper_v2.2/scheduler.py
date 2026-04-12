"""
Scheduler v2.2.0 para ejecutar el scraper periódicamente.
"""

import signal
import sys
import threading
import time
from datetime import datetime, timedelta
from typing import Optional

from config.settings import settings
from core.database import DatabaseManager, ScrapingRepository
from core.scraper import WebScraper, ParallelWebScraper
from utils.logger import get_logger

logger = get_logger(__name__)


class ScraperScheduler:
    """Scheduler para ejecutar el scraper a intervalos regulares."""

    def __init__(
        self, use_parallel: bool = False, max_workers: int = 3, pais: str = None
    ):
        self._running = False
        self._stop_event = threading.Event()
        self.use_parallel = use_parallel
        self.max_workers = max_workers
        self.pais = pais  # None = todos, o código específico (BO, HN, etc.)

    def _setup_signal_handlers(self) -> None:
        """Configura manejadores de señales para shutdown graceful."""

        def signal_handler(signum, frame):
            logger.info(f"Señal {signum} recibida, iniciando shutdown...")
            self._running = False
            self._stop_event.set()

        signal.signal(signal.SIGINT, signal_handler)
        if sys.platform != "win32":
            signal.signal(signal.SIGTERM, signal_handler)

    def _create_scraper(self, keywords: list) -> WebScraper:
        """Crea la instancia de scraper apropiada."""
        if self.use_parallel:
            return ParallelWebScraper(keywords, max_workers=self.max_workers)
        return WebScraper(keywords)

    def run_continuous_all_countries(self, interval_hours: float = 2.0) -> None:
        """
        Bucle infinito: procesa todos los países y categorías secuencialmente,
        luego espera X horas y repite.

        Orden: Bolivia PEP → Bolivia OPI → Honduras PEP → Honduras OPI → ...

        Args:
            interval_hours: Horas de espera entre ciclos completos
        """
        self._setup_signal_handlers()
        self._running = True

        logger.info(f"🔄 Iniciando bucle continuo para TODOS los países y categorías")
        logger.info(f"⏱️  Espera entre ciclos: {interval_hours} horas")

        ciclo = 0
        while self._running:
            ciclo += 1
            inicio_ciclo = datetime.now()

            logger.info("=" * 60)
            logger.info(
                f"🌎 CICLO #{ciclo} - Inicio: {inicio_ciclo.strftime('%H:%M:%S')}"
            )
            logger.info("=" * 60)

            try:
                # Obtener países y categorías activos
                paises = ScrapingRepository.get_paises_activos()
                categorias = ScrapingRepository.get_categorias_activas()

                if not paises:
                    logger.warning("No hay países configurados")
                    self._wait_for_next_cycle(interval_hours)
                    continue

                if not categorias:
                    logger.warning(
                        "No hay categorías configuradas, usando todas las keywords"
                    )
                    categorias = [None]  # None = todas las categorías

                total_combinaciones = len(paises) * len(categorias)
                contador = 0

                # Procesar cada país
                for p in paises:
                    if not self._running:
                        break

                    # Procesar cada categoría dentro del país
                    for cat in categorias:
                        if not self._running:
                            break

                        contador += 1
                        cat_display = cat if cat else "TODAS"

                        logger.info("")
                        logger.info(
                            f"🏳️  [{contador}/{total_combinaciones}] {p['nombre']} ({p['codigo']}) - {cat_display}"
                        )
                        logger.info("-" * 40)

                        self.run_scraping_cycle(
                            pais_override=p["codigo"], categoria_override=cat
                        )

                # Calcular duración del ciclo
                fin_ciclo = datetime.now()
                duracion = fin_ciclo - inicio_ciclo

                logger.info("")
                logger.info("=" * 60)
                logger.info(f"✅ CICLO #{ciclo} COMPLETADO")
                logger.info(f"   Duración: {duracion}")
                logger.info(f"   Países: {len(paises)} | Categorías: {len(categorias)}")
                logger.info("=" * 60)

                # Esperar antes del siguiente ciclo
                if self._running:
                    self._wait_for_next_cycle(interval_hours)

            except Exception as e:
                logger.error(f"Error en ciclo #{ciclo}: {e}")
                if self._running:
                    logger.info("Reintentando en 30 minutos...")
                    self._stop_event.wait(1800)  # 30 min

        logger.info("🛑 Bucle detenido")

    def _wait_for_next_cycle(self, hours: float) -> None:
        """Espera hasta el siguiente ciclo mostrando cuenta regresiva."""
        if not self._running:
            return

        proxima = datetime.now() + timedelta(hours=hours)

        logger.info("")
        logger.info(f"💤 Esperando {hours} horas...")
        logger.info(f"   Próximo ciclo: {proxima.strftime('%H:%M:%S')}")
        logger.info(f"   (Presiona Ctrl+C para detener)")

        # Esperar en intervalos de 5 minutos para poder mostrar progreso
        segundos_totales = int(hours * 3600)
        segundos_restantes = segundos_totales

        while segundos_restantes > 0 and self._running:
            # Esperar máximo 5 minutos o lo que quede
            espera = min(300, segundos_restantes)

            if self._stop_event.wait(espera):
                # Se solicitó parar
                break

            segundos_restantes -= espera

            if segundos_restantes > 0 and self._running:
                minutos = segundos_restantes // 60
                logger.info(f"   ⏳ {minutos} minutos restantes...")

    def run_scraping_cycle(
        self, pais_override: str = None, categoria_override: str = None
    ) -> bool:
        """
        Ejecuta un ciclo completo de scraping.

        Args:
            pais_override: Código de país para este ciclo (override del constructor)
            categoria_override: Categoría (PEP, OPI) para este ciclo

        Retorna True si fue exitoso.
        """
        pais = pais_override or self.pais
        categoria = categoria_override

        pais_display = pais if pais else "TODOS"
        cat_display = categoria if categoria else "TODAS"

        logger.info("=" * 60)
        logger.info(
            f"INICIANDO SCRAPING - País: {pais_display} | Categoría: {cat_display}"
        )
        logger.info("=" * 60)

        log_id = ScrapingRepository.log_scraper_inicio()

        try:
            if not DatabaseManager.health_check():
                logger.error("No se puede conectar a la base de datos")
                ScrapingRepository.log_scraper_fin(
                    log_id, "error", mensaje_error="No se puede conectar a la BD"
                )
                return False

            keywords = ScrapingRepository.get_keywords(pais, categoria)
            websites = ScrapingRepository.get_websites(pais)

            if not keywords:
                logger.warning(
                    f"No hay palabras clave configuradas para {pais_display}/{cat_display}"
                )
                ScrapingRepository.log_scraper_fin(
                    log_id, "completado", items_procesados=0, items_resultado=0
                )
                return True

            if not websites:
                logger.warning(f"No hay sitios web configurados para {pais_display}")
                ScrapingRepository.log_scraper_fin(
                    log_id, "completado", items_procesados=0, items_resultado=0
                )
                return True

            logger.info(f"País: {pais_display} | Categoría: {cat_display}")
            logger.info(f"Keywords: {len(keywords)} | Sitios: {len(websites)}")
            logger.info(
                f"Filtro: keyword en título = {settings.filter.require_keyword_in_title}"
            )

            scraper = self._create_scraper(keywords)
            scraper.pais_actual = pais  # Pasar el país al scraper
            scraper.categoria_actual = categoria  # Pasar la categoría al scraper
            stats = scraper.run(websites)

            logger.info("=" * 60)
            logger.info(f"COMPLETADO [{pais_display}/{cat_display}]: {stats}")
            logger.info("=" * 60)

            # Registrar fin exitoso en log_scripts
            ScrapingRepository.log_scraper_fin(
                log_id,
                estado="completado",
                items_procesados=stats.sites_processed,
                items_resultado=stats.results_found,
                errores=stats.errors,
            )

            return True

        except Exception as e:
            logger.exception(f"Error en ciclo de scraping: {e}")
            ScrapingRepository.log_scraper_fin(
                log_id,
                estado="error",
                mensaje_error=str(e),
            )
            return False

    def run_continuous(self) -> None:
        """Ejecuta el scraper continuamente a intervalos definidos."""
        self._running = True
        self._setup_signal_handlers()

        interval_hours = settings.scraper.scrape_interval_hours
        interval_seconds = interval_hours * 3600

        logger.info(f"Scheduler iniciado. Intervalo: {interval_hours} horas")

        consecutive_failures = 0
        max_consecutive_failures = 3

        while self._running:
            try:
                success = self.run_scraping_cycle()

                if success:
                    consecutive_failures = 0
                else:
                    consecutive_failures += 1
                    if consecutive_failures >= max_consecutive_failures:
                        logger.error(
                            f"Demasiados fallos consecutivos ({consecutive_failures})"
                        )

                if not self._running:
                    break

                if consecutive_failures > 0:
                    wait_seconds = min(60 * consecutive_failures, interval_seconds)
                else:
                    wait_seconds = interval_seconds

                next_run = datetime.now() + timedelta(seconds=wait_seconds)
                logger.info(
                    f"Próxima ejecución: {next_run.strftime('%Y-%m-%d %H:%M:%S')}"
                )

                self._stop_event.wait(timeout=wait_seconds)
                self._stop_event.clear()

            except Exception as e:
                logger.exception(f"Error en scheduler: {e}")
                self._stop_event.wait(timeout=60)
                self._stop_event.clear()

        logger.info("Scheduler detenido")

    def run_once(self) -> bool:
        """Ejecuta el scraper una sola vez."""
        return self.run_scraping_cycle()

    def stop(self) -> None:
        """Detiene el scheduler de forma segura."""
        logger.info("Solicitando detención del scheduler...")
        self._running = False
        self._stop_event.set()


def main_continuous(use_parallel: bool = False, max_workers: int = 3):
    """Punto de entrada para ejecución continua."""
    scheduler = ScraperScheduler(use_parallel=use_parallel, max_workers=max_workers)
    try:
        scheduler.run_continuous()
    finally:
        DatabaseManager.close_pool()


def main_once(use_parallel: bool = False, max_workers: int = 3) -> bool:
    """Punto de entrada para ejecución única."""
    scheduler = ScraperScheduler(use_parallel=use_parallel, max_workers=max_workers)
    try:
        return scheduler.run_once()
    finally:
        DatabaseManager.close_pool()


if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(description="Web Scraper Scheduler v2.2")
    parser.add_argument("--once", action="store_true", help="Ejecutar solo una vez")
    parser.add_argument("--parallel", action="store_true", help="Modo paralelo")
    parser.add_argument(
        "--workers", type=int, default=3, help="Workers para modo paralelo"
    )

    args = parser.parse_args()

    try:
        if args.once:
            success = main_once(use_parallel=args.parallel, max_workers=args.workers)
            sys.exit(0 if success else 1)
        else:
            main_continuous(use_parallel=args.parallel, max_workers=args.workers)
    except KeyboardInterrupt:
        logger.info("Interrupción por teclado")
    finally:
        DatabaseManager.close_pool()
