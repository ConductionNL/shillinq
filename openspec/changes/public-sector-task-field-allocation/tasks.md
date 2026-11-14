# Tasks: public-sector-task-field-allocation

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Task fields

- [x] 1.1 `TaskFieldRealisation::forYear` and the dashboard payload (REQ-BBV-012). Verify: PHPUnit red first over real GL lines and mappings; an account without a mapping is listed, not dropped. — `lib/Service/PublicSector/TaskFieldRealisation.php` over posted `GLTransaction`/`GLLine` (through `LedgerActuals`) and `BbvAccountMapping`; `BBVDashboardController` adds `taskFieldRealisation` to the scoped envelope. `TaskFieldRealisationTest`, `BBVDashboardControllerTest::testTheScopedEnvelopeCarriesTheRealisationPerTaskField`.
- [x] 1.2 Dashboard table and unmapped list (REQ-BBV-012). Verify: vitest on the component; `npm run check:manifest`. — `src/components/Dashboard/BBVTaskFieldRealisation.vue` as the `bbv-task-fields` widget of `BBVComplianceDashboard`, reading `taskFieldRealisation` from `/api/bbv-dashboard`; each unmapped account links to `/overheid/bbv-mapping`. `tests/vitest/bbvTaskFieldRealisation.spec.js`.

## 2. Joint arrangement

- [x] 2.1 `GRAllocationRun` fragment and `JointArrangementAllocation::propose` (REQ-GRC-007). Verify: PHPUnit red first; the payload validates against the real fragment; three participants at 50, 30 and 20 percent of EUR 100,000.01 add up to the cent. — `lib/Settings/register.d/public-sector-task-field-allocation.json` (GRAllocationRun draft/confirmed/invoiced; `GRDeelnemer.customerId`, `ARInvoice.allocationRunId`); `lib/Service/PublicSector/JointArrangementAllocation.php` behind `JointArrangementAllocationInterface`, run by `lib/Lifecycle/Action/JointArrangementAllocationAction.php` on the `propose` transition. `tests/Unit/PublicSector/JointArrangementAllocationTest.php`.
- [x] 2.2 `confirm` and `invoice` (REQ-GRC-008). Verify: PHPUnit; each draft invoice validates against the real `ARInvoice` schema; a confirmed run cannot be proposed again for the same key and period. — the `confirm` and `invoice` transitions of GRAllocationRun through the same action; a participant without a customer stops the invoicing before any invoice is written, and a line with an invoice keeps it.
- [x] 2.3 Pages and nav (REQ-GRC-007). Verify: `check:manifest`, nav reachability, vitest on the propose action. — `src/manifest.d/public-sector-task-field-allocation.json`: `GRAllocationRuns` (`/gr/verdeelruns`) after the allocation keys in the Bookkeeping menu, and `GRAllocationRunDetail` with Propose, Confirm and Invoice participants as lifecycle transitions. `tests/vitest/grAllocationRunManifest.spec.js`.

## 3. Market separation

- [x] 3.1 `calculate` action on `CommercialActivity` (REQ-WMO-013). Verify: PHPUnit red first with the real calculator; the `IntegralCostPrice` payload validates against the real fragment. — the `calculate` transition (input `costPriceYear`) on CommercialActivity runs `lib/Lifecycle/Action/CalculateIntegralCostPriceAction.php` over `lib/Service/PublicSector/ActivityCostPriceCalculation.php`: direct costs from the year's posted lines on the cost centre and cost object, sorted by RGS code; the overhead pool from task field 0.4 spread by the activity's `overheadKey`; `IntegralCostPriceCalculator::compose` writes one provisional `IntegralCostPrice` per year, named by the activity code. `tests/Unit/PublicSector/ActivityCostPriceCalculationTest.php`.
- [ ] 3.2 `CrossSubsidyScanJob` (REQ-WMO-014). Verify: PHPUnit; a second run does not duplicate an open alert; job registered in info.xml.

## 4. Strings and live check

- [ ] 4.1 English and Dutch strings. Verify: `npm run test:l10n`, `check:schema-l10n`.
- [ ] 4.2 Live: post a line on an unmapped account in a municipal administration, see it listed; run an allocation over three participants; calculate a cost price.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
