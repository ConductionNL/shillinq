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

## Wave 1 coverage (700+ rules)

| Domain | Rules | Mostly from |
|---|---|---|
| invoicing | 228 | EN 16931 business rules + VAT Directive art. 220–231 |
| measurement | 124 | IAS 2/16/36/38, IFRS 16, ASC 330/360/350, HGB §253 |
| presentation | 83 | IAS 1 / IFRS 18, ASC 205/210/220, BW2 layouts, §266/275 HGB, PCG |
| vat | 82 | VAT Directive (place of supply, reverse charge, ICS, OSS/IOSS) |
| ledger-integrity | 59 | GoBD, FEC/NF525, NL administratieplicht, IRC §6001, SOX |
| recognition | 56 | IFRS 15, ASC 606, RJ 270 |
| reporting | 35 | ESEF, SAF-T fields, filing deadlines |
| retention | 32 | NL/DE/FR/BE/ES/IT/US retention periods |
| chart-of-accounts | 14 | RGS, SKR03/04, PCG classes |

> ~450 of these are flagged `machineCheckable: true` — directly enforceable by the
> bookkeeping engine (the rest are judgemental/disclosure rules kept for
> traceability). The corpus grows in later waves (deeper IFRS/US-GAAP/national
> disclosure rules, more SAF-T / e-invoicing countries).

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
