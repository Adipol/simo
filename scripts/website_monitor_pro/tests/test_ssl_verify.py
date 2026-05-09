"""
Tests del helper verify_para_url() — decide si un request a una URL debe
verificar SSL según la lista de hosts configurada en SSL_VERIFY_SKIP_HOSTS.

Ejecutar con:
    python -m pytest scripts/website_monitor_pro/tests/test_ssl_verify.py -v
"""
import sys
import os

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from pep_monitor import verify_para_url


class TestVerifyParaUrl:
    def test_default_sin_skip_hosts_verifica(self):
        # Sin lista de skip → todos verifican
        assert verify_para_url("https://google.com", skip_hosts=set()) is True
        assert verify_para_url("https://www.aetn.gob.bo/", skip_hosts=set()) is True

    def test_host_en_lista_no_verifica(self):
        skip = {"www.aetn.gob.bo"}
        assert verify_para_url("https://www.aetn.gob.bo/web/main", skip_hosts=skip) is False

    def test_host_fuera_de_lista_verifica(self):
        skip = {"www.aetn.gob.bo"}
        assert verify_para_url("https://www.bcb.gob.bo/", skip_hosts=skip) is True
        assert verify_para_url("https://google.com", skip_hosts=skip) is True

    def test_host_es_case_insensitive(self):
        skip = {"www.aetn.gob.bo"}  # lowercase en config
        # URL con uppercase debería matchear
        assert verify_para_url("https://WWW.AETN.GOB.BO/web", skip_hosts=skip) is False

    def test_url_invalida_sin_host_no_revienta(self):
        skip = {"www.aetn.gob.bo"}
        # urlparse retorna hostname=None para "not-a-url"
        # debe retornar True (verifica) para no crear problemas
        assert verify_para_url("not-a-url", skip_hosts=skip) is True
        assert verify_para_url("", skip_hosts=skip) is True

    def test_url_con_puerto(self):
        skip = {"www.aetn.gob.bo"}
        # Puerto no debería confundir el match (urlparse separa host de puerto)
        assert verify_para_url("https://www.aetn.gob.bo:443/", skip_hosts=skip) is False

    def test_subdominio_no_matchea(self):
        # Solo match exacto, no por subdominio
        skip = {"aetn.gob.bo"}
        assert verify_para_url("https://www.aetn.gob.bo/", skip_hosts=skip) is True
        assert verify_para_url("https://aetn.gob.bo/", skip_hosts=skip) is False

    def test_lee_env_var_si_skip_hosts_es_none(self, monkeypatch):
        # Por default skip_hosts=None debería leer la env var
        monkeypatch.setenv("SSL_VERIFY_SKIP_HOSTS", "host1.bo,host2.bo")
        assert verify_para_url("https://host1.bo/") is False
        assert verify_para_url("https://host3.bo/") is True

    def test_env_var_vacia_significa_todos_verifican(self, monkeypatch):
        monkeypatch.setenv("SSL_VERIFY_SKIP_HOSTS", "")
        assert verify_para_url("https://www.aetn.gob.bo/") is True

    def test_env_var_con_espacios_se_normaliza(self, monkeypatch):
        monkeypatch.setenv("SSL_VERIFY_SKIP_HOSTS", " host1.bo , host2.bo  ,  ")
        assert verify_para_url("https://host1.bo/") is False
        assert verify_para_url("https://host2.bo/") is False
        assert verify_para_url("https://host3.bo/") is True
