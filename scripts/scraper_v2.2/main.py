#!/usr/bin/env python3
"""
Web Scraper Profesional v2.2.0
==============================
Scraper con filtrado inteligente: prioriza keyword en titulo.

Uso:
    python main.py                    # Ejecutar continuamente
    python main.py --once             # Ejecutar una vez
    python main.py --no-title-filter  # Aceptar keyword en cualquier parte
    python main.py --status           # Ver estadisticas

Cambios v2.2.0:
    - Filtrado inteligente de URLs (excluye navegacion, categorias)
    - Prioriza resultados donde keyword esta en el TITULO
    - Extrae contenido principal del articulo (ignora sidebars, ads)
    - Sistema de puntaje de relevancia
"""

import sys
import os
import argparse
from pathlib import Path

ROOT_DIR = Path(__file__).parent
sys.path.insert(0, str(ROOT_DIR))


def print_banner():
    """Imprime banner de inicio."""
    print("=" * 61)
    print("       WEB SCRAPER PROFESIONAL v2.2.0")
    print("  Filtrado Inteligente - Keyword en Titulo")
    print("=" * 61)
    print()


def show_status(pais: str = None):
    """Muestra el estado actual y estadisticas con metricas de relevancia."""
    from config.settings import settings
    from core.database import ScrapingRepository, DatabaseManager
    from utils.logger import get_logger

    logger = get_logger(__name__)

    pais_display = pais.upper() if pais else "TODOS"

    print(f"\nESTADO DEL SISTEMA v2.2 - Pais: {pais_display}")
    print("=" * 55)

    try:
        print(f"\nConexion a BD: ", end="")
        if DatabaseManager.health_check():
            print("OK")
        else:
            print("FALLIDO")
            sys.exit(1)

        print(f"\nConfiguracion:")
        print(f"   Base de datos : {settings.db.host}/{settings.db.database}")
        print(f"   Intervalo     : {settings.scraper.scrape_interval_hours} horas")
        print(
            f"   Rate limit    : {settings.scraper.request_delay_min}-{settings.scraper.request_delay_max}s"
        )

        print(f"\nFiltros activos:")
        print(
            f"   Keyword en titulo : {'SI' if settings.filter.require_keyword_in_title else 'NO'}"
        )
        print(f"   Relevancia minima : {settings.filter.min_relevance_score}")
        print(
            f"   Selector articulo : {'SI' if settings.filter.use_article_selector else 'NO'}"
        )

        keywords = ScrapingRepository.get_keywords(pais.upper() if pais else None)
        websites = ScrapingRepository.get_websites(pais.upper() if pais else None)

        print(f"\nDatos configurados:")
        print(f"   Palabras clave activas : {len(keywords)}")
        print(f"   Sitios web activos     : {len(websites)}")

        if keywords:
            print(f"   Keywords: {', '.join(keywords[:5])}", end="")
            if len(keywords) > 5:
                print(f" (+{len(keywords) - 5} mas)")
            else:
                print()

        summary = ScrapingRepository.get_results_summary(days=7)

        print(f"\nResultados ultimos 7 dias:")
        if summary:
            total = sum(day["total"] for day in summary)
            alta_relevancia = sum(day.get("en_titulo", 0) for day in summary)

            print(f"   Total resultados             : {total}")
            print(
                f"   Alta relevancia (en titulo)  : {alta_relevancia} ({100 * alta_relevancia // max(total, 1)}%)"
            )

            print(f"\n   Por dia:")
            for day in summary[:5]:
                en_titulo = day.get("en_titulo", 0)
                print(f"   {day['fecha']}: {day['total']} total, {en_titulo} en titulo")
        else:
            print("   Sin resultados recientes")

        high_relevance = ScrapingRepository.get_high_relevance_results(days=3, limit=5)
        if high_relevance:
            print(f"\nUltimos resultados de ALTA relevancia:")
            for r in high_relevance:
                titulo = (r["titulo"] or "")[:50]
                print(f"   [{r['keyword']}] {titulo}...")

        DatabaseManager.close_pool()

    except Exception as e:
        print(f"\nError obteniendo estado: {e}")
        sys.exit(1)

    print("\n" + "=" * 55)


