#!/usr/bin/env python3
"""
REFERENCE SCRIPT — NOT EXECUTED BY LARAVEL

Este script es una referencia para integrar stemming NLTK en scrapers Python.
Muestra cómo filtrar keywords usando SnowballStemmer (Spanish) antes de guardar
en la base de datos, para que el filtro de similitud semántica en Laravel
reciba palabras ya reducidas a su raíz.

Instalación:
    pip install nltk

Uso como referencia:
    Copiar las funciones stem_keywords() y text_contains_stem() en tu scraper.
    El scraper debe guardar las keywords stemmeadas en la columna correspondiente
    de la tabla resultados_scraping.

Ejemplo de integración en scraper:
    from stemming_filter import stem_keywords, text_contains_stem

    # 1. Definir keywords relevantes del caso
    keywords = ['designado', 'juramento', 'ministro', 'capturado']

    # 2. Stemear ANTES de procesar
    stemmed = stem_keywords(keywords)

    # 3. Filtrar textos del scraping
    for texto in scrapes:
        if text_contains_stem(texto, stemmed):
            # Guardar resultado relevante en BD
            save_to_db(texto)
"""

import re
from typing import List

try:
    from nltk.stem import SnowballStemmer
except ImportError:
    print("ERROR: nltk no está instalado. Ejecutá: pip install nltk")
    print("Luego descargá el stemmer:")
    print("  python -c \"import nltk; nltk.download('snowball_data')\"")
    raise SystemExit(1)


# ============================================================================
# Funciones principales
# ============================================================================

def stem_keywords(keywords: List[str]) -> List[str]:
    """
    Reduce cada keyword a su raíz (stem) usando SnowballStemmer para español.

    Elimina duplicados y convierte a minúsculas.

    Args:
        keywords: Lista de palabras clave originales.

    Returns:
        Lista de stems únicos (sin duplicados, lowercase).

    Ejemplo:
        >>> stem_keywords(['designado', 'juramento', 'ministro', 'capturado'])
        ['design', 'jurament', 'ministr', 'captur']
    """
    stemmer = SnowballStemmer("spanish")
    stems = set()

    for kw in keywords:
        # Normalizar: lowercase + quitar espacios laterales
        normalized = kw.strip().lower()
        if normalized:
            stem = stemmer.stem(normalized)
            stems.add(stem)

    return sorted(stems)


def text_contains_stem(text: str, stemmed_keywords: List[str]) -> bool:
    """
    Verifica si el texto contiene alguna de las raíces (stems) provistas.

    Tokeniza el texto en palabras, stemmea cada una, y compara contra la lista.

    Args:
        text: Texto completo a analizar (puede ser HTML, contenido scrapeado, etc.).
        stemmed_keywords: Lista de stems previamente generados con stem_keywords().

    Returns:
        True si al menos un stem del texto coincide con stemmed_keywords.

    Ejemplo:
        >>> stemmed = ['design', 'jurament', 'ministr', 'captur']
        >>> text_contains_stem('Fue designado como nuevo ministro', stemmed)
        True
        >>> text_contains_stem('El clima está soleado hoy', stemmed)
        False
    """
    if not text or not stemmed_keywords:
        return False

    stemmer = SnowballStemmer("spanish")

    # Tokenizar: extraer solo palabras (ignora puntuación, números sueltos, etc.)
    tokens = re.findall(r'[a-záéíóúñü]+', text.lower())

    # Stemmeamos cada token y verificamos contra la lista
    stemmed_set = set(stemmed_keywords)

    for token in tokens:
        if stemmer.stem(token) in stemmed_set:
            return True

    return False


# ============================================================================
# Demo — ejecutar directamente para ver el funcionamiento
# ============================================================================

if __name__ == "__main__":
    # Keywords originales del caso
    keywords_originales = [
        'designado',
        'juramento',
        'ministro',
        'capturado',
    ]

    # 1. Stemming
    stemmed = stem_keywords(keywords_originales)

    print("=" * 60)
    print("STEMMING REFERENCE — Español (Snowball)")
    print("=" * 60)
    print()
    print(f"Keywords originales: {keywords_originales}")
    print(f"Stems generados:     {stemmed}")
    print()

    # 2. Filtrado de textos
    textos_ejemplo = [
        "El juez fue designado por el poder ejecutivo para el nuevo cargo.",
        "Hoy el clima está soleado y agradable en toda la región.",
        "El nuevo ministro juramento ante el congreso nacional.",
        "El capturado fue trasladado a la comisaría central anoche.",
        "La economía creció un 3% según los últimos datos oficiales.",
        "Designaron al nuevo embajador ante las Naciones Unidas.",
    ]

    print("Filtrado de textos:")
    print("-" * 60)

    for texto in textos_ejemplo:
        match = text_contains_stem(texto, stemmed)
        marca = "✅ MATCH" if match else "❌ NO"
        print(f"  {marca}  {texto[:70]}...")

    print()
    print("=" * 60)
    print("Listo. Copiá stem_keywords() y text_contains_stem() en tu scraper.")
    print("=" * 60)
