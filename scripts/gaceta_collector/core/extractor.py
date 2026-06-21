"""
Extractor for Gaceta Oficial decree summaries — tuned for REAL Bolivian gazette language.

Design decisions:
- Extensible verb lexicon; V1 auto-extracts ONLY designa-family verbs.
  All other governing verbs -> requiere_revision (human review queue).
- ALLOWLIST (not denylist): the sumario must be GOVERNED by a Designa-family
  verb, i.e. the verb leads the sumario after pre-processing.  Any other
  governing verb (Aprueba, Modifica, Complementa, Confirma, Reincorpora,
  Ratifica, Deroga, etc.) -> requiere_revision.
- Real date prefix 'DD DE MES DE YYYY .- ' is stripped before trigger detection
  (real gazette encodes date in the sumario text).
- Preamble 'tiene por objeto: ' is stripped when present.
- Two extraction patterns for the real gazette:
    Pattern A (name-first, ~13%):
        Designa al ciudadano <NAME>, como <CARGO>, quien tomara posesion...
    Pattern B (cargo-first, ~63%):
        Designese <CARGO>, al ciudadano <Name>, Cargo actual, mientras dure...
        Also: Designa <CARGO>, al ciudadano <Name>  (Designa + cargo-first variant)
- INTERINO detection:
    - Pattern A: 'INTERINO' keyword in the 'como INTERINO <cargo>' group.
    - Pattern B: 'INTERINO'/'INTERINA' anywhere in the cargo string, OR
      'mientras dure la ausencia' anywhere in the segment.
- Per-segment extraction: the sumario is split by roman-numeral incisos (I./II./
  III.) then each segment is tried with Pattern A first, Pattern B as fallback.
- Bulk summaries without individual names -> requiere_detalle.
- Never silently drops a norma -- every case is classified.
- Pure function; no I/O.

Completeness guard (unchanged from Round 7):
- DUAL SIGNAL: max(clause_count, ciudadano_count).
  1. 'como <Cargo>' clause counter -- catches co-appointments without ciudadano prefix.
  2. 'ciudadano/ciudadana' token counter -- catches non-'como' connectors.
  threshold = max(clause_count, ciudadano_count).
  If len(eventos) < threshold -> requiere_revision.
- Pattern B appointments have ciudadano_count >= 1 per appointment and
  clause_count = 0 (no 'como' in the cargo-first structure), so
  threshold = max(0, N) = N which equals the number of Pattern B appointments.

Accepted V1 residuals:
  1. Comma-style co-appointment ('y a MARIA ROCHA, Ministra de Economia') has
     NEITHER ciudadano prefix NOR 'como' clause -> invisible to both extractor
     and guard.  Pinned in TestExtractorV1AcceptedLimitations.
  2. Pattern B: Title Case names with unusual Unicode not in the explicit charclass
     may not extract fully.  Extend charclass if additional scripts are found.
"""
import re
import unicodedata
from dataclasses import dataclass, field


# ── Estado constants ──────────────────────────────────────────────────────────

ESTADO_PROCESADO = "procesado"
ESTADO_REQUIERE_DETALLE = "requiere_detalle"
ESTADO_REQUIERE_REVISION = "requiere_revision"


# ── Pre-processing patterns ───────────────────────────────────────────────────

# Strip the date prefix that real sumarios carry: 'DD DE MES DE YYYY .- '
# The month name is any sequence of uppercase/lowercase letters (accented ok).
_RE_DATE_PREFIX = re.compile(
    r"^\d{1,2}\s+DE\s+[A-Za-záéíóúñüÁÉÍÓÚÑÜ]+\s+DE\s+\d{4}\s+\.-\s*",
    re.UNICODE,
)

# Strip preamble 'tiene por objeto:' that wraps the main verb in some decrees.
# Use DOTALL so '.*?' can span across whitespace/newlines in the preamble.
_RE_PREAMBLE_STRIP = re.compile(
    r"^.*?tiene\s+por\s+objeto:\s*",
    re.IGNORECASE | re.UNICODE | re.DOTALL,
)


# ── Trigger pattern ───────────────────────────────────────────────────────────

