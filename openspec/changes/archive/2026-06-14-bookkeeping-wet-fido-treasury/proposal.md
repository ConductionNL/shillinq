# Proposal: bookkeeping-wet-fido-treasury

`kind: config` per ADR-032 — the centre of mass is declarative schemas
(`treasurystatuut`, `kasgeld-limiet`, `rente-risiconorm`, `schatkistbankieren-saldo`,
`lening`, `derivaat`, `quarterly-fido-report`, `treasury-paragraph`) + workflow
enforcement on limiet-breaches and RUDDO derivaat validation. No PHP treasury
calculation service is authored; all limiet-math is declarative metadata.

## Summary

Introduce the **Wet Financiering Decentrale Overheden (Wet Fido) & Treasurystatuut**
compliance capability for Shillinq as one of the T3 regulatory + compliance capabilities
(per `adr-001-bookkeeping-tier-roadmap.md`). This change declares eight new registers:

- `treasurystatuut` — versioned local treasury risk policy (signingMandates, permitted instruments, counterparties)
- `kasgeld-limiet` — daily short-term debt ceiling per Wet Fido baseline % (8.5% gemeente, 7% provincie, 23% waterschap)
- `rente-risiconorm` — 4-year rolling interest-rate herfinanciering & rate-reset exposure ceiling (20% norm)
- `schatkistbankieren-saldo` — daily cash-sweep position to AGT with drempelbedrag automation
- `lening` — short-term & long-term loans with instrument type, maturity, signing-mandate enforcement
- `derivaat` — interest-rate swaps / caps / floors with RUDDO hedging-only validation
- `quarterly-fido-report` — the verplichte kwartaalrapportage to toezichthouder (provincie/BZK)
- `treasury-paragraph` — jaarrekening treasury narrative & projection (BBV Article 13)

The treasury-compliance flow is a declarative `x-openregister-lifecycle` on
`treasurystatuut` (versioning & adoption), `lening` (validation at entry), and
`quarterly-fido-report` (generation + sign-off). All limiet-checks are real-time
guardrails: a treasurer cannot draw a kasgeldlening that breaches the limiet
without an explicit override + rationale recorded in the audit trail.

