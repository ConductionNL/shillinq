# Design — Credit Control & Dunning Ladder

## Context

Dutch SMBs experience chronic payment delays: Atradius reports 39% of B2B invoices
are paid late, 7% non-payment. Manual dunning (reminder letters, escalation calls,
collections) is labour-intensive and emotionally fraught. Legal incasso costs
(Besluit BIK staffel: 15%–0.5%, minimum €40; handelsrente B2B 11.5% per art. 6:119a BW;
wettelijke rente B2C 7% per art. 6:119 BW) are complex and rarely correctly applied.

The dunning ladder automates a multi-stage, configurable escalation workflow:
from friendly reminder (day 0) → herinnering (day 14) → aanmaning + 14-dagen-brief
(day 30, mandatory for B2C) → ingebrekestelling (day 60, formal notice) → overdracht
incasso (day 90). Per customer, the ladder is pliable: overheid get extended terms
(day 0/30/60/90 per Wet betalingstermijnen overheid); trusted customers skip stages
4–5; disputed invoices pause the ladder with audit trail.

Per ADR-031, the entire measurement model is declarative: schema metadata +
aggregation queries that emit dunning-run records, BIK-staffel calculations,
wettelijke-rente accruals. Per ADR-022, templates are stored in docudesk,
not app-local; evidence (e-mail headers, PDF renders, digital signatures) is
archived in openregister + openregister for 7-year retention.

The change is **spec-only**. Implementation lands later through `opsx-apply`
and the standard Hydra pipeline.

## Goals

- Express the entire Dutch dunning + incasso accounting surface as **declarative metadata**—
  schemas + lifecycle + aggregation formulas — per ADR-031.
- Make the spec a **jurist-readable contract** — Dutch wettelijke incassa-vereisten
  (Wet IK art. 6:96, BW art. 6:119–119a, Besluit BIK, Wet betalingstermijnen overheid)
  recognisable end-to-end (ladder config, per-klant override, stage triggers, 14-dagen-brief,
  BIK-staffel, rente-accrual, dispute-pauzering, overdracht incasso).
- Enforce correct BIK-staffel calculation, wettelijke-rente per B2B/B2C, 14-dagen-brief
  voor B2C, partial-payment tracking, evidence-trail per art. 6:96 vereisten zonder
  PHP dunning-calculation service logic.
- Support relatie-bewuste escalatie: per-klant ladder-aanpassingen, toon-gradient (vriendelijk
  → juridisch), dispute-pauzering, partial-settlement correcties.
- Maintain complete evidence-trail (e-mail headers, PDF hashes, digital signatures) for
  judicial/incasso-procedure proof-of-contact vereisten.

## Non-Goals

- No PHP dunning-calculation service beyond the ADR-031 exception guard.
- No real-time credit-rating-change alerts (credit-score snapshot only, periodic updates).
- No multi-currency dunning (EUR-only; T5 adds multi-currency).
- No governance workflows (regeling-wijziging approval, PEC-style decision logs) — owned
  by decidesk integration (T4).
- No mortality/longevity improvement modelling — inputs only.

## Decisions

### D1 — Seven registers: ladder header + per-klant override + execution log + incasso-cost + dispute-pause + optional credit + optional write-off

Dunning accounting is decomposed into:
- **DunningLadder**: configuration per ondernemer (or per klant-groep): stages, timing,
  kanaal, templates, toon-gradient, escalatie-acties.
- **KlantLadderOverride**: per-klant exception op standaard-ladder (overheid extended terms,
  VIP klanten no stage 4/5) met motivatie + audit trail.
- **DunningRun**: per-invoice per-stage execution record (kanaal, verstuurde inhoud,
  delivery-status, open-tracking, ontvangers, evidence-refs).
- **IncassoKostenBerekening**: per-invoice BIK-staffel calculation (per schaal) +
  wettelijke-rente-accrual (B2B vs B2C) + totaal-verschuldigd.
- **DunningPauseDispute**: dispute-pause management (start, eind, reden, gepauzeerdDoor,
  evidenceRefs) with hard-deadline optie (60 dagen).
