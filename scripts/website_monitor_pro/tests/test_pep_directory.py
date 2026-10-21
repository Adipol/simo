"""Offline contract tests: synthetic people/institutions only."""
import dataclasses
import importlib.util
from datetime import datetime, timedelta, timezone
from pathlib import Path
import sys
import unittest
from zoneinfo import ZoneInfo

MODULE_PATH = Path(__file__).resolve().parents[1] / "pep_directory.py"
spec = importlib.util.spec_from_file_location("pep_directory", MODULE_PATH)
directory = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = directory
spec.loader.exec_module(directory)

Observation = directory.Observation
Position = directory.Position
Validity = directory.Validity
Kind = directory.Kind
Disposition = directory.Disposition
compare = directory.compare

T1 = datetime(2025, 1, 1, tzinfo=timezone.utc)
T2 = datetime(2025, 1, 2, tzinfo=timezone.utc)


def position(person="Ana Ejemplo", role="Jurídica", unit="Sede Norte",
             condition="titular", evidence="raw role card"):
    return Position(unit, role, person, condition, evidence)


def observation(*positions, source="Instituto Ficticio/directorio", revision="v1",
                observed_at=T1, validity=Validity.VALID, evidence="raw page"):
    return Observation(source, revision, observed_at, validity, tuple(positions), evidence)


