"""
Tests TDD para la lógica de cascada de imágenes en pep_monitor.py
Cubre: comparar_imagenes_cascada, extraer_imagenes_html

Ejecutar con:
    python -m pytest scripts/website_monitor_pro/tests/test_cascade.py -v
"""
import hashlib
import sys
import os
from unittest.mock import MagicMock, patch, call
from typing import Optional

# Aseguramos que pep_monitor sea importable desde cualquier CWD
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from pep_monitor import extraer_imagenes_html, comparar_imagenes_cascada


# ════════════════════════════════════════════════════════════════
# HELPERS
# ════════════════════════════════════════════════════════════════

def _make_imagen_snapshot(
    src: str,
    sha256: str = "oldhash",
    content_length: Optional[int] = 500,
    etag: Optional[str] = "etag-old",
    last_modified: Optional[str] = None,
    mime_type: Optional[str] = "image/png",
) -> dict:
    """Crea un dict con la forma de una fila de snapshot_imagenes."""
    return {
        "src": src,
        "sha256": sha256,
        "content_length": content_length,
        "etag": etag,
        "last_modified": last_modified,
        "mime_type": mime_type,
    }


def _make_session_mock(
    head_status: int = 200,
    head_headers: Optional[dict] = None,
    get_content: bytes = b"fake-image-bytes",
    get_status: int = 200,
) -> MagicMock:
    """Crea un mock de requests.Session con .head() y .get() configurados."""
    session = MagicMock()

    head_resp = MagicMock()
    head_resp.status_code = head_status
    head_resp.headers = head_headers or {
        "Content-Length": "1000",
        "ETag": "etag-new",
        "Content-Type": "image/png",
    }
    session.head.return_value = head_resp

    get_resp = MagicMock()
    get_resp.status_code = get_status
    get_resp.content = get_content
    session.get.return_value = get_resp

    return session


def _sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


# ════════════════════════════════════════════════════════════════
# TESTS: extraer_imagenes_html
# ════════════════════════════════════════════════════════════════

class TestExtraerImagenesHtml:

    def test_extrae_url_absoluta(self):
        html = '<html><body><img src="https://example.com/foto.png"></body></html>'
        resultado = extraer_imagenes_html(html, "https://example.com")
        assert len(resultado) == 1
        assert resultado[0]["src"] == "https://example.com/foto.png"
        assert resultado[0]["src_absoluto"] == "https://example.com/foto.png"

    def test_resuelve_url_relativa(self):
        html = '<html><body><img src="imagenes/foto.jpg"></body></html>'
        resultado = extraer_imagenes_html(html, "https://ejemplo.gob.bo/directorio/")
        assert len(resultado) == 1
        assert resultado[0]["src_absoluto"] == "https://ejemplo.gob.bo/directorio/imagenes/foto.jpg"

    def test_filtra_data_uri(self):
        html = '<html><body><img src="data:image/png;base64,abc123"></body></html>'
        resultado = extraer_imagenes_html(html, "https://example.com")
        assert resultado == []

    def test_filtra_src_vacio(self):
        html = '<html><body><img src=""></body></html>'
        resultado = extraer_imagenes_html(html, "https://example.com")
        assert resultado == []

    def test_mime_hint_desde_extension_png(self):
        html = '<html><body><img src="foto.png"></body></html>'
        resultado = extraer_imagenes_html(html, "https://example.com/")
        assert resultado[0]["mime_hint"] == "image/png"

    def test_mime_hint_desde_extension_jpg(self):
        html = '<html><body><img src="foto.jpg"></body></html>'
        resultado = extraer_imagenes_html(html, "https://example.com/")
        assert resultado[0]["mime_hint"] == "image/jpeg"

    def test_mime_hint_none_para_extension_desconocida(self):
        html = '<html><body><img src="foto.xyz"></body></html>'
        resultado = extraer_imagenes_html(html, "https://example.com/")
        assert resultado[0]["mime_hint"] is None

    def test_sin_imgs(self):
        html = "<html><body><p>Texto</p></body></html>"
        resultado = extraer_imagenes_html(html, "https://example.com")
        assert resultado == []

    def test_multiples_imgs(self):
        html = """
        <html><body>
          <img src="a.png">
          <img src="b.png">
          <img src="data:image/gif;base64,xyz">
        </body></html>
        """
        resultado = extraer_imagenes_html(html, "https://example.com/")
        assert len(resultado) == 2

    def test_img_sin_atributo_src(self):
        html = '<html><body><img alt="sin src"></body></html>'
        resultado = extraer_imagenes_html(html, "https://example.com")
        assert resultado == []