- **CreditScore** (optional): external credit-scoring snapshot (Graydon, Creditsafe,
  Atradius Insights) per klant, updated periodically.
- **OninbaarAfschrijving** (optional): definitive write-off record (hoofdsom, BTW-teruggaaf,
  art. 29 OB-verklaring, faillissement-evidence) for journal + tax-filing.

**Alternative considered**: Monolithic `DunningRun` register with all fields embedded.
Rejected — multi-stage execution + per-stage kanaal-choice + BIK-staffel-recalc on
partial-payment + dispute-pause logic require first-class records for audit trail,
dispute-resolution, and aggregation queries.

### D2 — 5-stage ladder with toon-gradient (vriendelijk → juridisch)

All ladders follow a default 5-stage template:
1. **Stage 1 (day 0)**: Vriendelijke reminder — e-mail "Wellicht heb je onze factuur
   over het hoofd gezien..."
2. **Stage 2 (day 14)**: Herinnering — e-mail "Dit is een vriendelijke reminder..."
3. **Stage 3 (day 30)**: Aanmaning + 14-dagen-brief — e-mail+post with formal notice
   + mandatory 14-dagen-termijn (B2C) per art. 6:96 BW.
4. **Stage 4 (day 60)**: Ingebrekestelling — aangetekende post (PostNL Track & Trace)
   with legal notice of collection-agency referral.
5. **Stage 5 (day 90)**: Overdracht incasso — API POST dossier-bundel to linked
   incasso-bureau (Bos Incasso, Atradius Collections, Intrum).

Per-klant, stages can be reordered, skipped, or replaced. Per-ondernemer, stages can
be reconfigured (e.g. overheid: stages on days 0/30/60/90; aggressive debtors: 0/7/21/45/75).

Template library (docudesk) supplies stage 1–5 templates per toon + default merge-fields
(klantNaam, factuurNummer, factuurDatum, openstaandBedrag, vervaldatum, IBAN, betaaltermijn,
incassokosten, rente).

**Alternative considered**: Auto-detect tone from risk-profile (new customer → aggressive,
established → gentle). Rejected — tone is relationship-specific; let operator choose
and audit-trail the choice.

### D3 — 14-dagen-brief mandatory for B2C at stage 3 per art. 6:96 BW

For B2C invoices, stage 3 MUST include wettelijke 14-dagen-brief text:
"U krijgt 14 dagen om de factuur alsnog te voldoen. Na deze termijn kunnen incassokosten
verschuldigd zijn" (exact wording per RJ Guidance).

Incassokosten MAY NOT be calculated until day 44 (day 30 + 14-day period expiry).

For B2B, incassokosten are calculated immediately at day 30 (no 14-day period required
per art. 6:83 BW — verzuim van rechtswege).

Enforcement: schema field `partyType` enum (B2B/B2C) on `Invoice` entity; DunningRun
lifecycle guard blocks B2C stage 3 without 14-dagen-brief text; IncassoKostenBerekening
query blocks B2C incasso-cost calculation before day 44.

**Alternative considered**: Single unified dunning flow. Rejected — B2C / B2B legal
distinction is mandatory per Wet IK and Burgerlijk Wetboek; conflating them risks
legal liability.

### D4 — BIK-staffel calculation per Besluit BIK (15% / 10% / 5% / 1% / 0.5%)

Incassokostenstaffel (per hauptsomme):
- €0–€2.500: 15% (min €40)
- €2.500–€5.000: 10% on the slice above €2.500
- €5.000–€10.000: 5% on the slice above €5.000
- €10.000–€200.000: 1% on the slice above €10.000
- €200.000+: 0.5% on the slice above €200.000

Example: €8.400 = 15% × €2.500 (€375) + 10% × €2.500 (€250) + 5% × €3.400 (€170) = €795.

Calculation deferred to day 30 for B2C (after 14-day period) and day 30 for B2B.
Upon partial payment, staffel is recalculated on remaining saldo.

**Alternative considered**: Fixed €50 per invoice. Rejected — Besluit BIK mandates
the graduated scale; violation creates legal jeopardy (not recoverable in court).

