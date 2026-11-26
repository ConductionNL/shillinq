# Tasks: leges-at-intake

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Schema and admin

- [x] 1.1 Add `feeSchedule` to a register fragment `lib/Settings/register.d/leges-at-intake.json` with the D1 properties and the validity uniqueness (REQ-SOPR-006)
- [x] 1.2 Add the fee schedule settings page with the BbvTaakveld column (D4). Page built 9 Oct 2026 (Government > Fee schedules, `src/manifest.d/fee-schedules.json`). Column built per Q-shillinq-2 (answered 9 Oct): optional `Account.taskField` (`lib/Settings/register.d/public-sector-account-task-field.json`), copied onto `FeeSchedule.revenueTaskField` on save by `FeeScheduleValidationListener` via `lib/Service/RevenueTaskFieldLookup.php`; IV3 reads the same field. Tests: `tests/Unit/Listener/FeeScheduleValidationListenerTest.php`, `tests/Unit/Reporting/Generator/Iv3ReportGeneratorTaskFieldTest.php` (REQ-SOPR-011).

## 2. Journey step

- [x] 2.1 Decide the step: resolve the schedule, append one `leges` request, gate on `payAtIntake`, reuse a resumed run's request (REQ-SOPR-007). This is `LegesIntakeStepService`, covered by PHPUnit
- [ ] 2.2 Amend ADR-085 to name a `payment` step kind, then register the decision as that step's renderer for provider `shillinq` in portaliq. Blocked and not startable; the dependency is recorded in portaliq#617 and the reason is below

### Why `LegesIntakeStepService` has no caller

The class is dark on purpose, and this is the reason rather than an oversight.
It decides a journey step of kind `payment`, and there is no journey to put
a step in. Four links are missing, each checked rather than inferred from the
one before it:

- `CnJourney`, which ADR-085 puts in `@conduction/nextcloud-vue`, is not in
  the library. Zero hits in its source and zero in the built 3.2.0 bundle.
- A `journey` and a `journeyRun` are OpenRegister objects (ADR-085). Neither
  is among the 31 schemas in portaliq's register.
- ADR-085 names three step kinds: `form`, `review` and `confirmation`. There
  is no `payment` kind to register a renderer for, and adding one is an
  amendment to that ADR, which task 2.2 now asks for. Shillinq is the driver.
- Portaliq's portal still boots React, so its own in-page journey task has
  not landed either. Every task in hydra's `portaliq-phase-two`, which builds
  the journey primitive, is unchecked.

Portaliq's existing intake cannot stand in. Its submit route answers with a
reference and `state: queued` and the citizen leaves; the case object is
written later by a case app. So there is no object to raise a request against
at that moment, and `payAtIntake: required` exists to hold the applicant until
the money is authorized, which a fire-and-forget queue has nobody left to do.
Wiring the service there would give the gate nothing to gate.

So task 2.1's original wording was wrong twice. It claimed the renderer was
registered through the shared portal runtime, and nothing registers anything;
and it said the portaliq renderer was waiting on task 2.2, when task 2.2 is
itself waiting on an ADR amendment and a runtime nobody has built.

## 3. Desk

- [x] 3.1 Return `fee` from `list` on a type object and add "Raise leges request" to the panel (REQ-SOPR-008)

## 4. Quality

- [x] 4.1 PHPUnit: schedule validity, product amount read, step gating per `payAtIntake`, `noFee`, resumed run reuse; run inside the container
- [ ] 4.2 Extend `tests/e2e/payment-request-panel.spec.ts` with the desk case; Dutch and English strings; docs with screenshots; tell dossiq which case types to give a `payment` step
  - Desk case built and run live (26 Nov 2026, sandbox Nextcloud 34.0.6 + PostgreSQL 16 + OpenRegister main 01e805cd with openregister#11192 and its raw-body fix): `payment-request-panel.spec.ts` 4 passed. The new test makes its own case type (register + schema with `caseType`), publishes a EUR 125.50 schedule for `bouwvergunning`, raises leges on a case through `POST /api/payment-requests/leges` and finds one `pending` leges request of the schedule's amount on the case, described with its regulation (REQ-SOPR-008). Writing it found three live faults, fixed here except the first: (1) OpenRegister's object API dropped a FeeSchedule's own `register`/`schema` fields, so no schedule could be saved through the settings page (openregister#11192, plus a fix on that branch: the body is read raw, as `$request->post` answers the merged parameters in which the route wins); (2) `FeeScheduleService` filtered on `register`, which OpenRegister reads as its own register metadata, and kept only array rows while OpenRegister answers `ObjectEntity` rows, so no fee ever resolved (`FeeScheduleServiceTest::testAFeeResolvesFromEntityRowsWithoutARegisterFilter`, red without the fix); (3) raising leges on a register the instance does not have answered 500, now "No such object." 404 (`PaymentRequestActionControllerTest::testRaisingLegesOnAnAbsentRegisterIsNoSuchObject`). The test skips with the reason while OpenRegister lacks openregister#11192. Left: the panel click and docs screenshots on a dossiq case page (needs dossiq), and telling dossiq which case types get a `payment` step.
