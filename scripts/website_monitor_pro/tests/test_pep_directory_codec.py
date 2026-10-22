"""Pure validated wire boundary; no database driver required."""
import unittest
from datetime import datetime
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from pep_directory import Observation, Position, Validity, compare
from pep_directory_codec import encode_observation, decode_observation, encode_event, decode_event


def observation(person='Ana Ejemplo', day=1):
    return Observation('synthetic:directory', 'v1', datetime.fromisoformat(f'2026-01-{day:02}T12:34:56.123456-04:00'),
                       Validity.VALID, (Position('Unidad', 'Cargo', person, 'a.i.', '<row> original </row>'),), '<html> María </html>')


class CodecTest(unittest.TestCase):
    def test_complete_observation_and_event_roundtrip(self):
        old, new = observation(), observation('Beatriz Ejemplo', 2)
        self.assertEqual(decode_observation(encode_observation(old)), old)
        event = compare(old, new).events[0]
        self.assertEqual(decode_event(encode_event(event)), event)
        self.assertEqual(encode_observation(old)['observed_at'], '2026-01-01T12:34:56.123456-04:00')
        self.assertIsNone(encode_event(event)['effective_date'])

    def test_strict_wire_rejects_extra_fields_types_and_naive_dates(self):
        for key, value in [('positions', 'not an array'), ('observed_at', '2026-01-01'), ('source', 4), ('extra', None)]:
            with self.subTest(key=key), self.assertRaises(ValueError):
                decode_observation(dict(encode_observation(observation()), **{key: value}))

    def test_diagnostic_capture_can_preserve_empty_evidence_and_groups(self):
        value = Observation('synthetic:directory', None, observation().observed_at, Validity.FAILED, (), '')
        self.assertEqual(decode_observation(encode_observation(value)), value)

    def test_all_group_and_condition_fields_survive(self):
        old = observation()
        new = Observation(old.source, old.revision, observation(day=2).observed_at, old.validity,
                          (Position('Unidad', 'Cargo', 'Ana Ejemplo', 'titular', 'different'),
                           Position('Unidad', 'Cargo', 'Carla Ejemplo', None, 'original Carla')), 'new raw')
        event = compare(old, new).events[0]
        self.assertTrue(event.condition_changes)
        self.assertEqual(decode_event(encode_event(event)), event)

    def test_effective_date_cannot_be_fabricated(self):
        event = compare(observation(), observation('Beatriz Ejemplo', 2)).events[0]
        with self.assertRaises(ValueError):
            decode_event(dict(encode_event(event), effective_date='2026-01-01'))