# V1 ALLOWLIST anchored to the start of the pre-processed text.
# Recognizes ALL real Bolivian gazette designation verbs observed in the corpus:
#   Designa      -- present tense (name-first Pattern A)
#   Designar     -- infinitive  (name-first Pattern A)
#   Se designa   -- reflexive  (name-first Pattern A)
#   Designese    -- imperative 'e' variant (cargo-first Pattern B, ~63% of corpus)
#   Designase    -- imperative 'a' variant (older decrees)
#   Designacion de -- noun form (bulk decrees -> requiere_detalle)
# The optional leading inciso marker ([IVX]+. ) handles sumarios that remain
# after preamble stripping where the first inciso precedes the verb.
# re.IGNORECASE allows 'DESIGNESE'/'designese' variants.
_RE_DESIGNA_TRIGGER = re.compile(
    r"^\s*"
    r"(?:Decreto\s+Supremo\s+N[°ºo]?\.?\s*[\d\.\-/]+\s*[–—\-]\s*)?"
    r"(?:[IVX]+\.\s+)?"
    r"(Se\s+designa|Des[íi]gn[ae]se|Designar?\b|Designaci[oó]n\s+del?(?!\s+cargo))\b",
    re.IGNORECASE | re.UNICODE,
)

# Core extraction pattern — Pattern A (name-first).
# Groups: nombre, interino (per-appointment), cargo, entidad (optional).
#
# Key fix vs previous version: the separator between nombre and 'como' is now
# '[,\s]+' (comma-or-whitespace, one or more) instead of '\s+' alone.  Real
# gazette text is 'NAME, como CARGO' (comma separator), not 'NAME como CARGO'.
#
# Name charclass: includes uppercase AND lowercase accented letters so that
# Title Case names (Jose Luis Lupo Flores) are matched alongside ALL-CAPS names
# (RICARDO ERICK SANJINES CHAVEZ).  With re.IGNORECASE already active, the
# uppercase ranges implicitly match their lowercase counterparts too; the
# explicit lowercase ranges are kept for clarity.
_RE_APPOINTMENT = re.compile(
    r"(?:al?)\s+ciudadan[oa]\s+"
    r"(?P<nombre>[A-Za-záéíóúñüÁÉÍÓÚÑÜÀÈÌÒÙÂÊÎÔÛÃÕàèìòùâêîôûãõ]"
    r"[A-Za-záéíóúñüÁÉÍÓÚÑÜÀÈÌÒÙÂÊÎÔÛÃÕàèìòùâêîôûãõ\s]+?)"
    r"[,\s]+como\s+(?P<interino>INTERINO[A]?\s+)?(?P<cargo>[A-Za-záéíóúñüÁÉÍÓÚÑÜ][^,\.\n;]+)[,\.]",
    re.IGNORECASE | re.UNICODE,
)

# Pattern B (cargo-first): '<TRIGGER> <CARGO>, al ciudadano <Name>'.
# Used for 'Designese CARGO, al ciudadano Name' and the 'Designa CARGO, al
# ciudadano Name' variant.
#
# Design choices:
# - NOT using re.IGNORECASE: the cargo group '[A-ZÁÉÍÓÚÑÜ][A-ZÁÉÍÓÚÑÜ\s]+?'
#   must match UPPERCASE cargo text only.  Without IGNORECASE, 'al' (lowercase)
#   at the start of 'Designa al ciudadano...' does NOT match the cargo charclass,
#   preventing false Pattern B matches on regular Pattern A sumarios.
# - Trigger alternatives listed without IGNORECASE so they match the exact case
#   forms seen in the corpus:
#     Designese / Desígnese  (imperative)
#     Designa                (present — cargo-first variant)
#     Designar               (infinitive — cargo-first variant)
#     Se designa             (reflexive — cargo-first variant)
# - Name charclass includes both uppercase and lowercase accented ranges to
#   capture Title Case names (Jose Luis Lupo Flores) common in Pattern B.
# - Interino is derived separately from cargo text or segment context in
#   _build_evento_from_match_b(), not from a named group here.
# Pattern B: 'a la ciudadana' and 'a el ciudadano' are handled by '(?:la\\s+|el\\s+)?'
# after 'al?' so that feminine-article forms ('a la') and masculine-article
# forms ('a el') are both captured.  'al ciudadano' is covered by 'al?' alone.
#
# Cargo charclass: '[A-ZÁÉÍÓÚÑÜ\\s,]+?' — commas are explicitly included.
# Reason: real Bolivian ministry names contain comma-separated parts such as
# "MINISTRO INTERINO DE OBRAS PÚBLICAS, SERVICIOS Y VIVIENDA" or
# "MINISTRO INTERINO DE TURISMO SOSTENIBLE, CULTURAS, FOLKLORE Y GASTRONOMÍA".
# The non-greedy '+?' ensures that the regex stops at the FIRST comma that is
# followed by 'al ciudadano/a la ciudadana', leaving earlier commas in the cargo.
_RE_APPOINTMENT_B = re.compile(
    r"(?:Des[íiÍI]gn[ae]se|Designar\b|Designa\b|Se\s+designa)\s+"
    r"(?P<cargo>[A-ZÁÉÍÓÚÑÜ][A-ZÁÉÍÓÚÑÜ\s,]+?)"
    r"\s*,\s*al?\s+(?:la\s+|el\s+)?ciudadan[oa]\s+"
    r"(?P<nombre>[A-Za-záéíóúñüÁÉÍÓÚÑÜÀÈÌÒÙÂÊÎÔÛÃÕàèìòùâêîôûãõ]"
    r"[A-Za-záéíóúñüÁÉÍÓÚÑÜÀÈÌÒÙÂÊÎÔÛÃÕàèìòùâêîôûãõ\s]+?)"
    r"(?:,|\.|\n|$)",
    re.UNICODE,  # Intentionally no re.IGNORECASE -- see design note above.
)