# ════════════════════════════════════════════════════════════════
# TESTS: comparar_imagenes_cascada — Nivel 1 (URL nueva)
# ════════════════════════════════════════════════════════════════

class TestCascadaNivel1:
    """7.5/7.6 — URL nueva → descargar y analizar siempre."""

    def test_url_nueva_devuelve_para_analizar(self):
        """URL que no existía en snapshot → candidata a analizar."""
        content = b"imagen-bytes-png"
        sha_esperado = _sha256_bytes(content)

        session = _make_session_mock(
            head_headers={
                "Content-Length": str(len(content)),
                "ETag": "etag-abc",
                "Content-Type": "image/png",
            },
            get_content=content,
        )

        imgs_actual = [
            {"src": "https://x.com/foto.png", "src_absoluto": "https://x.com/foto.png", "mime_hint": "image/png"}
        ]
        imgs_anterior = []

        a_analizar, metadata = comparar_imagenes_cascada(
            imgs_actual, imgs_anterior, session
        )

        assert len(a_analizar) == 1
        assert a_analizar[0]["sha256"] == sha_esperado
        assert a_analizar[0]["bytes"] == content
        assert a_analizar[0]["content_length"] == len(content)

    def test_url_nueva_incluida_en_metadata(self):
        """La URL nueva debe aparecer en imgs_metadata para el upsert."""
        session = _make_session_mock()
        imgs_actual = [
            {"src": "https://x.com/foto.png", "src_absoluto": "https://x.com/foto.png", "mime_hint": "image/png"}
        ]
        a_analizar, metadata = comparar_imagenes_cascada(imgs_actual, [], session)
        assert len(metadata) == 1
        assert metadata[0]["src"] == "https://x.com/foto.png"

    def test_head_se_llama_con_url_correcta(self):
        """Debe llamar HEAD a la URL absoluta."""
        session = _make_session_mock()
        imgs_actual = [
            {"src": "foto.png", "src_absoluto": "https://site.com/foto.png", "mime_hint": "image/png"}
        ]
        comparar_imagenes_cascada(imgs_actual, [], session)
        session.head.assert_called_once_with(
            "https://site.com/foto.png",
            timeout=15,
            allow_redirects=True,
        )


# ════════════════════════════════════════════════════════════════
# TESTS: comparar_imagenes_cascada — Nivel 2 (ETag difiere)
# ════════════════════════════════════════════════════════════════

