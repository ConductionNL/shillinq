## Context

Token-aware scan: **177 schemas / 610 Dutch properties** across 37 register fragments,
**30 files / 25 classes / 59 methods**. Largest schema surface in the fleet by a wide
margin.

The commitment domain is already done — PR #495 renamed `Verplichting*` to `Commitment*`
(5 schemas, all properties, enums and transitions) and is open against `development`.
This change is everything else.

But the headline number is not 610. It is **144**.

## The real constraint: 144 schemas are declared by more than one fragment

shillinq's register is assembled from 37 fragments by ADR-037's `deepMergeConfig()`,
which **concatenates list values**. A survey of `lib/Settings/register.d/` finds 144
schemas declared in two or more fragments:

| schema | fragments |
|---|---:|
| `ARInvoice` | **17** |
| `GLTransaction` | **11** |
| `Account` | 8 |
| `GLLine` | 8 |
| `Appointment`, `SupplierInvoice` | 6 |
| `CustomerMaster` | 5 |
| `Resource`, `BBVProgramme`, `BbvAccountMapping`, `BudgetBBVMapping`, `FiscalPeriod`, `Invoice`, `Receipt`, `FinancialStatement` | 4 |
| … 130 more at 2–3 | |

**This is exactly what caused shillinq#485.** Two fragments each declared
`Verplichting.required`; the merge concatenated them, and the resulting schema demanded
both vocabularies at once — a schema no payload could satisfy, which showed up as a
Newman 400 and skipped seeds rather than as a merge error.

For a vocabulary rename this changes the unit of work. Renaming one property on
`ARInvoice` is not a one-file edit — it is **up to 17 coordinated edits**, and missing one
leaves a fragment declaring the old property name. Because merge is additive, the result
is a schema carrying *both* names, with the old one possibly still in a concatenated
`required`. Nothing errors. The seed fails, or worse, does not.

**Decision:** the rename operates on **schemas, not files**. For every property renamed,
every declaring fragment is located first and edited together. A file-by-file sweep is
structurally unable to get this right.

## Goals / Non-Goals

**Goals:**

- Rename the remaining Dutch vocabulary outside the commitment domain.
- Handle multi-fragment schemas as single units.
- Adopt the fleet words, especially the validity-date collapse.

**Non-Goals:**

- Re-doing the commitment domain. PR #495 owns it; this change starts after it merges.
- Renaming SBR/XBRL, Peppol/UBL or Belastingdienst filing field names — those are wire.
- Renaming BBV/IV3 taxonomy codes, which are statutory classification values.

## Decisions

### 1. Order: after #495, and the `Commitment` slug is now claimed

PR #495 introduces `Commitment`, `CommitmentLine`, `CommitmentMovement`. Once merged,
shillinq owns those slugs fleet-wide, since resolution is instance-global. decidesk's
change has already been steered to `CouncilCommitment` for this reason.

**Decision:** this change starts after #495 merges, and treats the `Commitment*` names as
taken.

### 2. Statutory financial vocabulary: English plus marker

| Dutch | English | basis |
|---|---|---|
| `BbvTaakveld`, `Taakveld` | `BbvTaskField` | BBV art. 66 / IV3 |
| `Programmabegroting` | `ProgrammeBudget` | BBV |
| `Begrotingswijziging` | `BudgetAmendment` | BBV |
| `Rechtmatigheidstoets`/`-bevinding`/`-paragraaf` | `LawfulnessCheck`/`Finding`/`Statement` | BADO / Rechtmatigheidsverantwoording |
| `AlgemeenBelangBesluit` | `PublicInterestDecision` | Wet Markt en Overheid |
| `OpdrachtgeversVerklaring` | `ClientStatement` | DBA / Wet DBA |
| `IncassoKostenBerekening` | `CollectionCostCalculation` | WIK |
| `LoonPeriode`, `LoonStrook`, `Loonjournaalpost`, `LHAfdracht`, `LoonheffingTabel2026` | `PayPeriod`, `Payslip`, `PayrollJournalEntry`, `PayrollTaxRemittance`, `PayrollTaxTable2026` | Wet LB |
| `Werkgever`/`Werknemer` | `Employer`/`Employee` | — plain, no marker |
| `UrenRegistratie` | `TimeEntry` | — |
| `OninbaarAfschrijving` | `BadDebtWriteOff` | — |
| `KlantLadderOverride` | `CustomerLadderOverride` | — `klant` → `customer` per the fleet list |
| `DBAOpdracht`, `DBAEvidenceDossier` | `DbaAssignment`, `DbaEvidenceDossier` | Wet DBA — keep `DBA` as the statute's own abbreviation |
| `Goedkeuringsstap` | `ApprovalStep` | — |
| `TenderNedAanbesteding` | `TenderNedProcurement` | TenderNed is a product name and stays |
| `kernGegevensConfig` | `coreDataConfig` | — |

