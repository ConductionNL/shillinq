#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
# Copyright (C) 2026 Conduction B.V.
#
# Seed a coherent 12-month financial demo administration into the
# shillinq register, so the Financial overview dashboard (KPIs,
# turnover, margin, cashflow, billable hours, open debiteuren /
# crediteuren) renders a meaningful story on a fresh environment.
#
# The story: "Demo Consultancy BV", a small consultancy with three
# consultants, growing revenue (two AR invoices per month), a stable
# cost base (five AP invoices per month), monthly bank settlement
# batches, a handful of deliberately open/overdue invoices, hour
# registrations split billable / non-billable, and a 13-week
# cashflow forecast.
#
# All seeded objects carry the DEMO- prefix on their functional
# numbers and administrationId "demo-administration", which makes
# the seed idempotent (re-running aborts unless --wipe) and fully
# removable (--wipe deletes only demo objects).
#
# Usage:
#   python3 scripts/seed-demo-financials.py [--url http://localhost:8080]
#       [--user admin] [--password admin] [--wipe]
#
# @spec openspec/changes/financial-dashboard-graphs/specs/financial-dashboard-graphs/spec.md

import argparse
import base64
import json
import sys
import time
import urllib.request
import urllib.error
from concurrent.futures import ThreadPoolExecutor
from datetime import date, timedelta

REGISTER = 'shillinq'
ADMIN_ID = 'demo-administration'
PREFIX = 'DEMO-'

ACCOUNTS = [
    ('0800', 'Eigen vermogen', 'equity'),
    ('1010', 'Bank - zakelijke rekening', 'assets'),
    ('1300', 'Debiteuren', 'assets'),
    ('1520', 'BTW af te dragen', 'liabilities'),
    ('1600', 'Crediteuren', 'liabilities'),
    ('4000', 'Personeelskosten', 'expenses'),
    ('4400', 'Huisvesting', 'expenses'),
    ('4500', 'Software en hosting', 'expenses'),
    ('4600', 'Marketing', 'expenses'),
    ('4700', 'Reiskosten', 'expenses'),
    ('8000', 'Omzet consultancy', 'revenue'),
    ('8100', 'Omzet support', 'revenue'),
]

CUSTOMERS = [
    ('DEMO-C1', 'Gemeente Rivierdal'),
    ('DEMO-C2', 'Waterschap De Maasvallei'),
    ('DEMO-C3', 'Stichting Zorgnet Oost'),
    ('DEMO-C4', 'Provincie Middenland'),
    ('DEMO-C5', 'Hogeschool Deltastad'),
    ('DEMO-C6', 'Veiligheidsregio Kempenland'),
]

VENDORS = [
    ('DEMO-V1', 'Salarisbureau Janssen BV', '4000'),
    ('DEMO-V2', 'Kantoorpand Beheer BV', '4400'),
    ('DEMO-V3', 'CloudHost Europe BV', '4500'),
    ('DEMO-V4', 'Studio Vormgevers', '4600'),
    ('DEMO-V5', 'NS Zakelijk', '4700'),
]

PEOPLE = [
    ('anna', 95.0, 128),
    ('bram', 85.0, 136),
    ('carla', 110.0, 118),
]

# Deterministic per-month seasonality (12 entries, oldest first).
SEASONALITY = [0.92, 0.97, 1.04, 1.08, 0.95, 0.88, 1.02, 1.10, 1.06, 0.98, 1.05, 1.12]
VAT = 0.21


def month_starts(count, today):
    """First-of-month dates for the trailing `count` months, ascending."""
    months = []
    for i in range(count - 1, -1, -1):
        year = today.year
        month = today.month - i
        while month <= 0:
            year -= 1
            month += 12
        months.append(date(year, month, 1))
    return months


