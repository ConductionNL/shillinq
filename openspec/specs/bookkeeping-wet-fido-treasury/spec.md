---
status: done
---

# Spec: Wet Fido & Treasurystatuut Compliance

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T3 (regulatory + compliance)  
**Primary spec:** bookkeeping-wet-fido-treasury  

**Depends on:**
- bookkeeping-programmabegroting (supplies vastgestelde begroting)
- bookkeeping-schatkistbankieren (daily sweep to AGT)
- bookkeeping-bbv-compliance (BBV-paspoort metadata)
- bookkeeping-jaarrekening-publication (treasury-paragraph in annual accounts)

---

## Purpose

This spec introduces complete Wet Fido (Wet Financiering Decentrale Overheden) and
local Treasurystatuut compliance for Shillinq. The system enforces two hard
quantitative limits — the kasgeldlimiet on rolling 3-month average short-term debt
and the rente-risiconorm on per-year interest-rate herfinanciering + reset exposure
— and operationalises the mandatory schatkistbankieren (central treasury) cash-sweep
scheme. Every treasury transaction (lening, derivaat) is tested in real-time against
both the legal ceilings and the organisation's adopted Treasurystatuut. The system
produces the verplichte kwartaalrapportage to the toezichthouder and the jaarrekening's
treasury-paragraph without manual spreadsheet work.

Eight new registers are declared:
1. **treasurystatuut** — versioned local treasury policy (signingMandates, instruments, counterparties)
2. **kasgeld-limiet** — daily short-term debt ceiling (rolling 3-month average net exposure)
3. **rente-risiconorm** — 4-year rolling interest-rate exposure ceiling (per-year herfinanciering + resets)
4. **schatkistbankieren-saldo** — daily cash-park position to AGT with drempelbedrag automation
5. **lening** — short-term & long-term loans with instrument type, signing-mandate enforcement
6. **derivaat** — interest-rate swaps/caps/collars with RUDDO hedging-only validation
7. **quarterly-fido-report** — auto-generated rapportage to toezichthouder, signed & transmitted
8. **treasury-paragraph** — auto-populated jaarrekening treasury narrative & projection

The accounting flow is declarative: schema metadata + aggregation queries
(limiet-recompute, rapportage-generation) + lifecycle automation. No PHP treasury
service.

---

## Requirements

@e2e exclude pure backend/compliance: Wet Fido treasury — not browser-testable


### Requirement: REQ-FDO-001 Treasurystatuut adoption & enforcement

The system SHALL maintain exactly one adopted Treasurystatuut per organisation
at any moment, and SHALL refuse any treasury transaction whose instrument,
counterparty, or signing-mandate is not permitted by the adopted statuut. When a
new Treasurystatuut is adopted by the raad/staten/AB (via formal besluit), the
system records the new version + adoptionDate and marks prior versions as superseded.

#### Scenario: Nieuwe Treasurystatuut aanname 2026

- **GIVEN** a gemeente has adopted a Treasurystatuut dated 2025-11-30 effective
  2026-01-01, with signingMandates: treasurer ≤€5M kasgeld zelfstandig, directeur
  €5M–€25M co-sign, college >€25M
- **WHEN** a treasurer attempts to record a kasgeldlening van €3M on 2026-01-15
- **THEN** the system checks the lening against the current (2026-01-01) adopted
  Treasurystatuut, verifies the signing-mandate row for kasgeld + €3M shows
  "treasurer zelfstandig" permitted, and accepts the lening
- **AND** when the same treasurer attempts a kasgeldlening van €30M on 2026-02-01
- **THEN** the system flags that the mandate requires "college-besluit"; recording
  is possible only with an explicit override + rationale recorded in audit trail

### Requirement: REQ-FDO-002 Kasgeldlimiet daily rolling 3-month average

The system SHALL compute the kasgeldlimiet daily as the rolling 3-month average of
net short-term debt per Wet Fido definition, where net short-term debt =
(opgenomen kasgeldleningen + rekening-courant draws + schuld RO + overige korte schuld < 1 jaar)
− (korte vorderingen < 1 jaar). The ceiling is calculated as percentage ×
vastgestelde begroting per 1 januari, where percentage varies by organisatie-type
(8.5% gemeente, 7% provincie, 23% waterschap, varying for GR per Wet Fido Annex II).
Current exposure MUST be recomputed every banking day.

#### Scenario: Kasgeldlimiet 3-maands rolling average berekening 2026