class TestCascadaNivel2:
    """7.7/7.8 — URL conocida pero ETag cambió → GET + SHA-256 comparison."""

    def test_etag_diferente_descarga_y_compara_sha(self):
        """ETag cambió → descargar y si SHA difiere → a_analizar."""
        new_content = b"nuevo-contenido-imagen"
        new_sha = _sha256_bytes(new_content)

        session = _make_session_mock(
            head_headers={
                "Content-Length": "500",
                "ETag": "etag-new",
                "Content-Type": "image/png",
            },
            get_content=new_content,
        )

        imgs_actual = [
            {"src": "https://x.com/foto.png", "src_absoluto": "https://x.com/foto.png", "mime_hint": "image/png"}
        ]
        imgs_anterior = [
            _make_imagen_snapshot("https://x.com/foto.png", sha256="oldhash", etag="etag-old", content_length=500)
        ]

        a_analizar, metadata = comparar_imagenes_cascada(imgs_actual, imgs_anterior, session)

        assert len(a_analizar) == 1
        assert a_analizar[0]["sha256"] == new_sha
        session.get.assert_called_once()

    def test_content_length_diferente_descarga(self):
        """Content-Length cambió (sin ETag) → descargar."""
        new_content = b"contenido-distinto"
        session = _make_session_mock(
            head_headers={
                "Content-Length": "9999",  # diferente al anterior (500)
                "Content-Type": "image/jpeg",
            },
            get_content=new_content,
        )

        imgs_actual = [
            {"src": "https://x.com/img.jpg", "src_absoluto": "https://x.com/img.jpg", "mime_hint": "image/jpeg"}
        ]
        imgs_anterior = [
            _make_imagen_snapshot("https://x.com/img.jpg", sha256="old", etag=None, content_length=500)
        ]

        a_analizar, metadata = comparar_imagenes_cascada(imgs_actual, imgs_anterior, session)
        session.get.assert_called_once()

    def test_etag_diferente_pero_sha_igual_no_a_analizar(self):
        """ETag difiere pero SHA es el mismo → NOT en a_analizar (raro pero posible)."""
        content = b"mismo-contenido"
        sha = _sha256_bytes(content)

        session = _make_session_mock(
            head_headers={
                "Content-Length": str(len(content)),
                "ETag": "etag-diferente",
                "Content-Type": "image/png",
            },
            get_content=content,
        )

        imgs_actual = [
            {"src": "https://x.com/foto.png", "src_absoluto": "https://x.com/foto.png", "mime_hint": "image/png"}
        ]
        imgs_anterior = [
            _make_imagen_snapshot("https://x.com/foto.png", sha256=sha, etag="etag-viejo")
        ]

        a_analizar, metadata = comparar_imagenes_cascada(imgs_actual, imgs_anterior, session)

        # SHA es igual → no a_analizar (imagen no cambió realmente)
        assert a_analizar == []
        # pero metadata sí debe actualizarse (nueva etag)
        assert len(metadata) == 1


# ════════════════════════════════════════════════════════════════
# TESTS: comparar_imagenes_cascada — Nivel 3 (sin cambios)
# ════════════════════════════════════════════════════════════════

class TestCascadaNivel3:
    """7.9/7.10 — Metadata idéntica → skip, NO hacer GET."""

    def test_metadata_iguales_no_descarga(self):
        """ETag y Content-Length iguales → no GET."""
        session = _make_session_mock(
            head_headers={
                "Content-Length": "500",
                "ETag": "etag-abc",
                "Content-Type": "image/png",
            }
        )

        imgs_actual = [
            {"src": "https://x.com/foto.png", "src_absoluto": "https://x.com/foto.png", "mime_hint": "image/png"}
        ]
        imgs_anterior = [
            _make_imagen_snapshot("https://x.com/foto.png", sha256="oldhash", etag="etag-abc", content_length=500)
        ]

        a_analizar, metadata = comparar_imagenes_cascada(imgs_actual, imgs_anterior, session)

        assert a_analizar == []
        session.get.assert_not_called()

    def test_metadata_iguales_no_aparece_en_a_analizar(self):
        """La URL ya conocida sin cambios NO va a a_analizar."""
        session = _make_session_mock(
            head_headers={
                "Content-Length": "1024",
                "ETag": "etag-stable",
                "Content-Type": "image/jpeg",
            }
        )
        imgs_actual = [
            {"src": "https://x.com/img.jpg", "src_absoluto": "https://x.com/img.jpg", "mime_hint": "image/jpeg"}
        ]
        imgs_anterior = [
            _make_imagen_snapshot("https://x.com/img.jpg", sha256="hashX", etag="etag-stable", content_length=1024)
        ]
        a_analizar, metadata = comparar_imagenes_cascada(imgs_actual, imgs_anterior, session)
        assert len(a_analizar) == 0

    def test_metadata_iguales_aparece_en_metadata_para_ultima_vez_visto(self):
        """Aunque no cambie, debe aparecer en metadata para actualizar ultima_vez_visto."""
        session = _make_session_mock(
            head_headers={
                "Content-Length": "500",
                "ETag": "etag-abc",
                "Content-Type": "image/png",
            }
        )
        imgs_actual = [
            {"src": "https://x.com/foto.png", "src_absoluto": "https://x.com/foto.png", "mime_hint": "image/png"}
        ]
        imgs_anterior = [
            _make_imagen_snapshot("https://x.com/foto.png", sha256="hashX", etag="etag-abc", content_length=500)
        ]
        a_analizar, metadata = comparar_imagenes_cascada(imgs_actual, imgs_anterior, session)
        assert len(metadata) == 1
        assert metadata[0]["src"] == "https://x.com/foto.png"