class Api:
    def __init__(self, base, user, password):
        self.base = base.rstrip('/')
        token = base64.b64encode(f'{user}:{password}'.encode()).decode()
        self.headers = {
            'Authorization': f'Basic {token}',
            'Content-Type': 'application/json',
            'OCS-APIRequest': 'true',
            'Accept': 'application/json',
        }

    def request(self, method, path, body=None, params=''):
        url = f'{self.base}/index.php/apps/openregister/api/objects/{REGISTER}/{path}{params}'
        data = json.dumps(body).encode() if body is not None else None
        # The dev container drops or 5xx-es the odd request under
        # bursty load; retry transient failures with backoff before
        # giving up. 4xx responses are real payload errors and abort.
        for attempt, delay in enumerate((2, 5, 10, 0)):
            req = urllib.request.Request(url, data=data, headers=self.headers, method=method)
            try:
                with urllib.request.urlopen(req, timeout=120) as response:
                    return json.loads(response.read() or b'{}')
            except urllib.error.HTTPError as e:
                detail = e.read().decode(errors='replace')[:300]
                if e.code >= 500 and delay:
                    time.sleep(delay)
                    continue
                raise SystemExit(f'{method} {url} -> HTTP {e.code}: {detail}')
            except (urllib.error.URLError, ConnectionError, TimeoutError, OSError) as e:
                if delay:
                    time.sleep(delay)
                    continue
                raise SystemExit(f'{method} {url} -> {type(e).__name__}: {e}')

    def list(self, schema):
        rows = self.request('GET', schema, params='?_limit=5000')
        return rows.get('results') or rows.get('objects') or []

    def create(self, schema, obj):
        return self.request('POST', schema, body=obj)

    def delete(self, schema, object_id):
        return self.request('DELETE', f'{schema}/{object_id}')


def is_demo(obj):
    if obj.get('administrationId') == ADMIN_ID:
        return True
    for key in ('transactionNumber', 'invoiceNumber', 'customerId', 'vendorNumber', 'weekId', 'transactionId'):
        if str(obj.get(key, '')).startswith(PREFIX):
            return True
    return False


def object_id_of(obj):
    return (obj.get('@self') or {}).get('id') or obj.get('id')


def delete_with_retry(api, target):
    """Delete one (schema, object id), retrying once; False when both attempts fail."""
    schema, object_id = target
    for _ in range(2):
        try:
            api.delete(schema, object_id)
            return True
        except SystemExit:
            pass
    return False


def wipe(api):
    schemas = ['GLLine', 'GLTransaction', 'ARInvoice', 'APTransaction',
               'UrenRegistratie', 'CashflowWeek', 'CustomerMaster', 'Payee', 'Account']
    total = 0
    failed = 0
    for schema in schemas:
        demo = [o for o in api.list(schema) if is_demo(o)]
        for object_id in filter(None, map(object_id_of, demo)):
            if delete_with_retry(api, (schema, object_id)):
                total += 1
            else:
                failed += 1
        if demo:
            print(f'  wiped {len(demo):4d} × {schema}')
    print(f'Wipe done ({total} objects' + (f', {failed} failed' if failed else '') + ').')


class Ledger:
    """The GL transactions and lines of the seed, in posting order."""

    def __init__(self):
        self.transactions = []
        self.lines = []

    def post(self, entry, gl_lines):
        """Post one transaction; `entry` is (number, posting_date, description)."""
        number, posting_date, description = entry
        period = f'{PREFIX}{posting_date.strftime("%Y-%m")}'
        self.transactions.append({
            'transactionNumber': number,
            'postingDate': posting_date.isoformat(),
            'periodId': period,
            'currency': 'EUR',
            'description': description,
            'state': 'posted',
            'administrationId': ADMIN_ID,
        })
        for index, (account, side, amount) in enumerate(gl_lines, start=1):
            self.lines.append({
                'transactionId': number,
                'lineNumber': index,
                'accountNumber': account,
                'side': side,
                'amount': round(amount, 2),
                'currency': 'EUR',
                'periodId': period,
                'subLedgerType': 'none',
                'description': description,
            })


