---
kind: code
depends_on: [ledger-posting-path]
---

# Proposal: public-sector-reserves-and-interest

## Summary

A municipality keeps reserves whose additions and withdrawals the council
decides year by year, and charges interest over the book value of its assets
to the tasks that use them (rentetoerekening under the BBV). Shillinq has a
reserve schema and an investment schema, but no page reaches either and no
code allocates interest. This change records reserve mutations per year with
a multi-year overview, and runs the yearly interest allocation.

## Motivation

Two public-sector rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), both with tender
demand, decided `build` by the OpenSpec pass of 2026-09-27. No column in the
matrix serves the Dutch public sector; the demand is the tender.

**`pub-reserves`**, "Keep reserves with additions and withdrawals per year
and a multi-year overview." Rated no, built state none. Matrix note:
"Reserve schema with toevoegingen, onttrekkingen and opening and closing
balances exists (lib/Settings/shillinq_register.json:20209 area), but no
manifest page in src/manifest.json or src/manifest.d names it." Tender
demand: https://www.tenderned.nl/aankondigingen/overzicht/416109.

**`pub-asset-interest`**, "Charge interest on assets over their book value
and allocate it to tasks." Rated no, built state none. Matrix note: "Reserve
has a rentetoerekening flag and the programmabegroting register describes an
Investering kapitaallasten schedule
(lib/Settings/register.d/bookkeeping-programmabegroting.json:7), but no page
reaches Investering and nothing charges interest to tasks." Tender demand:
https://www.tenderned.nl/aankondigingen/overzicht/416109. odoo is rated no.

## Affected Projects

- [ ] Project: `shillinq`: reserve mutations and pages, a multi-year reserve overview, investment pages, and a yearly interest allocation.

## Scope

### In Scope

- A `ReserveMutation` record per addition or withdrawal, per year, with its council resolution and programme, planned or realised.
- Reserve and investment index and detail pages in the public-sector menu.
- A multi-year reserve overview: opening balance, additions, withdrawals and closing balance per reserve per year, realised and planned.
- A yearly interest allocation run: the omslagrente percentage over the book value of each investment at the start of the year, charged to its taakveld, and interest added to reserves marked for it, posted as journal entries.

### Out of Scope

- Provisions (Voorziening), which have their own rules.
- Calculating the omslagrente percentage itself; the controller enters it per year from the rente-schema.

## Approach

Mutations are records whose sums give the balances. The run reads
investments and reserves and writes one journal entry per year. Details are
in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/bookkeeping-programmabegroting.json`: `ReserveMutation`, `InterestAllocationRun`, calculations on `Reserve`.
- `lib/Service/`: the interest allocation.
- `src/manifest.d/bookkeeping-programmabegroting.json`: pages and menu entries.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: Two reserve schemas with different fields
**Severity:** Medium. **Mitigation:** `Reserve` is declared in `shillinq_register.json` and in the programmabegroting fragment and merged; the balances become calculations over mutations, and the stored balance fields are kept read-only for the history they already hold.

## Rollback Strategy

Hide the pages and stop the run; posted interest entries can be reversed.

## Open Questions

None.