# Appointment-clause counter for the completeness guard (Round 5/6/7).
# Counts 'como [INTERINO] <Cargo-start>' occurrences, independent of whether
# each appointee is preceded by the 'ciudadano/ciudadana' prefix token.
# Round 7 fix: re.IGNORECASE added so 'Como'/'COMO' connectors are counted.
# Round 6 fix: charclass aligned with _RE_APPOINTMENT to include lowercase.
_RE_APPOINTMENT_CLAUSE = re.compile(
    r"\bcomo\s+(?:INTERINO[A]?\s+)?[A-Za-záéíóúñüÁÉÍÓÚÑÜ]",
    re.IGNORECASE | re.UNICODE,
)

# Ciudadano/ciudadana token counter for the completeness guard (Round 7).
# Singular-only: plural forms ('ciudadanos', 'ciudadanas') appear in bulk
# preambles and must not inflate the count.
_RE_CIUDADANO_TOKEN = re.compile(r"\bciudadan[oa]\b", re.IGNORECASE | re.UNICODE)

# Roman numeral inciso separator: I. / II. / III. etc.
# Uppercase only -- real Gaceta incisos are always uppercase.
# Dropping re.IGNORECASE prevents lowercase 'v.' embedded in district names
# from being mistaken for inciso separators.
_RE_INCISO = re.compile(
    r"\b(I{1,3}V?|VI{0,3}|IX|IV|V|X)\.\s+",
)

# Bulk sumario indicator (no individual proper name extractable).
_RE_BULK_KEYWORDS = re.compile(
    r"\b(alto\s+mando|ministros\s+de\s+estado|fuerzas\s+armadas|gabinete)\b",
    re.IGNORECASE | re.UNICODE,
)

# Per-appointment interino detection for Pattern B:
# 'INTERINO' or 'INTERINA' in the extracted cargo string.
_RE_INTERINO_IN_CARGO = re.compile(r"\bINTERIN[OA]\b", re.IGNORECASE)

# 'mientras dure la ausencia' anywhere in the segment -> also signals interino.
_RE_AUSENCIA = re.compile(r"mientras\s+dure\s+la\s+ausencia", re.IGNORECASE | re.UNICODE)