class Story:
    """The seed being built: the create queue, the ledger and the months."""

    def __init__(self, today):
        self.today = today
        self.months = month_starts(12, today)
        self.queue = []  # (schema, object) pairs, posted in phases
        self.ledger = Ledger()
        self.receipts_due = {}  # month index -> gross amount received in month i+1
        self.payments_due = {}  # month index -> gross amount paid in month i+1

    def add(self, schema, obj):
        self.queue.append((schema, obj))

    def is_last(self, i):
        return i == len(self.months) - 1

    def months_back(self, i):
        """How many months before the current one month `i` is."""
        return len(self.months) - 1 - i


def parse_args():
    parser = argparse.ArgumentParser(description='Seed shillinq financial demo data.')
    parser.add_argument('--url', default='http://localhost:8080')
    parser.add_argument('--user', default='admin')
    parser.add_argument('--password', default='admin')
    parser.add_argument('--wipe', action='store_true', help='Delete previously seeded demo objects first')
    return parser.parse_args()


def queue_master_data(story, api):
    """Chart of accounts (skipping numbers that already exist), customers and vendors."""
    have = {str(a.get('accountNumber')) for a in api.list('Account')}
    for number, name, account_type in ACCOUNTS:
        if number in have:
            continue
        story.add('Account', {
            'accountNumber': number, 'name': name, 'accountType': account_type,
            'currency': 'EUR', 'administrationId': ADMIN_ID, 'lifecycleState': 'active',
        })
    for cid, name in CUSTOMERS:
        story.add('CustomerMaster', {
            'customerId': cid, 'legalName': name, 'administrationId': ADMIN_ID,
            'lifecycleState': 'active', 'email': f'{cid.lower()}@example.org',
        })
    for vid, name, _ in VENDORS:
        story.add('Payee', {
            'vendorNumber': vid, 'name': name, 'administrationId': ADMIN_ID,
            'lifecycleState': 'active', 'paymentTermDays': 30,
        })


def sales_state(story, sale):
    """Lifecycle state of a sales invoice; `sale` is (month index, revenue account)."""
    i, revenue_account = sale
    if story.is_last(i):
        return 'issued'
    # The support invoice of two months ago stays unpaid → overdue.
    if story.months_back(i) == 2 and revenue_account == '8100':
        return 'overdue'
    return 'paid'


def queue_sales(story, i):
    """Two sales invoices: consultancy (day 5) and support (day 20)."""
    month = story.months[i]
    month_tag = month.strftime('%Y-%m')
    consultancy = round((14000 + 550 * i) * SEASONALITY[i], 2)
    support = round(2200 + 60 * i, 2)
    sales = [
        (f'{PREFIX}F-{month_tag}-01', month.replace(day=5), CUSTOMERS[i % 6][0], consultancy, '8000'),
        (f'{PREFIX}F-{month_tag}-02', month.replace(day=20), CUSTOMERS[(i + 3) % 6][0], support, '8100'),
    ]
    month_gross = 0.0
    for number, invoice_date, customer, net, revenue_account in sales:
        vat = round(net * VAT, 2)
        gross = round(net + vat, 2)
        state = sales_state(story, (i, revenue_account))
        story.add('ARInvoice', {
            'invoiceNumber': number, 'customerId': customer,
            'administrationId': ADMIN_ID,
            'invoiceDate': invoice_date.isoformat(), 'dueDate': (invoice_date + timedelta(days=30)).isoformat(),
            'netAmount': net, 'vatAmount': vat, 'grossAmount': gross,
            'currency': 'EUR', 'periodId': f'{PREFIX}{month_tag}',
            'lifecycleState': state, 'glTransactionId': number,
        })
        story.ledger.post((number, invoice_date, f'Sales invoice {number}'),
                          [('1300', 'debit', gross), (revenue_account, 'credit', net), ('1520', 'credit', vat)])
        if state == 'paid':
            month_gross += gross
    story.receipts_due[i] = round(month_gross, 2)


def cost_state(story, cost):
    """Lifecycle state of a purchase invoice; `cost` is (month index, expense account)."""
    i, expense_account = cost
    if story.is_last(i):
        return 'received'
    # One hosting invoice from three months back was never paid → overdue.
    if story.months_back(i) == 3 and expense_account == '4500':
        return 'overdue'
    return 'paid'


