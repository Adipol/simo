#!/usr/bin/env python3
"""
Diagnóstico de SSL: prueba cada fuente activa con verificación SSL estricta
y reporta cuáles fallan. La salida sugiere qué hosts agregar a la env var
SSL_VERIFY_SKIP_HOSTS del .env.

Uso:
    python scripts/website_monitor_pro/check_ssl.py

Salida tipo:
    OK    https://www.bcb.gob.bo/ ............................... cert válido
    FAIL  https://www.aetn.gob.bo/ ............................. cert autofirmado
    FAIL  https://cajacordes.org.bo/ ........................... cert expirado

    Hosts a agregar a SSL_VERIFY_SKIP_HOSTS:
    SSL_VERIFY_SKIP_HOSTS=www.aetn.gob.bo,cajacordes.org.bo
"""
import os
import sys
from urllib.parse import urlparse

import psycopg2
import psycopg2.extras
import requests
from dotenv import load_dotenv

load_dotenv()


def main() -> None:
    # Conectar a Postgres
    conn = psycopg2.connect(
        host=os.getenv("DB_HOST", "localhost"),
        port=int(os.getenv("DB_PORT", "5432")),
        user=os.getenv("DB_USER", "postgres"),
        password=os.getenv("DB_PASSWORD", ""),
        dbname=os.getenv("DB_NAME", "simo"),
        cursor_factory=psycopg2.extras.RealDictCursor,
    )
    cursor = conn.cursor()
    cursor.execute("SELECT id, url FROM fuentes WHERE activo = TRUE ORDER BY url")
    fuentes = cursor.fetchall()
    conn.close()

    print(f"\nDiagnóstico SSL para {len(fuentes)} fuentes activas\n")
    print("=" * 75)

    fallidos: list[str] = []

    for f in fuentes:
        url = f["url"]
        host = urlparse(url).hostname or "?"

        try:
            resp = requests.head(
                url,
                timeout=10,
                allow_redirects=True,
                verify=True,  # Estricto a propósito
                headers={"User-Agent": "SIMO-SSL-Check/1.0"},
            )
            status = "OK"
            mensaje = f"HTTP {resp.status_code}"
        except requests.exceptions.SSLError as e:
            status = "FAIL"
            mensaje = "SSL inválido"
            fallidos.append(host)
        except requests.exceptions.ConnectionError:
            status = "????"
            mensaje = "no responde (red/DNS)"
        except requests.exceptions.Timeout:
            status = "????"
            mensaje = "timeout"
        except Exception as e:
            status = "????"
            mensaje = f"error: {type(e).__name__}"

        host_padded = host.ljust(38)
        print(f"  {status:5} {host_padded} {mensaje}")

    print("=" * 75)

    if fallidos:
        # Ordenar y deduplicar
        unicos = sorted(set(fallidos))
        print(f"\nHosts con SSL inválido ({len(unicos)}):")
        for h in unicos:
            print(f"  - {h}")
        print("\nLínea sugerida para .env:")
        print(f"SSL_VERIFY_SKIP_HOSTS={','.join(unicos)}")
        print()
    else:
        print("\nTodos los hosts tienen SSL válido.")
        print("No hace falta setear SSL_VERIFY_SKIP_HOSTS.\n")


if __name__ == "__main__":
    main()