- **GIVEN** a gemeente has vastgestelde begroting €200M (per 1-1-2026), kasgeldlimiet
  percentage 8.5%, thus ceiling = €17M
- **AND** historical rolling 3-month average net short-term debt: Day 1–60 €14M,
  Day 61–90 €15M, Day 91–120 €16M
- **WHEN** the system computes rolling 3-month average on Day 121
- **THEN** the system calculates rolling-avg = (€15M + €16M + new daily-debt) / 3
- **AND** if rolling-avg ≤ €17M, status remains "binnen-norm"
- **AND** if rolling-avg exceeds €17M for the first time (e.g., €17.5M on Day 121),
  status becomes "overschrijding-1-kwartaal" and an alert is raised
- **AND** if the overschrijding persists through Day 151 (end of second consecutive
  quarter), status becomes "overschrijding-2-kwartalen"
- **AND** if the overschrijding persists through Day 181 (end of third consecutive
  quarter), status becomes "sanering-verplicht" and the system blocks further
  kasgeldleningen unless explicitly overridden with rationale

### Requirement: REQ-FDO-003 Rente-risiconorm 4-year forward projection

The system SHALL compute the rente-risiconorm as 20% × uitstaande vaste schuld per
1 januari per Wet Fido, and SHALL project per-year herfinanciering + rate-reset
exposure volumes for the next 4 years from recorded leningen with defined repayment
schedules and floating-rate instruments with known reset-dates. The system SHALL
refuse to record a new lening whose forward-looking per-year exposure would breach
the 20% norm in any of the next 4 years.

#### Scenario: Rente-risico 4-jaars projectie bij nieuwe lening

- **GIVEN** a provincie has uitstaande vaste schuld €500M per 1-1-2026, thus
  rente-risiconorm ceiling = €100M per-year
- **AND** recorded herfinanciering-schedule: Year 1 €80M, Year 2 €85M, Year 3 €70M,
  Year 4 €60M (all within €100M limit)
- **WHEN** a treasurer attempts to record a new vaste lening van €25M maturing
  2027-12-31 (herfinanciering in Year 2), which would raise Year-2 exposure to
  €110M
- **THEN** the system refuses the lening with error: "Rente-risiconorm breach
  Year 2: projected €110M exceeds ceiling €100M. Recommend shorter maturity or
  floating-rate hedge."
- **AND** if the treasurer overrides with rationale "Treasury-approved hedging via
  IRS to manage rate reset", the lening is recorded but flagged in the next
  quarterly rapportage with override explanation

### Requirement: REQ-FDO-004 RUDDO hedging-only derivaat validation

The system SHALL refuse to persist a Derivaat record unless the RUDDOJustification
field demonstrates a direct hedging relationship to an existing Lening or
KasgeldPositie, the notional does not exceed the hedged exposure, and the
counterparty rating meets the RUDDO-minimum (single-A long-term per recognised
rating agencies). Speculative derivatives are never recorded.

#### Scenario: RUDDO hedging-only validatie bij derivaat-entry

- **GIVEN** a gemeente has a recorded IRS on a floating-rate lening €10M at Euribor
  + 2.5%, maturing 2028-06-30, currently marked-to-market at €150K unrealised loss