This change conforms to the shared `nextcloud-app` spec for app structure and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-programmabegroting`](../bookkeeping-programmabegroting/proposal.md) — supplies the vastgestelde begroting that anchors all limiet-percentages
- [`bookkeeping-schatkistbankieren`](../bookkeeping-schatkistbankieren/proposal.md) — operates the daily sweep to AGT; this spec adds limiet-enforcement
- [`bookkeeping-bbv-compliance`](../bookkeeping-bbv-compliance/proposal.md) — BBV-paspoort metadata for treasury-GL posting
- [`bookkeeping-jaarrekening-publication`](../bookkeeping-jaarrekening-publication/proposal.md) — treasury-paragraph embedded in published annual accounts

## Motivation

Wet Fido governs how Dutch gemeenten, provincies, waterschappen, and gemeenschappelijke
regelingen may finance themselves and invest surplus liquidity. The law imposes two
hard quantitative limits — the kasgeldlimiet on rolling 3-month average short-term debt
and the rente-risiconorm on per-year herfinanciering + rate-reset exposure — and a
mandatory scheme (schatkistbankieren) for parking surplus cash with the central treasury.

Regulatory breaches historically triggered inter-governmental crises (Vestia derivative
losses, Amarantis hedge-fund blow-up, municipalities with unsustainable debt spirals).
Today, most entities manually track limiet-compliance in Excel sheets or hire external
treasurers (€15–30K/year). Breaches are discovered quarterly in arrears, not in real-time
before they occur.

Per ADR-031, the kasgeldlimiet-berekening, rente-risico-projectie, and
schatkistbankieren-sweep are declarative metadata: registration schemas + aggregation
formulas + lifecycle state machines. The treasurer records each lening, derivaat, and
cash position; Shillinq applies standardised Wet Fido logic to compute live limiet-headroom,
project 4-year rente-risico, and enforce guardrails on every transaction. The quarterly
rapportage is auto-generated from completed transactions + overrides, signed by treasurer
& controller, and auto-transmitted to the toezichthouder.

This is one of the T3 regulatory changes; this proposal scopes only the wet-fido-treasury
slice.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-wet-fido-treasury`);
  declares 8 new registers (`treasurystatuut`, `kasgeld-limiet`, `rente-risiconorm`,
  `schatkistbankieren-saldo`, `lening`, `derivaat`, `quarterly-fido-report`,
  `treasury-paragraph`) with lifecycles + validations; adds 5 manifest navigation
  entries (Treasurystatuut, Leningen, Derivaten, Quarterly Fido Reports, Treasury
  Dashboard).
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-lifecycle`, `x-openregister-validations`, `x-openregister-aggregations`
  for limiet-computation, RUDDO enforcement, report generation.
- [ ] Project: bookkeeping-schatkistbankieren — existing module extended with daily
  sweep automation + limiet-headroom query per this spec.

## Scope

### In Scope

- One new capability spec (`bookkeeping-wet-fido-treasury`) — see the `specs/` folder.
- 8 new registers with full Wet Fido + Treasurystatuut logic:
  - `treasurystatuut` (versioned, adopted per raad/staten/AB besluit)
  - `kasgeld-limiet` (daily recompute, rolling 3-month net short-term debt)
  - `rente-risiconorm` (4-year rolling herfinanciering + rate-reset exposure)
  - `schatkistbankieren-saldo` (daily sweep target & drempelbedrag automation)
  - `lening` (kasgeld, onderhandse, obligatie, MTN, EMTN with signing-mandate enforcement)
  - `derivaat` (IRS, cap, floor, collar with RUDDO hedging-only validation)
  - `quarterly-fido-report` (auto-generated, signed, transmitted to toezichthouder)
  - `treasury-paragraph` (jaarrekening narrative & projection per BBV Article 13)
- Limiet-baseline percentages per organisatie-type: 8.5% gemeente, 7% provincie, 23% waterschap, varying for GR
- Real-time guardrails: every lening/derivaat entry tested live against both the legal ceilings and the adopted Treasurystatuut
- Statutory enforcement ladder: 1-quarter overschrijding alert → 2-quarter alert → 3-quarter sanering-verplicht blocker
- RUDDO enforcement: derivaten refused unless RUDDOJustification demonstrates direct hedging relationship to underlying Lening/KasgeldPositie
- Schatkistbankieren automation: daily sweep job targets drempelbedrag (0.75% begroting, min €1M, max €1bn)
- Quarterly rapportage: auto-generated within 10 working days after quarter-end, signed by treasurer + concerncontroller, transmitted to toezichthouder with digital receipt
- Treasury-paragraph: auto-populated in jaarrekening with limiet-status, projected liquiditeit, and statuut narrative

### Out of Scope

- No PHP treasury calculation service beyond the ADR-031 exception guard.
- No governance workflows (raad/staten/AB approval of Treasurystatuut wijziging) — owned by decidesk integration (T4).
- No real-time rate-market connectors (Bloomberg, CNS) for auto-updating herinciering + duurte-matched discount rates — T4.
- No multi-currency FX revaluation within Wet Fido (single-currency EUR scope).
- No regulatory filing automation to provincie/BZK beyond the quarterly rapportage — T4.

## Risks & Trade-offs

| Risk | Mitigation |
|---|---|
| Treasurystatuut is legally binding per raad/staten/AB besluit; if operator enters wrong signing-mandate matrix, breaches go undetected | Treasurystatuut adopted only after formal raad-approval gate + concerncontroller sign-off; live limiet-checks log all overrides with rationale |
| Kasgeldlimiet rolling 3-month average is complex; daily recomputation may not match Excel baseline treasury-functionaris uses | Spec-level transparency: limiet-headroom widget shows daily rolling-average, components (kasgeld-leningen, rekening-courant, overige korte schuld) with detail drill-down |
| Rente-risiconorm 4-year projection depends on herfinanciering-schema assumptions; if herfinanciering actually spreads different, limit breached post-hoc | Forward-looking 4-year per-year exposure projection visible on `rente-risiconorm` register; quarterly rapportage flags when projection changes materially |
| RUDDO hedging validation requires treasurer to document hedged exposure; off-the-cuff derivatives without documentation refused | Schema-level RUDDOJustification field + linked Lening/KasgeldPositie reference mandatory; any derivaat without link fails save |
| Schatkistbankieren daily sweep via OpenConnector may fail or lag; cash not swept on time = above-drempel exposure | Sweep is idempotent: if it runs twice, netto effect same. Daily job logs all sweeps + failures in audit trail; treasurer alerted on sweep-failure |

## Rollback

Treasury limiet-breaches are non-reversible once recorded and disclosed in the quarterly
rapportage to the toezichthouder. Rollback occurs only if the spec is rejected before any
entity records production treasury data. Once live, corrections are journalised as
amendment-transactions + adjustment-rapportages, not deletions.

## Open Questions

1. **Treasurystatuut approval workflow**: Does raad/staten/AB approval occur via
   decidesk integration (T4) or manual gate in this spec? Recommend decidesk T4.
2. **Lening-overdraft workflow**: When kasgeldlimiet is breached, can treasurer enter
   a lening with explicit override? If so, does override require controller co-sign?
   Recommend override with audit-log, flagged in next quarterly rapportage.
3. **Herfinanciering-schema source**: Manual entry per lening, or bulk import from
   bank-supplied amortization schedules? Recommend manual entry v1, bank-import
   connector T4.

## Dependencies

- **bookkeeping-programmabegroting**: Supplies the vastgestelde begroting that
  anchors the kasgeldlimiet & rente-risiconorm percentages.
- **bookkeeping-schatkistbankieren**: Operates the daily sweep to AGT; this spec
  adds limiet-enforcement & daily headroom query.
- **bookkeeping-bbv-compliance**: BBV-paspoort metadata for treasury GL posting
  (uitzetting > 1 jaar vs <= 1 jaar classification).
- **bookkeeping-jaarrekening-publication**: Treasury-paragraph consumed by jaarrekening
  notes-generation.

## Success Criteria

- Treasurer can enter a new lening, and the system immediately tests it against
  the live kasgeldlimiet + rente-risiconorm + Treasurystatuut signingMandates;
  override + rationale is required if any limit is breached.
- Derivaat entry is refused unless RUDDOJustification links to an existing Lening
  and demonstrates hedging-only purpose per Article 2 RUDDO.
- Live Treasury Dashboard widget shows kasgeld-headroom (days until ceiling hit),
  rente-risico-headroom per future year, schatkist-saldo, and any open alerts,
  with drill-down to underlying transactions.
- Quarterly rapportage is auto-generated within 10 working days after quarter-end,
  signed by treasurer + concerncontroller, and auto-transmitted to toezichthouder
  (provincie voor gemeente, ministerie BZK voor provincie/waterschap) with digital
  receipt.
- Treasury-paragraph is auto-populated in jaarrekening without manual spreadsheet work.
- Audit trail on all leningen, derivaten, overrides, and rapportage sign-offs visible
  for external accountant review.
