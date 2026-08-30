# Spec: bookkeeping-verplichtingenadministratie

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (operations)
**Depends on:** T1 `bookkeeping-chart-of-accounts`, T1 `bookkeeping-general-ledger`, T1 `bookkeeping-budget-planning`, T2 `bookkeeping-accounts-payable-core`

This capability delivers **commitment accounting** (verplichtingenadministratie), enabling Dutch organisations to track financial obligations from the moment they become legally binding (PO signed, contract executed, subsidy awarded) through delivery, invoicing, and payment. It corrects budget management to reflect outstanding commitments in real-time and enforces mandate-based authorization at the commitment stage.

This is foundational for compliance with BBV (Besluit Begroting en Verantwoording) and rechtmatigheidsverantwoording in municipalities, and essential for cash-flow forecasting in commercial operators.

## ADDED Requirements

### REQ-VPL-001: Budget-blocking at commitment-time

When a verplichting is moved to `aangegaan` status, the system SHALL immediately block the corresponding bedrag from the available budget (`vrije_ruimte`), regardless of whether an invoice or payment has been received.

Budget shall be calculated as: `vrije_ruimte = geautoriseerd_bedrag - gerealiseerd_bedrag - openstaande_verplichtingen`

#### Scenario: Verplichting blocks budget on aangegaan

- **GIVEN** a budget on programma 5.1 with `vrije_ruimte = EUR 200.000`, `gerealiseerd_bedrag = EUR 0`, `openstaande_verplichtingen = EUR 0`
- **WHEN** a verplichting of EUR 75.000 is moved to `aangegaan` state
- **THEN** `openstaande_verplichtingen` SHALL increase to EUR 75.000, `vrije_ruimte` SHALL decrease to EUR 125.000, and `gerealiseerd_bedrag` SHALL remain unchanged

#### Scenario: Multi-year verplichting blocks per boekjaar

- **GIVEN** a budget with `vrije_ruimte = EUR 500.000` for programma 5.1 / boekjaar 2026, and a separate budget for 2027 with `vrije_ruimte = EUR 500.000`
- **WHEN** a raamovereenkomst with `looptijd_van = 2026-05-01, looptijd_tot = 2027-04-30` and total bedrag EUR 200.000 is moved to `aangegaan`
- **THEN** verplichtingsregel for boekjaar 2026 SHALL block EUR 100.000 on the 2026 budget, verplichtingsregel for boekjaar 2027 SHALL block EUR 100.000 on the 2027 budget independently

#### Scenario: Budget-exceeded commitment without mandate override is rejected

- **GIVEN** a verplichting of EUR 250.000 is being moved to `aangegaan`, but `vrije_ruimte = EUR 200.000` and the user has no mandate to exceed
- **WHEN** the state-change is attempted
- **THEN** the system SHALL reject the transition with error message "insufficient budget; vrije_ruimte = EUR 200.000" and SHALL NOT reduce vrije_ruimte

### REQ-VPL-002: Mandate-toetsing bij aangaan

No verplichting SHALL move to `aangegaan` without valid mandaat-check. If the verplichting bedrag exceeds a user's mandaat ceiling, an approval-workflow SHALL be triggered before `aangegaan` is reached.

#### Scenario: User with mandate can sign within limit

- **GIVEN** a user with mandaat `M-INKOOP-50K` (maximumbedrag EUR 50.000) and `soort_verplichting IN ['inkooporder', 'raamovereenkomst']`
- **WHEN** a verplichting of type `inkooporder` with bedrag EUR 30.000 is moved to `aangegaan`
- **THEN** the transition SHALL complete immediately, mandaat shall be logged in `verplichting.mandaat_toegepast`, and verplichting status SHALL become `aangegaan`

#### Scenario: Mandate exceeded triggers approval-workflow

- **GIVEN** a user with mandaat `M-INKOOP-50K` (EUR 50.000) attempts to move a verplichting of EUR 75.000 to `aangegaan`
- **WHEN** the state-change is triggered
- **THEN** verplichting status SHALL move to `in_goedkeuring`, a goedkeuringsstap SHALL be created assigned to the next-higher mandaat-holder (e.g., directeur), and the verplichting SHALL NOT reach `aangegaan` until approval is granted

