# Design — KOR (Kleine Ondernemersregeling)

## Context

KOR (Kleine Ondernemersregeling) is a Dutch VAT simplification regime for small businesses with annual
turnover under EUR 20.000. Since 1-1-2025, it expanded to include KOR-EU (cross-border) with a EUR 100.000
threshold per EU member state. The regime is fiscally onomkeerbaar (irreversible) for three years — choosing
KOR locks you in until the end of the third calendar year. Overschrijding (exceeding the threshold) is
automatic and immediate; revocatie-datum is the delivery date of the triggering transaction, not year-end.

This is NOT a feature for the "nice-to-have" bucket; it is a **critical survival feature** for 320k+ Dutch
entrepreneurs. The spec is driven by three failure modes:
1. Unknowing aanmelding without understanding the three-year lock-in.
2. Surprise overschrijding due to lack of realtime drempel-monitoring.
3. Incorrect factuurvermelding or vorbelasting-handling, creating tax compliance risk.

The change is **spec-only**. Implementation lands later through `opsx-apply` and the standard Hydra pipeline.
This doc explains *why* the shape is what it is.

## Goals

- Express the entire KOR lifecycle as **declarative metadata** — schemas + lifecycle + aggregations +
  manifest entries — per ADR-031.
- Make the spec a **competent boekhouder readable contract** — Dutch KOR flow recognisable end-to-end
  (aanmelding, drempel-monitoring, alert-escalation, revocatie, factuur-kwaliteit).
- Enforce correctness at the **system boundary**, not via advice:
  - Scenario-analysis at aanmelding (not just "info for you").
  - System-enforced factuur-vermelding (not "please remember").
  - Automatic voorbelasting-blokkade (not "ask your advisor").
  - Revocatie-datum exactness (not "sometime in September").
- Support both **NL-KOR (domestic)** and **KOR-EU (cross-border)** per 2025 law.
- Integrate seamlessly with **VAT filing**, **AR/AP**, and **tax-regime** tiers.

## Non-Goals

- No chatbot or wizard beyond scenario-analysis (text-based advisory only).
- No Belastingdienst webservice integration; aanmelding remains web-formulier.
- No automatic SBR / EX-nummer assignment (manual entry for now).
- No multi-entity KOR or fiscale-eenheid grouping (single onderneming per spec).
- No behavioral tweaks for "friendly UX" that violate fiscal correctness (e.g., soft warnings instead of hard revocatie).

## Decisions

### D1 — KOR registration is a TOP-LEVEL ENTITY with immutable dates

`KORRegistration` is not a sub-entity of `Entity` or `Corporation` — it is a parallel, first-class entity
in the register. Each registration has:
- `regime` (KOR_NL or KOR_EU) — immutable once set.
- `aanmeldDatum` (when ondernemer submitted to Belastingdienst).
- `ingangsDatum` (effective start date — typically 1-1 of next calendar year).
- `lockInEindDatum` (last day of the third calendar year after ingangsDatum).
- `vroegsteOpzegDatum` (earliest opt-out date, three months before lockInEindDatum).

Why: KOR is a **regime-level** decision, not a transaction-level property. It affects invoicing, filing,
tax-calculation, and procurement rules simultaneously. Treating it as top-level makes governance and
cross-app coordination explicit.

### D2 — Drempel-benutting is monitored per-invoice post-posting, NOT per-booking-entry

`KORAnnualTurnover.lopendeOmzet` is recalculated immediately after an invoice (from AR or AP) is booked
with a KOR-relevant transaction type. Omzet ONLY counts:
- AR invoices with `vrijstellingsGrondslag: "KOR_ART25_OB"` (KOR-eligible revenue).
- Excluded: vrijgestelde prestaties (art. 11 OB), intracommunautaire leveringen, omzet from onroerend goed.

Why: KOR drempel is an **omzet-drempel**, not a booking-drempel. It must span AR ledger and be independent
of GL account codes or cost-center logic. Post-invoice recalc ensures real-time accuracy; maandgemiddelde
trend provides early-warning prognose.

### D3 — Three escalation levels (80% / 90% / 100%) with distinct channeling

`KORThresholdAlert` triggers at three levels:
- **80%** (VROEG): Email only, informational. "You've reached 80% of your KOR limit; keep track."
- **90%** (KRITIEK): Email + in-app + dashboard, advisory. "You're at 90%. Consider opt-out or strategic
  pre-invoicing."
- **100%** (OVERSCHRIJDING): Synchronous revocatie with system-generated messages. "Your KOR has ended per
  [revocatie-datum]."

