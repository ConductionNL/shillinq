# Lane f-billing (follow-up, 2026-09-28)

## Part 1: shillinq billing-inherited-defects
- Branch `fix/billing-inherited-defects` (cut --no-track from origin/development af077af86).
- Artifacts: proposal, 5 spec deltas (SPPI-011, RIN-010, IQD-007, SOPR-009, CCD-016), design, migration, tasks; validate --strict valid.
- Fixes, each red first: invoice lookup find(uuid) then slug (stub now matches nothing on an id filter, 9 tests red); ARInvoice 0.16.0 declares recurringProfileId, billingPeriod, customerReference, invoiceLines[].glAccount; PaymentRequest 0.6.0 declares requestedBy (BillingPayloadDeclaredFieldsTest red without the fragment); recurring payload invoiceNumber REC-yyyymm-8-nn + periodId + line glAccount; quick draft line glAccount (vitest red); DunningTemplateRegistry WIRED in executeStage via resolve() (tests red).
- Commits 030c640ea..24aa9b3d9. Status: strict + gates running.

## Part 2: portaliq news-item-translation (clone pq-guard, log there too)
- Branch `feat/news-item-translation`, commits d345579..2ecdf86.
- Gate 51 NEW (invoiceLines overlay title) fixed 4th commit; strict run 1 red on SchoolContributionsFragmentTest pin (NEW) fixed 9ba2d22b6; strict run 2 EXIT 0 (5393 OK); npm checks 0; gates diff EXIT 0 (50/50).
- PR https://github.com/ConductionNL/shillinq/pull/1745 (not merged). opsx-verify headless clean. Part 1 DONE.
- Part 2 portaliq PR https://github.com/ConductionNL/portaliq/pull/837, details in pq-guard/LANE-LOG-f.md. DONE.
