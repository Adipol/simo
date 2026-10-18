"""Pure, bounded extraction for configured authority table and card layouts."""

from __future__ import annotations

from dataclasses import dataclass
import hashlib
import json
from typing import Mapping

import soupsieve
from bs4 import BeautifulSoup
from bs4.element import Tag

from authorities import Authority


_CONFIG_VERSION = 1
_MAX_HTML_LENGTH = 2_000_000
_MAX_SELECTOR_LENGTH = 500
_MAX_FIELD_LENGTH = 300
_DEFAULT_MIN_ROWS = 1
_DEFAULT_MAX_ROWS = 200
_REQUIRED_SELECTORS = (
    "container_selector",
    "row_selector",
    "person_selector",
    "role_selector",
)
_ALLOWED_CONFIG_FIELDS = frozenset(("version", *_REQUIRED_SELECTORS, "min_rows", "max_rows"))


@dataclass(frozen=True)
class StructuredAuthoritiesResult:
    """A bounded parser result; authorities exist only for a complete extraction."""

    status: str
    authorities: tuple[Authority, ...]
    config_fingerprint: str | None
    error_code: str | None


def extract_structured_authorities(html: str, config: object) -> StructuredAuthoritiesResult:
    """Extract configured person-role pairs without fetching or retaining source HTML.

    Valid configuration always gets a deterministic fingerprint, including when
    the page cannot produce a complete authority roster.
    """
    effective_config = _validate_config(config)
    if effective_config is None:
        if config is None:
            return _result("unconfigured")
        return _result("incomplete", error_code="invalid_config")

    fingerprint = _fingerprint(effective_config)
    if not isinstance(html, str):
        return _result("incomplete", fingerprint, "html_invalid")
    if len(html) > _MAX_HTML_LENGTH:
        return _result("incomplete", fingerprint, "html_too_large")

    soup = BeautifulSoup(html, "lxml")
    container = _one_match(effective_config["container_selector"], soup)
    if container is None:
        return _result("incomplete", fingerprint, "container_scope_invalid")

    rows = soupsieve.select(
        effective_config["row_selector"],
        container,
        limit=effective_config["max_rows"] + 1,
    )
    if not _valid_row_scope(rows) or not _within_row_bounds(rows, effective_config):
        return _result("incomplete", fingerprint, "row_count_out_of_bounds")

    authorities: list[Authority] = []
    seen: set[tuple[str, str]] = set()
    for row in rows:
        person = _one_match(effective_config["person_selector"], row)
        role = _one_match(effective_config["role_selector"], row)
        if person is None or role is None or _fields_overlap(person, role):
            return _result("incomplete", fingerprint, "field_scope_invalid")

        persona = _display_text(person)
        cargo = _display_text(role)
        if not persona or not cargo:
            return _result("incomplete", fingerprint, "field_scope_invalid")
        if len(persona) > _MAX_FIELD_LENGTH or len(cargo) > _MAX_FIELD_LENGTH:
            return _result("incomplete", fingerprint, "field_too_long")

        authority = Authority(cargo=cargo, persona=persona)
        identity = (authority.normalized_cargo, authority.normalized_persona)
        if not all(identity) or identity in seen:
            return _result("incomplete", fingerprint, "duplicate_authority")
        seen.add(identity)
        authorities.append(authority)

    return StructuredAuthoritiesResult(
        status="ready",
        authorities=tuple(authorities),
        config_fingerprint=fingerprint,
        error_code=None,
    )


def _validate_config(config: object) -> dict[str, object] | None:
    if not isinstance(config, Mapping) or set(config) - _ALLOWED_CONFIG_FIELDS:
        return None
    if not set(_REQUIRED_SELECTORS) | {"version"} <= set(config):
        return None
    if type(config.get("version")) is not int or config["version"] != _CONFIG_VERSION:
        return None

    effective: dict[str, object] = {"version": _CONFIG_VERSION}
    for field in _REQUIRED_SELECTORS:
        value = config.get(field)
        if not isinstance(value, str):
            return None
        value = value.strip()
        if not value or len(value) > _MAX_SELECTOR_LENGTH:
            return None
        try:
            soupsieve.compile(value)
        except soupsieve.SelectorSyntaxError:
            return None
        effective[field] = value

    min_rows = config.get("min_rows", _DEFAULT_MIN_ROWS)
    max_rows = config.get("max_rows", _DEFAULT_MAX_ROWS)
    if type(min_rows) is not int or type(max_rows) is not int:
        return None
    if not 1 <= min_rows <= max_rows <= _DEFAULT_MAX_ROWS:
        return None
    effective["min_rows"] = min_rows
    effective["max_rows"] = max_rows
    return effective


def _fingerprint(effective_config: Mapping[str, object]) -> str:
    canonical_json = json.dumps(
        effective_config,
        sort_keys=True,
        separators=(",", ":"),
        ensure_ascii=True,
    )
    return hashlib.sha256(canonical_json.encode("ascii")).hexdigest()


def _one_match(selector: str, scope: Tag | BeautifulSoup) -> Tag | None:
    matches = soupsieve.select(selector, scope, limit=2)
    return matches[0] if len(matches) == 1 else None


def _fields_overlap(person: Tag, role: Tag) -> bool:
    return person is role or _is_descendant(person, role) or _is_descendant(role, person)


def _is_descendant(node: Tag, ancestor: Tag) -> bool:
    return any(descendant is node for descendant in ancestor.descendants)


def _valid_row_scope(rows: list[Tag]) -> bool:
    return all(not any(other is not row and row in other.descendants for other in rows) for row in rows)


def _within_row_bounds(rows: list[Tag], config: Mapping[str, object]) -> bool:
    return int(config["min_rows"]) <= len(rows) <= int(config["max_rows"])


def _display_text(node: Tag) -> str:
    return " ".join(node.get_text(" ", strip=True).split())


def _result(
    status: str,
    config_fingerprint: str | None = None,
    error_code: str | None = None,
) -> StructuredAuthoritiesResult:
    return StructuredAuthoritiesResult(
        status=status,
        authorities=(),
        config_fingerprint=config_fingerprint,
        error_code=error_code,
    )