Why: Entrepreneurs need GRADUATED warnings, not a binary on/off. 80% is "pay attention"; 90% is "action
needed"; 100% is "fiscal reality." Each level has distinct remediation paths.

### D4 — Revocatie-datum is ALWAYS the delivery date of the trigger transaction

When an invoice pushes omzet > EUR 20.000, `KORRevocation.revocatieDatum` is set to the invoice's
`leveringsDatum` (delivery date), **NOT**:
- The invoice posting date.
- The end of the current month.
- The end of the current quarter.
- The calendar year-end.

Why: Dutch tax law (Wet OB 1968 art. 25) defines the drempel as omzet earned (leveringsdatum), not booked.
Revocatie becomes effective on the date the drempel was breached, period. This affects:
- Suppletie-berekening: all invoices AFTER revocatieDatum are subject to VAT.
- Voorbelasting-correctie: purchases AFTER revocatieDatum can reclaim VAT.
- Compliance: Belastingdienst audits on leveringsdatum, not boekdatum.

### D5 — Voorbelasting-aftrek is zero-forced DURING KOR, not blocked by field

When `KORRegistration.status == ACTIEF`, the AP system (accounts-payable-core) receives an event and
forcibly sets `APInvoice.voorbelastingAftrekBaar = false` for all new invoices. Existing invoices are
NOT retroactively changed; only new postings during the ACTIEF period are forced.

Why: Ondernemers must NOT be able to accidentally claim voorbelasting during KOR. A field toggle is too
easy to miss; system-enforced zero is not. Post-revocatie (when `status != ACTIEF`), voorbelasting flows
normally again, and herzieningsregels kick in for purchases made during the KOR period.

### D6 — Opt-in workflow includes mandatory scenario-analysis with three-year confirmation

The aanmeldstroom (`REQ-001`) MUST show:
- Historical omzet (last two fiscal years).
- Prognose for current year (based on H1 trend or manual input).
- Fiscal scenario: "KOR saves you €X in filing and compliance, but costs you €Y in voorbelasting forgone."
- A MANDATORY checkbox: "Ik begrijp dat ik tot [lockInEindDatum] geen wijziging kan aanvragen."

Why: Regret is the #1 compliance failure. Shillinq's role is to make the consequence VISIBLE and EXPLICIT
before the ondernemer commits. The scenario-analysis is not "nice-to-have UX"; it is a **compliance control**.

### D7 — KOR-EU per-lidstaat drempels are DATA, not code

`KOREUTurnover.perLidstaat` is a data structure:
```json
{
  "BE": {"omzet": 12400.00, "drempelBE": 25000, "benutting": 0.496},
  "DE": {"omzet": 8200.00, "drempelDE": 22000, "benutting": 0.372},
  ...
}
```

Each lidstaat's drempel is configured in `lib/Settings/shillinq_register.json` or fetched from
a Belastingdienst-maintained data-feed (future). This ensures updates to EU member thresholds
don't require code changes.

Why: EU member-state VAT thresholds are subject to periodic review and change. Hardcoding them is unmaintainable.
A data-driven model scales.

### D8 — Kwartaalopgaaf (Q1/Q2/Q3/Q4) is prepared but NOT auto-submitted to Belastingdienst

`KOREUTurnover.kwartaalopgaafStatus` tracks the status of each quarterly declaration:
- `OPEN` — Not yet prepared.
- `DRAFT` — Data prepared by Shillinq, awaiting ondernemer review.
- `INGEDIEND` — Submitted to Belastingdienst (manual upload via mijnbelastingdienst.nl).

Why: The Belastingdienst KOR-EU portal is still web-formulier-only. Shillinq can prepare the data,
but submission remains the ondernemer's responsibility. This keeps the system honest: no "black-box"
filing on behalf of the ondernemer.

### D9 — Branche-specifieke drempelbeoordeling signals conflicts with existing vrijstellingen

Before aanmelding, `REQ-010` triggers a branche-check. If the ondernemer's primary prestatie already
qualifies for a full VAT exemption (e.g., educational services under art. 11-1-o, medical services under
11-1-g), the system signals: "KOR offers no fiscal advantage for your branch and will BLOCK voorbelasting
aftrek. Consider declining."

Why: Many Dutch entrepreneurs combine vrijgestelde + belaste prestaties. KOR is ONLY beneficial for the
belaste portion. If a yoga instructor does 80% lessons (vrijgesteld) and 20% merchandise (belast), KOR only
applies to the 20% — but blocks voorbelasting for ALL purchases. This is a trap the spec must prevent.

