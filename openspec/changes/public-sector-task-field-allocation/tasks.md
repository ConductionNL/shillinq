# Tasks: public-sector-task-field-allocation

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Task fields

- [ ] 1.1 `TaskFieldRealisation::forYear` and the dashboard payload (REQ-BBV-012). Verify: PHPUnit red first over real GL lines and mappings; an account without a mapping is listed, not dropped.
- [ ] 1.2 Dashboard table and unmapped list (REQ-BBV-012). Verify: vitest on the component; `npm run check:manifest`.

## 2. Joint arrangement

- [ ] 2.1 `GRAllocationRun` fragment and `JointArrangementAllocation::propose` (REQ-GRC-007). Verify: PHPUnit red first; the payload validates against the real fragment; three participants at 50, 30 and 20 percent of EUR 100,000.01 add up to the cent.
- [ ] 2.2 `confirm` and `invoice` (REQ-GRC-008). Verify: PHPUnit; each draft invoice validates against the real `ARInvoice` schema; a confirmed run cannot be proposed again for the same key and period.
- [ ] 2.3 Pages and nav (REQ-GRC-007). Verify: `check:manifest`, nav reachability, vitest on the propose action.

## 3. Market separation

- [ ] 3.1 `calculate` action on `CommercialActivity` (REQ-WMO-013). Verify: PHPUnit red first with the real calculator; the `IntegralCostPrice` payload validates against the real fragment.
- [ ] 3.2 `CrossSubsidyScanJob` (REQ-WMO-014). Verify: PHPUnit; a second run does not duplicate an open alert; job registered in info.xml.

## 4. Strings and live check

- [ ] 4.1 English and Dutch strings. Verify: `npm run test:l10n`, `check:schema-l10n`.
- [ ] 4.2 Live: post a line on an unmapped account in a municipal administration, see it listed; run an allocation over three participants; calculate a cost price.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
