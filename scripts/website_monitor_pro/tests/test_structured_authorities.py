from __future__ import annotations

import os
import sys

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from structured_authorities import extract_structured_authorities


TABLE_CONFIG = {
    "version": 1,
    "container_selector": "#authority-table",
    "row_selector": "tbody > tr",
    "person_selector": "td:nth-of-type(2)",
    "role_selector": "td:nth-of-type(1)",
}
CARD_CONFIG = {
    "version": 1,
    "container_selector": ".authority-cards",
    "row_selector": ".authority-card",
    "person_selector": ".person",
    "role_selector": ".role",
    "min_rows": 2,
    "max_rows": 3,
}
TABLE_HTML = """
<nav><span class="person">Navigation Person</span></nav>
<section id="authority-table"><table><thead><tr><th>Role</th><th>Person</th></tr></thead>
<tbody><tr><td> Rectora </td><td> Dra. María Álvarez </td></tr>
<tr><td>Vicerrector</td><td> Luis Pérez </td></tr></tbody></table>
<p>Biography: Former Rectora Another Person</p></section>
"""
CARD_HTML = """
<nav class="authority-card"><span class="role">Menu</span><span class="person">Ignored</span></nav>
<section class="authority-cards"><article class="authority-card"><b class="role">Presidenta</b><span class="person"> Ana  Quispe </span></article>
<article class="authority-card"><b class="role">Secretary</b><span class="person">José Núñez</span></article></section>
"""


def _pairs(result: object) -> list[dict[str, str]]:
    return [authority.to_dict() for authority in result.authorities]


def test_extracts_scoped_table_rows_and_preserves_display_values() -> None:
    result = extract_structured_authorities(TABLE_HTML, TABLE_CONFIG)

    assert result.status == "ready"
    assert result.error_code is None
    assert _pairs(result) == [
        {"cargo": "Rectora", "persona": "Dra. María Álvarez"},
        {"cargo": "Vicerrector", "persona": "Luis Pérez"},
    ]
    assert result.config_fingerprint


def test_extracts_scoped_card_rows() -> None:
    result = extract_structured_authorities(CARD_HTML, CARD_CONFIG)

    assert result.status == "ready"
    assert _pairs(result) == [
        {"cargo": "Presidenta", "persona": "Ana Quispe"},
        {"cargo": "Secretary", "persona": "José Núñez"},
    ]


def test_effective_defaults_and_key_order_share_a_fingerprint() -> None:
    reordered = dict(reversed(list(TABLE_CONFIG.items())))
    explicit_defaults = {**reordered, "min_rows": 1, "max_rows": 200}

    defaulted = extract_structured_authorities(TABLE_HTML, TABLE_CONFIG)
    explicit = extract_structured_authorities(TABLE_HTML, explicit_defaults)

    assert defaulted.config_fingerprint == explicit.config_fingerprint
    assert defaulted.config_fingerprint == extract_structured_authorities(TABLE_HTML, reordered).config_fingerprint


@pytest.mark.parametrize("change", [
    {"person_selector": "td:nth-of-type(1)"},
    {"min_rows": 2},
    {"max_rows": 2},
])
def test_effective_config_changes_change_fingerprint(change: dict[str, object]) -> None:
    baseline = extract_structured_authorities(TABLE_HTML, TABLE_CONFIG)
    changed = extract_structured_authorities(TABLE_HTML, {**TABLE_CONFIG, **change})

    assert changed.config_fingerprint != baseline.config_fingerprint


def test_absent_config_is_unconfigured() -> None:
    result = extract_structured_authorities(TABLE_HTML, None)

    assert result.status == "unconfigured"
    assert result.authorities == ()
    assert result.config_fingerprint is None
    assert result.error_code is None


@pytest.mark.parametrize("config", [
    {**TABLE_CONFIG, "version": 2},
    {**TABLE_CONFIG, "version": True},
    {**TABLE_CONFIG, "container_selector": 1},
    {**TABLE_CONFIG, "row_selector": " "},
    {**TABLE_CONFIG, "extra": "no"},
    {**TABLE_CONFIG, "person_selector": "x" * 501},
    {**TABLE_CONFIG, "min_rows": True},
    {**TABLE_CONFIG, "min_rows": 0},
    {**TABLE_CONFIG, "max_rows": 201},
    {**TABLE_CONFIG, "min_rows": 3, "max_rows": 2},
    {**TABLE_CONFIG, "role_selector": "[unterminated"},
])
def test_invalid_config_is_incomplete_without_fingerprint(config: object) -> None:
    result = extract_structured_authorities(TABLE_HTML, config)

    assert result.status == "incomplete"
    assert result.authorities == ()
    assert result.config_fingerprint is None
    assert result.error_code == "invalid_config"


