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

Completeness guard (Round 5/6/7 — JD remediation):
- DUAL SIGNAL (Round 7 structural close):
  The guard now uses TWO independent signals and routes on the stricter one:
    1. 'como <Cargo>' clause counter (_count_appointment_clauses) — catches
       co-appointments that lack the 'ciudadano/ciudadana' prefix token.
    2. 'ciudadano/ciudadana' token counter (_count_ciudadano_tokens) — catches
       co-appointments that have the prefix but use a non-'como' connector
       (e.g. 'en calidad de', 'para desempeñar el cargo de').
  threshold = max(clause_count, ciudadano_count).
  If len(eventos) < threshold → requiere_revision, eventos=[].
- Round 7 also adds re.IGNORECASE to _RE_APPOINTMENT_CLAUSE so that
  'Como'/'COMO' connectors are counted in the clause counter (previously only
  lowercase 'como' was matched), closing the case-asymmetry gap where
  _RE_APPOINTMENT (IGNORECASE) could extract via 'COMO' but the clause counter
  would miss it, causing an under-count and a silent-drop.
- Round 6 fix: _RE_APPOINTMENT_CLAUSE charclass aligned with _RE_APPOINTMENT to
  include lowercase-start cargo letters.  Previously the counter used uppercase-
  only [A-ZÁÉÍÓÚÑÜ], missing 'como ministra'-style clauses and causing silent
  under-counts that wrongly routed decrees to procesado.
- When eventos is empty (no names parsed — bulk or raw), the guard is not
  reached; the decree returns requiere_detalle (human review for detail).
  This is intentional: requiere_detalle signals "could not extract any detail",
  while requiere_revision signals "extracted some but incomplete".

Accepted V1 residual (after Round 7):
  The ONLY silent-drop path that remains is a co-appointee that has NEITHER
  a 'ciudadano/ciudadana' prefix token NOR a 'como <Cargo>' clause in any
  casing — the comma-style pattern ('y a MARIA ROCHA, Ministra de Economía').
  This is intentional V1 behavior; a V2 fix would require a comma-style
  clause counter.  See TestExtractorV1AcceptedLimitations for the pinned test.
