# Spec Delta: bookkeeping-vat-btw-filing

This delta adds the detection + compilation engine that REQ-VBTW-009
declared but that no code implemented (`VatCorrection` register landed,
zero services under `lib/Service/` reference it).

## ADDED Requirements

### Requirement: REQ-VBTW-013 — The system SHALL detect drift between a filed `VATReturn` and its underlying GL ledger

`VatSuppletieDetectionService::detect()` MUST accept a filed (submitted or
later) `VATReturn` id, recompute the same GL-derived per-rubriek grouping
`VATReturnService::deriveVATLines()` produces (grouped by `type` × `taxRate`)
**without persisting it**, and diff it bucket-by-bucket against the
`VATDeclaration` rows already persisted for that return (the as-filed
snapshot, stable because nothing re-derives them outside an explicit
`rebase`). The method MUST create a `VatCorrection` in `draft` state
carrying both the filed snapshot and the current snapshot, and MUST NOT
mutate the original `VATReturn`, its `VATDeclaration`s, or its `VATLine`s.
When the two snapshots are identical (no drift), `detect()` MUST return
without creating a `VatCorrection`.

#### Scenario: Drift is detected after a late-posted GL transaction

- **GIVEN** a `VATReturn` submitted for Q1-2026 with a persisted
  `VATDeclaration` of `21% collected: €3.150,00`
- **AND** a new `GLTransaction` posted after submission adds €500,00 of
  taxable revenue at 21% within the Q1-2026 date range on a
  `vatApplicable` account
- **WHEN** `VatSuppletieDetectionService::detect()` runs for that return
- **THEN** a `VatCorrection` MUST be created in `draft` state with
  `filedSnapshot` containing the original €3.150,00 bucket and
  `currentSnapshot` containing the recomputed €3.255,00 bucket (21% of the
  extra €500,00 = €105,00 collected VAT added)
- **AND** the original `VATReturn`'s `VATDeclaration` rows MUST remain
  unchanged.

#### Scenario: No drift produces no correction

- **GIVEN** a filed `VATReturn` whose GL data has not changed since filing
- **WHEN** `detect()` runs
- **THEN** no `VatCorrection` MUST be created.

@e2e exclude pure backend/data: GL diff computation is not browser-testable

### Requirement: REQ-VBTW-014 — The system SHALL compile per-rubriek deltas, decide suppletie-eligibility against the €1.000 grens, and stage a GL correction posting

`VatSuppletieDetectionService::prepare()` MUST take a `detected` (draft,
`preparedAt` null) `VatCorrection`, compute `rubriekDeltas` (one entry per
`type:taxRate` bucket with a non-zero difference between filed and current
snapshots), sum them into a net `correctionAmount`/`adjustmentAmount`, set
`thresholdExceeded` to `true` when `abs(correctionAmount) >= 1000` (the
statutory suppletie grens — see Notes) and `false` otherwise, stamp a
`filingDeadline` of `preparedAt + 8 weeks` (per the Belastingdienst's
discovery-to-filing obligation), and create a companion `draft`
`GLTransaction` with one balanced `GLLine` per non-zero rubriek delta
against the account originally used for that bucket, offset by a clearing
account. The method MUST NOT auto-post the `GLTransaction` and MUST NOT
auto-transition the `VatCorrection` past `draft` — the operator decides
whether and when to file, per REQ-VBTW-009's existing non-auto-decide rule.

#### Scenario: Above-grens correction is flagged threshold-exceeded with an 8-week deadline

- **GIVEN** a `detected` `VatCorrection` whose recomputed delta nets to
  €1.450,00 additional payable
- **WHEN** `prepare()` runs
- **THEN** `thresholdExceeded` MUST be `true`
- **AND** `filingDeadline` MUST be set to 8 weeks after `preparedAt`
- **AND** a `draft` `GLTransaction` MUST exist with balanced `GLLine`s
  summing to €1.450,00.

#### Scenario: Below-grens correction is flagged as next-return-eligible

- **GIVEN** a `detected` `VatCorrection` whose recomputed delta nets to
  €320,00
- **WHEN** `prepare()` runs
- **THEN** `thresholdExceeded` MUST be `false`
- **AND** the `VatCorrection` MUST still be fully compiled (deltas, GL
  posting) so the operator can choose to file anyway or fold it into the
  next return.

#### Scenario: The audit trail preserves the original filed figures

- **GIVEN** a `VatCorrection` compiled from a `VATReturn` originally filed
  with `totalVATCollected: €3.150,00`
- **WHEN** an auditor inspects the `VatCorrection`
- **THEN** `filedSnapshot` MUST reproduce the exact figures that were true
  at filing time, independent of any later GL changes
- **AND** the `VatCorrection` object itself MUST be covered by OR's
  immutable audit-trail (already satisfied by
  `add-shillinq-audit-trail.json`'s `x-openregister-audit-trail.enabled`
  flag per REQ-VBTW-012 — confirmed, not modified, by this change).

@e2e exclude pure backend/data: threshold decision + GL posting compilation is not browser-testable

## Notes

- **€1.000 threshold, verified 2026-07-13 via WebSearch against
  belastingdienst.nl** (not from training-data memory): "Hebt u bij uw
  btw-aangifte een bedrag van maximaal €1000 te veel of te weinig ingevuld?
  Geef dit dan aan in uw eerstvolgende btw-aangifte." Source:
  https://www.belastingdienst.nl/wps/wcm/connect/bldcontentnl/belastingdienst/zakelijk/btw/btw_aangifte_doen_en_betalen/aangifte_corrigeren/
  — corrections at or above €1.000 require the formal suppletie form and,
  per the Belastingdienst's 1 January 2025 update, must be filed within
  8 weeks of discovery or risk a "vergrijpboete" (up to 100% of the
  underpaid amount).
- This delta bridges a pre-existing dual-schema situation
  (`VATReturn`/all-caps vs. `VatReturn`/mixed-case) documented in
  `design.md`; `originalVatReturnId` on the compiled `VatCorrection`
  points at the all-caps `VATReturn.id` because that is the only schema
  with real, computed per-rubriek data today.
- Status lifecycle `detected → prepared → filed` is layered on the
  already-landed `draft/submitted/accepted/rejected` states via
  `preparedAt` rather than new states — see `design.md` Decision 3.
