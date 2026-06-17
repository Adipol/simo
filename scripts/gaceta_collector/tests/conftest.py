"""
Shared pytest fixtures and path setup for gaceta_collector tests.
"""
import os
import sys
from pathlib import Path

# Add the package root to sys.path so that `config`, `core`, `drivers` are importable.
_PKG_ROOT = Path(__file__).resolve().parent.parent
if str(_PKG_ROOT) not in sys.path:
    sys.path.insert(0, str(_PKG_ROOT))