@pytest.mark.parametrize("html, expected_error", [
    (TABLE_HTML.replace('id="authority-table"', 'id="authority-table" id="other"') + TABLE_HTML, "container_scope_invalid"),
    ("<section id=\"authority-table\"><table><tbody><tr><td>R</td><td>P</td></tr></tbody></table></section>".replace('id="authority-table"', 'id="authority-table"') * 0, "container_scope_invalid"),
])
def test_invalid_container_scope_returns_no_partial_results(html: str, expected_error: str) -> None:
    result = extract_structured_authorities(html, TABLE_CONFIG)

    assert result.status == "incomplete"
    assert result.authorities == ()
    assert result.error_code == expected_error
    assert result.config_fingerprint


def test_missing_and_duplicate_fields_fail_closed() -> None:
    missing = TABLE_HTML.replace("Luis Pérez", "")
    duplicate = TABLE_HTML.replace("Luis Pérez </td>", "Luis Pérez </td><td>Extra</td>")
    duplicate_config = {**TABLE_CONFIG, "person_selector": "td"}

    assert extract_structured_authorities(missing, TABLE_CONFIG).authorities == ()
    result = extract_structured_authorities(duplicate, duplicate_config)
    assert result.status == "incomplete"
    assert result.authorities == ()
    assert result.error_code == "field_scope_invalid"


def test_nested_row_scopes_fail_closed() -> None:
    nested = CARD_HTML.replace(
        "<span class=\"person\">José Núñez</span>",
        "<article class=\"authority-card\"><span class=\"role\">Nested</span><span class=\"person\">Nested Person</span></article><span class=\"person\">José Núñez</span>",
    )

    result = extract_structured_authorities(nested, CARD_CONFIG)

    assert result.status == "incomplete"
    assert result.authorities == ()
    assert result.error_code == "row_count_out_of_bounds"


@pytest.mark.parametrize("row", [
    '<article class="authority-card"><div class="person">Alice <span><em class="role">Rector</em></span></div></article>',
    '<article class="authority-card"><div class="role">Rector <span><em class="person">Alice</em></span></div></article>',
])
def test_ancestor_descendant_fields_fail_closed(row: str) -> None:
    html = f'<section class="authority-cards">{row}</section>'
    config = {**CARD_CONFIG, "min_rows": 1}

    result = extract_structured_authorities(html, config)

    assert result.status == "incomplete"
    assert result.authorities == ()
    assert result.error_code == "field_scope_invalid"
    assert result.config_fingerprint


def test_nested_but_sibling_fields_remain_valid() -> None:
    html = """
    <section class="authority-cards"><article class="authority-card">
      <div><span class="person">Alice <em>Example</em></span><span class="role"><em>Rector</em></span></div>
    </article></section>
    """
    result = extract_structured_authorities(html, {**CARD_CONFIG, "min_rows": 1})

    assert result.status == "ready"
    assert _pairs(result) == [{"cargo": "Rector", "persona": "Alice Example"}]


def test_partial_rows_and_normalized_duplicate_pairs_fail_closed() -> None:
    partial = TABLE_HTML.replace("<td>Vicerrector</td><td> Luis Pérez </td>", "<td>Vicerrector</td>")
    duplicate = TABLE_HTML.replace("Vicerrector</td><td> Luis Pérez", "Rectora</td><td> María Alvarez")

    assert extract_structured_authorities(partial, TABLE_CONFIG).authorities == ()
    result = extract_structured_authorities(duplicate, TABLE_CONFIG)
    assert result.status == "incomplete"
    assert result.authorities == ()
    assert result.error_code == "duplicate_authority"


@pytest.mark.parametrize("config", [
    {**TABLE_CONFIG, "min_rows": 3},
    {**TABLE_CONFIG, "max_rows": 1},
])
def test_row_count_outside_configured_bounds_fails_closed(config: dict[str, object]) -> None:
    result = extract_structured_authorities(TABLE_HTML, config)

    assert result.status == "incomplete"
    assert result.authorities == ()
    assert result.error_code == "row_count_out_of_bounds"


def test_oversized_html_and_field_are_bounded() -> None:
    oversized_html = "x" * 2_000_001
    long_person = "x" * 301
    long_field_html = TABLE_HTML.replace("Luis Pérez", long_person)

    assert extract_structured_authorities(oversized_html, TABLE_CONFIG).error_code == "html_too_large"
    result = extract_structured_authorities(long_field_html, TABLE_CONFIG)
    assert result.status == "incomplete"
    assert result.authorities == ()
    assert result.error_code == "field_too_long"
