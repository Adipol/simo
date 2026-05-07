"""
Tests para guardar_imagen_local y registrar_snapshot_imagenes en pep_monitor.py

Ejecutar con:
    python -m pytest scripts/website_monitor_pro/tests/test_storage.py -v
"""
import os
import sys
from unittest.mock import MagicMock, patch, mock_open

# Aseguramos que pep_monitor sea importable desde cualquier CWD
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from pep_monitor import guardar_imagen_local, registrar_snapshot_imagenes, ImagenStorageError


# ════════════════════════════════════════════════════════════════
# S-04.1 — guardar_imagen_local
# ════════════════════════════════════════════════════════════════

class TestGuardarImagenLocal:
    """Tests para guardar_imagen_local()."""

    def test_guarda_imagen_y_devuelve_path_relativo(self, tmp_path, monkeypatch):
        """bytes + cambio_id=42 + idx=0 + mime=image/png → archivo creado, return = 'img_cambios/42_0.png'"""
        monkeypatch.setenv("LARAVEL_STORAGE_PATH", str(tmp_path))

        image_bytes = b"\x89PNG\r\n\x1a\n" + b"\x00" * 100
        result = guardar_imagen_local(image_bytes, cambio_id=42, idx=0, mime_type="image/png")

        assert result == "img_cambios/42_0.png"
        archivo = tmp_path / "img_cambios" / "42_0.png"
        assert archivo.exists(), f"Archivo no creado: {archivo}"
        assert archivo.read_bytes() == image_bytes

    def test_devuelve_path_correcto_para_jpeg(self, tmp_path, monkeypatch):
        """mime=image/jpeg → extensión .jpg en el path retornado."""
        monkeypatch.setenv("LARAVEL_STORAGE_PATH", str(tmp_path))

        result = guardar_imagen_local(b"fake-jpeg", cambio_id=7, idx=2, mime_type="image/jpeg")

        assert result == "img_cambios/7_2.jpg"

    def test_crea_directorio_si_no_existe(self, tmp_path, monkeypatch):
        """Directorio img_cambios no existe → makedirs lo crea automáticamente."""
        storage_nuevo = tmp_path / "storage_nuevo"
        monkeypatch.setenv("LARAVEL_STORAGE_PATH", str(storage_nuevo))

        # El directorio no existe aún
        assert not (storage_nuevo / "img_cambios").exists()

        guardar_imagen_local(b"bytes", cambio_id=1, idx=0, mime_type="image/webp")

        assert (storage_nuevo / "img_cambios").exists()
        assert (storage_nuevo / "img_cambios" / "1_0.webp").exists()

    def test_mime_desconocido_usa_extension_bin(self, tmp_path, monkeypatch):
        """MIME type desconocido → extensión .bin."""
        monkeypatch.setenv("LARAVEL_STORAGE_PATH", str(tmp_path))

        result = guardar_imagen_local(b"data", cambio_id=99, idx=3, mime_type="image/unknown")

        assert result == "img_cambios/99_3.bin"

    def test_lanza_imagen_storage_error_si_disco_lleno(self, tmp_path, monkeypatch):
        """OSError(28, 'No space left on device') → lanza ImagenStorageError."""
        monkeypatch.setenv("LARAVEL_STORAGE_PATH", str(tmp_path))

        with patch("builtins.open", side_effect=OSError(28, "No space left on device")):
            try:
                guardar_imagen_local(b"bytes", cambio_id=5, idx=0, mime_type="image/png")
                assert False, "Debería haber lanzado ImagenStorageError"
            except ImagenStorageError as e:
                assert "5_0.png" in str(e)


# ════════════════════════════════════════════════════════════════
# S-04.2 — registrar_snapshot_imagenes
# ════════════════════════════════════════════════════════════════

class TestRegistrarSnapshotImagenes:
    """Tests para registrar_snapshot_imagenes()."""

    def _make_imagen(self, src: str = "https://example.com/img.png") -> dict:
        return {
            "src": src,
            "sha256": "abc123",
            "content_length": 1000,
            "etag": '"etag-1"',
            "last_modified": None,
            "mime_type": "image/png",
        }

    def test_inserta_imagen_nueva(self):
        """cursor mock + 1 imagen → cursor.execute llamado 1 vez con SQL de INSERT/ON CONFLICT."""
        cursor = MagicMock()
        imagen = self._make_imagen("https://example.com/foto.png")

        registrar_snapshot_imagenes(cursor, snapshot_id=10, fuente_id=3, imagenes_metadata=[imagen])

        assert cursor.execute.call_count == 1
        # call_args.args es (sql, params_tuple)
        args = cursor.execute.call_args.args
        sql = args[0]
        params = args[1]
        assert "INSERT INTO snapshot_imagenes" in sql
        assert "ON CONFLICT" in sql
        # Verificar que los parámetros contienen los valores correctos
        assert 10 in params  # snapshot_id
        assert 3 in params   # fuente_id
        assert "https://example.com/foto.png" in params

    def test_actualiza_via_upsert_con_on_conflict(self):
        """El SQL generado debe usar ON CONFLICT (fuente_id, src) DO UPDATE."""
        cursor = MagicMock()
        imagen1 = self._make_imagen("https://example.com/img1.png")
        imagen2 = self._make_imagen("https://example.com/img2.png")

        registrar_snapshot_imagenes(
            cursor,
            snapshot_id=20,
            fuente_id=5,
            imagenes_metadata=[imagen1, imagen2],
        )

        assert cursor.execute.call_count == 2
        for call_args in cursor.execute.call_args_list:
            sql = call_args[0][0]
            assert "ON CONFLICT" in sql
            assert "DO UPDATE" in sql

    def test_lista_vacia_no_ejecuta_query(self):
        """Lista vacía → cursor.execute NO se llama."""
        cursor = MagicMock()

        registrar_snapshot_imagenes(cursor, snapshot_id=1, fuente_id=1, imagenes_metadata=[])

        cursor.execute.assert_not_called()