#### Scenario: Tweede handtekening required above threshold

- **GIVEN** a mandaat with `maximumbedrag = EUR 50.000` and `vereist_tweede_handtekening_boven = EUR 25.000`, applied to two users
- **WHEN** a verplichting of EUR 30.000 is moved to `aangegaan`
- **THEN** BOTH users' signatures SHALL be collected before verplichting reaches `aangegaan` state, and the final goedkeuringsstap SHALL record both signatures

### REQ-VPL-003: Drie-staps registratie (aangegaan–ontvangen–gefactureerd)

The lifecycle SHALL distinguish three separate moments: commitment (PO signed), receipt (goods/services delivered), and invoicing (invoice received). Each is recorded as a separate verplichtingsmutatie with distinct impacts on budget and GL.

#### Scenario: Prestatie-ontvangst does not affect budget

- **GIVEN** a verplichting for 100 chairs at EUR 250 each (EUR 25.000 total) in state `aangegaan`
- **WHEN** a partial receipt of 60 chairs is recorded via mutatie `soort = prestatie_ontvangen, bedrag = EUR 15.000`
- **THEN** `verplichtingsregel.geleverd_bedrag` SHALL increase to EUR 15.000, `restant_verplicht` SHALL remain EUR 25.000 (unchanged), and `vrije_ruimte` SHALL not change

#### Scenario: Gefactureerd reduces restant_verplicht

- **GIVEN** prestatie-ontvangst of 60 chairs (EUR 15.000) has been recorded
- **WHEN** an invoice for those 60 chairs is received and posted
- **THEN** a mutatie `soort = gefactureerd, bedrag = EUR 15.000` SHALL be created, `verplichtingsregel.gefactureerd_bedrag` SHALL increase to EUR 15.000, `restant_verplicht` SHALL decrease to EUR 10.000, and the invoice SHALL move from AP draft to a matched state

#### Scenario: Betaling does not affect outstanding commitments

- **GIVEN** an invoice for EUR 15.000 has been posted (gefactureerd state)
- **WHEN** the invoice is paid via a payment run
- **THEN** a mutatie `soort = betaald, bedrag = EUR 15.000` SHALL be created, but `openstaande_verplichtingen` on the budget SHALL NOT change (the invoice already released it in gefactureerd stage)

### REQ-VPL-004: Raamovereenkomsten en meerjareige verplichtingen

The system SHALL support multi-year framework agreements with automatic per-year budget blocking and independent call-offs per year.

#### Scenario: Raamovereenkomst creates regels per boekjaar

- **GIVEN** a raamovereenkomst with `looptijd_van = 2026-04-01, looptijd_tot = 2030-03-31` and `totaalbedrag = EUR 400.000` (EUR 100k per year)
- **WHEN** the verplichting is created with `soort = raamovereenkomst`
- **THEN** FOUR verplichtingsregels SHALL be created: one for 2026, one for 2027, one for 2028, one for 2029, each with `bedrag = EUR 100.000`

#### Scenario: Jaarlijkse afroep consumes independent regel

- **GIVEN** the above raamovereenkomst with independent yearly regels for EUR 100k each
- **WHEN** an invoice for EUR 25.000 dated in 2027-05-15 is matched to the verplichting
- **THEN** the invoice SHALL match against the 2027-regel only, leaving the 2026-regel, 2028-regel, and 2029-regel at EUR 100k each

#### Scenario: Jaarcap enforcement (if exceeds yearly limit)

- **GIVEN** a raamovereenkomst with per-year limit EUR 100.000 and total 4-year limit EUR 400.000
- **WHEN** invoices totalling EUR 110.000 are submitted against the 2026-regel
- **THEN** the system SHALL reject the invoice-match with "exceeds yearly limit for 2026" and advise the user to amend the raamovereenkomst

### REQ-VPL-005: Drie-weg-matching (PO ↔ GR ↔ Invoice)

When an AP invoice is posted, the system SHALL verify that a valid verplichting exists, that receipt has been confirmed (if applicable), and that amount / quantity variance is within tolerance.

#### Scenario: Invoice matched to valid PO and GR