"""
import re
import unicodedata
from dataclasses import dataclass, field


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
# Groups: nombre, interino (per-appointment capture), cargo, entidad (optional)
# FIX A2: capture INTERINO as a named group so it is resolved per-match, not
# against the full sumario. This prevents a leading INTERINO in one inciso from
# propagating to subsequent appointments that do not carry the flag.
_RE_APPOINTMENT = re.compile(
    r"(?:al?)\s+ciudadan[oa]\s+"
    r"(?P<nombre>[A-ZÁÉÍÓÚÑÜÀÈÌÒÙÂÊÎÔÛÃÕ][A-ZÁÉÍÓÚÑÜÀÈÌÒÙÂÊÎÔÛÃÕ\s]+?)"
    r"\s+como\s+(?P<interino>INTERINO\s+)?(?P<cargo>[A-ZÁÉÍÓÚÑÜA-Za-záéíóúñ][^\.,\n;]+?)"
    r"(?:\s+de\s+(?P<entidad>[^\.,\n;]+?))?[,\.]",
    re.IGNORECASE | re.UNICODE,
)

# Appointment-clause counter for the completeness guard (Round 5/6/7).
# Counts 'como [INTERINO] <Cargo-start>' occurrences, independent of whether
# each appointee is preceded by the 'ciudadano/ciudadana' prefix token.
# This closes the Round-4 residual gap where a co-appointment phrased without
# the prefix token escaped both the extractor and the old ciudadano counter.
#
# Design choices:
# - Round 7 fix: re.IGNORECASE added so that 'Como'/'COMO' connectors are
#   counted alongside lowercase 'como'.  _RE_APPOINTMENT already uses
#   IGNORECASE, so without this flag the clause counter would under-count
#   any co-appointment whose connector is title-cased or all-caps, while
#   _RE_APPOINTMENT could still extract via it — causing a false procesado.
# - Charclass aligned with _RE_APPOINTMENT (Round 6 fix): includes both
#   uppercase AND lowercase cargo-start letters.  _RE_APPOINTMENT accepts
#   lowercase-start cargo (re.IGNORECASE, charclass [A-ZÁÉÍÓÚÑÜA-Za-záéíóúñ]);
#   the counter must mirror this so a 'como ministra' clause is not missed.
#   The previous uppercase-only [A-ZÁÉÍÓÚÑÜ] caused the counter to under-count
#   when a co-appointee's cargo started with a lowercase letter, silently
#   routing the decree to procesado instead of requiere_revision.
# - Bias to safety (over-count preferred over under-count): a false positive
#   (extra clause counted) routes to requiere_revision, which is safe — human
#   review queue. A false negative (missed clause) would silently mark procesado,
#   which is NOT acceptable.
_RE_APPOINTMENT_CLAUSE = re.compile(
    r"\bcomo\s+(?:INTERINO\s+)?[A-ZÁÉÍÓÚÑÜA-Za-záéíóúñ]",
    re.IGNORECASE | re.UNICODE,
)

# Ciudadano/ciudadana token counter for the completeness guard (Round 7).
# Counts singular 'ciudadano'/'ciudadana' prefix tokens (case-insensitive).
# This closes the gap where a co-appointment has the prefix token but uses a
# non-'como' connector ('en calidad de', 'para desempeñar el cargo de', etc.):
# - _RE_APPOINTMENT cannot extract it (no 'como' connector).
# - _RE_APPOINTMENT_CLAUSE cannot count it (no 'como' clause).
# With this counter running alongside clause_count, threshold = max(clause_count,
# ciudadano_count) catches the case where ciudadano_count exceeds clause_count.
# Singular-only match (\bciudadan[oa]\b): plural forms ('ciudadanos',
# 'ciudadanas') appear in bulk-decree preambles and must not inflate the count.
_RE_CIUDADANO_TOKEN = re.compile(r"\bciudadan[oa]\b", re.IGNORECASE | re.UNICODE)

# Roman numeral inciso separator: I. / II. / III. etc.
# Uppercase only — real Gaceta incisos are always uppercase.
# Dropping re.IGNORECASE prevents lowercase tokens embedded in names or
# district designations (e.g. "Distrito v. de La Paz") from being mistaken
# for inciso separators, which would split the segment before the terminating
# period and silently drop the preceding appointment.
_RE_INCISO = re.compile(
    r"\b(I{1,3}V?|VI{0,3}|IX|IV|V|X)\.\s+",
)

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

    # Round-7 dual-signal completeness guard.
    # Two independent signals are combined; we route on the STRICTER one.
    # Signal 1: 'como <Cargo>' clause counter — catches co-appointments that
    #   lack the 'ciudadano/ciudadana' prefix (Round 5/6).
    # Signal 2: 'ciudadano/ciudadana' token counter — catches co-appointments
    #   that have the prefix but use a non-'como' connector such as
    #   'en calidad de' or 'para desempeñar el cargo de' (Round 7).
    # threshold = max(clause_count, ciudadano_count) uses whichever signal
    # sees the higher appointment count, preventing either path from being
    # evaded alone.  If fewer eventos were extracted than the threshold,
    # at least one appointment was silently dropped — route to requiere_revision
    # so the human-review queue can re-extract from scratch.
    clause_count = _count_appointment_clauses(sumario)
    ciudadano_count = _count_ciudadano_tokens(sumario)
    threshold = max(clause_count, ciudadano_count)
    if len(eventos) < threshold:
        return ExtractorResult(eventos=[], estado_extraccion=ESTADO_REQUIERE_REVISION)

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

    FIX A2: INTERINO is now resolved from the per-match named group 'interino'
    captured by _RE_APPOINTMENT.  This ensures that only the appointment whose
    cargo is preceded by 'INTERINO' receives interino=True.  The full-sumario
    scan via _RE_INTERINO was removed from this path to prevent cross-appointment
    contamination in multi-appointment decrees.
    """
    persona_nombre = match.group("nombre").strip()
    cargo = match.group("cargo").strip()
    entidad = (match.group("entidad") or "").strip() or None
    interino = bool(match.group("interino"))

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


def _count_appointment_clauses(sumario: str) -> int:
    """
    Count 'como [INTERINO] <Cargo-start>' appointment clauses in the sumario.

    Round-5/6/7 completeness guard: counts appointment-structure markers that
    are independent of the 'ciudadano/ciudadana' prefix token.  Each occurrence
    of 'como' (any casing after Round 7) followed by an optional 'INTERINO' and
    a cargo-start letter represents a distinct appointment clause, regardless of
    how the appointee was introduced.

    Bias to safety: the pattern over-counts rather than under-counts.  A false
    positive routes to requiere_revision (human review — safe); a false negative
    would silently mark procesado (not acceptable).
    """
    return len(_RE_APPOINTMENT_CLAUSE.findall(sumario))


def _count_ciudadano_tokens(sumario: str) -> int:
    """
    Count singular 'ciudadano'/'ciudadana' prefix tokens in the sumario.

    Round-7 completeness guard: provides the second signal in the dual-signal
    max() threshold.  This counter catches co-appointments that carry the
    'ciudadano/ciudadana' prefix but use a non-'como' connector such as
    'en calidad de' — cases that escape both _RE_APPOINTMENT (no 'como'
    connector) and _count_appointment_clauses (no 'como' clause).

    Singular-only: the word-boundary anchor (\b) followed by [oa]\b ensures
    that plural forms ('ciudadanos', 'ciudadanas') in bulk-decree preambles
    are not matched and do not inflate the count.

    Bias to safety: over-count preferred.  A false positive routes to
    requiere_revision (safe); a false negative silently marks procesado (unsafe).
    """
    return len(_RE_CIUDADANO_TOKEN.findall(sumario))


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