# ════════════════════════════════════════════════════════════════
# TESTS: Filtros pre-download
# ════════════════════════════════════════════════════════════════

class TestFiltrosPreDownload:
    """7.11/7.12 — Skip por tamaño. 7.13/7.14 — Skip por MIME."""

    def test_skip_si_size_excede_max(self):
        """Content-Length > max_image_bytes → skip, no GET."""
        session = _make_session_mock(
            head_headers={
                "Content-Length": "6000000",  # 6MB > 5MB default
                "ETag": "etag-x",
                "Content-Type": "image/png",
            }
        )
        imgs_actual = [
            {"src": "https://x.com/grande.png", "src_absoluto": "https://x.com/grande.png", "mime_hint": "image/png"}
        ]

        a_analizar, metadata = comparar_imagenes_cascada(
            imgs_actual, [], session, max_image_bytes=5 * 1024 * 1024
        )

        assert a_analizar == []
        session.get.assert_not_called()

    def test_skip_svg_mime(self):
        """Content-Type: image/svg+xml → skip."""
        session = _make_session_mock(
            head_headers={
                "Content-Length": "500",
                "Content-Type": "image/svg+xml",
            }
        )
        imgs_actual = [
            {"src": "https://x.com/logo.svg", "src_absoluto": "https://x.com/logo.svg", "mime_hint": None}
        ]

        a_analizar, metadata = comparar_imagenes_cascada(imgs_actual, [], session)

        assert a_analizar == []
        session.get.assert_not_called()

    def test_skip_mime_no_soportado(self):
        """Content-Type no reconocido → skip."""
        session = _make_session_mock(
            head_headers={
                "Content-Length": "100",
                "Content-Type": "application/octet-stream",
            }
        )
        imgs_actual = [
            {"src": "https://x.com/blob", "src_absoluto": "https://x.com/blob", "mime_hint": None}
        ]
        a_analizar, metadata = comparar_imagenes_cascada(imgs_actual, [], session)
        assert a_analizar == []
        session.get.assert_not_called()

    def test_imagen_dentro_del_limite_no_se_skipea(self):
        """Content-Length dentro del límite → sí se descarga."""
        content = b"imagen-ok"
        session = _make_session_mock(
            head_headers={
                "Content-Length": str(len(content)),
                "ETag": "etag-ok",
                "Content-Type": "image/jpeg",
            },
            get_content=content,
        )
        imgs_actual = [
            {"src": "https://x.com/ok.jpg", "src_absoluto": "https://x.com/ok.jpg", "mime_hint": "image/jpeg"}
        ]

        a_analizar, metadata = comparar_imagenes_cascada(
            imgs_actual, [], session, max_image_bytes=5 * 1024 * 1024
        )

        assert len(a_analizar) == 1
        session.get.assert_called_once()

    def test_sin_content_length_en_head_no_skipea_por_size(self):
        """Si HEAD no retorna Content-Length, no podemos saber tamaño → no skipear por size."""
        content = b"sin-length"
        session = _make_session_mock(
            head_headers={
                "Content-Type": "image/png",
                # sin Content-Length
            },
            get_content=content,
        )
        imgs_actual = [
            {"src": "https://x.com/img.png", "src_absoluto": "https://x.com/img.png", "mime_hint": "image/png"}
        ]
        a_analizar, metadata = comparar_imagenes_cascada(imgs_actual, [], session)
        # Sin content-length no podemos filtrar por size → debe descargar
        assert len(a_analizar) == 1
