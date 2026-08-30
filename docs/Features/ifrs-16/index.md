---
sidebar_position: 1
title: IFRS 16 Leases
description: Capitalise lease contracts, generate audit-ready RoU and liability journal entries every period, and produce the IFRS 16 disclosure note without leaving Shillinq.
---

# IFRS 16 Leases

Shillinq implements the single lessee model from **IFRS 16 *Leases*** (effective 1 January 2019) and its RJ 292 Dutch GAAP equivalent. Every lease longer than 12 months and above the low-value threshold is capitalised as a **right-of-use (RoU) asset** with a matching **lease liability**, posted automatically to the general ledger via the same journal-entry surface every other bookkeeping module uses.

## What you get

- A full **lease register** scoped per administration, with classification, IBR, term, and payment economics on one screen.
- Automatic **RoU / lease-liability recognition** at commencement, the matching journal entry, and a fixed-asset record carrying `isRouAsset=true`.
- **Period-by-period schedule** generation — interest, principal split, and straight-line depreciation, materialised to the `lease-payment-schedule` register and consumed by the period-close engine.
- **Reassessment workflow** for indexation, extension-option / termination-option reassessment, scope / term / payment modifications, IBR reset, impairment, abandonment, partial and full termination. Every event captures before/after snapshots, balanced GL lines, and routes through decidesk approval above EUR 100,000 RoU impact.
- **Short-term (≤12 months)** and **low-value** exemption handling with straight-line expense posting and policy elections at portfolio level.
- **Annual disclosure table** generation per IFRS 16.51–60 — RoU by asset class, current / non-current liability split, undiscounted maturity analysis, liability-weighted IBR, expense breakdown, qualitative narrative seed. CSV export today, PDF + XBRL in Phase 2.
- **Audit-pack export** index (lease-contract PDF, full schedule CSV, IBR evidence FKs, every reassessment event with snapshots, disclosure CSV) ready for the docudesk pipeline.

## Where to start

1. **[Create a lease](./create-lease.md)** — register a contract, classify it, and activate the draft → active transition.
2. **[Payment schedule](./payment-schedule.md)** — read the period-by-period amortisation and understand the GL postings.
3. **[Reassessment](./reassessment.md)** — record indexation, extension reassessment, modifications, impairment, abandonment.
4. **[Exemptions](./exemptions.md)** — set up the portfolio policy for short-term and low-value leases.
5. **[Disclosures](./disclosures.md)** — generate and review the IFRS 16 disclosure note.
6. **[FAQ](./faq.md)** — IBR derivation, "reasonably certain" judgements, modification accounting, transition methodology.

## Architecture

| Capability | Service | Notes |
|---|---|---|
| Period-by-period maths | `LeaseAmortizationCalculator` | Pure-logic; cents-only arithmetic; unit-tested against IASB illustrative examples. |
| Schedule generation | `LeasePaymentScheduleService` | Persists `LeasePaymentSchedule` rows via the real OpenRegister `saveObject`. |
| RoU / liability recognition | `LeaseRecognitionService` | Produces balanced JE lines for the generic GL surface; no parallel tables. |
| Disclosure table | `LeaseDisclosureService` | Aggregates by asset class; CSV export; PDF render in Phase 2. |
| Reassessment events | `LeaseReassessmentService` | Indexation / extension / modification / impairment; routes ≥ EUR 100K to decidesk. |
| Transition wizard | `LeaseTransitionWizard` | Modified- and full-retrospective; honours IFRS 16.C3/C10 expedient elections. |
| Audit pack | `LeaseAuditPackGenerator` | Skeleton index — PDF/ZIP rendering lands with docudesk Phase 2. |
| Activation gate | `LeaseContractGuard` | Fail-closed precondition: classification enum + every economic field present (REQ-LC-004). |

All schemas live in the ADR-037 `register.d/bookkeeping-ifrs-16-lease.json` fragment; the monolith register is never touched.
