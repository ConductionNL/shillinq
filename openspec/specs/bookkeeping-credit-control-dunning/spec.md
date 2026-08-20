# Spec: bookkeeping-credit-control-dunning

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../bookkeeping-accounts-receivable-core/spec.md` (AR invoices + klanten),
`../bookkeeping-general-ledger/spec.md` (GL posting), `../bookkeeping-btw-aangifte/spec.md` (BTW-teruggaaf),
`../docudesk/spec.md` (dunning-templates), `../openconnector/spec.md` (API integrations)

## Purpose

This specification defines the requirements for bookkeeping credit control dunning in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude unbuilt UI: dunning/credit control pages not yet implemented


### REQ-CCD-001: DunningLadder configuration SHALL be declared as `DunningLadder` + `KlantLadderOverride` registers

Dunning ladders MUST be expressed as two new registers in `lib/Settings/shillinq_register.json` per ADR-024:

- **`DunningLadder`** — configuration per ondernemer (or per klant-groep): naam, klantGroep,
  stages array with (nr, dagenNaVervalDatum, naam, kanaal enum: EMAIL/EMAIL+POSTREGISTRATIE/
  AANGETEKENDE_POST/INCASSOBUREAU_API, templateId FK to docudesk, optionele wettelijkEffect
  enum: 14_DAGEN_BRIEF_BIK/VERZUIM_INTREDEN), actief boolean.
- **`KlantLadderOverride`** — per-klant exception on base ladder: klantId FK, baseLadderId FK,
  overrides (stage array overriding base), reden (free-text), createdBy, createdAt,
  approvedBy, approvedAt.

This capability establishes the foundational dunning workflow for accounts receivable
collections. Configuring a `DunningLadder` MUST declare stage triggers, kanaal-selection,
template-references, and optionele wettelijke-effect markers (14-dagen-brief for B2C,
verzuim-marker for B2B). Per-klant `KlantLadderOverride` allows Aangepaste escala­tion
(bv. overheid extended terms, VIP skip stage 4–5).

#### Scenario: Reviewer confirms no parallel dunning-table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `dunning_ladder*`, `dunning_policy*`
- **THEN** no such classes SHALL exist (dunning config is in openregister registers).

#### Scenario: Overheid klant gets extended-term ladder via override

- **GIVEN** klant Gemeente Amsterdam with `partyType: GOVERNMENT`
- **WHEN** dunning is configured for this klant
- **THEN** the base `DunningLadder` MUST be overridden with `KlantLadderOverride`:
  stages on days 0/30/60/90 per Wet betalingstermijnen overheid. Override record
  MUST capture reden: "Wet betalingstermijnen overheid — 30-dagensbetaaltermijn".

### REQ-CCD-002: DunningRun register SHALL track per-invoice per-stage execution with evidence-trail

The `DunningRun` register MUST declare a fixed minimum field set:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `factuurId` | string | Yes | FK to APTransaction (or ARInvoice) UUID |
| `ladderId` | string | Yes | FK to DunningLadder UUID |
| `stageNr` | integer | Yes | Stage number (1–5) |
| `uitgevoerdOp` | datetime | Yes | Execution timestamp |
| `kanaal` | enum | Yes | EMAIL / EMAIL+POSTREGISTRATIE / AANGETEKENDE_POST / INCASSOBUREAU_API |
| `ontvangerEmail` | string | No | Recipient e-mail (if EMAIL kanaal) |
| `ontvangerNaam` | string | No | Recipient name |
| `ontvangerAdres` | object | No | Recipient address (if POST kanaal) |
| `templateId` | string | Yes | FK to docudesk template |
| `renderedSubject` | string | No | Rendered e-mail subject (if EMAIL) |
| `renderedBody` | string | No | Rendered e-mail body |
| `renderedPdfHash` | string | No | SHA-256 hash of PDF letter |
| `deliveryStatus` | enum | Yes | DELIVERED / BOUNCED / FAILED / PENDING |
| `openTracking` | object | No | {opened: boolean, openedAt: datetime} (EMAIL only) |
| `postageStatus` | object | No | {barcode, deliveredAt: datetime} (POST only) |
| `digitalSignature` | string | No | Optional digital signature on letter |
| `factuurBedrag` | number | Yes | Amount of invoice at time of dunning-run |
| `incassokostenBedrag` | number | No | Incasso costs at time of run (if calculated) |
| `renteBedrag` | number | No | Rente-accrual at time of run (if calculated) |
| `administrationId` | string | Yes | FK to administration |

Schema.org annotation: `schema:Event`.

#### Scenario: Stage 3 dunning-run captures 14-dagen-brief evidence

- **GIVEN** a B2C invoice €8.400 entering stage 3 on day 30
- **WHEN** the dunning-run executes
- **THEN** the DunningRun MUST capture: factuurId, stageNr=3, kanaal=EMAIL+POSTREGISTRATIE,
  renderedBody containing verbatim 14-dagen-brief text per art. 6:96 BW,
  renderedPdfHash for letter-post evidence, deliveryStatus=DELIVERED after confirmation,
  postageStatus with barcode + deliveredAt.

#### Scenario: Dunning-run is immutable post-execution

- **GIVEN** a DunningRun with deliveryStatus=DELIVERED
- **WHEN** an operator attempts to amend renderedSubject or renderedBody
- **THEN** the update MUST fail with "Executed DunningRun cannot be amended (immutable)".

### REQ-CCD-003: IncassoKostenBerekening SHALL calculate BIK-staffel per Besluit BIK + wettelijke rente per art. 6:119–119a BW

The `IncassoKostenBerekening` register MUST declare a fixed minimum field set:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `factuurId` | string | Yes | FK to invoice UUID |
| `hoofdsom` | number | Yes | Invoice amount at time of calculation |
| `berekening` | object | Yes | {schaal1_0_2500, schaal2_2500_5000, schaal3_5000_10000, schaal4_10000_200000, schaal5_200000plus, totaal, minimum, maximum, toegepast, btwVerrekenbaar, btwPercentage, btwBedrag, toegepastInclBtw} |
| `wettelijkeRente` | object | Yes | {tarief (decimal; 0.1015 B2B / 0.04 B2C per 1-1-2026), type enum: HANDELSRENTE_B2B_6_119A_BW / WETTELIJKE_RENTE_B2C_6_119_BW, ingangsdatum, berekendOp, dagen, bedrag, perioden[] (per-rate sub-periods {van, tot, dagen, tarief, bedrag})} |
| `partyType` | enum | Yes | B2B / B2C (controls rente-rate choice) |
| `totaalVerschuldigd` | number | Yes | hoofdsom + incassokosten (incl. BTW) + rente |
| `administrationId` | string | Yes | FK to administration |

Staffel-berekening (Besluit BIK):
- €0–€2.500: 15% (min €40)
- €2.500–€5.000: 10% on amount above €2.500
- €5.000–€10.000: 5% on amount above €5.000
- €10.000–€200.000: 1% on amount above €10.000
- €200.000+: 0.5% on amount above €200.000

The applied fee MUST honour the statutory **maximum €6.775** (reached at a €1.000.000 hoofdsom):
`toegepast = min(max(totaal, €40), €6.775)`.

Wettelijke rente MUST be resolved from a **maintained, date-keyed rate table** (the rate changes
~biannually; per 1-1-2026 B2C is 4% per art. 6:119 BW and B2B handelsrente is 10,15% per art. 6:119a
BW). An accrual window crossing a statutory rate boundary MUST split into sub-periods (`perioden`)
that each accrue at their own rate; `bedrag` is their sum. An explicit override tarief forces a flat
single period (contractual B2B, art. 6:119a lid 3 BW).

**BTW-over-incassokosten** (art. 2 lid 2 Besluit BIK): when the creditor cannot offset the VAT on the
collection service (`btwVerrekenbaar = false`) and declares this, the fee is increased by
`btwPercentage` (default 21%); `btwBedrag` and `toegepastInclBtw` MUST be recorded and
`totaalVerschuldigd` MUST count `toegepastInclBtw`.

#### Scenario: Statutory maximum €6.775 caps incassokosten on large claims

- **GIVEN** an outstanding hoofdsom of €2.000.000
- **WHEN** IncassoKostenBerekening is evaluated
- **THEN** `berekening.totaal` MUST be €11.775 but `berekening.toegepast` MUST be €6.775; a
  €1.000.000 hoofdsom MUST likewise yield `toegepast` €6.775 (the cap is reached exactly).

#### Scenario: B2C rente splits across a statutory rate boundary

- **GIVEN** invoice €10.000, partyType=B2C, rente accruing 2025-12-17 → 2026-01-16 (6% → 4% at 1-1-2026)
- **WHEN** wettelijkeRente is calculated with no override
- **THEN** `perioden` MUST contain 15 days @ 6% (€24.66) + 15 days @ 4% (€16.44) and `bedrag` MUST be
  €41.10; `tarief` MUST be 0.04.

#### Scenario: BTW-over-incassokosten added when creditor cannot offset VAT

- **GIVEN** invoice €8.400 with staffel `toegepast` €795 and a creditor who cannot offset VAT
- **WHEN** IncassoKostenBerekening is evaluated with `btwVerrekenbaar=false`
- **THEN** `btwBedrag` MUST be €166.95 and `toegepastInclBtw` €961.95; with the default
  `btwVerrekenbaar=true` `btwBedrag` MUST be €0.

#### Scenario: B2B incassokostenstaffel calculated correctly on €8.400

- **GIVEN** invoice €8.400, partyType=B2B, stage 3 entered on day 30
- **WHEN** IncassoKostenBerekening is evaluated
- **THEN** staffel-berekening MUST yield: 15% × €2.500 (€375) + 10% × €2.500 (€250)
  + 5% × €3.400 (€170) = €795 total.

#### Scenario: B2B handelsrente calculated per art. 6:119a BW (10.15% per 1-1-2026)

- **GIVEN** invoice €8.400 in verzuim, calculated over 22 days in 2026
- **WHEN** wettelijkeRente is calculated for partyType=B2B with no override
- **THEN** rente MUST be (€8.400 × 0.1015 × 22/365) = €51.39; tarief MUST be 0.1015;
  type MUST be HANDELSRENTE_B2B_6_119A_BW.

#### Scenario: B2C rente calculated per art. 6:119 BW (4% per 1-1-2026)

- **GIVEN** invoice €820, partyType=B2C, calculated over 31 days in 2026
- **WHEN** wettelijkeRente is calculated with no override
- **THEN** rente MUST be (€820 × 0.04 × 31/365) = €2.79; tarief MUST be 0.04;
  type MUST be WETTELIJKE_RENTE_B2C_6_119_BW.

#### Scenario: B2C incassokostenstaffel NOT calculated before day 44

- **GIVEN** invoice €820, partyType=B2C, stage 3 on day 30
- **WHEN** IncassoKostenBerekening is triggered at day 35 (within 14-day period)
- **THEN** the calculation MUST be BLOCKED with error "B2C incassokostenberekening niet
  toegestaan vóór dag 44 (30 + 14-dagenperiode)" per art. 6:96 BW.

#### Scenario: Partial payment recalculates staffel on remaining saldo

- **GIVEN** invoice €8.400 with IncassoKostenBerekening (€795 incasso-kosten),
  partial payment €3.000 received on day 50
- **WHEN** partial-payment is booked and IncassoKostenBerekening is recalculated
- **THEN** new calculation MUST be for remaining saldo €5.400: 15% × €2.500 (€375)
  + 10% × €2.900 (€290) = €665 (lower than original €795).

### REQ-CCD-004: DunningPauseDispute register SHALL pause dunning with audit-trail when invoice is disputed

The `DunningPauseDispute` register MUST declare a fixed minimum field set:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `factuurId` | string | Yes | FK to invoice UUID |
| `pauzeStart` | datetime | Yes | When pause began |
| `pauzeEind` | datetime | No | When pause ended (null = still paused) |
| `reden` | enum | Yes | DISPUTED / PAYMENT_PLAN / OTHER |
| `details` | string | Yes | Free-text details (e.g., "Klant betwist 4 uur week-22") |
| `gepauzeerdDoor` | string | Yes | User/actor ID |
| `evidenceRefs` | array of string | No | FK array to docudesk or openregister evidence records |
| `hardDeadlineEindigt` | datetime | No | Auto-resume date (60 days after pauzeStart if not resolved earlier) |
| `administrationId` | string | Yes | FK to administration |

When a DunningPauseDispute is created for a disputed invoice:
1. DunningRun for that invoice MUST halt (no further stage-actions).
2. Rente + incassokostenstaffel accrual MUST pause (no additional charges during dispute).
3. Ladder resumes upon: operator marking "Dispute resolved" OR hardDeadlineEindigt expiry.

#### Scenario: Dispute pauses dunning, stops rente accrual

- **GIVEN** invoice €8.400 in stage 3 (day 30), DunningPauseDispute created with
  reden=DISPUTED, details="Klant betwist 4 uur"
- **WHEN** the pause is active
- **THEN** no further DunningRun stage-actions SHALL execute; no IncassoKostenBerekening
  rente-accrual SHALL occur; hardDeadlineEindigt SHALL be set to (today + 60 days).

#### Scenario: Dispute resolved with partial settlement, ladder resumes on remaining saldo

- **GIVEN** the dispute from above, partial settlement agreed: €4.800 (original €8.400
  reduced by €3.600 dispute credit)
- **WHEN** operator marks `pauzeEind=<today>`, updates invoice saldo to €4.800,
  documents reden="Partial settlement agreed"
- **THEN** DunningRun for that invoice MUST resume at stage 3 (not re-execute stage 1–2);
  IncassoKostenBerekening MUST recalculate on €4.800; rente SHALL resume accrual from
  pauzeEind forward.

### REQ-CCD-005: APTransaction lifecycle SHALL transition overdue → dunning-driven stages via scheduled-workflow

`APTransaction` (from `bookkeeping-accounts-receivable-core`) MUST declare lifecycle transitions:

| From | To | Trigger | Guard |
|---|---|---|---|
| `issued` | `overdue` | scheduled workflow @ today > dueDate | none |
| `overdue` | `dunning_stage_1` | scheduled workflow @ day 0 post-overdue | stage 1 of ladder applies |
| `dunning_stage_N` | `dunning_stage_{N+1}` | scheduled workflow @ day threshold | stage N+1 of ladder applies |
| `overdue` / `dunning_*` | `partially_paid` | bank-reconciliation match (partial) | payment amount < totalAmount |
| `dunning_*` | `paid` | bank-reconciliation match (full) | cumulative payment amount = totalAmount |
| `dunning_*` | `disputed` | operator action | none; pauses ladder |
| `disputed` | `dunning_*` | operator resolves dispute | resumes from stage where paused |
| `dunning_*` | `written_off` | operator action (role: controller) | creates compensating GL posting |
| `dunning_*` | `in_incasso` | operator triggers stage 5 overdraft-action | POST to incasso-bureau API succeeds |

The `issued → overdue` transition MUST fire via OR's `ScheduledWorkflow` primitive,
not via app-local `*Job` PHP class (per ADR-031).

#### Scenario: Invoice automatically transitions to overdue

- **GIVEN** invoice with dueDate=2026-05-20, today=2026-05-21
- **WHEN** the OR scheduled-workflow ticks
- **THEN** APTransaction state MUST transition from `issued` to `overdue` automatically.

#### Scenario: Stage 1 dunning-run fires on day 0 post-overdue

- **GIVEN** invoice transitioned to `overdue` on 2026-05-21
- **WHEN** the OR scheduled-workflow ticks on the same day
- **THEN** DunningRun MUST execute: kanaal=EMAIL, template=stage-1 (vriendelijke reminder),
  deliveryStatus=DELIVERED (or BOUNCED). APTransaction state remains `overdue` (no
  immediate state change to `dunning_stage_1`).

### REQ-CCD-006: Stage 3 B2C dunning SHALL include mandatory 14-dagen-brief per art. 6:96 BW

For B2C invoices, stage 3 (aanmaning) MUST include wettelijke 14-dagen-brief text:

> "U krijgt 14 dagen om de factuur alsnog te voldoen zonder dat incassokosten of rente verschuldigd worden. Na deze termijn kunnen incassokosten en wettelijke rente verschuldigd zijn."

(Exact wording per RJ Guidance + Wet Incassokosten art. 6:96 lid 6 BW.)

#### Scenario: B2C stage 3 dunning-run contains 14-dagen-brief

- **GIVEN** invoice partyType=B2C entering stage 3 on day 30
- **WHEN** DunningRun executes
- **THEN** renderedBody MUST contain the mandatory 14-dagen-brief text verbatim;
  DunningRun MUST capture kanaal=EMAIL+POSTREGISTRATIE for proof-of-receipt.

#### Scenario: B2C incassokostenstaffel blocked before 14-day termijn expires

- **GIVEN** invoice partyType=B2C, stage 3 on day 30, 14-day termijn expires day 44
- **WHEN** IncassoKostenBerekening is queried on day 40 (within 14-day period)
- **THEN** the calculation MUST be BLOCKED; error message: "B2C incassokostenstaffel
  niet toegestaan vóór dag 44 (einde 14-dagenperiode)".

### REQ-CCD-007: Optionele credit-score-integratie SHALL fetch customer score from Graydon/Creditsafe/Atradius Insights

The `CreditScore` register MUST declare a fixed minimum field set (if enabled):

| Field | Type | Required | Purpose |
|---|---|---|---|
| `klantId` | string | Yes | FK to customer UUID |
| `provider` | enum | Yes | GRAYDON / CREDITSAFE / ATRADIUS_INSIGHTS |
| `scoreDatum` | date | Yes | Date score was fetched |
| `score` | number | Yes | Numeric score (provider-specific scale) |
| `scoreSchaal` | string | Yes | Scale description (e.g., "1-10", "0-100") |
| `betalingsRisicoIndicatie` | enum | No | LAAG / MIDDEN / HOOG |
| `creditLimietAdvies` | number | No | Recommended credit limit |
| `kostenLookup` | number | No | Cost of this lookup in EUR |
| `administrationId` | string | Yes | FK to administration |

Upon invoice-creation (if credit-score integration is enabled), the app:
1. Queries CreditScore for the customer (use cached snapshot if < 30 days old).
2. If score < threshold (e.g., 3.0 on 1–10 scale), display UI warning:
   "Klant heeft lage creditscore. Overweeg vooruitbetaling of factorageservice".
3. Optionally store the score snapshot for audit trail.

Periodic updates (e.g., monthly) refresh scores; dunning-run does NOT re-fetch.

#### Scenario: UI warns on low credit score at invoice-creation

- **GIVEN** invoice for klant with CreditScore (provider=GRAYDON, score=2.4 on 1–10 scale)
- **WHEN** operator creates a new invoice € 15.000
- **THEN** UI MUST display warning: "Klant ACME BV heeft lage creditscore (2.4). Overweeg
  vooruitbetaling of deelfacturatie" + "Recommended credit limit: €10.000".

### REQ-CCD-008: Optionele overdraft-actie stage 5 SHALL POST dossier-bundel to incassobureau-API

The system SHALL satisfy this requirement: Optionele overdraft-actie stage 5 SHALL POST dossier-bundel to incassobureau-API.

At stage 5 (day 90, overdraft incasso), operator can trigger:

```
POST /api/incasso-bureau/dossier
{
  "factuurId": "<UUID>",
  "inhoud": {
    "invoice": { ...full invoice data... },
    "dunningRuns": [ ...all stage 1–4 DunningRun records... ],
    "incassoKosten": { ...IncassoKostenBerekening... },
    "klantGegevens": { name, contact, address, ... },
    "evidenceRefs": [ ...docudesk/openregister URIs... ]
  }
}
```

Upon API success (HTTP 200–299), APTransaction state MUST transition to `in_incasso`;
DunningRun for that invoice MUST be locked (immutable). Further dunning-actions on
that invoice are blocked.

API-error (network, 4xx/5xx) MUST queue for retry; operator notified.

#### Scenario: Overdraft-API POST succeeds, invoice locked

- **GIVEN** invoice in stage 5 (day 90), overdraft-action triggered
- **WHEN** the API-POST to incasso-bureau succeeds with HTTP 200
- **THEN** APTransaction state MUST become `in_incasso`; subsequent DunningRun
  updates for that invoice MUST FAIL with "Invoice in_incasso; dunning-actions locked".

### REQ-CCD-009: Optionele PostNL aangetekende-post SHALL send stage 4 ingebrekestelling via PostNL Track & Trace API

The system SHALL satisfy this requirement: Optionele PostNL aangetekende-post SHALL send stage 4 ingebrekestelling via PostNL Track & Trace API.

If PostNL integration is enabled, stage 4 (ingebrekestelling) can be configured
to dispatch via aangetekende post:

1. System renders letter from docudesk template.
2. POST to PostNL API: `POST /api/postnl/send { letter, recipient_address, reference }`.
3. PostNL returns barcode + Track & Trace URL.
4. DunningRun captures postageStatus: { barcode, trackingUrl, deliveredAt (when confirmed) }.
5. Evidence (postage receipt, delivery-confirmation) archived in openregister.

#### Scenario: Stage 4 letter sent via PostNL, tracking confirmed

- **GIVEN** invoice entering stage 4 (day 60), PostNL enabled
- **WHEN** DunningRun executes with kanaal=AANGETEKENDE_POST
- **THEN** letter MUST be POST'ed to PostNL API; DunningRun MUST capture barcode
  + trackingUrl; deliveryStatus MUST poll PostNL for update; upon confirmation,
  postageStatus.deliveredAt MUST be set; evidence archived.

### REQ-CCD-010: Oninbare-afschrijving register SHALL record write-off with BTW-teruggaaf per art. 29 OB

The `OninbaarAfschrijving` register MUST declare a fixed minimum field set:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `factuurId` | string | Yes | FK to invoice UUID |
| `hoofdsomAfgeschreven` | number | Yes | Amount written off (excl. VAT) |
| `btwBedrag` | number | No | VAT amount for recovery per art. 29 OB |
| `art29OBVerklaring` | string | Yes | Free-text reason (e.g., "Faillissement", "Schuldsanering", "1 jaar onbetaald") |
| `evidenceRef` | string | No | FK to docudesk (faillissementsvonnis, schuldsaneringsverzoek) |
| `boekingId` | string | No | FK to GL posting (journal entry for write-off) |
| `btwAangiftePeriode` | string | No | VAT-filing period (e.g., "2026-Q2") for art. 29 OB correction |
| `administrationId` | string | Yes | FK to administration |

Upon operator action "Afschrijven oninbaar":
1. Create compensating GL posting: debit AP/AR payable, credit bad-debt recovery account.
2. Create OninbaarAfschrijving record with art29OBVerklaring.
3. Flag invoice as `written_off`.
4. Queue BTW-teruggaaf preparation for eerstvolgende BTW-aangifte (per `bookkeeping-btw-aangifte`).

#### Scenario: Write-off creates GL posting + BTW-teruggaaf prep

- **GIVEN** invoice €4.200 (incl. €882 VAT) declared uncollectible, linked to faillissement
  vonnis 2026-04-12
- **WHEN** operator triggers "Afschrijven oninbaar"
- **THEN** GL posting MUST be created (debit bad-debt account €4.200, credit AP/AR €4.200);
  OninbaarAfschrijving record MUST be saved with art29OBVerklaring="Faillissement vonnis
  2026-04-12"; btwAangiftePeriode MUST be set to next VAT-filing quarter; invoice state
  MUST become `written_off`.

### REQ-CCD-011: Anti-pattern-detector SHALL halt escalation if admin-error detected

The system SHALL satisfy this requirement: Anti-pattern-detector SHALL halt escalation if admin-error detected.

If a customer has paid 1+ invoices successfully in the prior 90 days AND a dunning
trigger arises from a single admin-error scenario (wrong IBAN, missing payment-reference),
the system MUST:

1. Flag invoice as "Potential admin error detected".
2. Send proactive customer contact: "Beste [klantNaam], we hebben mogelijk een
   administratieve fout gedetecteerd bij factuur [factuurNummer]. Kunt u alstublieft
   uw betalingsgegevens bevestigen?"
3. Pause dunning (mark as disputed, soft-pause) for 7 days.
4. Resume only after: customer confirms payment details OR 7-day timeout elapses.

#### Scenario: Admin-error detector halts escalation on good customer

- **GIVEN** customer ACME BV paid 5 invoices successfully in prior 90 days; new invoice
  has wrong IBAN entered (detected via address-parsing error during stage 1 e-mail send)
- **WHEN** dunning is triggered
- **THEN** DunningRun MUST FAIL at stage 1 with "Admin error detected: wrong IBAN.
  Sending proactive customer contact..."; DunningPauseDispute MUST be created with
  reden=OTHER, details="Possible admin error (wrong IBAN)"; pause MUST last 7 days
  unless customer confirms.

---

## Standards & Sources

- Burgerlijk Wetboek (BW) art. 6:96 lid 6 (14-dagen-brief), art. 6:119 (wettelijke rente B2C),
  art. 6:119a (handelsrente B2B), art. 6:83 (verzuim van rechtswege)
- Wet Incassokosten (WIK), 1 juli 2012
- Besluit vergoeding voor buitengerechtelijke incassokosten (Stb. 2012, 141) — BIK-staffel
- Wet bestrijding betalingsachterstand (Stb. 2012, 647) — handelsrente B2B
- Wet betalingstermijnen overheid (Stb. 2017, 226) — 30-dagensbetaaltermijn
- Wet op de omzetbelasting 1968, art. 29 lid 1 (BTW-teruggaaf oninbaar)
- Aanbestedingswet 2012, ARIV 2018, ARVODI 2018 (Rijksoverheid inkoopvoorwaarden)
- Wettelijke handelsrente B2B (art. 6:119a BW, ECB Main Refinancing Rate + 8pp): 10.15% per 1-1-2026 (Wieringa Advocaten, wettelijke-rente.com)
- Wettelijke rente B2C (art. 6:119 BW, AMvB 10-12-2025): 4% per 1-1-2026
- Besluit BIK maximum incassokosten: €6.775 (reached at €1.000.000 vordering); art. 2 lid 2 BTW-verhoging
- Atradius Payment Practices Barometer 2024
- RJ Guidance (Richtlijnen voor de Jaarrekening) — dunning-brief formatting
