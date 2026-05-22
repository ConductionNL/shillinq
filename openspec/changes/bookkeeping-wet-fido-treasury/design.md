# Design — Wet Fido & Treasurystatuut Compliance

## Context

The Wet Financiering Decentrale Overheden (Wet Fido, 2001, amended through 2024)
and the local Treasurystatuut (adopted by each raad/staten/AB once per term) govern
how Dutch public-sector organisations — gemeenten, provincies, waterschappen,
gemeenschappelijke regelingen — may finance themselves and manage cash. The law
imposes two hard quantitative limits and a mandatory cash-park scheme:

1. **Kasgeldlimiet**: rolling 3-month average of net short-term debt (kasgeldleningen,
   rekening-courant draws, short-term schuld < 1 jaar, minus short-term vorderingen)
   MUST NOT exceed a statutory percentage of the vastgestelde begroting. Percentage
   varies by organisatie-type: 8.5% gemeente, 7% provincie, 23% waterschap, varying
   for gemeenschappelijke regelingen.

2. **Rente-risiconorm**: per-year exposure to interest-rate resets + refinancings over
   the next 4 years MUST NOT exceed 20% of the opening-year vaste schuld per Wet Fido.

3. **Schatkistbankieren**: surplus cash above a statutory drempelbedrag (0.75% of
   total begroting, min €1M, max €1bn) MUST be swept daily to the Agentschap van
   de Generale Thesaurie for safekeeping.

The Treasurystatuut, adopted by raad/staten once per term (binding legal document),
operationalises the Wet Fido envelope locally: which counterparties are acceptable
(banks, medeoverheden, schatkist), which instruments are permitted (lening,
deposito, staatsobligatie, medeoverheidsobligatie, derivaat-hedging-only), and which
signing-mandate matrix applies (role × amount × instrument, e.g., treasurer
≤€10M lening zelfstandig, >€10M co-sign by directeur, >€50M college besluit).

Historically, breaches are discovered quarterly in arrears via manual Excel-tracking
or outsourced treasurer-reports. Shillinq operationalises all three limits as
real-time guardrails + auto-generated quarterly rapportage.

Per ADR-031, the entire measurement + enforcement model is declarative: schema
metadata + aggregation queries that emit limit-status + breach-alerts + rapportage
records + lifecycle automation. Per ADR-022, lening-documents + rapportage-artefacts
are archived via docudesk, not in app-local storage.

The change is **spec-only**. Implementation lands later through `opsx-apply` and the
standard Hydra pipeline.

## Goals

- Express the entire Wet Fido + Treasurystatuut surface as **declarative metadata** —
  schemas + lifecycle + aggregation formulas + validations — per ADR-031.
- Make the spec a **competent-treasurer readable contract** — Dutch Wet Fido + 
  Treasurystatuut enforcement annual cycle recognisable end-to-end (plan adoption,
  lening-entry, live-limiet-check, quarterly-rapportage, toezichthouder-submission).
- Enforce kasgeldlimiet + rente-risiconorm + RUDDO + signingMandates guardrails
  in **real-time** at transaction-entry, not quarterly arrears.
- Support both short-term kasgeld loans (< 1 jaar) and long-term emissions
  (vaste schuld, > 1 jaar) with distinct limiet-calculations.
- Generate the **verplichte kwartaalrapportage** within 10 working days after
  quarter-end, signed by treasurer + concerncontroller, auto-transmitted to
  toezichthouder with digital receipt.
- Make the **treasury-paragraph** for jaarrekening auto-populated from live
  limiet-status, projected liquiditeit, and statuut narrative.

## Non-Goals

- No PHP treasury calculation service (LimietCalculator.php, HerfinancieringAnalyser.php).
- No governance workflows (raad/staten/AB approval, wijziging-moties) — owned by
  decidesk integration (T4).
- No real-time rate-market connectors (Bloomberg, CNS) for auto-duurte-matching and
  dynamic discount-rate updates — T4.
- No multi-currency FX exposure (single EUR scope).
- No DNB/BZK filing automation beyond quarterly rapportage — T4.
- No pension-fund / reserve-fund treasury accounting (scope is the core municipal
  treasury function).

## Decisions

### D1 — Eight registers: statuut + limiet-tracking + lening + derivaat + reporting

