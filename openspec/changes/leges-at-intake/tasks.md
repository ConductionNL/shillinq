# Tasks: leges-at-intake

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Schema and admin

- [x] 1.1 Add `feeSchedule` to a register fragment `lib/Settings/register.d/leges-at-intake.json` with the D1 properties and the validity uniqueness (REQ-SOPR-006)
- [ ] 1.2 Add the fee schedule settings page with the BbvTaakveld column (D4)

## 2. Journey step

- [x] 2.1 Register the `payment` step renderer for provider `shillinq` through the shared portal runtime; resolve schedule, append request, gate on `payAtIntake` (REQ-SOPR-007) — the decision lives in `LegesIntakeStepService`; the portaliq-side renderer waits on task 2.2
- [ ] 2.2 Open the portaliq follow-up so `journey.steps[]` validates kind `payment` (ADR-085); record the id here

## 3. Desk

- [x] 3.1 Return `fee` from `list` on a type object and add "Raise leges request" to the panel (REQ-SOPR-008)

## 4. Quality

- [x] 4.1 PHPUnit: schedule validity, product amount read, step gating per `payAtIntake`, `noFee`, resumed run reuse; run inside the container
- [ ] 4.2 Extend `tests/e2e/payment-request-panel.spec.ts` with the desk case; Dutch and English strings; docs with screenshots; tell dossiq which case types to give a `payment` step