def month_costs(i):
    """(vendor, net amount) of the five cost invoices of month `i`."""
    season = SEASONALITY[i]
    return [
        (VENDORS[0], round((8200 + 180 * i), 2)),
        (VENDORS[1], 1450.0),
        (VENDORS[2], round(620 + 15 * i, 2)),
        (VENDORS[3], round(420 * season, 2)),
        (VENDORS[4], round(260 * season, 2)),
    ]


def queue_costs(story, i):
    """Five cost invoices (payroll, rent, hosting, marketing, travel)."""
    month = story.months[i]
    month_tag = month.strftime('%Y-%m')
    invoice_date = month.replace(day=3)
    month_paid = 0.0
    for index, ((vid, vendor_name, expense_account), net) in enumerate(month_costs(i), start=1):
        vat = round(net * VAT, 2)
        total = round(net + vat, 2)
        number = f'{PREFIX}I-{month_tag}-{index:02d}'
        state = cost_state(story, (i, expense_account))
        story.add('APTransaction', {
            'invoiceNumber': number, 'vendorId': vid,
            'invoiceDate': invoice_date.isoformat(), 'dueDate': (invoice_date + timedelta(days=30)).isoformat(),
            'currency': 'EUR', 'totalAmount': total, 'taxAmount': vat,
            'lines': [{'description': f'{vendor_name} {month_tag}', 'amount': net,
                       'accountNumber': expense_account, 'taxCode': 'BTW21'}],
            'state': state, 'periodId': f'{PREFIX}{month_tag}',
            'administrationId': ADMIN_ID, 'glTransactionId': number,
        })
        story.ledger.post((number, invoice_date, f'Purchase invoice {number}'),
                          [(expense_account, 'debit', net), ('1520', 'debit', vat), ('1600', 'credit', total)])
        if state == 'paid':
            month_paid += total
    story.payments_due[i] = round(month_paid, 2)


def post_settlements(story, i):
    """Settlement batches: month i settles month i-1."""
    if i == 0:
        return
    month = story.months[i]
    month_tag = month.strftime('%Y-%m')
    received = story.receipts_due.get(i - 1)
    if received:
        story.ledger.post((f'{PREFIX}BANK-IN-{month_tag}', month.replace(day=10), 'Customer receipts settlement batch'),
                          [('1010', 'debit', received), ('1300', 'credit', received)])
    paid = story.payments_due.get(i - 1)
    if paid:
        story.ledger.post((f'{PREFIX}BANK-OUT-{month_tag}', month.replace(day=27), 'Vendor payments settlement batch'),
                          [('1600', 'debit', paid), ('1010', 'credit', paid)])


def queue_hours(story, i):
    """Hour registrations: weekly billable entries + monthly internal time."""
    month = story.months[i]
    for person, rate, base_hours in PEOPLE:
        monthly = round(base_hours * SEASONALITY[i])
        for week in range(4):
            entry_date = month + timedelta(days=2 + 7 * week)
            if entry_date > story.today:
                continue
            story.add('UrenRegistratie', {
                'administrationId': ADMIN_ID, 'personId': f'{PREFIX}{person}',
                'date': entry_date.isoformat(), 'hours': round(monthly / 4, 1),
                'recognisedRate': rate, 'projectId': f'{PREFIX}P-{(i % 3) + 1}',
                'description': f'Consultancy {person} week {week + 1}',
            })
        internal_date = month + timedelta(days=5)
        if internal_date <= story.today:
            story.add('UrenRegistratie', {
                'administrationId': ADMIN_ID, 'personId': f'{PREFIX}{person}',
                'date': internal_date.isoformat(), 'hours': float(18 + (i % 3) * 3),
                'recognisedRate': 0.0,
                'description': f'Internal time {person}',
            })