- **WHEN** a treasurer attempts to record a new IRS (receiver-fixed, payer-floating)
  with notional €8M, hedge-maturity 2028-06-30, on the same counterparty
  (ING Bank, Moody's rating A1)
- **THEN** the system checks:
  - RUDDOJustification contains text linking to the €10M floating-rate lening ✓
  - hedgedExposure field set to FK of €10M lening ✓
  - notional €8M ≤ hedged lening €10M ✓
  - counterparty rating A1 ≥ single-A ✓
  - All checks pass → IRS is recorded
- **AND** when the same treasurer attempts a new IRS (receiver-fixed, notional €2M)
  with RUDDOJustification = "Speculative short-rate bet on ECB policy shift"
- **THEN** the system refuses with error: "RUDDO Article 2: hedging-only purpose
  required. Rationale does not demonstrate hedge-relationship to existing
  Lening/KasgeldPositie."

### Requirement: REQ-FDO-005 Schatkistbankieren daily automated sweep

The system SHALL sweep cash above the drempelbedrag to schatkistbankieren on every
banking day, calculate the drempelbedrag as max(0.75% × begrotingstotaal, €1,000,000)
capped at €1bn, and record the daily rekening-courant saldo plus the parked amount
for audit purposes. The sweep is idempotent: if the daily job runs twice, the netto
effect is the same. Sweep failures are logged in the audit trail; the treasurer is
alerted.

#### Scenario: Schatkistbankieren daily sweep en drempelbedrag

- **GIVEN** a waterschap has begrotingstotaal €150M, thus drempelbedrag = max(0.75% ×
  €150M, €1M) = €1.125M
- **AND** rekening-courant saldo on Day N: €1.5M (above drempelbedrag)
- **WHEN** the daily schatkistbankieren sweep-job runs at 16:00 on Day N
- **THEN** the system:
  - Computes excess = €1.5M − €1.125M = €0.375M
  - Initiates OpenConnector call to AGT to park €0.375M
  - Records SchatkistbankierenSaldo: parkedAtSchatkist = €0.375M, currentRekeningCourant = €1.125M
  - Posts GL entry: debit uitzetting-schatkist €0.375M, credit rekening-courant €0.375M
  - Logs sweep in audit trail with timestamp + status "success"
- **AND** if the OpenConnector call fails (e.g., AGT system unavailable on Day N+1)
- **THEN** the system:
  - Logs failure in audit trail with timestamp + error message
  - Alerts treasurer: "Schatkistbankieren sweep failed; manual initiation required"
  - Retries on next banking day (idempotent: if manual sweep initiated on Day N+1,
    automatic retry on Day N+2 computes new excess and sweeps only that)

### Requirement: REQ-FDO-006 Quarterly Fido rapportage generation & submission

The system SHALL generate the verplichte QuartaalrapportageFido within 10 working days
after each quarter-end, requiring co-sign by treasurer and concerncontroller, and
SHALL transmit the rapportage to the toezichthouder (provincie for gemeente, ministerie
BZK for provincie/waterschap) with a digital receipt. The rapportage summarises all
treasury mutations that quarter, limiet-status snapshots, and any breaches or
overrides.

#### Scenario: Kwartaalrapportage Q4 2025 generatie & ondertekening

- **GIVEN** a gemeente completes Q4 2025 (31-12-2025) with:
  - 3 new leningen recorded (€2M, €1.5M, €0.8M)
  - 1 derivaat-entry (€5M notional IRS hedging one lening)
  - Kasgeldlimiet final-quarter exposure €16.8M (within €17M ceiling, no breach)
  - Rente-risiconorm no breaches across 4 years
  - Schatkistbankieren daily average parked €300K
- **WHEN** the system auto-generates QuartaalrapportageFido on 2026-01-05
  (5 working days after Q4-end)
- **THEN** the rapportage includes:
  - Header: gemeente name, Q4 2025, raad-besluit date for current Treasurystatuut
  - Leningen summary: 3 entries, €4.3M total, all signed per Treasurystatuut
  - Derivaten summary: 1 IRS, €5M notional, hedging-link documented per RUDDO
  - Kasgeldlimiet snapshot: ceiling €17M, final-quarter exposure €16.8M, headroom €200K
  - Rente-risiconorm snapshot: ceiling €100M, per-year exposures within limit
  - Schatkistbankieren summary: average daily parked €300K, no sweep-failures
  - Narrative: "No limiet-breaches; all transactions executed per Treasurystatuut"
- **AND** the treasurer reviews rapportage, signs with date + time
- **AND** the concerncontroller reviews rapportage, signs with date + time
- **AND** the system transmits the signed rapportage to the provincie via OpenConnector
  (or BZK for provincie-level entity)
- **AND** the system logs the submission timestamp + digital receipt from provincie
  in audit trail

### Requirement: REQ-FDO-007 Treasury-paragraph jaarrekening auto-generation

The system SHALL produce the treasury-paragraph as part of the programmabegroting &
jaarrekening, automatically populated from current limiet-status, projected
liquiditeit, and the adopted Treasurystatuut narrative, leaving only manual
annotation to the concerncontroller. The paragraph SHALL cover limiet-compliance
status, projected liquidity position, schatkistbankieren expectations, and summary
of treasury strategy & risks per BBV Article 13.

#### Scenario: Treasury-paragraph jaarrekening 2026 auto-populatie

- **GIVEN** a gemeente closing jaarrekening 2025 (per 2026-05-15 BBV deadline) with:
  - Final kasgeldlimiet status: € 16.8M exposure vs €17M ceiling (no breach)
  - Final rente-risiconorm status: no breaches across 4-year projection
  - Schatkistbankieren average daily parked €300K (within drempelbedrag)
  - Adopted Treasurystatuut 2024 version (riskAppetite: "midden")
- **WHEN** the jaarrekening-renderer calls treasury-paragraph data-source
- **THEN** the system auto-generates paragraph text (Dutch):
  ```
  Schatkistfunctie: Het kasgeldlimiet bedraagt 8,5% van de begroting (€17,0M).
  Het gemiddelde netto kortlopende schuld over Q4 was €16,8M; geen overschrijding.
  De rente-risiconorm (20% vast schuld herfinancieringsrisico) toont geen
  overschrijding in de 4-jaars projectie. Dagelijks gemiddelde geparkt bij AGT:
  €0,3M. Treasurystatuut 2024 voorziet in risk-appetite "midden"; alle transacties
  voldoen aan mandaatmatrix.
  ```
- **AND** the concerncontroller may add manual annotation (e.g., "In 2026, we expect
  to issue a €20M 10-year obligatie to fund capital-works; this will raise vaste
  schuld and extend maturity profile favorably.")
- **AND** the final jaarrekening notes include both auto-generated + annotated text

### Requirement: REQ-FDO-008 Real-time lening entry validation & override

The system SHALL validate every lening at entry-time against the live kasgeldlimiet,
rente-risiconorm, and Treasurystatuut signingMandates. If any limit is breached,
the system SHALL NOT block outright but SHALL flag the breach, show the remaining
headroom, and require the treasurer to enter an explicit override-rationale recorded
in the audit trail.

#### Scenario: Kasgeldlening entry met limiet-overschrijding en override

- **GIVEN** a gemeente with kasgeldlimiet ceiling €17M and current rolling 3-month
  exposure €16.9M (headroom €0.1M)
- **WHEN** a treasurer attempts to record a new kasgeldlening €0.5M for emergency
  bridging finance
- **THEN** the system displays:
  - Alert: "BREACH: Proposed lening €0.5M would raise rolling-3-month exposure to
    €17.4M, exceeding ceiling €17M by €0.4M."
  - Headroom: "Current headroom: €0.1M; adding €0.5M creates overschrijding."
  - Override option: Checkbox "I acknowledge the breach and enter rationale:"
- **AND** the treasurer checks the override box and enters: "Grant-receipt delayed
  from 2026-02-15 to 2026-04-30; bridging finance repaid when grant arrives."
- **THEN** the system:
  - Records the lening with status "recorded-with-override"
  - Logs the override-rationale in audit trail with timestamp
  - Flags the lening in the next QuartaalrapportageFido with override-explanation
  - Allows the concerncontroller to review + approve before rapportage submission

### Requirement: REQ-FDO-009 Limiet-baseline percentages per organisatie-type

The system SHALL maintain a lookup table (FidoNormcatalogus) of statutory
kasgeldlimiet percentages per organisatie-type (gemeente 8.5%, provincie 7%,
waterschap 23%, gemeenschappelijke regelingen varying per Wet Fido Annex II) and
SHALL automatically select the applicable percentage on first setup based on the
organisation's organisationType. If Wet Fido percentages change via formal wets-wijziging,
the table can be updated without code-deploy.

#### Scenario: FidoNormcatalogus lookup bij gemeenschappelijke regeling

- **GIVEN** a newly-configured gemeenschappelijke regeling (type GR-waterkwaliteit)
- **WHEN** the concerncontroller sets up the first kasgeldlimiet record
- **THEN** the system queries FidoNormcatalogus for organisationType = "GR-waterkwaliteit"
  and retrieves percentage = 15.0% (per Wet Fido Annex II for waterschap-related GR)
- **AND** if baseBegroting = €50M, the system auto-calculates ceiling = 15.0% × €50M = €7.5M
- **AND** if Parliament later amends Wet Fido to change GR-waterkwaliteit percentage to
  17.5%, the treasurer simply updates FidoNormcatalogus record; next daily recompute
  uses new percentage

## Verification

`openspec validate` must exit clean on the change folder. Treasurer / CFO / concern-controller
peer-review confirms the kasgeldlimiet rolling-average + rente-risiconorm projection +
RUDDO hedging + quarterly-rapportage flow matches Dutch Wet Fido + Treasurystatuut annual
cycle. Architecture reviewer confirms ADR-022 + ADR-031 compliance (no app-local treasury
calculation service; no app-local document storage; limiet-math declarative; rapportage
aggregation-driven; manifest carries navigation). No source code changes outside
`openspec/changes/bookkeeping-wet-fido-treasury/`.
