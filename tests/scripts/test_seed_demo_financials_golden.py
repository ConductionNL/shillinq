# SPDX-License-Identifier: EUPL-1.2
# Copyright (C) 2026 Conduction B.V.
"""Golden record of scripts/seed-demo-financials.py.

Each scenario runs main() against an in-memory API with a fixed "today"
and records every object it creates (per schema, the serial first one and
the rest in a stable order), every delete, the exit code and the printed
output. One scenario keeps every created object in full; the others pin
each schema's created objects by a sha256 of their sorted JSON. Re-record with SHILLINQ_GOLDEN_RECORD=1 only for an intended change.

Run: python3 -m unittest tests/scripts/test_seed_demo_financials_golden.py
"""

import contextlib
import datetime
import hashlib
import importlib.util
import io
import json
import os
import sys
import threading
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SCRIPT = os.path.join(ROOT, 'scripts', 'seed-demo-financials.py')
GOLDEN = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'fixtures', 'seed-demo-financials.golden.json')


def load_script():
    spec = importlib.util.spec_from_file_location('seed_demo_financials', SCRIPT)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class FakeApi:
    """Records creates and deletes; failure switches come from the scenario."""

    def __init__(self, scenario):
        self.stored = scenario.get('stored', {})
        self.create_fail = dict(scenario.get('create_fail', {}))
        self.delete_fail = dict(scenario.get('delete_fail', {}))
        self.created = {}
        self.first = {}
        self.deleted = []
        self.lock = threading.Lock()

    def list(self, schema):
        return self.stored.get(schema, [])

    def create(self, schema, obj):
        key = json.dumps(obj, sort_keys=True)
        with self.lock:
            if self.create_fail.get(schema, 0) > 0 and schema not in self.first:
                self.create_fail[schema] -= 1
                raise SystemExit(f'POST {schema} -> HTTP 503')
            self.first.setdefault(schema, obj)
            self.created.setdefault(schema, []).append(key)
        return obj

    def delete(self, schema, object_id):
        with self.lock:
            self.deleted.append([schema, object_id])
            if self.delete_fail.get(object_id, 0) > 0:
                self.delete_fail[object_id] -= 1
                raise SystemExit(f'DELETE {schema}/{object_id} -> HTTP 500')


def fixed_date(today):
    class FixedDate(datetime.date):
        @classmethod
        def today(cls):
            return cls(*today)
    return FixedDate


DEMO_GL = {'GLTransaction': [{'transactionNumber': 'DEMO-OPENING'}]}
WIPE_STORE = {
    'GLLine': [
        {'@self': {'id': 'l-1'}, 'transactionId': 'DEMO-OPENING'},
        {'id': 'l-2', 'transactionId': 'REAL-1'},
        {'transactionId': 'DEMO-NOID'},
    ],
    'ARInvoice': [{'id': 'a-1', 'invoiceNumber': 'DEMO-F-1'}, {'id': 'a-2', 'invoiceNumber': 'F-1'}],
    'CustomerMaster': [{'id': 'c-1', 'administrationId': 'demo-administration'}, {'id': 'c-2', 'customerId': 'DEMO-C1'}],
    'Payee': [{'id': 'p-1', 'vendorNumber': 'DEMO-V1'}],
    'CashflowWeek': [{'id': 'w-1', 'weekId': 'DEMO-CFW'}],
    'Account': [{'id': 'x-1', 'accountNumber': '1010', 'administrationId': 'other'}],
}

FULL = 'fresh, mid-month Wednesday'

SCENARIOS = {
    'fresh, mid-month Wednesday': {'today': (2026, 6, 17)},
    'fresh, january wraps the year': {'today': (2026, 1, 2)},
    'fresh, month end on a Monday': {'today': (2026, 8, 31)},
    'fresh, first of the month Sunday': {'today': (2026, 3, 1)},
    'fresh, december': {'today': (2025, 12, 31)},
    'existing accounts are skipped': {'today': (2026, 6, 17), 'stored': {'Account': [{'accountNumber': '1010'}, {'accountNumber': 4000}]}},
    'already seeded': {'today': (2026, 6, 17), 'stored': DEMO_GL},
    'real data only is not a seed': {'today': (2026, 6, 17), 'stored': {'GLTransaction': [{'transactionNumber': 'GL-1'}, {}]}},
    'wipe then reseed': {'today': (2026, 6, 17), 'argv': ['--wipe'], 'stored': WIPE_STORE},
    'wipe with a retried delete': {'today': (2026, 6, 17), 'argv': ['--wipe'], 'stored': WIPE_STORE, 'delete_fail': {'a-1': 1}},
    'wipe with a failed delete': {'today': (2026, 6, 17), 'argv': ['--wipe'], 'stored': WIPE_STORE, 'delete_fail': {'a-1': 2, 'p-1': 5}},
    'wipe leaves a seed behind': {'today': (2026, 6, 17), 'argv': ['--wipe'], 'stored': dict(WIPE_STORE, **DEMO_GL)},
    'first create retried': {'today': (2026, 6, 17), 'create_fail': {'Account': 1}},
    'first create fails twice': {'today': (2026, 6, 17), 'create_fail': {'CustomerMaster': 2}},
    'internal time on the day itself': {'today': (2026, 6, 6)},
    'wipe skips a row without id first': {
        'today': (2026, 6, 17),
        'argv': ['--wipe'],
        'stored': {'GLLine': [{'transactionId': 'DEMO-NOID'}, {'id': 'l-3', 'transactionId': 'DEMO-X'}]},
    },
    'custom url and user': {'today': (2026, 6, 17), 'argv': ['--url', 'http://x/', '--user', 'u', '--password', 'p']},
}


class SeedDemoFinancialsGoldenTest(unittest.TestCase):

    def run_scenario(self, scenario, full):
        module = load_script()
        api = FakeApi(scenario)
        made = []

        def factory(base, user, password):
            made.append([base, user, password])
            return api

        module.Api = factory
        module.date = fixed_date(scenario['today'])
        out = io.StringIO()
        code = None
        argv = sys.argv
        sys.argv = ['seed-demo-financials.py'] + scenario.get('argv', [])
        try:
            with contextlib.redirect_stdout(out):
                module.main()
        except SystemExit as e:
            code = str(e.code)
        finally:
            sys.argv = argv
        return {
            'api': made,
            'exit': code,
            'stdout': out.getvalue().splitlines(),
            'deleted': api.deleted,
            'schemaOrder': list(api.created.keys()),
            'first': api.first,
            'created': {schema: self.pin(sorted(rows), full) for schema, rows in api.created.items()},
        }

    @staticmethod
    def pin(rows, full):
        if full:
            return [json.loads(row) for row in rows]
        return {'count': len(rows), 'sha256': hashlib.sha256('\n'.join(rows).encode()).hexdigest()}

    def test_every_scenario_matches_the_golden_record(self):
        record = os.environ.get('SHILLINQ_GOLDEN_RECORD') == '1'
        golden = {}
        if not record:
            with open(GOLDEN) as handle:
                golden = json.load(handle)
        recorded = {}
        for name, scenario in SCENARIOS.items():
            with self.subTest(name):
                actual = json.loads(json.dumps(self.run_scenario(scenario, name == FULL)))
                if record:
                    recorded[name] = actual
                else:
                    self.assertEqual(golden.get(name), actual, name)
        if record:
            with open(GOLDEN, 'w') as handle:
                json.dump(recorded, handle, indent=1, sort_keys=True)
                handle.write('\n')


if __name__ == '__main__':
    unittest.main()