### D5 — Wettelijke rente B2B vs B2C

**B2B** (art. 6:119a BW): Handelsrente = ECB Main Refinancing Rate + 8 percentage points.
As of 1-1-2026, ECB rate = 3.5%, so handelsrente = 11.5% per annum.

**B2C** (art. 6:119 BW): Gewone wettelijke rente per half-year DNB publication.
As of 1-1-2026, rente = 7% per annum.

Calculation: `(Bedrag × Tarief × DagenVerzuim) / 365`.
Applied from day 30 (B2B) or day 44 (B2C) onward, compounded per IncassoKostenBerekening
record.

ECB rate cached at app startup; fallback to hard-coded defaults per 1-1-2026 with
UI warning "rates may be outdated". T3 adds periodic ECB-rate refresh.

**Alternative considered**: Single rate for all parties. Rejected — legal distinction
per art. 6:119 vs 6:119a is non-negotiable; different rates reflect policy intent.

### D6 — Per-klant ladder-override with audit-trail

KlantLadderOverride allows admin to customize ladder per klant without modifying the
base ladder:

- **Overheid**: stages on days 0/30/60/90 (extended 30-day term per Wet betalingstermijnen
  overheid) with rationale "Aanbestedingswet — overheid has 30-day statutory term".
- **VIP/trusted customers**: stages 1–3 only; stage 4/5 escalation requires manual
  approval with role gate (controller/director).
- **High-risk customers**: aggressive ladder on days 0/7/21/45/75 with manual approval.

Override record captures: baseLadderId, klantId, overrides (stage list), reden (free-text),
createdBy (user), createdAt, approvedBy (if required), approvedAt.

Audit trail on all amendments per field-level tracking in openregister.

**Alternative considered**: Centralized policy engine (roles, customer-segment → ladder).
Rejected — per-klant override is the simpler mental model; auditor can review each
exception case-by-case.

### D7 — Dispute-pause with hard-deadline (60 days) or manual resolution

When an invoice is flagged "Disputed: klant betwist 4 uur", dunning pauses
(no stage-actions, no rente-accrual) with DunningPauseDispute record.

Pause ends when:
1. Operator marks "Dispute resolved" with amended invoice (bv. partial settlement),
   OR
2. Hard-deadline of 60 days elapses (auto-resume).

Rente + incassokosten are NOT accrued during pause period.

Upon resume, ladder continues from the stage where pause occurred (no stage-re-execution).

Audit trail on pause event, resolution method, amended amounts.

**Alternative considered**: Manual escalation to account-manager queue. Rejected —
hard-deadline ensures disputes don't linger indefinitely; manual override is available
if needed.

### D8 — Partial-payment flow: saldo-adjustment + ladder-resume

When payment received for partial amount (bv. €3.000 of €8.400 invoice):
1. Bank reconciliation matches payment.
2. APTransaction state: `issued` → `partially-paid`.
3. Invoice saldo adjusted: €8.400 → €5.400.
4. IncassoKostenBerekening recalculated on €5.400 (new staffel: 15% × €2.500 + 10% × €2.900).
5. Ladder resumes for remaining €5.400 (stage continues from where it was; no backtrack).

Rente accrual on original amount (€8.400) up to payment-date, then on remaining saldo
(€5.400) from payment-date onward.

Audit trail on all partial-payment amendments.

**Alternative considered**: Pause ladder until full payment. Rejected — partial payment
indicates customer engagement; ladder should continue on remainder with interest.

### D9 — Evidence-trail: immutable record per DunningRun with cryptographic proof

Each DunningRun record captures:
- Rendered template (subject, body text).
- Kanaal (EMAIL, POST registered, AANGETEKENDE_POST, API).
- Verstuurde-op (timestamp).
- Delivery-status (delivered, bounced, failed).
- Open-tracking (email open, postage confirmation).
- PDF-render with SHA-256 hash (for letter-post proof).
- Digital signature (if signed).
- Recipient name + e-mail/address.