def cashflow_week(start, flows):
    """One CashflowWeek; `flows` is (inflow, outflow, closing saldo)."""
    inflow, outflow, saldo = flows
    net = round(inflow - outflow, 2)
    return {
        'weekId': f'{PREFIX}CFW-{start.isoformat()}',
        'horizonId': f'{PREFIX}HORIZON-13WK',
        'weeknummer': int(start.strftime('%V')),
        'weekStart': start.isoformat(),
        'weekEind': (start + timedelta(days=6)).isoformat(),
        'openingSaldo': round(saldo - net, 2),
        'inflows_ar_geprognosticeerd': round(inflow * 0.8, 2),
        'inflows_overig': round(inflow * 0.2, 2),
        'inflows_totaal': inflow,
        'outflows_ap_geprognosticeerd': round(outflow * 0.6, 2),
        'outflows_recurring_huur': round(outflow * 0.4, 2),
        'outflows_totaal': outflow,
        'nettoMutatie': net,
        'eindSaldo': saldo,
        'bufferStatus': 'BOVEN_BUFFER' if saldo > 10000 else 'VOORALARM',
        'administrationId': ADMIN_ID,
    }


def queue_cashflow(story):
    """13-week cashflow forecast, starting next Monday."""
    saldo = 31000.0
    today = story.today
    next_monday = today + timedelta(days=(7 - today.weekday()) % 7 or 7)
    for week in range(13):
        inflow = round(5200 + 900 * ((week * 7) % 5), 2)
        outflow = round(4300 + 700 * ((week * 5) % 4), 2)
        saldo = round(saldo + round(inflow - outflow, 2), 2)
        story.add('CashflowWeek', cashflow_week(next_monday + timedelta(weeks=week), (inflow, outflow, saldo)))


def build_story(api, today):
    """Queue the whole 12-month demo administration."""
    story = Story(today)
    queue_master_data(story, api)
    story.ledger.post((f'{PREFIX}OPENING', story.months[0], 'Opening capital Demo Consultancy BV'),
                      [('1010', 'debit', 25000), ('0800', 'credit', 25000)])
    for i in range(len(story.months)):
        queue_sales(story, i)
        queue_costs(story, i)
        post_settlements(story, i)
        queue_hours(story, i)
    queue_cashflow(story)
    story.queue += [('GLTransaction', t) for t in story.ledger.transactions]
    story.queue += [('GLLine', line) for line in story.ledger.lines]
    return story.queue


def create_with_retry(api, item):
    """Create one (schema, object), with one retry for transient hiccups."""
    schema, obj = item
    try:
        api.create(schema, obj)
    except SystemExit:
        # One retry: transient table-creation/locking hiccups.
        api.create(schema, obj)


def post_queue(api, queue):
    """Create the queued objects, schema by schema.

    The FIRST object of each schema is created serially: OpenRegister
    lazily creates the magic table on first insert, and concurrent
    first-inserts race on Postgres pg_type.
    """
    by_schema = {}
    for schema, obj in queue:
        by_schema.setdefault(schema, []).append(obj)

    for schema, objects in by_schema.items():
        print(f'Seeding {len(objects):4d} × {schema} ...')
        create_with_retry(api, (schema, objects[0]))
        rest = objects[1:]
        if rest:
            with ThreadPoolExecutor(max_workers=4) as pool:
                list(pool.map(lambda obj, schema=schema: create_with_retry(api, (schema, obj)), rest))


def main():
    args = parse_args()
    api = Api(args.url, args.user, args.password)

    if args.wipe:
        wipe(api)

    existing = [t for t in api.list('GLTransaction') if str(t.get('transactionNumber', '')).startswith(PREFIX)]
    if existing:
        print(f'Demo data already present ({len(existing)} DEMO GL transactions). Use --wipe to reseed.')
        sys.exit(0)

    queue = build_story(api, date.today())
    post_queue(api, queue)
    print(f'Done — {len(queue)} objects seeded into register "{REGISTER}" '
          f'(administration {ADMIN_ID}). Open the Financial overview dashboard.')


if __name__ == '__main__':
    main()
