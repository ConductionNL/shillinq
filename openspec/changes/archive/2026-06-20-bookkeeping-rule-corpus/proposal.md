---
kind: code
depends_on:
  - expand-standards-eu-us
---

# Change: bookkeeping-rule-corpus

## Why

The standards catalogue answers *which* frameworks/mandates apply; it does not
hold the granular **operative rules** a bookkeeping system actually applies on
each transaction — "an invoice must carry a sequential number", "inventory at the
lower of cost and NRV", "retain books 7 years (NL) / 10 years (DE)", "recognise
revenue when control transfers". For an EU + US bookkeeping product these number
in the thousands across invoicing, VAT, retention, ledger integrity, recognition,
measurement, presentation, disclosure, reporting, chart of accounts, tax, payroll,
banking/AML, sustainability and public sector.

These rules are **facts about standards/laws** — identical for every tenant,
changing only with regulation — so they belong in versioned static code, and they
are the machine-readable source for turning rules into validations/specs.

## What Changes

- **NEW capability `bookkeeping-rules`** formalising the operative rule corpus:
  a versioned static `RuleCatalogue` over per-domain JSON (`lib/Standards/rules/`),
  every rule carrying `id`, `domain`, `jurisdiction`, `framework`, `source`,
  `statement`, `severity`, `machineCheckable`, `effectiveDate`, `sourceUrl`.
- **Wave 1 + 2 ship ~1,300 sourced rules** across 12 domains (invoicing/EN 16931,
  VAT, retention, ledger integrity, recognition, measurement, presentation,
  disclosure, reporting, chart of accounts, tax/payroll, plus banking/AML,
  sustainability, public sector and national GAAPs for NL/DE/FR/IT/ES/BE).
- The corpus **defines behaviour** (validation / posting / spec-generation) and
  **adds no menu or pages**; it is queried by business logic via `RuleCatalogue`.

## Out of scope

- Per-rule validators/enforcement in the posting engine (a follow-up build, fed by
  `machineCheckable` rules).
- Exhaustive disclosure-checklist coverage — the corpus is a verified foundation
  that grows in later waves.