- **GIVEN** verplichting `PO-2026-00874` with regel bedrag EUR 15.000, and a GR-record confirming receipt of the matching goods
- **WHEN** an invoice for EUR 15.000 is posted against this PO
- **THEN** the three-way match SHALL pass, invoice state SHALL move from `draft` to `matched`, and posting SHALL proceed

#### Scenario: Invoice exceeds PO amount triggers review

- **GIVEN** verplichting `PO-2026-00874` with regel bedrag EUR 15.000
- **WHEN** an invoice for EUR 16.500 (10% overage) is posted
- **THEN** the invoice SHALL move to `in_behandeling_afwijking` state, and a manual approval SHALL be required from the budgethouder with justification before posting proceeds

#### Scenario: Invoice without PO above threshold is rejected

- **GIVEN** an invoice for EUR 7.500 with no PO reference
- **WHEN** the invoice is posted
- **THEN** the system SHALL reject posting with "Verplichting ontbreekt; eerst PO opvoeren (above EUR 5.000 threshold)" UNLESS an exemption-soort applies (e.g., energierekening, belastingaanslag)

### REQ-VPL-006: Wijzigingen, verhogingen, en annulering

Verplichtingen SHALL be amendable via mutations. All changes are recorded immutably and trigger re-evaluation of mandate-checks if applicable.

#### Scenario: Verhoging re-triggers mandaat-check

- **GIVEN** a verplichting of EUR 50.000 with `mandaat_toegepast = M-INKOOP-50K` (maximum EUR 50.000)
- **WHEN** the user attempts to increase bedrag to EUR 60.000 via a mutation `soort = verhoogd, bedrag = EUR 10.000`
- **THEN** the system SHALL re-check the mandate, recognize that EUR 60k exceeds the EUR 50k ceiling, and route the amendment to `in_goedkeuring` for approval by the next-higher mandaathouder

#### Scenario: Annulering before delivery releases budget

- **GIVEN** a verplichting of EUR 100.000 in state `aangegaan` (budget blocked)
- **WHEN** the verplichting is cancelled via mutation `soort = geannuleerd`
- **THEN** verplichting status SHALL move to `geannuleerd`, a reversal mutation SHALL be recorded, and `openstaande_verplichtingen` SHALL decrease by EUR 100.000, releasing budget back to `vrije_ruimte`

#### Scenario: Afsluiting with partial realisatie

- **GIVEN** a verplichting with total bedrag EUR 100.000, where only EUR 80.000 has been geleverd and gefactureerd, and 0 restant
- **WHEN** the verplichting is closed via mutation `soort = afgesloten`
- **THEN** the remaining EUR 20.000 SHALL be released back to the budget (openstaande_verplichtingen decreases), verplichting status SHALL be `afgesloten`, and the regel SHALL be marked `afgesloten = true`

### REQ-VPL-007: Arbeidscontracten en personeelsverplichtingen

Employment contracts MUST be registered as verplichtingen with automatic monthly realisatie as salaries are paid.

#### Scenario: Arbeidscontract creates verplichting

- **GIVEN** an employment contract for a new employee with gross salary EUR 4.500 / month for 12 months
- **WHEN** the contract is recorded in the system
- **THEN** a verplichting of `soort = arbeidscontract` SHALL be created with `totaalbedrag = EUR 54.000` (12 × EUR 4.500), and budget SHALL be blocked

#### Scenario: Maandlijkse salarisrun reduces verplichting

- **GIVEN** the above verplichting of EUR 54.000 in state `aangegaan`
- **WHEN** the monthly payroll run executes, paying EUR 4.500 to the employee
- **THEN** a mutatie `soort = betaald, bedrag = EUR 4.500` SHALL be auto-created, `restant_verplicht` SHALL decrease to EUR 49.500, and the remaining balance SHALL be visible in cash-flow prognose

#### Scenario: Onbepaalde-tijd contract with 24-month forward

- **GIVEN** an open-ended employment contract
- **WHEN** recorded in the system
- **THEN** a verplichting SHALL be created with `looptijd_tot = today + 24 months` (forward-looking), and jaarlijks hernieuwing during the budget-cycle SHALL extend the looptijd_tot to maintain 24-month forward visibility

### REQ-VPL-008: Subsidieverplichtingen

Subsidy grants MUST be recorded as verplichtingen at the moment of award (beschikking), not at payment.

