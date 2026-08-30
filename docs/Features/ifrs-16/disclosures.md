---
sidebar_position: 6
title: Disclosures
description: Generate the IFRS 16.51–60 disclosure note, export to CSV, and review the audit pack for sign-off.
---

# Disclosures and audit pack

## What the disclosure table contains

`LeaseDisclosureService::generateForPeriod(administrationId, fiscalPeriod)` reads every active / modified lease for the administration and produces a `LeaseDisclosureTable` payload with:

| Section | Fields |
|---|---|
| Period scope | `fiscalPeriod`, `administrationId`, `materializedAtPeriodClose` |
| RoU asset | `closingRouByClass` (per asset class), `totalRouAdditionsInPeriod`, `totalRouDepreciationInPeriod`, `totalRouDisposalsInPeriod` |
| Lease liability | `totalLeaseLiabilityCurrent`, `totalLeaseLiabilityNoncurrent` |
| Maturity analysis (REQ-LD-002) | Undiscounted buckets: `lt1y`, `y1to2`, `y2to3`, `y3to4`, `y4to5`, `gt5y` |
| Weighted-average IBR (REQ-LD-003) | Per asset class, weighted by opening liability |
| Expenses | `totalInterestExpense`, `totalShortTermLeaseExpense`, `totalLowValueLeaseExpense`, `totalVariableLeaseExpense` |
| Narrative | `qualitativeNarrative` seed for the operator to refine |

Draft leases are excluded; only `active` and `modified` contribute.

## CSV export (task 10.3)

`LeaseDisclosureService::exportToCSV($disclosure)` flattens the payload into a 3-column RFC 4180 CSV (`section,label,value`) suitable for direct download or import into a working paper. Each scalar total, every RoU class, every maturity bucket, and the weighted-average IBR per class are emitted as separate rows so the consumer can group by `section`. Quotes / commas / newlines are escaped per RFC 4180.

```text
section,label,value
header,fiscalPeriod,2026
header,administrationId,adm-1
rou-by-class,vehicle,18500.00
rou-by-class,real-estate,920000.00
maturity-analysis,lt1y,36000.00
maturity-analysis,y1to2,36000.00
weighted-average-ibr,vehicle,4.00
weighted-average-ibr,real-estate,5.25
totals,totalLeaseLiabilityCurrent,32000.00
totals,totalInterestExpense,4350.00
narrative,qualitativeNarrative,"The entity leases assets …"
```

## PDF / XBRL — Phase 2

- **PDF export** uses the docudesk PDF pipeline once it's merged.
- **XBRL / ESEF skeleton** lands with the `bookkeeping-sbr-xbrl-reporting` change; the disclosure payload is the input.

Until both ship, the CSV export covers the auditor's working paper need; the on-page table covers the live review.

## Audit pack

`LeaseAuditPackGenerator::generate(leaseId, administrationId, operatorId)` builds the audit-pack **index** for a single lease — the artefacts an auditor needs to walk the contract end-to-end:

- `index.md` — pack index and walkthrough.
- `lease-contract.pdf` — placeholder for the docudesk PDF render (Phase 2).
- `schedule/<lease>-schedule.csv` — full `LeasePaymentSchedule` rows.
- `disclosure/disclosure.csv` — the disclosure CSV scoped to the lease's classes.
- `ibr-evidence/` — every docudesk FK from `ibrEvidenceDocuments`.
- `reassessments/` — every `LeaseReassessmentEvent` with before/after snapshots.

The deterministic download path is `/shillinq/audit-packs/YYYY-MM-DD/<lease>.zip`. Re-export on the same day overwrites the same path; a next-day export lands at a new path. Status starts as `pending-pdf-pipeline` and flips to `ready` once the docudesk renderer fills the placeholder.

## Workflow

1. **Period close.** Period-close engine generates / materialises the disclosure table for the closing fiscal period.
2. **Review.** Operator opens `Bookkeeping → IFRS 16 Leases → Annual Disclosures` and reviews the per-class breakdown.
3. **Validate.** "Validate Disclosures" action runs sanity checks (debit/credit on every reassessment event, no draft leases pulled in, weighted-average IBR ≤ max contract IBR).
4. **Export CSV.** Download the CSV for the working paper.
5. **Audit pack.** For each lease the auditor flags, run the audit-pack export.
6. **Sign-off.** Attach the PDF (Phase 2) and CSV to the period-close binder; mark `materializedAtPeriodClose=true`.