### D10 — Three-year lock-in is ABSOLUTE except for death, dissolution, bankruptcy

`KORRegistration.status` cannot transition out of ACTIEF before `lockInEindDatum` UNLESS:
- `ondernemerDeceased() == true` (documented via onderneming-lifecycle event).
- `ondernemingDissolved() == true` (KvK uitschrijving).
- `ondernemingBankrupt() == true` (faillissement-announcement from Belastingdienst).

Why: The three-year lock-in is THE fiscal foundation of KOR. Allowing opt-out before the period would
make the regime fiscally unstable (unternemers would game it: "apply in Nov, cancel in Feb" to pick
the low-cost years). The spec MUST enforce this at the register level, not as a guideline.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Threshold monitoring | OR `x-openregister-aggregations` | `KORAnnualTurnover` aggregation: SUM(AR omzet by type) recalc post-invoice + LINEAR_TREND prognose |
| Alert dispatching | T2 `notifications` contract (`notifications.dispatch`) | `KORThresholdAlert` events trigger dispatch to email + in-app + dashboard per alert-level |
| Revocatie workflow | OR `x-openregister-lifecycle` (ADR-031) | `KORRegistration` lifecycle: ACTIEF → GEEINDIGD_OVERSCHRIJDING (hard limit) or GEEINDIGD_VRIJWILLIG (after opt-out window) |
| Voorbelasting blocking | T2 `bookkeeping-accounts-payable-core` | AP system listens to `kor.registration.activated` event; zero-forces `voorbelastingAftrekBaar` for new invoices during ACTIEF period |
| Filing suspension | T2 `bookkeeping-vat-btw-filing` | Filing system listens to `kor.registration.activated`; marks VAT declarations "niet van toepassing" for KOR periods |
| Factuur-vermelding | T2 `bookkeeping-accounts-receivable-core` | AR invoice-render template selects KOR-variant with mandatory vermeldingsregel; system enforces at post time |
| Scenario-analysis | T2 `bookkeeping-zzp-tax-regime` (context) | Aanmeldstroom calls `TaxRegimeAdvisory.scenario(baseYear, prognoseYear, estimatedTax)` for fiscal advisory |
| Omzet aggregation | GL sub-ledger (T1 AR ledger) | `KORAnnualTurnover` reads AR ledger directly; excludes vrijgestelde + intracommunautaire via transaction-type flags |
| Audit trail | T2 `bookkeeping-audit-trail` | Automatic on all `KORRegistration`, `KORThresholdAlert`, `KORRevocation` lifecycle transitions |
| Manifest navigation | T1 manifest pattern | 4 entries (KOR Aanmelding, KOR Dashboard, Drempel Monitor, Opzegging) + their pages |

## Data Model Alignment

The spec declares five new registers conforming to ADR-000:

- **KORRegistration** — Meta-entity holding aanmeldgegevens, regime, status, lock-in dates, belastingdienst-ref.
  One per onderneming per regime.
- **KORAnnualTurnover** — Annual drempel-tracking: lopende-omzet, drempel-benutting (%), prognose,
  maandlijkse breakdown, uitgeslotenPosten.
- **KORThresholdAlert** — Alert events: 80% / 90% / 100%, omzet-at-moment, ernstlevel, kanaal, aanbeveling.
- **KORRevocation** — Beëindiging-entity: type (overschrijding / vrijwillig), revocatie-datum,
  suppletie-bedrag, herrekenrange, blokkade-heraanmelding, belastingdienst-notificatie-status.
- **KOREUTurnover** — KOR-EU-only: per-lidstaat omzet + drempel, kwartaalopgaaf-status.

All five conform to the shillinq `schema:*` vocabulary per ADR-011 and link to `Administration`
(many-to-one) per ADR-031 multi-administration governance.

## Fiscal Correctness First

The spec prioritises **correctness over convenience**:

- **Scenario-analysis is MANDATORY**, not optional UX.
- **System-enforced factuur-vermelding**, not user-remembered.
- **Revocatie-datum exactness** (leveringsdatum, not year-end).
- **Three-year lock-in is ABSOLUTE** except for death/dissolution/bankruptcy.
- **Voorbelasting-blokkade is automatic**, not field-toggleable.

Every design choice is anchored in **Wet OB 1968** and **Handboek Ondernemen** (Belastingdienst).
The spec is NOT a "nice-to-have simplification"; it is a **compliance control**.