def run_scraper(
    once: bool = False,
    interval: int = None,
    parallel: bool = False,
    workers: int = 3,
    no_title_filter: bool = False,
    pais: str = None,
    loop_paises: bool = False,
    espera_ciclo: float = 2.0,
):
    """Ejecuta el scraper."""
    from scheduler import ScraperScheduler
    from core.database import DatabaseManager, ScrapingRepository
    from utils.logger import get_logger
    import time

    logger = get_logger(__name__)

    if interval:
        os.environ["SCRAPE_INTERVAL_HOURS"] = str(interval)

    if no_title_filter:
        os.environ["REQUIRE_KEYWORD_IN_TITLE"] = "false"
        os.environ["MIN_RELEVANCE_SCORE"] = "20"

    print_banner()

    from config.settings import settings

    mode = "paralelo" if parallel else "secuencial"

    # MODO BUCLE INFINITO TODOS LOS PAISES
    if loop_paises:
        print(f"   Modo            : BUCLE INFINITO - TODOS LOS PAISES")
        print(f"   Espera ciclos   : {espera_ciclo} horas")
        print(f"   Procesamiento   : {mode}")
        if parallel:
            print(f"   Workers         : {workers}")

        filter_status = "Desactivado" if no_title_filter else "Activado"
        print(f"   Filtro titulo   : {filter_status}")
        print()
        print("   Presiona Ctrl+C para detener\n")

        scheduler = ScraperScheduler(use_parallel=parallel, max_workers=workers)

        try:
            scheduler.run_continuous_all_countries(interval_hours=espera_ciclo)
        except KeyboardInterrupt:
            print("\nDetenido por el usuario")
        finally:
            DatabaseManager.close_pool()
            print("\nScraper finalizado correctamente")
        return

    pais_display = pais.upper() if pais else "TODOS"

    print(f"   Pais            : {pais_display}")
    print(f"   Modo            : {mode}")
    if parallel:
        print(f"   Workers         : {workers}")

    filter_status = "Desactivado" if no_title_filter else "Activado"
    print(f"   Filtro titulo   : {filter_status}")
    print()

    # Si pais es "todos", ejecutar secuencialmente cada pais (UNA VEZ)
    if pais and pais.lower() == "todos":
        try:
            paises = ScrapingRepository.get_paises_activos()
            if not paises:
                print("No hay paises configurados en la base de datos")
                return

            print(
                f"Ejecutando para {len(paises)} paises: {', '.join(p['codigo'] for p in paises)}\n"
            )

            for i, p in enumerate(paises):
                print(f"\n{'=' * 60}")
                print(
                    f"INICIANDO: {p['nombre']} ({p['codigo']}) [{i + 1}/{len(paises)}]"
                )
                print(f"{'=' * 60}\n")

                scheduler = ScraperScheduler(
                    use_parallel=parallel, max_workers=workers, pais=p["codigo"]
                )
                scheduler.run_once()

            print(f"\nTodos los paises procesados")

        except KeyboardInterrupt:
            print("\nDetenido por el usuario")
        except Exception as e:
            logger.exception(f"Error: {e}")
            print(f"\nError: {e}")
        finally:
            DatabaseManager.close_pool()
        return

    # Un solo pais o todos sin distincion
    scheduler = ScraperScheduler(
        use_parallel=parallel, max_workers=workers, pais=pais.upper() if pais else None
    )

    try:
        if once:
            print("Ejecutando scraping unico...\n")
            success = scheduler.run_once()
            if not success:
                print("\nEl scraping completo con errores")
        else:
            print("Iniciando scraper en modo continuo...\n")
            print(f"   Presiona Ctrl+C para detener\n")
            scheduler.run_continuous()

    except KeyboardInterrupt:
        print("\nDetenido por el usuario")
    except Exception as e:
        logger.exception(f"Error fatal: {e}")
        print(f"\nError fatal: {e}")
        sys.exit(1)
    finally:
        DatabaseManager.close_pool()
        print("\nScraper finalizado correctamente")


def main():
    """Punto de entrada principal."""
    parser = argparse.ArgumentParser(
        description="Web Scraper v2.2 - Multi-pais con filtrado inteligente",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Ejemplos:
  python main.py --once --pais BO          Solo Bolivia, una vez
  python main.py --once --pais todos       Todos los paises, una vez
  python main.py --loop-paises             Bucle infinito todos los paises (espera 2h)
  python main.py --loop-paises --espera 4  Bucle infinito (espera 4h entre ciclos)
  python main.py --pais BO                 Loop continuo, solo Bolivia
  python main.py --status                  Ver estadisticas
  python main.py --status --pais HN        Estadisticas de Honduras

Codigos de pais:
  BO = Bolivia       HN = Honduras      SV = El Salvador
  NI = Nicaragua     PY = Paraguay      GT = Guatemala
        """,
    )

    parser.add_argument(
        "--once", action="store_true", help="Ejecutar solo una vez y salir"
    )

    parser.add_argument(
        "--pais",
        type=str,
        metavar="CODIGO",
        help="Codigo de pais (BO, HN, SV, NI, PY, GT) o 'todos'",
    )

    parser.add_argument(
        "--loop-paises",
        action="store_true",
        help="Bucle infinito: procesa todos los paises, espera, repite",
    )

    parser.add_argument(
        "--espera",
        type=float,
        default=2.0,
        metavar="HORAS",
        help="Horas de espera entre ciclos completos (default: 2)",
    )

    parser.add_argument(
        "--interval",
        type=int,
        metavar="HORAS",
        help="Intervalo entre ejecuciones en horas (modo --pais sin --once)",
    )

    parser.add_argument(
        "--parallel", action="store_true", help="Usar procesamiento paralelo de sitios"
    )

    parser.add_argument(
        "--workers",
        type=int,
        default=3,
        metavar="N",
        help="Numero de workers para modo paralelo",
    )

    parser.add_argument(
        "--no-title-filter",
        action="store_true",
        help="Desactivar filtro de keyword en titulo (acepta cualquier coincidencia)",
    )

    parser.add_argument(
        "--status",
        action="store_true",
        help="Mostrar estado y estadisticas de relevancia",
    )

    parser.add_argument("--version", action="version", version="%(prog)s 2.2.0")

    args = parser.parse_args()

    if args.status:
        show_status(pais=args.pais)
    else:
        run_scraper(
            once=args.once,
            interval=args.interval,
            parallel=args.parallel,
            workers=args.workers,
            no_title_filter=args.no_title_filter,
            pais=args.pais,
            loop_paises=args.loop_paises,
            espera_ciclo=args.espera,
        )


if __name__ == "__main__":
    main()
