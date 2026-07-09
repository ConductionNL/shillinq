---
sidebar_position: 9
title: Rule corpus (behaviour)
description: The granular, machine-readable bookkeeping rules derived from standards and laws — reference data that defines behaviour, not a UI.
---

# Rule corpus

The framework pages in this section describe *what the standards say*. The **rule
corpus** is the granular, machine-readable layer beneath them: the individual
operative rules a bookkeeping system actually applies — *"an invoice must carry a
sequential number"*, *"inventory at the lower of cost and NRV"*, *"retain books 7
years (NL) / 10 years (DE)"*, *"recognise revenue when control transfers"*.

:::info This is behaviour, not a screen
The rule corpus, the [compliance catalogue](./digital-compliance.md) and policy
resolution are **business logic + reference data** — they add **no menu or
pages**. The only standards-related UI is the per-tenant apply/order screen
([Standards policy](./index.md#how-shillinq-uses-this-the-standards-policy)).
Surfacing rule or compliance *status* would be a **report**, added only if a
report is actually needed. The rules drive validation and posting behaviour, and
are the machine-readable source for turning rules into specs.
:::

## Where it lives

Versioned static data — one JSON file per domain under `lib/Standards/rules/`,
loaded and merged by `OCA\Shillinq\Standards\RuleCatalogue`. Not OpenRegister
(these are laws/standards, identical for every tenant; they change with releases,
not per administration).

Each rule: `{ id, domain, jurisdiction, framework, source, statement, severity,
machineCheckable, effectiveDate, sourceUrl }` (see `lib/Standards/rules/SCHEMA.md`).

## Coverage (~1,300 rules, Waves 1 & 2)

| Domain | Rules | Mostly from |
|---|---|---|
| invoicing | 239 | EN 16931 business rules + VAT Directive art. 220–231 |
| measurement | 225 | IAS 2/16/36/38, IFRS 9/16, ASC 330/360/350/326/815, HGB, OIC, PGC |
| presentation | 133 | IAS 1 / IFRS 18, ASC 205/210/220, BW2, §266/275 HGB, PCG, GASB |
| reporting | 133 | ESEF, SAF-T fields, filing deadlines, IPSAS/GASB/BBV reporting, ESRS |
| ledger-integrity | 131 | GoBD, FEC/NF525, administratieplicht, IRC §6001, SOX, AML/KYC, cash limits |
| recognition | 125 | IFRS 15, ASC 606, RJ 270, IAS 19/20, IFRS 3/10, IPSAS 23/47 |
| disclosure | 115 | ESRS (E1–E5/S1–S4/G1), IFRS S1/S2, IFRS 7 |
| vat | 82 | VAT Directive (place of supply, reverse charge, ICS, OSS/IOSS) |
| tax | 45 | NL loonheffingen, DE Lohnsteuer/SV, FR DSN, US payroll (941/W-2/FICA) |
| retention | 44 | NL/DE/FR/BE/ES/IT/PT/US retention periods |
| chart-of-accounts | 14 | RGS, SKR03/04, PCG/MAR classes |
| classification | 11 | IFRS 9 SPPI / business-model classification |

> ~450+ are flagged `machineCheckable: true` — directly enforceable by the
> bookkeeping engine; the rest are judgemental/disclosure rules kept for
> traceability. Wave 1 (713) covered invoicing/VAT/retention/integrity + IFRS/US
> GAAP/NL-DE-FR GAAP; Wave 2 (+584) added sustainability (ESRS/IFRS S1-S2), public
> sector (IPSAS/GASB/BBV), payroll/wage-tax, banking/AML, deeper IFRS/US-GAAP
> (financial instruments, business combinations, employee benefits) and national
> GAAP for IT/ES/BE + more jurisdictions. The corpus is versioned and grows.

## How business logic consumes it

```php
use OCA\Shillinq\Standards\RuleCatalogue;

RuleCatalogue::byJurisdiction('NL');   // NL + EU-wide + global rules
RuleCatalogue::byFramework('en-16931'); // all EN 16931 invoice rules
RuleCatalogue::byDomain('retention');   // retention-period rules
RuleCatalogue::machineCheckable();      // the enforceable subset
RuleCatalogue::version();               // catalogue version stamp
```

## Accuracy

Every rule carries a real `source` (article/paragraph) and `sourceUrl`; rules
whose exact citation could not be verified are flagged with `(verify)` in the
statement rather than dropped or fabricated. The corpus is a verified foundation,
not a legal guarantee — confirm against the linked source before relying on a
specific rule for a compliance decision.
