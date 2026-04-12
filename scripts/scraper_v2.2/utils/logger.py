"""
Sistema de logging centralizado con rotación de archivos.
"""
import logging
import sys
from logging.handlers import RotatingFileHandler
from pathlib import Path
from typing import Optional

from config.settings import settings


class LoggerFactory:
    """Factory para crear y gestionar loggers."""
    
    _initialized: bool = False
    _handlers_added: bool = False
    
    @classmethod
    def setup(cls) -> None:
        """Configura el sistema de logging."""
        if cls._initialized:
            return
        
        # Crear directorio de logs
        log_dir = settings.logging.base_dir
        log_dir.mkdir(parents=True, exist_ok=True)
        
        # Configurar logger raíz
        root_logger = logging.getLogger()
        root_logger.setLevel(getattr(logging, settings.logging.level))
        
        # Limpiar handlers existentes
        root_logger.handlers.clear()
        
        # Formatter común
        formatter = logging.Formatter(
            fmt=settings.logging.format,
            datefmt=settings.logging.date_format
        )
        
        # Handler para archivo (con rotación)
        file_handler = RotatingFileHandler(
            filename=log_dir / "scraper.log",
            maxBytes=settings.logging.max_bytes,
            backupCount=settings.logging.backup_count,
            encoding="utf-8"
        )
        file_handler.setFormatter(formatter)
        file_handler.setLevel(logging.DEBUG)
        root_logger.addHandler(file_handler)
        
        # Handler para consola
        console_handler = logging.StreamHandler(sys.stdout)
        console_handler.setFormatter(formatter)
        console_handler.setLevel(logging.INFO)
        root_logger.addHandler(console_handler)
        
        # Handler separado para errores
        error_handler = RotatingFileHandler(
            filename=log_dir / "errors.log",
            maxBytes=settings.logging.max_bytes,
            backupCount=settings.logging.backup_count,
            encoding="utf-8"
        )
        error_handler.setFormatter(formatter)
        error_handler.setLevel(logging.ERROR)
        root_logger.addHandler(error_handler)
        
        cls._initialized = True
    
    @classmethod
    def get_logger(cls, name: Optional[str] = None) -> logging.Logger:
        """Obtiene un logger configurado."""
        cls.setup()
        return logging.getLogger(name or settings.app_name)


def get_logger(name: Optional[str] = None) -> logging.Logger:
    """Función de conveniencia para obtener un logger."""
    return LoggerFactory.get_logger(name)
