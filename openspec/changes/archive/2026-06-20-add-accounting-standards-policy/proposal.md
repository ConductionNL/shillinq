---
kind: code
depends_on: []
---

# Change: add-accounting-standards-policy

## Why

A Shillinq administration is frequently subject to **several** accounting/reporting
frameworks at once — a Dutch BV keeps commercial books on BW2 Title 9 / RJ *and* a
tax computation on *goed koopmansgebruik*; an EU-listed group adds IFRS; a
municipality adds the BBV. Where two frameworks prescribe a **different treatment**
for the same transaction (leases on- vs off-balance, LIFO allowed vs prohibited,
development costs capitalised vs expensed — see the
`docs/standards/comparisons.md` reference), business logic needs to know **which
framework wins**.

Today that precedence is implicit and undocumented. There is no place for an
administrator to declare which frameworks apply and in which order, and no single
seam business logic can consult, so any future conflict-resolution would hard-code
a hidden default. This change makes the precedence an explicit, auditable
configuration choice.

## What Changes

- **NEW schema `StandardsPolicy`** (register fragment, ADR-037) — per-administration
  policy holding an ordered list of frameworks, each with `key`, `enabled` and
  `precedence`. Optional `administrationId` scopes the policy; `name`/`notes` are
  informational. Framework keys are a fixed enum mirroring the documentation
  (`ifrs`, `dutch-gaap`, `dutch-tax`, `us-gaap`, `ipsas`, `bbv`, `esrs`,
  `ifrs-sustainability`).
- **NEW admin page `AccountingStandardsPolicy`** + a **Settings → Accounting
  standards** section link — a `StandardsPolicyEditor` Vue component where an
  administrator enables the applicable frameworks and drags them into precedence
  order, persisted as a single `StandardsPolicy` object via the OpenRegister
  objects API.
- **NEW resolver `StandardsPolicyService`** — the single seam future posting and
  valuation logic consults. `resolve(topic)` returns the **highest-precedence
  enabled framework** for a conflict topic. This change ships the resolver and its
  pure ranking logic (`resolveFromPolicy`) **unit-tested**, but does **not** yet
  apply it to any real posting/valuation path — that is deliberately deferred so
  the precedence model can stabilise first.

## Out of scope

- Applying the resolved framework to actual GL postings, lease classification,
  inventory valuation, etc. (a follow-up `*-engine` change).
- Per-topic framework applicability beyond the simple "highest enabled wins"
  ranking (the `topic` argument is reserved for that future refinement).
