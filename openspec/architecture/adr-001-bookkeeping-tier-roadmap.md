# ADR-001: Bookkeeping tier roadmap — canonical 5-tier breakdown

**Status:** Accepted
**Date:** 2026-05-17

## Context

Shillinq pivoted from a customer-invoicing app to a full double-entry
bookkeeping engine for Dutch SMB (MKB), self-employed (ZZP), and
decentralised government (gemeenten, waterschappen, provincies). The
resulting surface area is large: 42 capability specs across 5
proposals in this single PR, with another tier (T5) explicitly
deferred. Rolling all of it as one change is not reviewable; rolling
it as 42 independent changes loses the dependency structure.

The chosen middle ground is a **tiered rollout**: each tier is one
OpenSpec change, every capability inside a tier is one spec file, and
the tiers chain via documented dependencies. Each of the five
proposals in this PR initially published its own variant of the
5-tier table — and they disagreed (T1 said T2=sub-ledgers, T2 said
T3=tax+sector, T3 said T4=reporting+analytics, T4-base said
T5=cross-cutting/specialised). Three proposals also referenced
phantom change slugs that were never created
(`add-shillinq-bookkeeping-subledgers`,
`add-shillinq-bookkeeping-period-close`,
`add-shillinq-bookkeeping-reporting`,
`add-shillinq-bookkeeping-multicurrency-and-tax`,
`add-shillinq-bookkeeping-subledgers-close-statements`).

This ADR fixes the breakdown in one place. Every proposal links here
instead of re-publishing its own table.

## Decision

There is **one canonical 5-tier breakdown** for the Shillinq
bookkeeping rollout, listed below. The 5 changes in this PR cover
**T1 through T4-specialized**. T5 is forward-looking and explicitly
empty in this PR; it is tracked separately and not implied by any
in-flight change in this envelope.

| Tier | Change slug | Scope | Capabilities |
|------|-------------|-------|--------------|
| **T1** | `add-shillinq-bookkeeping-foundation` | Foundation | `bookkeeping-chart-of-accounts`, `bookkeeping-general-ledger`, `bookkeeping-journal-entries` (3 specs) |
| **T2** | `add-shillinq-bookkeeping-compliance` | Sub-ledgers + period machinery | `bookkeeping-trial-balance`, `bookkeeping-period-close`, `bookkeeping-accounts-payable-core`, `bookkeeping-accounts-receivable-core`, `bookkeeping-financial-statements`, `bookkeeping-audit-trail` (consume from OR), `bookkeeping-document-attachment-integration` (consume from docudesk), `bookkeeping-bank-reconciliation`, `bookkeeping-ifrs15-revenue` (IFRS 15 / ASC 606 five-step revenue recognition; depends on T1 `bookkeeping-general-ledger` + T2 `bookkeeping-quote-order-invoice` + T2/T3 `bookkeeping-consultancy-project-accounting`; enables T4 segment reporting + consolidated IFRS 15.110-129 disclosure) (9 specs) |
| **T3** | `add-shillinq-bookkeeping-operations` | Operations + NL regulatory core | `bookkeeping-vat-btw-filing`, `bookkeeping-bbv-compliance`, `bookkeeping-iv3-reporting`, `bookkeeping-bcf-vat-compensation`, `bookkeeping-kor-kleine-ondernemersregeling`, `bookkeeping-zzp-tax-regime`, `bookkeeping-schatkistbankieren`, `bookkeeping-subsidie-verantwoording`, `bookkeeping-archiefwet-retention`, `bookkeeping-consultancy-project-accounting` (10 specs) |
| **T4-base** | `add-shillinq-bookkeeping-advanced` | Advanced engine features | `bookkeeping-sbr-xbrl-reporting`, `bookkeeping-fixed-assets-depreciation`, `bookkeeping-multi-currency`, `bookkeeping-cost-centers-dimensions`, `bookkeeping-year-end-close`, `bookkeeping-bank-connectors`, `bookkeeping-reconciliation-reports` (7 specs) |
| **T4-specialized** | `add-shillinq-gov-sector-mkb-advanced` | NL gov sector variants + Vpb + MKB innovation + detachering | `bookkeeping-waterschappen-bbv-variant`, `bookkeeping-provincies-bbv-variant`, `bookkeeping-gr-consolidation`, `bookkeeping-rekenkamer-audit-pack`, `bookkeeping-cbs-bestanden-extended`, `bookkeeping-emu-reporting`, `bookkeeping-sisa-reporting`, `bookkeeping-market-government-separation`, `bookkeeping-vpb-corporate-tax`, `bookkeeping-innovatiebox-administratie`, `bookkeeping-investeringsaftrek`, `bookkeeping-wbso-sno-administratie`, `bookkeeping-r-d-subsidies-mkb`, `bookkeeping-detachering-payroll-administratie` (14 specs) |
| **T5** | _(future, not in this PR)_ | Cross-cutting + e-invoicing + treasury | UBL/Peppol BIS 3.0 outbound for AR, intercompany eliminations, advanced group consolidation, treasury cash forecasting, IFRS rebridge, multi-administration aggregation. **Explicitly OUT of this PR; tracked separately.** |