# Referenced titular cargo for Pattern B interim decrees.
#
# Structure after the appointee name:
#   '<Name>, <Cargo Titular>, mientras dure la ausencia...'
# The titular cargo is the appointee's PERMANENT role mentioned as context.
# It sits between the comma after the name and the ', mientras dure' clause.
#
# Applied to segment text starting from match.end('nombre'), which points to
# the character right after the last char of the extracted name (before the
# trailing terminator consumed by _RE_APPOINTMENT_B).
#
# Charclass for titular cargo: Title Case text — accepts both uppercase and
# lowercase accented chars.  Non-greedy '+?' stops at the earliest ', mientras'.
# The pattern requires a comma BEFORE 'mientras' so that ', mientras dure'
# alone (no titular phrase) does NOT match.
_RE_CARGO_REFERENCIADO = re.compile(
    r"\s*,\s*"
    r"([A-Za-záéíóúñüÁÉÍÓÚÑÜÀÈÌÒÙÂÊÎÔÛÃÕàèìòùâêîôûãõ][^,\n]+?)"
    r"\s*,\s*mientras\s+dure",
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

    Pre-processes the sumario to strip date prefix and preamble, then applies
    the ALLOWLIST trigger check and dual-pattern extraction.

    V1 behaviour:
    - Sumario governed by Designa-family verb:
        * If individual name extracted -> ExtractorResult(eventos=[...], estado='procesado')
        * If bulk / no name found      -> ExtractorResult(eventos=[], estado='requiere_detalle')
    - Any other governing verb or no verb -> ExtractorResult(eventos=[], estado='requiere_revision')

    Pattern A (name-first): 'Designa al ciudadano NAME, como CARGO'
    Pattern B (cargo-first): 'Designese CARGO, al ciudadano Name'
    """
    processed = _preprocess(sumario)
    has_designa = bool(_RE_DESIGNA_TRIGGER.search(processed))

    if not has_designa:
        return ExtractorResult(eventos=[], estado_extraccion=ESTADO_REQUIERE_REVISION)

    # V1: leading Designa-family verb confirmed -- try to extract individual appointments.
    eventos = _extract_appointments(processed)

    if not eventos:
        # Could not extract names -- bulk or unstructured summary.
        return ExtractorResult(eventos=[], estado_extraccion=ESTADO_REQUIERE_DETALLE)

    # Round-7 dual-signal completeness guard.
    # Two independent signals are combined; we route on the STRICTER one.
    # Signal 1: 'como <Cargo>' clause counter.
    # Signal 2: 'ciudadano/ciudadana' token counter.
    # threshold = max(clause_count, ciudadano_count).
    # Pattern B appointments contribute to ciudadano_count (not clause_count),
    # so the guard correctly requires N extractions for N Pattern-B appointments.
    clause_count = _count_appointment_clauses(processed)
    ciudadano_count = _count_ciudadano_tokens(processed)
    threshold = max(clause_count, ciudadano_count)
    if len(eventos) < threshold:
        return ExtractorResult(eventos=[], estado_extraccion=ESTADO_REQUIERE_REVISION)

    return ExtractorResult(eventos=eventos, estado_extraccion=ESTADO_PROCESADO)


# ── Private helpers ───────────────────────────────────────────────────────────

def _preprocess(sumario: str) -> str:
    """
    Strip the date prefix and optional 'tiene por objeto' preamble.

    Operations (applied in order):
    1. Remove 'DD DE MES DE YYYY .- ' from the start of the sumario.
       This prefix appears in every real Gaceta Oficial sumario but is not
       part of the designating verb or appointment structure.
    2. Remove 'tiene por objeto: ' preamble when present.  Some decrees wrap
       the appointment clause in an object phrase (e.g. 'El presente Decreto
       tiene por objeto: I. Designese CARGO, al ciudadano...').

    Returns the stripped string with leading/trailing whitespace removed.
    """
    text = _RE_DATE_PREFIX.sub("", sumario, count=1)
    text = _RE_PREAMBLE_STRIP.sub("", text, count=1)
    return text.strip()


def _extract_appointments(sumario: str) -> list:
    """
    Extract individual appointment dicts from a (pre-processed) sumario.

    Handles:
    - Single appointment (no incisos)
    - Multiple appointments separated by roman numeral incisos (I./II./III.)
    - Pattern A (name-first) and Pattern B (cargo-first) in each segment.

    Per-segment strategy:
    1. Try Pattern A (finditer with _RE_APPOINTMENT).
    2. If Pattern A yields nothing in this segment, try Pattern B (finditer
       with _RE_APPOINTMENT_B).
    This prevents double-counting and keeps the two patterns orthogonal.
    """
    segments = _split_by_incisos(sumario)
    eventos = []
    for segment in segments:
        # Pattern A: 'al ciudadano NAME, como CARGO'
        a_matches = list(_RE_APPOINTMENT.finditer(segment))
        if a_matches:
            for match in a_matches:
                ev = _build_evento_from_match(match, sumario)
                eventos.append(ev)
        else:
            # Pattern B: 'TRIGGER CARGO, al ciudadano Name'
            for match in _RE_APPOINTMENT_B.finditer(segment):
                ev = _build_evento_from_match_b(match, segment)
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
    segments = []
    for i, part in enumerate(parts):
        if i % 2 == 0 and part.strip():
            segments.append(part.strip())
    return segments if segments else [sumario]


def _build_evento_from_match(match, full_sumario: str) -> dict:
    """
    Build an appointment event dict from a Pattern A regex match.

    FIX A2: INTERINO is resolved from the per-match named group 'interino'
    captured by _RE_APPOINTMENT.  This ensures only the appointment whose
    cargo is preceded by 'INTERINO' receives interino=True, preventing
    cross-appointment contamination in multi-appointment decrees.
    """
    persona_nombre = match.group("nombre").strip()
    cargo = match.group("cargo").strip()
    entidad = None  # Pattern A: "de <X>" is intrinsic to the cargo title, not a split entity
    interino = bool(match.group("interino"))

    return {
        "persona_nombre": persona_nombre,
        "persona_nombre_normalizado": _normalize_name(persona_nombre),
        "cargo": cargo,
        "cargo_categoria": None,
        "entidad": entidad,
        "tipo_evento": "designacion",
        "interino": interino,
        "cargo_referenciado": None,  # Pattern A has no titular-reference phrase
        "estado_revision": "pendiente",
    }


def _build_evento_from_match_b(match, segment: str) -> dict:
    """
    Build an appointment event dict from a Pattern B regex match (cargo-first).

    Pattern B structure: '<TRIGGER> <CARGO>, al ciudadano <Name>, <current-title>,
    [mientras dure la ausencia del titular.]'

    Interino detection for Pattern B (two independent signals):
    1. 'INTERINO' or 'INTERINA' appears in the extracted cargo string.
       Example: 'MINISTRO INTERINO DE RELACIONES EXTERIORES'
    2. 'mientras dure la ausencia' appears anywhere in the segment text.
       Example: a permanent-style cargo still marks interim via this phrase.

    The name is trimmed of trailing commas and periods (segment terminators
    from the regex can leak into the last character of the nombre group).
    """
    persona_nombre = match.group("nombre").strip().rstrip(",.")
    cargo = match.group("cargo").strip().rstrip(",")

    interino = bool(
        _RE_INTERINO_IN_CARGO.search(cargo)
        or _RE_AUSENCIA.search(segment)
    )

    # Extract the referenced titular cargo when present.
    # Looks at the segment text starting from the end of the nombre group,
    # searching for ', <Cargo Titular>, mientras dure'.
    # Returns None when the phrase is absent (permanent appointment or interim
    # without a titular-context clause).
    cargo_referenciado: str | None = None
    after_nombre = segment[match.end("nombre"):]
    ref_match = _RE_CARGO_REFERENCIADO.match(after_nombre)
    if ref_match:
        cargo_referenciado = ref_match.group(1).strip()

    return {
        "persona_nombre": persona_nombre,
        "persona_nombre_normalizado": _normalize_name(persona_nombre),
        "cargo": cargo,
        "cargo_categoria": None,
        "entidad": None,
        "tipo_evento": "designacion",
        "interino": interino,
        "cargo_referenciado": cargo_referenciado,
        "estado_revision": "pendiente",
    }


def _count_appointment_clauses(sumario: str) -> int:
    """
    Count 'como [INTERINO] <Cargo-start>' appointment clauses in the sumario.

    Round 5/6/7 completeness guard signal 1.  Independent of ciudadano prefix.
    Bias to safety: over-count routes to requiere_revision (safe); under-count
    would silently mark procesado (not acceptable).
    """
    return len(_RE_APPOINTMENT_CLAUSE.findall(sumario))


def _count_ciudadano_tokens(sumario: str) -> int:
    """
    Count singular 'ciudadano'/'ciudadana' prefix tokens in the sumario.

    Round 7 completeness guard signal 2.  Catches co-appointments with the
    ciudadano prefix but a non-'como' connector ('en calidad de', etc.).
    Also serves as the guard signal for Pattern B appointments (which have
    no 'como' clause but always carry 'al ciudadano').
    Singular-only: plural forms in bulk preambles must not inflate the count.
    """
    return len(_RE_CIUDADANO_TOKEN.findall(sumario))


def _normalize_name(name: str) -> str:
    """
    Return a normalized version of a person's name:
    - Decompose Unicode (NFD) then encode to ASCII (drops diacritics/accents)
    - Lowercase
    - Collapse whitespace

    Uses stdlib unicodedata only (no unidecode dependency in pure logic).
    Works for both ALL-CAPS names ('RICARDO ERICK SANJINES CHAVEZ') and
    Title Case names with accents ('Jose Luis Lupo Flores').
    """
    nfd = unicodedata.normalize("NFD", name)
    ascii_bytes = nfd.encode("ascii", errors="ignore")
    normalized = ascii_bytes.decode("ascii").lower()
    return " ".join(normalized.split())
