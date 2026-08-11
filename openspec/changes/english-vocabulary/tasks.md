# Tasks — english-vocabulary (shillinq)

Scan: **177 schemas / 610 Dutch properties** across 37 register fragments, **30 files /
25 classes / 59 methods**. Largest schema surface in the fleet.

The commitment domain is already renamed in PR #495 and is not in scope here.

⚠️🔥 **144 of shillinq's schemas are declared by more than one fragment** — `ARInvoice`
by 17, `GLTransaction` by 11, `Account` and `GLLine` by 8. ADR-037 merges fragments by
**concatenating list values**, which is exactly what produced #485. The unit of work is
therefore the **schema**, never the file.

## 1. Sequence and index

- [ ] 1.1 Wait for PR #495 to merge. It claims the `Commitment*` slugs fleet-wide
      (resolution is instance-global) and decidesk has already been steered off them.
- [ ] 1.2 Build the **schema → declaring-fragments index** for all 177 schemas. This index
      is the work plan; the file listing is not. Note that
      `add-shillinq-audit-trail.json` is the second declaring fragment for roughly 90 of
      the 144, so nearly every rename touches it.
- [ ] 1.3 Classify which properties belong to SBR/XBRL, Peppol/UBL or Belastingdienst
      filing payloads. These are wire and must not be renamed. An unclassified property
      does not get renamed.

## 2. Agree the shared names before renaming

- [ ] 2.1 Agree `Employee` and `Administration` with hrmq — both apps declare them today,
      and shillinq consumes hrmq properties, so the two already share a window.
- [ ] 2.2 Confirm whether `Requisition`'s mirroring of `Commitment`'s field names is
      documented anywhere, or known only through the guard that depends on it. Record it
      in the schema either way; renaming one side silently mis-evaluates the other.

## 3. Worked example first — prove the process on BbvTaakveld

- [ ] 3.1 Rename `BbvTaakveld` → `BbvTaskField` and `Taakveld` → `TaskField` across **all
      five** declaring fragments (`add-shillinq-audit-trail.json`,
      `add-shillinq-bbv-compliance.json`, `add-shillinq-bookkeeping-operations.json` and
      the two declaring `Taakveld`). Run `validate-registers` immediately after. If the
      process handles this correctly it handles the other 143.
- [ ] 3.2 Confirm the BBV/IV3 taakveld **codes** (`0.1`, `6.71`, …) are unchanged — they
      are published statutory classification values, i.e. data, not identifiers.

## 4. Rename statutory financial vocabulary with markers

- [ ] 4.1 `Programmabegroting` → `ProgrammeBudget`, `Begrotingswijziging` →
      `BudgetAmendment` (BBV); `Rechtmatigheidstoets`/`-bevinding`/`-paragraaf` →
      `LawfulnessCheck`/`LawfulnessFinding`/`LawfulnessStatement` (BADO);
      `AlgemeenBelangBesluit` → `PublicInterestDecision` (Wet Markt en Overheid);
      `IncassoKostenBerekening` → `CollectionCostCalculation` (WIK);
      `OpdrachtgeversVerklaring` → `ClientStatement` and `DBAOpdracht`/
      `DBAEvidenceDossier` → `DbaAssignment`/`DbaEvidenceDossier` (Wet DBA).
- [ ] 4.2 Payroll: `LoonPeriode`/`LoonStrook`/`Loonjournaalpost`/`LHAfdracht`/
      `LoonheffingTabel2026` → `PayPeriod`/`Payslip`/`PayrollJournalEntry`/
      `PayrollTaxRemittance`/`PayrollTaxTable2026` (Wet LB); `Werkgever`/`Werknemer` →
      `Employer`/`Employee` per task 2.1.
- [ ] 4.3 Remaining Dutch-named schemas: `UrenRegistratie` → `TimeEntry`,
      `OninbaarAfschrijving` → `BadDebtWriteOff`, `KlantLadderOverride` →
      `CustomerLadderOverride`, `Goedkeuringsstap` → `ApprovalStep`,
      `TenderNedAanbesteding` → `TenderNedProcurement` (TenderNed is a product name and
      stays), `OpdrachtUitvoering` → `AssignmentExecution`, `kernGegevensConfig` →
      `coreDataConfig`.
- [ ] 4.4 Attach statute markers to the schemas in 4.1 and 4.2.

## 5. Rename properties, adopting the fleet words

- [ ] 5.1 Validity boundaries → `validFrom`/`validUntil`: `ingangsdatum`, `einddatum`,
      `startDatum`, `eindDatum`, `geldigVan`, `geldigTot`, `vervalDatum`, `looptijdEind`.
      ⚠️ `bezwaartermijnEinde` is a statutory objection deadline → `objectionPeriodEnd`,
      not a validity boundary.
- [ ] 5.2 The rest of the fleet words: `naam`→`name`, `beschrijving`/`omschrijving`→
      `description`, `toelichting`→`notes`, `titel`→`title`, `onderwerp`→`subject`,
      `organisatie`→`organisation`, `bedrag`→`amount`, `bron`→`source`, `niveau`→`level`,
      `kenmerk`→`reference`, `klantId`→`customerId` (agree with pipelinq, which also
      carries it).
- [ ] 5.3 Work in schema-sized batches from the 1.2 index, all declaring fragments
      together, running `validate-registers` **after each batch** — not at the end.
      Re-check `add-shillinq-audit-trail.json` after every batch.

## 6. Code layer

- [ ] 6.1 Rename the 25 classes and 59 methods — the `Guard` and `Listener` families
      (`VerplichtingGuard`, `RechtmatigheidGuard`, `ProgrammabegrotingGuard`,
      `BegrotingswijzigingGuard`, `KlantLadderOverrideApprovalGuard`,
      `OpdrachtUitvoeringGuard`, `DBAOpdrachtGuard`, `BezwaarTermijnGuard`,
      `TenderNedAanbestedingGuard`, `DBAFactuurMonitorListener`,
      `OpdrachtUitvoeringTransitionListener`) and the generated `js/shillinq-src_manifest_d_*`
      bundles.
- [ ] 6.2 Update every register fragment that wires a guard or listener **by class name** —
      a renamed class stops being wired silently, with nothing raised.
- [ ] 6.3 Re-check `strtolower()`-compared literals, and use **no scripted substitution**
      anywhere. A camelCase rename makes a lowercase comparison permanently unsatisfiable
      with no test failing (this app already shipped one — `'orderFulfilment'` never
      matched); PHPStan does catch it. And `verplichting`→`commitment` once turned
      `openstaande_verplichtingen` into `openstaande_commitmenten` and rewrote `@spec`
      paths into a non-existent directory. Anchored per-file edits only.

## 7. Verify

- [ ] 7.1 `l10n/nl.json` re-pointed not re-extracted; `check-l10n`. `validate-seeds` at or
      below baseline; `validate-registers` PASS; Newman green.
- [ ] 7.2 Generate and validate one SBR/XBRL and one Peppol/UBL filing — a filing whose
      field names moved fails validation, not a unit test.
- [ ] 7.3 Re-run the token-aware scan (residual Dutch SHALL be exactly the classified
      filing-payload fields), then the full suite plus hydra gates 46/53/54/55/57/61.

## Acceptance criteria

- The schema → declaring-fragments index exists and was used as the work plan.
- Every renamed property renamed in **all** its declaring fragments; `validate-registers`
  run per batch, not once.
- BBV/IV3 codes and every filing payload field byte-identical.
- `Employee` and `Administration` agreed with hrmq before renaming.
- `Requisition`'s mirroring of `Commitment` documented and both sides aligned.
- No scripted substitution used anywhere in the change.
