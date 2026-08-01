"""Structured authority extraction and comparison contracts.

Snapshot JSON is a list of ``{"cargo": str, "persona": str}`` display values.
Cambio JSON is ``{"version": 1, "events": [...]}``, where every event has a
``type`` and nullable ``old``/``new`` objects with the same display fields.
"""

from __future__ import annotations

from dataclasses import dataclass
import re
import unicodedata
from typing import Callable


_TITLES = re.compile(r"^(?:dr|dra|lic|ing|sr|sra|señor|señora)\.?\s+", re.IGNORECASE)


def normalize_for_match(value: str) -> str:
    """Normalize case, accents, titles and whitespace without changing display text."""
    value = _TITLES.sub("", value.strip())
    value = unicodedata.normalize("NFKD", value.casefold())
    value = "".join(char for char in value if not unicodedata.combining(char))
    return " ".join(re.sub(r"[^\w\s]", " ", value).split())


@dataclass(frozen=True)
class Authority:
    cargo: str
    persona: str

    @property
    def normalized_cargo(self) -> str:
        return normalize_for_match(self.cargo)

    @property
    def normalized_persona(self) -> str:
        return normalize_for_match(self.persona)

    def to_dict(self) -> dict[str, str]:
        return {"cargo": self.cargo, "persona": self.persona}


def extract_divi_blurbs(html: str) -> list[Authority]:
    """Extract valid Divi authority cards, ignoring incomplete decorative blurbs."""
    from bs4 import BeautifulSoup

    soup = BeautifulSoup(html, "lxml")
    authorities: list[Authority] = []
    seen: set[tuple[str, str]] = set()
    for block in soup.select(".et_pb_blurb_container"):
        heading = block.find("h4")
        description = block.find("p")
        cargo = " ".join(heading.get_text(" ", strip=True).split()) if heading else ""
        persona = " ".join(description.get_text(" ", strip=True).split()) if description else ""
        authority = Authority(cargo=cargo, persona=persona)
        key = (authority.normalized_cargo, authority.normalized_persona)
        if not all(key) or key in seen:
            continue
        seen.add(key)
        authorities.append(authority)
    return authorities


_EXTRACTORS: dict[str, Callable[[str], list[Authority]]] = {
    "divi_blurb": extract_divi_blurbs,
}


def extract_authorities(html: str, extractor: str | None) -> list[Authority]:
    """Run the configured adapter, keeping unconfigured sources on the text path."""
    extractor_fn = _EXTRACTORS.get(extractor or "")
    return extractor_fn(html) if extractor_fn else []


def _event(event_type: str, old: Authority | None, new: Authority | None) -> dict[str, object]:
    return {
        "type": event_type,
        "old": old.to_dict() if old else None,
        "new": new.to_dict() if new else None,
    }


def compare_authorities(previous: list[Authority], current: list[Authority]) -> list[dict[str, object]]:
    """Return deterministic, non-overlapping authority events.

    Exact pairs are removed first. A replacement or job change is emitted only for
    a one-to-one normalized match, avoiding arbitrary matches in ambiguous lists.
    Remaining rows become removals/designations.
    """
    old_remaining = list(previous)
    new_remaining = list(current)

    for old in previous:
        match = next(
            (new for new in new_remaining if old.normalized_cargo == new.normalized_cargo
             and old.normalized_persona == new.normalized_persona),
            None,
        )
        if match is not None:
            old_remaining.remove(old)
            new_remaining.remove(match)

    events: list[dict[str, object]] = []
    for cargo in sorted({item.normalized_cargo for item in old_remaining} & {item.normalized_cargo for item in new_remaining}):
        old_matches = [item for item in old_remaining if item.normalized_cargo == cargo]
        new_matches = [item for item in new_remaining if item.normalized_cargo == cargo]
        if len(old_matches) == len(new_matches) == 1:
            old, new = old_matches[0], new_matches[0]
            events.append(_event("reemplazo", old, new))
            old_remaining.remove(old)
            new_remaining.remove(new)

    for persona in sorted({item.normalized_persona for item in old_remaining} & {item.normalized_persona for item in new_remaining}):
        old_matches = [item for item in old_remaining if item.normalized_persona == persona]
        new_matches = [item for item in new_remaining if item.normalized_persona == persona]
        if len(old_matches) == len(new_matches) == 1:
            old, new = old_matches[0], new_matches[0]
            events.append(_event("cambio_cargo", old, new))
            old_remaining.remove(old)
            new_remaining.remove(new)

    events.extend(_event("remocion", old, None) for old in sorted(old_remaining, key=lambda item: (item.normalized_cargo, item.normalized_persona)))
    events.extend(_event("designacion", None, new) for new in sorted(new_remaining, key=lambda item: (item.normalized_cargo, item.normalized_persona)))
    return events
