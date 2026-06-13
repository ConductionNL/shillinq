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


def wipe(api):
    schemas = ['GLLine', 'GLTransaction', 'ARInvoice', 'APTransaction',
               'UrenRegistratie', 'CashflowWeek', 'CustomerMaster', 'Payee', 'Account']
    total = 0
    failed = 0
    for schema in schemas:
        demo = [o for o in api.list(schema) if is_demo(o)]
        for obj in demo:
            object_id = (obj.get('@self') or {}).get('id') or obj.get('id')
            if not object_id:
                continue
            try:
                api.delete(schema, object_id)
                total += 1
            except SystemExit:
                try:
                    api.delete(schema, object_id)
                    total += 1
                except SystemExit:
                    failed += 1
        if demo:
            print(f'  wiped {len(demo):4d} × {schema}')
    print(f'Wipe done ({total} objects' + (f', {failed} failed' if failed else '') + ').')


def main():
    parser = argparse.ArgumentParser(description='Seed shillinq financial demo data.')
    parser.add_argument('--url', default='http://localhost:8080')
    parser.add_argument('--user', default='admin')
    parser.add_argument('--password', default='admin')
    parser.add_argument('--wipe', action='store_true', help='Delete previously seeded demo objects first')
    args = parser.parse_args()

    api = Api(args.url, args.user, args.password)

    if args.wipe:
        wipe(api)

    existing = [t for t in api.list('GLTransaction') if str(t.get('transactionNumber', '')).startswith(PREFIX)]
    if existing:
        print(f'Demo data already present ({len(existing)} DEMO GL transactions). Use --wipe to reseed.')
        sys.exit(0)

    today = date.today()
    months = month_starts(12, today)

    queue = []  # (schema, object) pairs, posted in phases

    # ---- Chart of accounts (skip numbers that already exist) ----
    have = {str(a.get('accountNumber')) for a in api.list('Account')}
    for number, name, account_type in ACCOUNTS:
        if number in have:
            continue
        queue.append(('Account', {
            'accountNumber': number, 'name': name, 'accountType': account_type,
            'currency': 'EUR', 'administrationId': ADMIN_ID, 'lifecycleState': 'active',
        }))

    # ---- Master data ----
    for cid, name in CUSTOMERS:
        queue.append(('CustomerMaster', {
            'customerId': cid, 'legalName': name, 'administrationId': ADMIN_ID,
            'lifecycleState': 'active', 'email': f'{cid.lower()}@example.org',
        }))
    for vid, name, _ in VENDORS:
        queue.append(('Payee', {
            'vendorNumber': vid, 'name': name, 'administrationId': ADMIN_ID,
            'lifecycleState': 'active', 'paymentTermDays': 30,
        }))

    transactions = []
    lines = []

    def post_gl(number, posting_date, description, gl_lines):
        transactions.append({
            'transactionNumber': number,
            'postingDate': posting_date.isoformat(),
            'periodId': f'{PREFIX}{posting_date.strftime("%Y-%m")}',
            'currency': 'EUR',
            'description': description,
            'state': 'posted',
            'administrationId': ADMIN_ID,
        })
        for index, (account, side, amount) in enumerate(gl_lines, start=1):
            lines.append({
                'transactionId': number,
                'lineNumber': index,
                'accountNumber': account,
                'side': side,
                'amount': round(amount, 2),
                'currency': 'EUR',
                'periodId': f'{PREFIX}{posting_date.strftime("%Y-%m")}',
                'subLedgerType': 'none',
                'description': description,
            })

    # ---- Opening capital ----
    post_gl(f'{PREFIX}OPENING', months[0], 'Opening capital Demo Consultancy BV',
            [('1010', 'debit', 25000), ('0800', 'credit', 25000)])

    # ---- 12 months of sales, costs and settlements ----
    receipts_due = {}   # month index -> gross amount received in month i+1
    payments_due = {}   # month index -> gross amount paid in month i+1
    overdue_skip = set()

    for i, month in enumerate(months):
        season = SEASONALITY[i]
        consultancy = round((14000 + 550 * i) * season, 2)
        support = round(2200 + 60 * i, 2)
        month_tag = month.strftime('%Y-%m')

        # Two sales invoices: consultancy (day 5) and support (day 20).
        sales = [
            (f'{PREFIX}F-{month_tag}-01', month.replace(day=5), CUSTOMERS[i % 6][0], consultancy, '8000'),
            (f'{PREFIX}F-{month_tag}-02', month.replace(day=20), CUSTOMERS[(i + 3) % 6][0], support, '8100'),
        ]
        month_gross = 0.0
        for number, invoice_date, customer, net, revenue_account in sales:
            vat = round(net * VAT, 2)
            gross = round(net + vat, 2)
            due = invoice_date + timedelta(days=30)
            is_last_month = i == len(months) - 1
            # The support invoice of two months ago stays unpaid → overdue.
            is_overdue = i == len(months) - 3 and revenue_account == '8100'
            if is_last_month:
                state = 'issued'
            elif is_overdue:
                state = 'overdue'
                overdue_skip.add(number)
            else:
                state = 'paid'
            queue.append(('ARInvoice', {
                'invoiceNumber': number, 'customerId': customer,
                'administrationId': ADMIN_ID,
                'invoiceDate': invoice_date.isoformat(), 'dueDate': due.isoformat(),
                'netAmount': net, 'vatAmount': vat, 'grossAmount': gross,
                'currency': 'EUR', 'periodId': f'{PREFIX}{month_tag}',
                'lifecycleState': state, 'glTransactionId': number,
            }))
            post_gl(number, invoice_date, f'Sales invoice {number}',
                    [('1300', 'debit', gross), (revenue_account, 'credit', net), ('1520', 'credit', vat)])
            if state == 'paid':
                month_gross += gross
        receipts_due[i] = round(month_gross, 2)

        # Five cost invoices (payroll, rent, hosting, marketing, travel).
        costs = [
            (VENDORS[0], round((8200 + 180 * i), 2)),
            (VENDORS[1], 1450.0),
            (VENDORS[2], round(620 + 15 * i, 2)),
            (VENDORS[3], round(420 * season, 2)),
            (VENDORS[4], round(260 * season, 2)),
        ]
        month_paid = 0.0
        for index, ((vid, vendor_name, expense_account), net) in enumerate(costs, start=1):
            vat = round(net * VAT, 2)
            total = round(net + vat, 2)
            invoice_date = month.replace(day=3)
            due = invoice_date + timedelta(days=30)
            number = f'{PREFIX}I-{month_tag}-{index:02d}'
            is_last_month = i == len(months) - 1
            # One hosting invoice from three months back was never paid → overdue.
            is_overdue = i == len(months) - 4 and expense_account == '4500'
            if is_last_month:
                state = 'received'
            elif is_overdue:
                state = 'overdue'
            else:
                state = 'paid'
            queue.append(('APTransaction', {
                'invoiceNumber': number, 'vendorId': vid,
                'invoiceDate': invoice_date.isoformat(), 'dueDate': due.isoformat(),
                'currency': 'EUR', 'totalAmount': total, 'taxAmount': vat,
                'lines': [{'description': f'{vendor_name} {month_tag}', 'amount': net,
                           'accountNumber': expense_account, 'taxCode': 'BTW21'}],
                'state': state, 'periodId': f'{PREFIX}{month_tag}',
                'administrationId': ADMIN_ID, 'glTransactionId': number,
            }))
            post_gl(number, invoice_date, f'Purchase invoice {number}',
                    [(expense_account, 'debit', net), ('1520', 'debit', vat), ('1600', 'credit', total)])
            if state == 'paid':
                month_paid += total
        payments_due[i] = round(month_paid, 2)

        # Settlement batches: month i settles month i-1.
        if i > 0 and receipts_due.get(i - 1):
            amount = receipts_due[i - 1]
            post_gl(f'{PREFIX}BANK-IN-{month_tag}', month.replace(day=10),
                    'Customer receipts settlement batch',
                    [('1010', 'debit', amount), ('1300', 'credit', amount)])
        if i > 0 and payments_due.get(i - 1):
            amount = payments_due[i - 1]
            post_gl(f'{PREFIX}BANK-OUT-{month_tag}', month.replace(day=27),
                    'Vendor payments settlement batch',
                    [('1600', 'debit', amount), ('1010', 'credit', amount)])

        # Hour registrations: weekly billable entries + monthly internal time.
        for person, rate, base_hours in PEOPLE:
            monthly = round(base_hours * season)
            for week in range(4):
                entry_date = month + timedelta(days=2 + 7 * week)
                if entry_date > today:
                    continue
                queue.append(('UrenRegistratie', {
                    'administrationId': ADMIN_ID, 'personId': f'{PREFIX}{person}',
                    'date': entry_date.isoformat(), 'hours': round(monthly / 4, 1),
                    'recognisedRate': rate, 'projectId': f'{PREFIX}P-{(i % 3) + 1}',
                    'description': f'Consultancy {person} week {week + 1}',
                }))
            internal_date = month + timedelta(days=5)
            if internal_date <= today:
                queue.append(('UrenRegistratie', {
                    'administrationId': ADMIN_ID, 'personId': f'{PREFIX}{person}',
                    'date': internal_date.isoformat(), 'hours': float(18 + (i % 3) * 3),
                    'recognisedRate': 0.0,
                    'description': f'Internal time {person}',
                }))

    # ---- 13-week cashflow forecast ----
    saldo = 31000.0
    next_monday = today + timedelta(days=(7 - today.weekday()) % 7 or 7)
    for week in range(13):
        start = next_monday + timedelta(weeks=week)
        inflow = round(5200 + 900 * ((week * 7) % 5), 2)
        outflow = round(4300 + 700 * ((week * 5) % 4), 2)
        net = round(inflow - outflow, 2)
        saldo = round(saldo + net, 2)
        queue.append(('CashflowWeek', {
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
        }))

    queue += [('GLTransaction', t) for t in transactions]
    queue += [('GLLine', line) for line in lines]

    # Group per schema and create the FIRST object of each schema
    # serially: OpenRegister lazily creates the magic table on first
    # insert, and concurrent first-inserts race on Postgres pg_type.
    by_schema = {}
    for schema, obj in queue:
        by_schema.setdefault(schema, []).append(obj)

    def create_with_retry(schema, obj):
        try:
            api.create(schema, obj)
        except SystemExit:
            # One retry: transient table-creation/locking hiccups.
            api.create(schema, obj)

    for schema, objects in by_schema.items():
        print(f'Seeding {len(objects):4d} × {schema} ...')
        create_with_retry(schema, objects[0])
        rest = objects[1:]
        if rest:
            with ThreadPoolExecutor(max_workers=4) as pool:
                list(pool.map(lambda obj: create_with_retry(schema, obj), rest))

    total = len(queue)
    print(f'Done — {total} objects seeded into register "{REGISTER}" '
          f'(administration {ADMIN_ID}). Open the Financial overview dashboard.')


if __name__ == '__main__':
    main()