Treasury compliance is decomposed into:
- **treasurystatuut**: regeling metadata (version, adoptionDate, effectiveFrom/To,
  riskAppetite, signingMandates matrix, permittedInstruments, counterpartyAllowlist)
- **kasgeld-limiet**: daily recompute (auditYear, baseBegroting, percentage, calculated
  ceiling, currentExposure rolling-3-month, headroom, status)
- **rente-risiconorm**: rolling per-year herfinancieering + reset-exposure ceiling
  (auditYear, baseVasteSchuld, calculatedCeiling, forwardLooking4Year per-year,
  headroomPerYear, status)
- **schatkistbankieren-saldo**: daily position (organisationId, drempelbedrag,
  currentRekeningCourant, daysAboveDrempel, parkedAtSchatkist, lastSweepAt)
- **lening**: short & long-term loans (counterparty, type, principal, rate, maturity,
  repaymentSchedule, signingMandate FK, Treasurystatuut FK, purpose, BBVPaspoort FK)
- **derivaat**: interest-rate swaps/caps/collars (type, notional, hedgedExposure FK,
  maturityDate, marketValue, RUDDOJustification, counterpartyRating)
- **quarterly-fido-report**: auto-generated snapshot + narrative (auditYear, kwartaal,
  kasgeldStatus, renteRisicoStatus, schatkistStatus, leningenMutaties, narrative,
  signOff treasurer+controller, submittedToToezichthouder, submissionReceipt)
- **treasury-paragraph**: jaarrekening content (auditYear, begrotingVersion, narrative,
  kasgeldProjectie, renteRisicoProjectie, liquiditeitsplanning, schatkistVerwachting)

**Alternative considered**: Monolithic treasury-register with all fields embedded.
Rejected — daily limiet-recompute + per-year rente-risico-projection + quarterly
rapportage require first-class records for audit trail, drill-down, and timeseries
tracking.

### D2 — Kasgeldlimiet: rolling 3-month average, daily recompute

Every banking day, the system recomputes the kasgeldlimiet exposure as the
rolling 3-month average of net short-term debt per Wet Fido definition:
= (opgenomen kasgeldleningen + rekening-courant draws + schuld RO + overige korte schuld < 1 jaar)
  − (korte vorderingen < 1 jaar)

Ceiling = percentage (per organisatie-type) × vastgestelde begroting per 1 januari.

Enforcement ladder:
- If exposure exceeds ceiling in 1 quarter → `overschrijding-1-kwartaal` alert
- If exceeded in 2 consecutive quarters → `overschrijding-2-kwartalen` alert
- If exceeded in 3 consecutive quarters → `sanering-verplicht` blocker (raad must
  consolidate to long-term debt)

**Alternative considered**: Snapshot-at-quarter-end rather than rolling average.
Rejected — Wet Fido defines rolling 3-month; rolling average detects trajectory
toward ceiling more smoothly than quarterly jumps.

### D3 — Rente-risiconorm: 4-year rolling herfinanciering + reset exposure per year

The rente-risiconorm ceiling = 20% × uitstaande vaste schuld per 1 januari
per Wet Fido. The system computes per-year herfinancieering + renteherziening
volumes for the next 4 years from recorded leningen + floating-rate debt with
reset-schedules. Sum of per-year exposure MUST NOT breach ceiling in any future year.

On every new lening-entry, the system recomputes the 4-year projection. If any
future year would breach 20%, the lening is refused with a validation error.

**Alternative considered**: Assume all herfinanciering clusters in year 1.
Rejected — realistic herfinanciering schedules spread over years; 4-year
granularity required for large debt-portfolios (provincie, waterschap).

### D4 — Treasurystatuut versioning + adoption workflow

Each organisation maintains exactly one adopted Treasurystatuut at any moment.
When the raad/staten/AB adopts a new version (via formal besluit), the system
records the new version + adoptionDate + effectiveFrom/To + status (draft/adopted/superseded).

Any lening/derivaat entry is tested against the **current adopted** Treasurystatuut
for permitted instruments, counterparties, and signingMandates. Pre-adoption draft
versions are not enforced.

**Alternative considered**: Allow multiple active statuuts (e.g., during transition).
Rejected — Wet Fido requires single adopted statuut; legal ambiguity if multiples active.