Evidence references archived in openregister or openconnector for 7-year retention
(per art. 6:96 vereiste voor gerechtelijke procedure).

Immutability enforced via status-lock post-delivery: DunningRun → `executed` state
cannot be amended.

**Alternative considered**: Transient logging (log-file only, no evidence register).
Rejected — court proceedings require forensic-grade proof; log files are not judicial
evidence; register-based archival with hashes is defensible.

### D10 — Anti-pattern-detector: detect unintended escalation (admin error)

If a customer has paid 1+ invoices in prior 90 days AND a dunning trigger arises
from a single admin-error scenario (wrong IBAN, missing payment-reference), the
system detects this and HALTS escalation:
1. Flag invoice as "Potential admin error detected".
2. Send proactive customer contact message: "We notice a potential issue with invoice
   XXX. Please confirm correct payment details..."
3. Resume ladder only after customer confirmation or 7-day timeout.

This prevents damaging good-customer relationships due to operator mistakes.

Audit trail on error-flag, customer response.

**Alternative considered**: Auto-correct (recalculate IBAN, retry payment). Rejected —
IBAN/payment-ref corrections require customer consent; better to ask than assume.

### D11 — Overdraft-specific terms per Wet betalingstermijnen overheid

Gemeente/provincie/Rijk/waterschap (identified via `partyType: GOVERNMENT` + lookup
against ARIV/ARVODI standard inkoopvoorwaarden) automatically get ladder adapted:
- Stage 1: day 0 (reminder on due-date).
- Stage 2: day 30 (30-day term per Wet betalingstermijnen overheid).
- Stage 3: day 60 (aanmaning after 30-day extension).
- Stage 4: day 90 (escalatie naar account-manager, not legal).

Rationale captured in override: "Wet betalingstermijnen overheid — 30-day statutory term".

**Alternative considered**: Admin manually sets ladder per overheid type (gemeente vs
provincie). Rejected — standardization per Wet reduces error; manual per-customer is
too fragile.

### D12 — Betalingsregeling-onderhandeling (payment-plan) at stage 4

At stage 4 (ingebrekestelling), operator can offer betalingsregeling:
- Customer proposes: "€8.400 in 3 × €2.800 monthly".
- Operator captures: terms, payment-schedule, condition "fail on any missed payment".
- Ladder pauses under betalingsregeling status.
- Automatic check each due-payment-date: if payment received on-time, OK; if missed,
  auto-escalate to stage 5 (overdracht incasso).

Audit trail on all betalingsregeling amendments, payment-schedule tracking, escalation
trigger.

**Alternative considered**: Manual collection queue. Rejected — automatic escalation
on missed payments is legally safer (proof of contract terms + auto-breach).

### D13 — Optional credit-score integration (Graydon, Creditsafe, Atradius Insights)

When configured, the app fetches customer credit-score at invoice-creation time:
- If score < threshold (e.g., 3.0 on 1–10 scale), trigger UI warning:
  "Klant has low credit score. Consider vooruitbetaling or factor-service".
- Suggest deelfacturatie (smaller invoices spread over time) to reduce risk.
- Store CreditScore snapshot (provider, score, scoreDate, betalingsRisicoIndicatie,
  creditLimietAdvies) for audit trail.

Periodic updates (e.g., monthly) refresh score; dunning does not re-fetch unless
manual refresh requested.

**Alternative considered**: Real-time credit-check on each dunning stage. Rejected —
too expensive; snapshot + periodic refresh is cost-effective.

### D14 — Optional overdraft to external incasso bureau via API

At stage 5 (day 90), operator can trigger "Overdracht incasso":
1. System bundles dossier: invoice(s), all DunningRun records, evidence, customer
   contact details.
2. POST to configured incasso-bureau API (Bos Incasso, Atradius Collections, Intrum)
   via openconnector.
3. On API success, DunningRun state → `overgedragen`, Invoice → `in_incasso`.
4. No further dunning-ladder actions on invoice (locked).

API-error handling: if POST fails (network, API down), queue for retry; operator
notified.

Audit trail on all overdracht-attempts, API responses, success/failure.

