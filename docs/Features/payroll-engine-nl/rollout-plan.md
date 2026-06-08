---
sidebar_position: 4
title: NL Payroll Engine — Rollout Plan
description: Pilot, GA release and post-release monitoring plan for the Shillinq NL payroll engine.
---

# NL Payroll Engine — Rollout Plan

This plan describes the three-phase rollout from internal pilot to General
Availability and post-release monitoring. Each phase is gated by exit
criteria; do **not** proceed to the next phase until every box is checked.

## Phase 1 — Pilot (May 2026 payroll)

### Goal

Validate the bruto→netto algorithm and the LH-afdracht aggregate against
the real Belastingdienst response for two to three Dutch SMB employers.

### Pilot cohort

| Werkgever | Profile | Loonperiode | Rationale |
| --- | --- | --- | --- |
| Conduction B.V. | Internal — seed data reference | MAAND | Baseline; we own all data |
| Pilot customer #1 | 5–10 werknemers; AWF-LAAG | MAAND | Standard SMB profile |
| Pilot customer #2 | 1–3 DGAs; signed NFA | MAAND | DGA-gebruikelijk-loon edge case |

Pilot customers must sign an NFA covering AVG (BSN-verwerking) before
production data lands in Shillinq.

### Exit criteria (Phase 1)

- [ ] Each pilot's mei 2026 loonstroken produced via Shillinq match the
      external payroll-bureau output within €0,02 per regel
- [ ] `LHAfdracht` for mei 2026 reconciles with the Belastingdienst's
      acknowledgement of the SBR submission (delivered by the
      bookkeeping-loonaangifte-sbr app)
- [ ] No `cumulatievenConsistent=false` warnings on the june-2026 strook
- [ ] DGA-gebruikelijk-loon warning fires when expected, does **not** fire
      when an exception is recorded
- [ ] All 16 hydra gates green at the pilot tag
- [ ] PHPUnit suite green (full repo)
- [ ] Pilot accountants sign off on the Shillinq output replacing their
      external bureau

### Monitoring during pilot

- Daily check of `LoonStrook` records that lack `cumulatieven` (data
  quality regression)
- Weekly bruto→netto sample-check against the Belastingdienst oranje-boek
- Weekly DGA-loon dashboard review
- Slack #shillinq-payroll-pilot channel for incident report-out

## Phase 2 — GA Release (June 2026)

### Pre-flight

- [ ] All four spec artifacts approved (proposal, design, specs, tasks)
- [ ] All 13 documented integration / handoff services covered by unit
      tests
- [ ] CI/CD pipeline green for `feature/payroll-engine-nl/*` branches
- [ ] Pilot feedback incorporated as either fixes or follow-up issues
- [ ] Documentation complete:
      `user-guide-nl.md`, `developer-guide.md`,
      `audit-compliance-checklist-nl.md`, `rollout-plan.md`
- [ ] No outstanding security findings from `composer audit`, hydra
      gate-7 (`no-admin-idor`) or gate-9 (`semantic-auth`)

### Release checklist

- [ ] Bump `appinfo/info.xml` `<version>` to the GA target (e.g.
      `0.7.0`)
- [ ] CHANGELOG entry under "## [0.7.0] — 2026-06-XX" enumerating the
      seven hand-off services + the three documentation pages
- [ ] Codeberg PR opened against `development` from
      `feature/payroll-engine-nl/bookkeeping-payroll-engine-nl-finish`
- [ ] PR description references this rollout plan + every commit's
      task-line
- [ ] `openspec` archive of the change once merged to `development`
- [ ] Production-ready announcement to #conduction-product and
      #shillinq-customers

### Communication plan

- Internal: Slack #conduction-product, plus a short demo
  (5 min Loom) showing the May→June switchover for one pilot
- External: Newsletter blurb plus an updated docs.shillinq.io entry
  pointing at the new `Features/payroll-engine-nl/` section

## Phase 3 — Post-Release Monitoring (July 2026 →)

### Operational dashboards (Shillinq admin)

- **LH-afdracht discrepancy** — alert when the Belastingdienst's
  acknowledged amount differs from our `LHAfdracht.totaalLoonheffing` by
  more than €1,00 per maand per werkgever
- **SV-premium drift** — UWV publishes premium revisions annually
  (september publicatie for next year); track diff between
  `PayrollCalculator::AWF_LAAG_2026` and the latest UWV value
- **Tax-table hot-patches** — Belastingdienst can publish a
  `LoonheffingTabel` correction mid-year (rare); when it happens we
  create a new immutable row, ALL subsequent berekeningen use it, and
  we report the impact per werkgever
- **Cumulatieven anomalies** — daily check of stroken with
  `cumulatievenConsistent=false`; investigate and roll-fix within 24h

### Quarterly review (Q3 / Q4 / Q1 / Q2)

- Pull a sample of 5 random werkgevers; verify every cumulatieven
  invariant
- Verify all `LoonheffingTabel2026` rows are still immutable (audit-trail
  no mutations after `geldigVan`)
- Verify retention (Section 10 of audit checklist): no loonstrook deleted
  before 7y, no jaaropgave before 5y
- Confirm `composer audit` still clean

### Annual update (December → January)

- [ ] Load the new `LoonheffingTabel2027` (December 2026-publicatie,
      versienr `2026-W47`)
- [ ] Update `PayrollCalculator::ZVW_TARIEF_LAAG_2027`,
      `MAX_PREMIELOON_SV_JAAR_2027`, etc.
- [ ] Confirm Werkhervattingskas 2027 per sector
- [ ] Run the test suite + hydra gates against the new constants
- [ ] Update the audit-compliance checklist if Belastingplan
      2026–2027 changes any threshold

### Incident response

- Sev-1 (incorrect netto > €100 per strook): pause new strook generation,
  roll-back to last balanced LoonPeriode, post-mortem within 48h
- Sev-2 (LH-afdracht off by €1–€100): produce correctieaangifte via the
  SBR-app
- Sev-3 (DGA warning false-positive): no production blocker; correct
  norm constant in calculator + add regression test

## Sign-off

- Phase 1 sign-off: Product owner + accountant of each pilot
- Phase 2 sign-off: Engineering lead + product owner
- Phase 3: ongoing; reviewed quarterly in the OpenSpec changes board