### Build order

T1 → T2 → T3 → T4-base / T4-specialized (the two T4 changes may
land in parallel; T4-specialized depends on selected T2/T3/T4-base
capabilities but not on the entirety of T4-base).

### Dependency annotations

Where a spec lists `Depends on:` in its header (or where a proposal
narrative cross-references a sibling), the `(T1)` / `(T2)` / etc.
annotations refer to **this table**. A reference like
"depends on `bookkeeping-trial-balance` (T2)" means the
`bookkeeping-trial-balance` capability lives in the T2 change
(`add-shillinq-bookkeeping-compliance`).

### VAT/BTW lands in T3, not T5

Earlier drafts of the T1 proposal deferred VAT/BTW posting automation
to T5. That was a drafting error: VAT/BTW filing ships in T3 as
`bookkeeping-vat-btw-filing` (under `add-shillinq-bookkeeping-operations`).
T1 has no VAT/BTW surface — neither in scope nor as a deferred
out-of-scope item — beyond the plain `vatApplicable` boolean on the
`Account` schema that downstream tiers consume.

## Consequences

### Positive

- **One source of truth.** Every proposal links here; there is no
  per-proposal table to drift.
- **Spec readers reason about tier ownership in one place.** A reader
  who sees "(T3)" next to a slug can look up exactly which change
  envelope owns it.
- **Phantom slugs are killed.** Future references to
  `add-shillinq-bookkeeping-subledgers`,
  `…-period-close`, `…-reporting`,
  `…-multicurrency-and-tax`, or
  `…-subledgers-close-statements` are review-blocking — those slugs
  do not exist and never will.

### Negative

- **One more file to maintain.** When a capability moves between
  tiers, this table updates. The cost is small (one edit per move,
  caught immediately by reviewer rather than slowly drifting across
  five proposals).

### Migration

This ADR supersedes any per-proposal "5-tier rollout" table. Those
tables MUST be removed from the five proposals and replaced with a
one-line link to this ADR. The replacement was done in the same PR
that introduces this ADR (see the proposals under
`openspec/changes/add-shillinq-bookkeeping-*` and
`openspec/changes/add-shillinq-gov-sector-mkb-advanced`).

## See also

- `adr-000-data-model.md` — the 225-entity catalogue every tier
  consumes.
- `hydra/openspec/architecture/adr-031-schema-declarative-business-logic.md`
  — declarative-first principle every tier follows.
- `hydra/openspec/architecture/adr-032-spec-sizing-and-chaining.md` —
  `kind:` taxonomy and chain primitive that the tier breakdown
  rests on.
- `hydra/openspec/architecture/adr-024-app-manifest.md` — manifest
  shape every tier extends.
- `hydra/openspec/architecture/adr-022-apps-consume-openregister-abstractions.md`
  — RBAC / audit / retention consumption every tier inherits.