**Alternative considered**: Manual dossier-assembly (download PDF, e-mail to incasso).
Rejected — API integration reduces error and ensures consistent data-format.

### D15 — Optional PostNL aangetekende-post API for stage 4

Stage 4 (ingebrekestelling) can be configured to send via PostNL Track & Trace
(aangetekende post). If configured:
1. System renders letter from docudesk template.
2. POST to PostNL API with customer address + letter content.
3. PostNL returns Track & Trace barcode.
4. DunningRun captures barcode + delivery-status polling.
5. Upon delivery confirmation, evidence archived (postage receipt + proof-of-delivery).

PostNL bulk-handling: for 100+ stage-4 letters per day, batch-queue to PostNL with
consolidation.

API-error: manual fallback to e-mail stage 4 or operator-dispatch.

Audit trail on all PostNL API calls, tracking-status updates, delivery-confirmations.

**Alternative considered**: E-mail stage 4 only. Rejected — for formal ingebrekestelling,
postal proof-of-delivery is legally stronger than e-mail open-tracking.

## Seed Data

Example DunningLadder (Default ZZP):
```json
{
  "id": "ladder-zzp-default-2026",
  "ondernemingId": "ond-zzp-001",
  "naam": "Standaard dunning-ladder ZZP",
  "klantGroep": "DEFAULT",
  "stages": [
    {"nr": 1, "dagenNaVervalDatum": 0, "naam": "Vriendelijke reminder", "kanaal": "EMAIL", "templateId": "tpl-stage1-nl"},
    {"nr": 2, "dagenNaVervalDatum": 14, "naam": "Herinnering", "kanaal": "EMAIL", "templateId": "tpl-stage2-nl"},
    {"nr": 3, "dagenNaVervalDatum": 30, "naam": "Aanmaning + 14d-brief", "kanaal": "EMAIL+POSTREGISTRATIE", "templateId": "tpl-stage3-nl"},
    {"nr": 4, "dagenNaVervalDatum": 60, "naam": "Ingebrekestelling", "kanaal": "AANGETEKENDE_POST", "templateId": "tpl-stage4-nl"},
    {"nr": 5, "dagenNaVervalDatum": 90, "naam": "Overdracht incasso", "kanaal": "INCASSOBUREAU_API", "actie": "TRANSFER_INCASSO"}
  ],
  "actief": true
}
```

Example KlantLadderOverride (Overheid):
```json
{
  "id": "override-gemeente-amsterdam",
  "klantId": "klant-gemeente-amsterdam",
  "baseLadderId": "ladder-zzp-default-2026",
  "overrides": {
    "stages": [
      {"nr": 1, "dagenNaVervalDatum": 0, "naam": "Reminder", "kanaal": "EMAIL"},
      {"nr": 2, "dagenNaVervalDatum": 30, "naam": "Herinnering (overheid 30d)", "kanaal": "EMAIL"},
      {"nr": 3, "dagenNaVervalDatum": 60, "naam": "Aanmaning", "kanaal": "EMAIL"},
      {"nr": 4, "dagenNaVervalDatum": 90, "naam": "Escalatie naar account-manager"}
    ]
  },
  "reden": "Wet betalingstermijnen overheid — 30-dagensbetaaltermijn"
}
```

Example IncassoKostenBerekening (€8.400 invoice):
```json
{
  "id": "ik-inv-2026-0247",
  "factuurId": "inv-2026-0247",
  "hoofdsom": 8400.00,
  "berekening": {
    "schaal1_0_2500_15pct": 375.00,
    "schaal2_2500_5000_10pct": 250.00,
    "schaal3_5000_10000_5pct": 170.00,
    "totaal": 795.00,
    "minimum": 40.00,
    "toegepast": 795.00
  },
  "wettelijkeRente": {
    "tarief": 0.115,
    "type": "HANDELSRENTE_B2B_6_119A_BW",
    "ingangsdatum": "2026-05-30",
    "berekendOp": "2026-06-21",
    "dagen": 22,
    "bedrag": 58.13
  },
  "totaalVerschuldigd": 9253.13
}
```