#### Scenario: Subsidiebeschikking creates multi-year verplichting

- **GIVEN** a subsidy award for EUR 50.000 covering 1 July 2026 – 30 June 2027
- **WHEN** the beschikking is recorded
- **THEN** a verplichting of `soort = subsidiebeschikking` SHALL be created with two regels: EUR 25.000 for boekjaar 2026 (Jul–Dec) and EUR 25.000 for boekjaar 2027 (Jan–Jun), and budget SHALL be blocked on both years

#### Scenario: Voorschot en eindafrekening tracked separately

- **GIVEN** a subsidy with 80% voorschot and 20% eindafrekening
- **WHEN** the 80% voorschot payment (EUR 40.000) is executed
- **THEN** a mutatie `soort = betaald, bedrag = EUR 40.000` SHALL be recorded, and the 20% restant SHALL remain in `restant_verplicht` pending eindafrekening

#### Scenario: Terugvordering on final settlement

- **GIVEN** a subsidy initially awarded EUR 50.000, later settled at EUR 40.000 in the eindverantwoording
- **WHEN** the vaststellingsbeschikking is recorded with the lower amount
- **THEN** a mutatie `soort = teruggevorderd, bedrag = EUR 10.000` (negative) SHALL be created, `openstaande_verplichtingen` SHALL decrease by EUR 10.000, and EUR 10.000 SHALL be freed back to the original budget-year

### REQ-VPL-009: BBV-rapportage met verplichtingen

The BBV compliance report SHALL include per-program columns for geautoriseerd, gerealiseerd, openstaande_verplichtingen, and vrije_ruimte.

#### Scenario: BBV report includes outstanding commitments

- **GIVEN** a BBV-programma report for Q2 2026 with the following per-program data:
  - programma 5.1: geautoriseerd EUR 1.000k, gerealiseerd EUR 400k, openstaande_verplichtingen EUR 300k
- **WHEN** the report is rendered
- **THEN** programma 5.1 SHALL display: geautoriseerd EUR 1.000k, gerealiseerd EUR 400k, openstaande_verplichtingen EUR 300k, vrije_ruimte EUR 300k

#### Scenario: Budget overcommitment alert

- **GIVEN** programma 5.1 with geautoriseerd EUR 1.000k, gerealiseerd EUR 700k, openstaande_verplichtingen EUR 350k (total EUR 1.050k > budget)
- **WHEN** the report is rendered
- **THEN** programma 5.1 SHALL be marked RED, and an automated alert SHALL be sent to the concerncontroller with the message "programma 5.1 is overcommitted by EUR 50k"

### REQ-VPL-010: Audit-trail en rechtmatigheidstoetsing

Every verplichtingsmutatie is immutable, and verplichtingen MAY be linked to rechtmatigheidstoetsen. Compliance reviews can be conducted at commitment-time, reducing invoice-level audit work.

#### Scenario: Verplichtingen auto-checked for compliance

- **GIVEN** a verplichting of EUR 75.000 for ICT-services, soort = `inkooporder`, bedrag > EUR 25.000 (procurement threshold)
- **WHEN** the verplichting is moved to `aangegaan`
- **THEN** automatic toetsing checks (per `bookkeeping-rechtmatigheidsverantwoording` spec) for `begroting`, `mandaat`, and `europees_aanbesteden` SHALL be applied and linked via `verplichting.rechtmatigheidstoetsen`

#### Scenario: Factuur-toets references PO-toets

- **GIVEN** the above verplichting with toets `europees_aanbesteden = voldoet` already recorded
- **WHEN** later invoices under this verplichting are posted
- **THEN** each invoice's rechtmatigheidstoets SHALL reference the PO-level toets result, and only invoice-specific checks (e.g., bedrag within PO, supplier match) SHALL be re-evaluated, avoiding duplicate checking

#### Scenario: Mutation immutability for audit trail

- **GIVEN** a verplichting with mutation `aangegaan` recorded on 2026-04-15 by user-ID 5
- **WHEN** an auditor queries the mutation history
- **THEN** the auditor SHALL see an immutable record: `{soort: 'aangegaan', bedrag: 50000, datum: '2026-04-15', gebruiker: 'user-5', id: 'mut-001'}` that cannot be altered, only queried