class DirectoryContractTest(unittest.TestCase):
    def test_first_valid_observation_is_baseline_without_events(self):
        current = observation(position())
        result = compare(None, current)
        self.assertEqual(result.disposition, Disposition.BASELINE)
        self.assertIs(result.baseline, current)
        self.assertEqual(result.events, ())
        self.assertEqual(result.reasons, ())

    def test_replacement_retains_raw_evidence_and_unknown_effective_date(self):
        before = observation(position(evidence="old card"), evidence="old page")
        after = observation(position("Beatriz Ejemplo", evidence="new card"),
                            observed_at=T2, evidence="new page")
        result = compare(before, after)
        self.assertEqual(result.disposition, Disposition.COMPARED)
        self.assertIs(result.baseline, after)
        self.assertEqual(result.reasons, ())
        event, = result.events
        self.assertEqual(event.kind, Kind.POSSIBLE_REPLACEMENT)
        self.assertEqual((event.source, event.unit, event.role),
                         (after.source, "Sede Norte", "Jurídica"))
        self.assertIs(event.before, before.positions[0])
        self.assertIs(event.after, after.positions[0])
        self.assertEqual(event.previous_observed_at, T1)
        self.assertEqual(event.detected_at, T2)
        self.assertEqual((event.previous_evidence, event.current_evidence),
                         ("old page", "new page"))
        self.assertIsNone(event.effective_date)
        self.assertIsNone(dataclasses.asdict(event)["effective_date"])

    def test_classifications_and_unchanged_refresh(self):
        cases = [("Ana Ejemplo", "titular", Kind.NO_CHANGE),
                 ("Ana Ejemplo", "interina", Kind.CONDITION_CHANGE),
                 ("Ana Ejemplo", "a.i.", Kind.CONDITION_CHANGE),
                 (None, None, Kind.POSSIBLE_DEPARTURE),
                 ("Beatriz Ejemplo", "suplente", Kind.POSSIBLE_REPLACEMENT)]
        before = observation(position())
        for person, condition, expected in cases:
            with self.subTest(expected=expected):
                after = observation(position(person, condition=condition), observed_at=T2,
                                    evidence="latest raw evidence")
                result = compare(before, after)
                self.assertEqual(result.events[0].kind, expected)
                self.assertIs(result.baseline, after)
                self.assertEqual(result.baseline.observed_at, T2)
                self.assertEqual(result.baseline.evidence, "latest raw evidence")

    def test_new_role_and_missing_or_renamed_role_require_distinct_classifications(self):
        before = observation(position())
        after = observation(position("Laura Ejemplo", role="Asesoría"), observed_at=T2)
        events = compare(before, after).events
        self.assertEqual([(e.role, e.kind) for e in events],
                         [("Asesoría", Kind.FIRST_OBSERVATION),
                          ("Jurídica", Kind.REVIEW_REQUIRED)])
        self.assertIsNone(events[0].before)
        self.assertIsNone(events[1].after)

    def test_unit_boundaries_and_sources_never_pair(self):
        before = observation(position())
        after = observation(position("Beatriz Ejemplo", unit="Sede Sur"), observed_at=T2)
        self.assertEqual({e.kind for e in compare(before, after).events},
                         {Kind.FIRST_OBSERVATION, Kind.REVIEW_REQUIRED})
        other_source = dataclasses.replace(after, source="Otra Institución/directorio")
        result = compare(before, other_source)
        self.assertEqual(result.disposition, Disposition.REJECTED)
        self.assertIs(result.baseline, before)
        self.assertEqual(result.reasons, ("source_mismatch",))
        self.assertEqual(result.events, ())

    def test_revision_changes_and_unknown_revisions_rebaseline(self):
        before = observation(position())
        for revision in ("v2", None):
            after = observation(position("Beatriz Ejemplo"), revision=revision, observed_at=T2)
            result = compare(before, after)
            self.assertEqual(result.disposition, Disposition.REBASELINED)
            self.assertIs(result.baseline, after)
            self.assertEqual(result.events, ())
            self.assertEqual(result.reasons, ("revision_boundary",))
        unknown = dataclasses.replace(before, revision=None)
        self.assertEqual(compare(unknown, dataclasses.replace(unknown, observed_at=T2)).disposition,
                         Disposition.REBASELINED)

    def test_reversible_mojibake_is_not_replacement_and_raw_name_survives(self):
        for name in ("María Ejemplo", "Peña Ejemplo"):
            for codec in ("latin1", "cp1252"):
                corrupted = name.encode("utf8").decode(codec)
                before = observation(position(name))
                after = observation(position(corrupted), observed_at=T2)
                result = compare(before, after)
                self.assertEqual(result.events[0].kind, Kind.NO_CHANGE)
                self.assertEqual(result.baseline.positions[0].person, corrupted)
                self.assertEqual(compare(after, dataclasses.replace(before, observed_at=T2)).events[0].kind,
                                 Kind.NO_CHANGE)

    def test_no_fuzzy_person_identity_or_arbitrary_byte_conversion(self):
        for name in ("Ana Ejempla", "Ána Ejemplo", "Béla Ejemplo"):
            result = compare(observation(position()), observation(position(name), observed_at=T2))
            self.assertEqual(result.events[0].kind, Kind.POSSIBLE_REPLACEMENT)

    def test_invalid_captures_empty_and_bad_structure_retain_baseline(self):
        before = observation(position())
        bad_inputs = [observation(position(), validity=v, observed_at=T2)
                      for v in Validity if v != Validity.VALID]
        bad_inputs += [observation(observed_at=T2),
                       observation(position(role=""), observed_at=T2),
                       observation(position(unit=""), observed_at=T2),
                       observation(position(person=""), observed_at=T2),
                       observation(position(evidence=""), observed_at=T2),
                       observation(position(), source="", observed_at=T2),
                       observation(position(), evidence="", observed_at=T2),
                       observation(position(), revision="", observed_at=T2),
                       observation(position(), observed_at=datetime(2025, 1, 2)),
                       dataclasses.replace(before, positions=[position()]),
                       observation(position(person="Ana � Ejemplo"), observed_at=T2),
                       observation(position(person="Ana Ã Ejemplo"), observed_at=T2)]
        for after in bad_inputs:
            with self.subTest(after=after):
                result = compare(before, after)
                self.assertEqual(result.disposition, Disposition.REJECTED)
                self.assertIs(result.baseline, before)
                self.assertEqual(result.events, ())
                self.assertTrue(result.reasons)
                self.assertIsNone(compare(None, after).baseline)

    def test_twenty_authorities_to_empty_never_produces_departures(self):
        before = observation(*(position("Persona Ejemplo", role=f"Cargo {i}") for i in range(20)))
        for revision in ("v1", "v2", None):
            result = compare(before, observation(revision=revision, observed_at=T2))
            self.assertEqual(result.disposition, Disposition.REJECTED)
            self.assertEqual(result.events, ())
            self.assertIs(result.baseline, before)
            self.assertEqual(result.reasons, ("empty_positions",))

    def test_rejection_reason_codes_and_uncertain_encodings(self):
        before = observation(position())
        cases = [(observation(position(), validity=v, observed_at=T2), "capture_" + v.value)
                 for v in Validity if v != Validity.VALID]
        cases += [(observation(observed_at=T2), "empty_positions"),
                  (observation(position(role=""), observed_at=T2), "invalid_structure"),
                  (observation(position(person="MarÃƒÂ­a Ejemplo"), observed_at=T2),
                   "uncertain_person_encoding"),
                  (observation(position(person="Ana � Ejemplo"), observed_at=T2),
                   "uncertain_person_encoding"),
                  (dataclasses.replace(before, validity="valid"), "invalid_structure"),
                  (dataclasses.replace(before, positions=("not a position",)), "invalid_structure"),
                  (dataclasses.replace(before, observed_at="2025-01-02"), "invalid_structure"),
                  (dataclasses.replace(before, positions=(position(condition=3),)), "invalid_structure"),
                  (None, "invalid_structure")]
        for current, reason in cases:
            with self.subTest(reason=reason, current=current):
                result = compare(before, current)
                self.assertEqual(result.reasons, (reason,))
                self.assertEqual(result.disposition, Disposition.REJECTED)
                self.assertIs(result.baseline, before)
                self.assertEqual(result.events, ())

    def test_duplicates_are_rejected_even_with_same_person(self):
        before = observation(position())
        for second in (position(), position("Beatriz Ejemplo")):
            result = compare(before, observation(position(), second, observed_at=T2))
            self.assertEqual(result.reasons, ("duplicate_position",))
            self.assertIs(result.baseline, before)
            self.assertEqual(result.events, ())

    def test_order_independent_and_no_page_evidence_change_trigger(self):
        positions = (position(), position("Carlos Ejemplo", role="Finanzas"))
        before = observation(*positions)
        after = observation(*reversed(positions), observed_at=T2, evidence="reordered page")
        result = compare(before, after)
        self.assertEqual(result.events, compare(dataclasses.replace(before, positions=tuple(reversed(positions))),
                                                dataclasses.replace(after, positions=positions)).events)
        self.assertEqual([e.kind for e in result.events], [Kind.NO_CHANGE, Kind.NO_CHANGE])

    def test_explicit_unnamed_role_is_not_missing_role(self):
        before = observation(position(None, condition=None))
        after = observation(position("Laura Ejemplo"), observed_at=T2)
        self.assertEqual(compare(before, after).events[0].kind, Kind.FIRST_OBSERVATION)
        unnamed = dataclasses.replace(before, observed_at=T2)
        self.assertEqual(compare(before, unnamed).events[0].kind, Kind.NO_CHANGE)

    def test_inputs_and_outputs_are_immutable_and_no_old_capture_rolls_back(self):
        before = observation(position())
        after = observation(position("Beatriz Ejemplo"), observed_at=T2)
        saved_before, saved_after = dataclasses.asdict(before), dataclasses.asdict(after)
        result = compare(before, after)
        self.assertEqual(dataclasses.asdict(before), saved_before)
        self.assertEqual(dataclasses.asdict(after), saved_after)
        for obj, field, value in ((before, "evidence", "changed"),
                                  (before.positions[0], "person", "changed"),
                                  (result, "events", ()),
                                  (result.events[0], "kind", Kind.NO_CHANGE)):
            with self.assertRaises(dataclasses.FrozenInstanceError):
                setattr(obj, field, value)
        rejected = compare(after, before)
        self.assertEqual(rejected.reasons, ("older_observation",))
        self.assertIs(rejected.baseline, after)
        self.assertEqual(rejected.events, ())

    def test_dst_fold_freshness_uses_absolute_instants_and_preserves_inputs(self):
        zone = ZoneInfo("America/New_York")
        ana = observation(position(evidence="Ana card"),
                          observed_at=datetime(2025, 11, 2, 1, 15, tzinfo=zone, fold=1),
                          evidence="Ana page")
        beatriz = observation(position("Beatriz Ejemplo", evidence="Beatriz card"),
                              observed_at=datetime(2025, 11, 2, 1, 45, tzinfo=zone, fold=0),
                              evidence="Beatriz page")
        for before, after, accepted in ((ana, beatriz, False), (beatriz, ana, True)):
            with self.subTest(accepted=accepted):
                saved_before, saved_after = dataclasses.asdict(before), dataclasses.asdict(after)
                result = compare(before, after)
                self.assertEqual(dataclasses.asdict(before), saved_before)
                self.assertEqual(dataclasses.asdict(after), saved_after)
                self.assertEqual(result.disposition,
                                 Disposition.COMPARED if accepted else Disposition.REJECTED)
                self.assertIs(result.baseline, after if accepted else before)
                self.assertEqual(result.reasons, () if accepted else ("older_observation",))
                if not accepted:
                    self.assertEqual(result.events, ())
                    continue
                event, = result.events
                self.assertEqual(event.kind, Kind.POSSIBLE_REPLACEMENT)
                self.assertIs(event.before, before.positions[0])
                self.assertIs(event.after, after.positions[0])
                self.assertIs(event.previous_observed_at, before.observed_at)
                self.assertIs(event.detected_at, after.observed_at)
                self.assertEqual((event.previous_evidence, event.current_evidence),
                                 (before.evidence, after.evidence))
                self.assertIsNone(event.effective_date)

    def test_equal_instants_with_different_offsets_are_allowed(self):
        before = observation(position(), observed_at=T1)
        stamp = datetime(2025, 1, 1, 1, tzinfo=timezone(timedelta(hours=1)))
        after = observation(position("Beatriz Ejemplo"), observed_at=stamp)
        for baseline, current in ((before, after), (after, before)):
            with self.subTest(current=current.observed_at):
                result = compare(baseline, current)
                self.assertEqual(result.disposition, Disposition.COMPARED)
                self.assertEqual(result.reasons, ())
                self.assertIs(result.baseline, current)
                event, = result.events
                self.assertIs(event.previous_observed_at, baseline.observed_at)
                self.assertIs(event.detected_at, current.observed_at)

    def test_freshness_at_datetime_bounds_is_exact_without_utc_overflow(self):
        for bound, hours in ((datetime.min, 1), (datetime.max, -1)):
            zone = timezone(timedelta(hours=hours))
            edge = bound.replace(tzinfo=zone)
            neighbor = edge + timedelta(microseconds=1 if hours > 0 else -1)
            older, newer = (edge, neighbor) if hours > 0 else (neighbor, edge)
            before = observation(position(), observed_at=older)
            after = observation(position("Beatriz Ejemplo"), observed_at=newer)
            with self.subTest(bound=bound):
                result = compare(before, after)
                self.assertEqual(result.disposition, Disposition.COMPARED)
                self.assertEqual(result.reasons, ())
                self.assertIs(result.baseline, after)
                event, = result.events
                self.assertIs(event.previous_observed_at, older)
                self.assertIs(event.detected_at, newer)
                rejected = compare(after, before)
                self.assertEqual(rejected.disposition, Disposition.REJECTED)
                self.assertEqual(rejected.reasons, ("older_observation",))
                self.assertIs(rejected.baseline, after)
                self.assertEqual(rejected.events, ())

    def test_invalid_supplied_baseline_is_not_trusted(self):
        with self.assertRaisesRegex(ValueError, "baseline"):
            compare(observation(), observation(position(), observed_at=T2))


if __name__ == "__main__":
    unittest.main()
