<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->

# Revenue Recognition screenshots

This folder collects screenshots referenced from the `docs/user-guide/bookkeeping/`
IFRS 15 docs and the `docs/journeys/` IFRS 15 persona stories.

Spec: [`bookkeeping-ifrs15-revenue`](../../../openspec/changes/bookkeeping-ifrs15-revenue/proposal.md)

## Naming convention

Screenshots are named `<surface>-<sequence>.png` matching the user-guide step
they illustrate:

- `contract-entry-01.png` … `contract-entry-04.png` — contract entry form
- `revenue-waterfall-01.png` … `revenue-waterfall-04.png` — 60-month chart
  and segment filter
- `contract-balances-01.png` … `contract-balances-03.png` — asset/liability
  bar chart and drill-down
- `variable-consideration-01.png` … `variable-consideration-04.png` —
  re-estimation modal with constraint reason
- `disclosure-viewer-01.png` … `disclosure-viewer-05.png` — IFRS 15.110-129
  pack viewer with export buttons

## How screenshots get captured

Screenshots are captured by the Playwright e2e screenshot run
(`tests/e2e/docs-screenshots.spec.ts` pattern) once the live OpenRegister
instance is seeded with the design-doc Example 1 (36-month SaaS contract)
and Example 2 (construction contract) fixtures. The implementing cycle wires
the seed fixtures + screenshot spec.

Until then this folder is intentionally a single README placeholder so the
`docs/user-guide/bookkeeping/ifrs15-disclosure.md` and sibling docs can link
forward without 404-ing the docs site build.