### D5 — Lening signing-mandate matrix enforcement

The Treasurystatuut encodes a role × amount × instrument matrix (e.g., treasurer
≤€10M lening/kasgeld, directeur €10M–€50M with co-sign, college >€50M). On lening-entry,
the system validates that the entered signingMandate matches the matrix and checks
that the lender role (treasurer, directeur, college) matches the authority recorded.

**Alternative considered**: Trust signatory on honor system; audit post-hoc.
Rejected — Wet Fido § enforcement depends on real-time mandate-check at transaction-time.

### D6 — RUDDO hedging-only validation on derivaten

Per RUDDO (Regeling uitzettingen en derivaten decentrale overheden), derivaten
(IRS, caps, collars) are restricted to **hedging-only** purposes. On derivaat-entry,
the system refuses save unless:
- RUDDOJustification field contains narrative that demonstrates direct hedging
  relationship to an existing Lening or KasgeldPositie (e.g., "IRS to hedge
  floating-rate risk on €20M MTN maturing 2028")
- The hedgedExposure field is set to the FK of the underlying Lening
- The notional does NOT exceed the hedged exposure
- The counterparty rating meets RUDDO-minimum (single-A long-term, per recognised
  rating agencies)

Speculative derivatives are **never** recorded.

**Alternative considered**: Log speculative derivative proposals with warning.
Rejected — RUDDO is a hard rule; speculation is illegal for municipalities;
system must refuse outright.

### D7 — Schatkistbankieren daily sweep automation

Per Wet schatkistbankieren, the system runs a scheduled daily job (after end-of-day
bankafschrift-verwerking) that:
1. Computes drempelbedrag = max(0.75% × begrotingstotaal, €1,000,000) capped at €1bn
2. Checks current rekening-courant saldo
3. If saldo > drempelbedrag for the 3rd consecutive banking day, initiates a sweep
   of the excess to schatkistbankieren (via OpenConnector to AGT)
4. Records the sweep in SchatkistbankierenSaldo + GL posting (uitzetting RO, credit
   rekening-courant)
5. Logs all sweep-events + failures in audit trail

The sweep is **idempotent**: if it runs twice, netto effect is the same.

**Alternative considered**: Manual treasurer sweep-initiation. Rejected — Wet
schatkistbankieren mandates **automatic** daily sweep.

### D8 — Quarterly rapportage: auto-generated, signed, transmitted

Per Wet Fido §§, the organisation MUST submit a verplichte kwartaalrapportage
to the toezichthouder (provincie for gemeente, ministerie BZK for provincie/waterschap)
within 10 working days after quarter-end. The system auto-generates the rapportage
from completed transactions + kasgeldlimiet-status + rente-risiconorm-status +
schatkistbankieren-position per that quarter, then requires co-sign by treasurer +
concerncontroller before submission.

Rapportage includes:
- Summary of all leningen entered that quarter (counterparty, instrument, amount, maturity)
- Derivative mutations (new, closed, revaluations)
- Kasgeldlimiet status (current exposure, ceiling, headroom, any breaches)
- Rente-risiconorm status (per-year exposure, headroom per year, any breaches)
- Schatkistbankieren saldo + sweeps
- Narrative explaining any breaches or material changes
- Signed by treasurer + concerncontroller + datetime + audit trail

Transmission to toezichthouder includes digital receipt.

**Alternative considered**: Manual rapportage authoring. Rejected — auto-generation
from transaction ledger ensures consistency + reduces manual error.

### D9 — Treasury-paragraph: auto-populated jaarrekening narrative

Per BBV Article 13, the jaarrekening MUST include a treasury-paragraph covering:
- Limiet-compliance status (kasgeldlimiet + rente-risiconorm)
- Projected liquidity position (next 12 months)
- Schatkistbankieren expectations
- Summary of treasury strategy + risks

The system auto-generates this paragraph from current limiet-status, projected
liquiditeit (from begroting), and the adopted Treasurystatuut narrative. The
concerncontroller adds manual annotation if needed.

**Alternative considered**: Treasurer manually authors. Rejected — auto-generation
from live register ensures timely + consistent disclosure.

### D10 — Real-time guardrails: override + rationale required

When a treasurer enters a lening that would breach kasgeldlimiet or rente-risiconorm,
the system does NOT block outright. Instead:
1. System flags the breach and shows the limiet-headroom remaining
2. Treasurer may override with an explicit rationale (e.g., "Emergency short-term
   borrowing to cover unexpected grant-receipt delay; planned repayment Q2")
3. Override + rationale is recorded in audit trail + flagged in next quarterly rapportage
4. Concerncontroller reviews override + rapportage before submission to toezichthouder

This enables operational flexibility while maintaining full audit trail for oversight.

**Alternative considered**: Hard block on any breach. Rejected — municipality may have
legitimate short-term emergency; audit trail + controller review safer than absolute
block.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Kasgeldlimiet rolling 3-month avg | OR `x-openregister-aggregations` (timeseries window function) | Daily aggregation query over prior 90 days of kasgeld-leningen + RO-draws |
| Rente-risiconorm 4-year projection | OR `x-openregister-calculations` (forward arithmetic) | Formula on `lening.repaymentSchedule` + floating-rate `rehetstartdates` to project per-year exposure |
| RUDDO hedging validation | OR `x-openregister-validations` (FK + business-rule check) | Schema-level validator on `derivaat.RUDDOJustification` + `hedgedExposure` link |
| Treasurystatuut adoption workflow | OR `x-openregister-lifecycle` (state machine) | Lifecycle: draft → approved → adopted → superseded; approval gate on entry |
| Kasgeldlimiet enforcement at lening-entry | T2 `bookkeeping-approval-workflow-management` (pre-save validator) | Validation hook at lening-save; if breach + override, log in audit trail |
| Signingmandate matrix check | OR `x-openregister-validations` (enum + cross-table rule) | Validator reads Treasurystatuut.signingMandates matrix + current user role + lening amount |
| Schatkistbankieren daily sweep | T2 `bookkeeping-schatkistbankieren` (existing module) + OpenConnector | Sweep-job extended; triggers OpenConnector to AGT; records SchatkistbankierenSaldo here |
| Quarterly rapportage generation | OR `x-openregister-aggregations` (quarter-window query) | Aggregation emits `quarterly-fido-report` records from quarterly-slice of leningen + derivaten + limiet-status |
| Treasury-paragraph for jaarrekening | T3 `bookkeeping-financial-statements` (narrative consumer) | `treasury-paragraph` data-source callable by jaarrekening notes-renderer |
| Audit trail | T2 `bookkeeping-audit-trail` | Automatic on all schema writes + lifecycle transitions + overrides |
| Toezichthouder submission + receipt | T3 `bookkeeping-publication-platform-integration` (digital delivery) | OpenConnector to provincie/BZK with receipt-tracking |

**Net new code in implementation cycle**: 8 schema declarations + 4 lifecycle blocks + 
3 aggregation queries (kasgeld rolling-avg, rente-risico projection, quarterly-report 
generation) + 5 manifest entry pairs + 1 small `SigningMandateValidator` helper if 
schema-level enforcement insufficient. No PHP treasury-calculation service.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Kasgeldlimiet rolling 3-month average | Declarative (`x-openregister-aggregations` rolling-window query over leningen + RO) | Pure data aggregation + arithmetic |
| Rente-risiconorm 4-year per-year projection | Declarative (formula on lening.repaymentSchedule + floating-rate resets) | Scalar adjustments applied to base loan schedule |
| RUDDO hedging validation | Declarative (schema-level FK + business-rule validator) | Enum check + link presence |
| Signing-mandate matrix enforcement | Declarative (matrix lookup from Treasurystatuut + schema validator) | Enum comparison rule |
| Quarterly rapportage generation | Declarative (aggregation query emitting quarterly-report records) | Template + data-source merge from transaction-ledger |
| Treasury-paragraph for jaarrekening | Declarative (schema fields + narrative template) | Operator enters narrative; system merges with limiet-status data |
| Schatkistbankieren daily sweep | Declarative (scheduled job config + threshold formula on saldo > drempelbedrag) | Deterministic logic + idempotent sweep-call |

No service class authored in this envelope (subject to ADR-031 exception: at most
one small `SigningMandateValidator` if needed).

## Seed Data

Three seed records:

1. **treasurystatuut**: "NL Standard Gemeente Treasurystatuut 2026"
   - version: 1.0
   - riskAppetite: "midden" (medium per Wet Fido 8.5% kasgeldlimiet)
   - signingMandates: treasurer ≤€5M kasgeld, directeur €5M–€25M with co-sign,
     college >€25M
   - permittedInstruments: lening, deposito, staatsobligatie, medeoverheidsobligatie
   - counterpartyAllowlist: Nederlandse banken (ABN-AMRO, ING, Rabobank, etc.),
     AGT, andere gemeenten
   - reportingCadence: quarterly default

2. **lening**: "Example: Município-RO 2026"
   - counterparty: "ABN AMRO Bank N.V."
   - type: "rekening-courant" (kasgeld)
   - principal: €2,500,000
   - rate: 3.75% fixed
   - issueDate: 2026-01-15
   - maturityDate: 2027-01-15
   - purpose: "Bridging finance for grant-receipt lag Q1"

3. **kasgeld-limiet** (template): 2026 gemeente €200M begroting
   - baseBegroting: €200,000,000
   - percentage: 8.5%
   - calculatedCeiling: €17,000,000
   - (operator customizes per entity)

Operators customize per entity on first use.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Treasurystatuut signingMandates entered incorrectly by concerncontroller; breaches go undetected | Schema-level audit trail on Treasurystatuut entry + formal raad-approval gate before adoption + concerncontroller sign-off |
| Kasgeldlimiet rolling 3-month daily recompute differs from treasurer's Excel baseline | Widget shows daily rolling-average, components, and daily change; drill-down exposes all kasgeldleningen + RO-draws used in calc |
| Rente-risiconorm 4-year herfinanciering schedule supplied by lender differs from reality | Forward-looking projection visible on register; quarterly rapportage flags when projection changes materially; lender-supplied amortization-schedule optional input (T4) |
| RUDDO hedging-validation requires treasurer to document hedge linkage; off-the-cuff IRS without narrative refused | Schema-level RUDDOJustification + hedgedExposure link mandatory; any derivaat without both fails save; UI guidance on hedging-criteria |
| Schatkistbankieren sweep-job fails or OpenConnector to AGT unavailable; cash not swept = above-drempel exposure | Daily sweep-job is idempotent; logs all sweeps + failures in audit trail; treasurer alerted on failure; manual sweep-initiation as fallback |
| Quarterly rapportage auto-generation format may not match toezichthouder's expectations | Template-driven generation per Wet Fido §; optional manual override for narrative; toezichthouder can request format-adjustments via T4 |
| Treasury-paragraph jaarrekening language may need manual polish; auto-generated text too dry | Auto-generation provides base narrative; concerncontroller may add/edit manual annotation for tone + entity-specific color |

## Migration Plan

No legacy data migration required. Wet Fido treasury compliance is introduced as a
new module; existing customers on Shillinq without treasury-limiet tracking are not affected.
Customers with existing treasury-tracking (via spreadsheets or external tools) can
opt-in and begin recording leningen + derivaten from 2026-01-01 forward. No backfill
of historical data required.

## Compliance & Standards

Spec implements:
- **Wet Financiering Decentrale Overheden (Wet Fido)**, Stb. 2000/569 with amendments
  through Stb. 2024/121
- **Uitvoeringsregeling Financiering decentrale overheden** (Uitvoeringsregeling Fido)
- **Regeling uitzettingen en derivaten decentrale overheden (RUDDO)**, Stcrt. 2009/9657
  with amendments
- **Wet Schatkistbankieren Decentrale Overheden**, Stb. 2013/530
- **Regeling Schatkistbankieren Decentrale Overheden**
- **Modelstatuut Treasury** (VNG / IPO / UvW), latest 2024 edition
- **Besluit Begroting en Verantwoording Provincies en Gemeenten (BBV)** — Article 13
  treasury-paragraph
- **Notitie Rente** (Commissie BBV)
- **Notitie Treasury** (Commissie BBV)
- **Handreiking Treasury** (BZK / Min Fin)

## Documentation & Audit Trail

All leningen, derivaten, statuut-versions, overrides, and rapportage-submissions
are recorded with entry date, entered-by person, approval status, and full audit trail.
External accountants + toezichthouders can review complete compliance record in the
quarterly rapportage cycle without requesting spreadsheets or external treasurer-reports.
