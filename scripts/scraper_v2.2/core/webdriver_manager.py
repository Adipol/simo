"""
Gestor de WebDriver de Selenium con manejo automático de recursos.
"""
from contextlib import contextmanager
from typing import Generator, Optional, List

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.remote.webdriver import WebDriver
from selenium.common.exceptions import WebDriverException

from config.settings import settings
from utils.logger import get_logger

logger = get_logger(__name__)


class WebDriverManager:
    """Gestor de instancias de WebDriver."""
    
    @staticmethod
    def create_chrome_options() -> Options:
        """Crea y configura las opciones de Chrome."""
        options = Options()
        
        # Configuración headless para producción
        options.add_argument("--headless=new")  # Nueva sintaxis de headless
        options.add_argument("--no-sandbox")
        options.add_argument("--disable-dev-shm-usage")
        options.add_argument("--disable-gpu")
        options.add_argument("--disable-software-rasterizer")
        
        # Optimizaciones de rendimiento
        options.add_argument("--disable-extensions")
        options.add_argument("--disable-infobars")
        options.add_argument("--disable-notifications")
        options.add_argument("--disable-popup-blocking")
        
        # Configuración de memoria
        options.add_argument("--disable-background-networking")
        options.add_argument("--disable-default-apps")
        options.add_argument("--disable-sync")
        options.add_argument("--disable-translate")
        
        # User agent para evitar bloqueos
        options.add_argument(
            "--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
            "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
        )
        
        # Configuración de página
        options.add_argument("--window-size=1920,1080")
        options.page_load_strategy = 'eager'  # No espera recursos secundarios
        
        # Preferencias adicionales
        prefs = {
            "profile.managed_default_content_settings.images": 2,  # Deshabilitar imágenes
            "profile.default_content_setting_values.notifications": 2,
            "profile.default_content_setting_values.geolocation": 2,
        }
        options.add_experimental_option("prefs", prefs)
        
        return options
    
    @classmethod
    def create_driver(cls) -> WebDriver:
        """Crea una nueva instancia de WebDriver."""
        options = cls.create_chrome_options()
        
        try:
            # Usar webdriver-manager si no hay ruta específica
            if settings.scraper.chromedriver_path:
                service = Service(settings.scraper.chromedriver_path)
            else:
                # Intentar usar webdriver-manager para gestión automática
                try:
                    from webdriver_manager.chrome import ChromeDriverManager
                    service = Service(ChromeDriverManager().install())
                except ImportError:
                    logger.warning("webdriver-manager no instalado, usando Chrome del sistema")
                    # Fallback: Chrome en PATH del sistema
                    service = Service()
            
            driver = webdriver.Chrome(service=service, options=options)
            driver.set_page_load_timeout(settings.scraper.page_load_timeout)
            driver.implicitly_wait(5)
            
            logger.debug("WebDriver creado exitosamente")
            return driver
            
        except WebDriverException as e:
            logger.error(f"Error al crear WebDriver: {e}")
            raise
    
    @classmethod
    @contextmanager
    def get_driver(cls) -> Generator[WebDriver, None, None]:
        """Context manager para obtener un WebDriver con limpieza automática."""
        driver = None
        try:
            driver = cls.create_driver()
            yield driver
        finally:
            if driver:
                try:
                    driver.quit()
                    logger.debug("WebDriver cerrado correctamente")
                except WebDriverException as e:
                    logger.warning(f"Error al cerrar WebDriver: {e}")


class DriverPool:
    """Pool simple de WebDrivers para reutilización."""
    
    def __init__(self, max_size: int = 3):
        self.max_size = max_size
        self._drivers: List[WebDriver] = []
        self._in_use: List[WebDriver] = []
    
    def acquire(self) -> WebDriver:
        """Obtiene un driver del pool o crea uno nuevo."""
        # Primero intentar reutilizar un driver existente
        if self._drivers:
            driver = self._drivers.pop()
            # Verificar que el driver siga funcionando
            try:
                driver.current_url  # Test simple
                self._in_use.append(driver)
                logger.debug("Driver reutilizado del pool")
                return driver
            except WebDriverException:
                # Driver corrupto, crear uno nuevo
                self._safe_quit(driver)
        
        # Crear nuevo driver si hay espacio
        if len(self._in_use) < self.max_size:
            driver = WebDriverManager.create_driver()
            self._in_use.append(driver)
            logger.debug(f"Nuevo driver creado. Pool: {len(self._in_use)}/{self.max_size}")
            return driver
        
        raise RuntimeError(
            f"Pool de drivers agotado ({self.max_size} en uso). "
            "Libera drivers con release() o aumenta max_size."
        )
    
    def release(self, driver: WebDriver) -> None:
        """Devuelve un driver al pool."""
        if driver not in self._in_use:
            logger.warning("Intentando liberar un driver que no está en uso")
            return
        
        self._in_use.remove(driver)
        
        # Limpiar cookies y sesión antes de reutilizar
        try:
            driver.delete_all_cookies()
            # Navegar a about:blank para limpiar estado
            driver.get("about:blank")
            self._drivers.append(driver)
            logger.debug("Driver liberado al pool")
        except WebDriverException as e:
            # Si hay error, cerrar el driver
            logger.warning(f"Error limpiando driver, cerrándolo: {e}")
            self._safe_quit(driver)
    
    def _safe_quit(self, driver: WebDriver) -> None:
        """Cierra un driver de forma segura."""
        try:
            driver.quit()
        except WebDriverException as e:
            logger.debug(f"Error cerrando driver (ignorado): {e}")
    
    def close_all(self) -> None:
        """Cierra todos los drivers del pool."""
        all_drivers = self._drivers + self._in_use
        for driver in all_drivers:
            self._safe_quit(driver)
        
        self._drivers.clear()
        self._in_use.clear()
        logger.info(f"Pool de drivers cerrado ({len(all_drivers)} drivers)")
    
    @property
    def available(self) -> int:
        """Cantidad de drivers disponibles."""
        return len(self._drivers)
    
    @property
    def in_use(self) -> int:
        """Cantidad de drivers en uso."""
        return len(self._in_use)
    
    def __enter__(self) -> 'DriverPool':
        return self
    
    def __exit__(self, exc_type, exc_val, exc_tb) -> None:
        self.close_all()
