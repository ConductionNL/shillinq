# Tasks: leges-at-intake

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Schema and admin

- [x] 1.1 Add `feeSchedule` to a register fragment `lib/Settings/register.d/leges-at-intake.json` with the D1 properties and the validity uniqueness (REQ-SOPR-006)
- [ ] 1.2 Add the fee schedule settings page with the BbvTaakveld column (D4)

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
