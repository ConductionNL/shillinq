# Tasks: fee-rule-calculation

- [x] FeeSchedule 0.3.0: `name`, `variantProperty`, `variantValue`, `calculation` and its fields
- [x] `FeeCalculation`: `assertValid()` at save, `applyTo()` per object
- [x] `FeeScheduleService`: the variant in the tuple; the object's variant first; the calculation applied in `resolveForObject()`
- [x] Tests: a percentage with its minimum and without a base; the variant's fee over the type's; a calculation without its numbers refused
