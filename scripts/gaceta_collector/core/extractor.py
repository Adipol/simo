"""
V1 extractor for Gaceta Oficial decree summaries.

Design decisions (from SDD design artifact):
- Extensible verb lexicon; V1 auto-extracts ONLY 'designa/designación' verb.
  All other verbs → requiere_revision (human review queue).
- Bulk summaries without individual names → requiere_detalle.
- INTERINO keyword in sumario → interino=True.
- Multiple appointments via roman numeral incisos (I./II./III.) → multiple eventos.
- Never silently drops a norma — every case is classified.
- Pure function; no I/O.
"""
import re
import unicodedata
from dataclasses import dataclass, field
from typing import Optional


# ── Estado constants ──────────────────────────────────────────────────────────

ESTADO_PROCESADO = "procesado"
ESTADO_REQUIERE_DETALLE = "requiere_detalle"
ESTADO_REQUIERE_REVISION = "requiere_revision"


# ── Regex patterns ────────────────────────────────────────────────────────────

# V1 trigger verb — only 'designa' (case-insensitive) triggers auto-extraction.
_RE_DESIGNA_TRIGGER = re.compile(
    r"\b(designa|designaci[oó]n\s+de(?!\s+cargo))\b",
    re.IGNORECASE | re.UNICODE,
)

# Non-V1 verbs (recognizable but not auto-extracted in V1).
_RE_OTHER_VERB = re.compile(
    r"\b(rat[ií]fica|des[ií]gnese|se\s+designa|deroga|revoca|acepta\s+la\s+renuncia)\b",
    re.IGNORECASE | re.UNICODE,
)

# Core extraction pattern (from design doc).
# Groups: nombre, cargo, entidad (optional)
_RE_APPOINTMENT = re.compile(
    r"(?:al?)\s+ciudadan[oa]\s+"
    r"(?P<nombre>[A-ZÁÉÍÓÚÑÜÀÈÌÒÙÂÊÎÔÛÃÕ][A-ZÁÉÍÓÚÑÜÀÈÌÒÙÂÊÎÔÛÃÕ\s]+?)"
    r"\s+como\s+(?:INTERINO\s+)?(?P<cargo>[A-ZÁÉÍÓÚÑÜA-Za-záéíóúñ][^\.,\n;]+?)"
    r"(?:\s+de\s+(?P<entidad>[^\.,\n;]+?))?[,\.]",
    re.IGNORECASE | re.UNICODE,
)

# Roman numeral inciso separator: I. / II. / III. etc.
_RE_INCISO = re.compile(
    r"\b(I{1,3}V?|VI{0,3}|IX|IV|V|X)\.\s+",
    re.IGNORECASE,
)

# INTERINO detection (anywhere in the sumario)
_RE_INTERINO = re.compile(r"\bINTERINO\b", re.IGNORECASE)

# Indicator of bulk sumario (no individual proper name extractable)
_RE_BULK_KEYWORDS = re.compile(
    r"\b(alto\s+mando|ministros\s+de\s+estado|fuerzas\s+armadas|gabinete)\b",
    re.IGNORECASE | re.UNICODE,
)


# ── Data model ────────────────────────────────────────────────────────────────

@dataclass
class ExtractorResult:
    """Result of running the extractor on a single sumario string."""

    eventos: list = field(default_factory=list)
    estado_extraccion: str = ESTADO_REQUIERE_REVISION


# ── Public API ────────────────────────────────────────────────────────────────

def extract_eventos(sumario: str) -> ExtractorResult:
    """
    Extract appointment events from a decree summary string.

    V1 behaviour:
    - 'Designa/Designación' verb present:
        * If individual name extracted → ExtractorResult(eventos=[...], estado='procesado')
        * If bulk / no name found      → ExtractorResult(eventos=[], estado='requiere_detalle')
    - Other verbs or no verb           → ExtractorResult(eventos=[], estado='requiere_revision')
    """
    has_designa = bool(_RE_DESIGNA_TRIGGER.search(sumario))
    has_other = bool(_RE_OTHER_VERB.search(sumario))

    if not has_designa:
        return ExtractorResult(eventos=[], estado_extraccion=ESTADO_REQUIERE_REVISION)

    # Mixed-verb guard: if any non-V1 verb is present alongside 'designa/designación',
    # the decree is ambiguous (e.g. "Ratifica la designación de…", "Acepta la renuncia y
    # designa…"). Conservative policy: flag for human review rather than risk a false
    # positive. Only a clean standalone V1 decree is auto-extracted.
    if has_other:
        return ExtractorResult(eventos=[], estado_extraccion=ESTADO_REQUIERE_REVISION)

    # V1: 'designa' detected — try to extract individual appointments
    eventos = _extract_appointments(sumario)

    if not eventos:
        # Could not extract names — bulk or unstructured summary
        return ExtractorResult(eventos=[], estado_extraccion=ESTADO_REQUIERE_DETALLE)

    return ExtractorResult(eventos=eventos, estado_extraccion=ESTADO_PROCESADO)


# ── Private helpers ───────────────────────────────────────────────────────────

def _extract_appointments(sumario: str) -> list:
    """
    Extract individual appointment dicts from a sumario.

    Handles:
    - Single appointment (no incisos)
    - Multiple appointments separated by roman numeral incisos (I./II./III.)
    """
    # Try splitting by incisos first
    segments = _split_by_incisos(sumario)
    eventos = []
    for segment in segments:
        ev = _parse_single_appointment(segment, sumario)
        if ev is not None:
            eventos.append(ev)
    return eventos


def _split_by_incisos(sumario: str) -> list:
    """
    Split a sumario into segments by roman numeral incisos.
    Returns the full sumario as a single-element list if no incisos are found.
    """
    parts = _RE_INCISO.split(sumario)
    if len(parts) <= 1:
        return [sumario]
    # Odd indices are the captured roman numeral tokens; even indices are text segments.
    # Reconstruct: group text parts (indices 0, 2, 4, …) that are non-trivial.
    segments = []
    for i, part in enumerate(parts):
        if i % 2 == 0 and part.strip():  # text segments
            segments.append(part.strip())
    return segments if segments else [sumario]


def _parse_single_appointment(segment: str, full_sumario: str) -> Optional[dict]:
    """
    Parse a single appointment from a text segment.
    Returns a dict or None if no appointment is found.
    """
    match = _RE_APPOINTMENT.search(segment)
    if match is None:
        return None

    persona_nombre = match.group("nombre").strip()
    cargo = match.group("cargo").strip()
    entidad = (match.group("entidad") or "").strip() or None

    # INTERINO is detected in the full sumario (applies to any segment)
    interino = bool(_RE_INTERINO.search(full_sumario))

    return {
        "persona_nombre": persona_nombre,
        "persona_nombre_normalizado": _normalize_name(persona_nombre),
        "cargo": cargo,
        "cargo_categoria": None,  # resolved by a separate cargo-lookup step (not V1)
        "entidad": entidad,
        "tipo_evento": "designacion",
        "interino": interino,
        "estado_revision": "pendiente",
    }


def _normalize_name(name: str) -> str:
    """
    Return a normalized version of a person's name:
    - Decompose Unicode (NFD) then encode to ASCII (drops diacritics)
    - Lowercase
    - Collapse whitespace

    Uses stdlib unicodedata only (no unidecode dependency in pure logic).
    """
    nfd = unicodedata.normalize("NFD", name)
    ascii_bytes = nfd.encode("ascii", errors="ignore")
    normalized = ascii_bytes.decode("ascii").lower()
    return " ".join(normalized.split())
