"""
V1 extractor for Gaceta Oficial decree summaries.

Design decisions (from SDD design artifact):
- Extensible verb lexicon; V1 auto-extracts ONLY 'designa/designación' verb.
  All other verbs → requiere_revision (human review queue).
- ALLOWLIST (not denylist): the sumario must be GOVERNED by the Designa verb,
  i.e. the verb leads the sumario (optionally preceded only by a decree-number
  prefix such as 'Decreto Supremo Nº 549 —'). Any other governing verb such as
  Aprueba, Modifica, Complementa, Confirma, Reincorpora, Ratifica, Deroga, etc.
  → requiere_revision. This is V1 conservative policy: err safe, never
  auto-confirm an ambiguous decree.
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

# V1 trigger — ALLOWLIST anchored to the leading governing position.
# Matches ONLY when the sumario is LED by a Designa-family verb, optionally
# preceded by a decree-number prefix (e.g. "Decreto Supremo Nº 549 — ").
# This guarantees that decrees governed by any other verb (Aprueba, Modifica,
# Complementa, Confirma, Ratifica, Deroga, …) do NOT match, even when the
# phrase "designación de" appears later in the sumario as an object.
_RE_DESIGNA_TRIGGER = re.compile(
    r"^\s*"
    r"(?:Decreto\s+Supremo\s+N[°ºo]?\.?\s*[\d\.\-/]+\s*[–—\-]\s*)?"
    r"(Des[íi]gnase|Designa|Designaci[oó]n\s+de(?!\s+cargo))\b",
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
# Uppercase only — real Gaceta incisos are always uppercase.
# Dropping re.IGNORECASE prevents lowercase tokens embedded in names or
# district designations (e.g. "Distrito v. de La Paz") from being mistaken
# for inciso separators, which would split the segment before the terminating
# period and silently drop the preceding appointment.
_RE_INCISO = re.compile(
    r"\b(I{1,3}V?|VI{0,3}|IX|IV|V|X)\.\s+",
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
    - Sumario LED by 'Designa / Desígnase / Designación de':
        * If individual name extracted → ExtractorResult(eventos=[...], estado='procesado')
        * If bulk / no name found      → ExtractorResult(eventos=[], estado='requiere_detalle')
    - Any other governing verb or no verb → ExtractorResult(eventos=[], estado='requiere_revision')

    The trigger is an ALLOWLIST anchored to the start of the sumario.  Only
    decrees whose first meaningful token is a Designa-family verb are
    auto-extracted.  All other decrees — including those that merely reference
    'designación de' as an object of another verb — are routed to human review.
    """
    has_designa = bool(_RE_DESIGNA_TRIGGER.search(sumario))

    if not has_designa:
        return ExtractorResult(eventos=[], estado_extraccion=ESTADO_REQUIERE_REVISION)

    # V1: leading Designa verb confirmed — try to extract individual appointments.
    eventos = _extract_appointments(sumario)

    if not eventos:
        # Could not extract names — bulk or unstructured summary.
        return ExtractorResult(eventos=[], estado_extraccion=ESTADO_REQUIERE_DETALLE)

    return ExtractorResult(eventos=eventos, estado_extraccion=ESTADO_PROCESADO)


# ── Private helpers ───────────────────────────────────────────────────────────

def _extract_appointments(sumario: str) -> list:
    """
    Extract individual appointment dicts from a sumario.

    Handles:
    - Single appointment (no incisos)
    - Multiple appointments separated by roman numeral incisos (I./II./III.)
    - Multiple appointments within the same inciso segment (e.g. co-appointments
      joined by conjunction without a dedicated inciso for each name)

    Uses finditer on _RE_APPOINTMENT per segment so that every matching
    appointment is captured, not just the first one found by search().
    """
    segments = _split_by_incisos(sumario)
    eventos = []
    for segment in segments:
        for match in _RE_APPOINTMENT.finditer(segment):
            ev = _build_evento_from_match(match, sumario)
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


def _build_evento_from_match(match, full_sumario: str) -> dict:
    """
    Build an appointment event dict from a regex match object.

    Shared by _extract_appointments (via finditer) and _parse_single_appointment.
    INTERINO is resolved against the full sumario so it applies to every
    appointment regardless of which inciso segment the match came from.
    """
    persona_nombre = match.group("nombre").strip()
    cargo = match.group("cargo").strip()
    entidad = (match.group("entidad") or "").strip() or None
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


def _parse_single_appointment(segment: str, full_sumario: str) -> Optional[dict]:
    """
    Parse the first appointment from a text segment.
    Returns a dict or None if no appointment is found.

    Note: _extract_appointments uses finditer (not this function) to capture
    all appointments in a segment. This function is kept for callers that
    need only the first match.
    """
    match = _RE_APPOINTMENT.search(segment)
    if match is None:
        return None
    return _build_evento_from_match(match, full_sumario)


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