⚠️ **`Werknemer` → `Employee` collides with hrmq's `Employee`**, and `Administration`
already collides between the two apps. shillinq consumes hrmq properties, so these two
apps must agree rather than each picking `Employee` independently.

### 3. `BbvTaakveld` is declared by three fragments and `Taakveld` by two

`BbvTaakveld` appears in `add-shillinq-audit-trail.json`, `add-shillinq-bbv-compliance.json`
and `add-shillinq-bookkeeping-operations.json`; `Taakveld` in two more. Renaming the
statutory taxonomy therefore touches five fragments for what looks like one schema.

**Decision:** treat this as the worked example in the tasks — if the process handles
`BbvTaakveld` correctly it handles the other 143.

⚠️ The BBV/IV3 **taakveld codes themselves** (`0.1`, `6.71`, …) are statutory
classification values published by the Rijk. They are data, not identifiers, and stay.

### 4. `add-shillinq-audit-trail.json` declares ~90 of the 144

One fragment re-declares almost every schema in the app to attach audit-trail fields.
That single file is the second declaring fragment for the majority of the 144, which
means **almost every rename in this change touches it**.

**Decision:** treat it as a cross-cutting fragment and re-check it after every batch,
rather than once at the end. It is the single most likely place for a missed rename to
survive.

### 5. Validity dates and the fleet words

shillinq carries `ingangsdatum`, `einddatum`, `startDatum`, `eindDatum`, `geldigVan`,
`geldigTot`, `vervalDatum`, `looptijdEind`, `bezwaartermijnEinde` — nine spellings.
Per the fleet collision test none co-occur, so → `validFrom`/`validUntil`, except
`bezwaartermijnEinde`, which is a statutory **objection deadline** →
`objectionPeriodEnd`.

## Risks / Trade-offs

- **A multi-fragment schema is renamed in some fragments but not all** → the merge
  produces a schema with both names and a concatenated `required`; the failure surfaces
  as a Newman 400 or a silently skipped seed, not as an error. This is #485 recurring, and
  it is the dominant risk. Mitigated by decision 1's schema-first unit of work and by
  `validate-registers` as a gate.
- **`Werknemer`/`Administration` collide with hrmq** → mitigated by agreeing the words
  with hrmq before renaming; the two apps already share a window because shillinq consumes
  hrmq properties.
- **A BBV/IV3 code or an SBR/XBRL field is renamed** → a statutory filing stops
  validating. Mitigated by the key/value split.
- **Scripted renaming** → already burned this app once: `verplichting`→`commitment` turned
  `openstaande_verplichtingen` into `openstaande_commitmenten` and rewrote `@spec` paths
  into a non-existent directory. Anchored, per-file edits only.
- **`Requisition` deliberately mirrors `Commitment`'s field names** so one guard serves
  both. Renaming one side silently mis-evaluates the other.
- **610 properties invites batching by file** → which is the one thing decision 1 forbids.

## Migration Plan

1. Wait for #495 to merge.
2. Build the schema → declaring-fragments index; it is the work plan.
3. Agree `Employee` / `Administration` with hrmq.
4. Rename in schema-sized batches, all declaring fragments together, running
   `validate-registers` after each batch rather than at the end.
5. Rename the 25 classes and 59 methods; update fragments wiring guards by class name.
6. `l10n/nl.json`, `check-l10n`, gates.

**Rollback:** per batch, provided step 4's per-batch validation is honoured. If batches
are merged before validating, rollback granularity is lost.

## Open Questions

- Which of shillinq's 610 properties belong to SBR/XBRL, Peppol/UBL or Belastingdienst
  filing payloads? Unclassified, and it is the tier that must not be renamed.
- Does hrmq or shillinq own `Employee` and `Administration`? Both declare them today.
- Is `Requisition`'s field-name mirroring of `Commitment` documented anywhere, or only
  known through the guard that depends on it?
