---
status: done
---

# Spec: bookkeeping-vpb-corporate-tax

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (MKB / innovation — Vpb)
**Depends on:** bookkeeping-bbv-compliance, bookkeeping-market-government-separation

## Purpose

This specification defines the requirements for bookkeeping vpb corporate tax in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude pure backend/compliance: VPB corporate tax — not browser-testable


### REQ-VPB-001: The system SHALL declare a `vpbPligtig` flag on `Account` distinguishing Vpb-pligtig accounts

The system SHALL satisfy this requirement: The system SHALL declare a `vpbPligtig` flag on `Account` distinguishing Vpb-pligtig accounts.

Per Wet modernisering Vpb-plicht (2016), municipal
ondernemingsactiviteiten and certain stichtingen / verenigingen
are Vpb-pligtig. The `Account` schema MUST gain a
`vpbPligtig: boolean` field (default `false`). When `true`,
postings against the account MUST contribute to the Vpb-balans
(REQ-VPB-003). Per ADR-031, the flag MUST be schema metadata —
no parallel `VpbAccount` register.

#### Scenario: A Vpb-pligtig account contributes to the Vpb-balans

- **GIVEN** an account with `vpbPligtig: true`
- **WHEN** a posting against the account is booked
- **THEN** the posting MUST appear in the Vpb-balans aggregation
  (REQ-VPB-003).

### REQ-VPB-002: The system SHALL declare a `VpbBalansLink` overlay tying ondernemingsactiviteit cost-centers to Vpb-pligtig accounts

A `VpbBalansLink` register MUST declare records with fields:
`costCenterId` (FK to `CostCenter` with
`ondernemingsActiviteit: true`), `accountNumbers` (array of
`Account.accountNumber` strings — the Vpb-pligtige accounts
belonging to this ondernemingsactiviteit), `vpbPligtigVanaf`
(date — the start date of Vpb-pligtigheid for this cluster).
Per ADR-031 this is a schema overlay — no Vpb service.

#### Scenario: An ondernemingsactiviteit is linked to Vpb-pligtige accounts

- **GIVEN** a cost-center with `ondernemingsActiviteit: true`
- **WHEN** a `VpbBalansLink` carrying that cost-center + a list
  of 5 accountNumbers is stored
- **THEN** the save MUST succeed; **AND** the Vpb-balans per
  ondernemingsactiviteit MUST group these 5 accounts.

### REQ-VPB-003: The system SHALL produce a Vpb-balans per ondernemingsactiviteit as a declarative aggregation

The Vpb-balans MUST be an `x-openregister-aggregations`
declaration (the output surface SHOULD carry a schema.org type
of `schema:Dataset`, e.g. as `VpbBalansFiltered`) filtering
`GLLine` on (`accountNumber IN VpbBalansLink.accountNumbers`
AND `periodId IN fiscalYearPeriods`) and grouping per
`costCenterId`, producing the balans column (Activa / Passiva /
Resultaat) per ondernemingsactiviteit. Per ADR-031, no PHP
Vpb-balans service.

#### Scenario: Vpb-balans matches the Vpb-pligtige postings

- **GIVEN** an ondernemingsactiviteit with 5 Vpb-pligtige
  accounts + €50 000 saldo over the closed year
- **WHEN** the Vpb-balans aggregation runs
- **THEN** the result MUST surface €50 000 as net result;
  **AND** Activa and Passiva columns MUST be in balance (per
  T1's balance invariant REQ-GL-005).

### REQ-VPB-004: The system SHALL produce a Vpb-aangifte voorbereiding document via docudesk

The Vpb-aangifte voorbereiding MUST be generated as a docudesk
document, populated from the Vpb-balans aggregation. The actual
aangifte transmission MUST ride the SBR path from T4-base
`bookkeeping-sbr-xbrl-reporting` (Belastingdienst SBR endpoint).
Shillinq MUST declare the docudesk template reference + SBR
payload binding; no PHP Vpb-aangifte service.

#### Scenario: Vpb-aangifte voorbereiding refers to the Vpb-balans

- **GIVEN** a closed fiscal year with Vpb-balans aggregation
  output
- **WHEN** the operator triggers the Vpb-aangifte voorbereiding
- **THEN** a docudesk document MUST appear with the Vpb-balans
  fields filled in; **AND** the SBR payload MUST validate
  against the Belastingdienst Vpb XSD.

### REQ-VPB-005: Vpb administration SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST declare a feature-flag-controlled menu
entry (`featureFlags.mkb-vpb`) under
`Bookkeeping > Vennootschapsbelasting` with `type: index` for
the Vpb-pligtige cost-centers / accounts + `type: detail` for
the Vpb-balans + aangifte voorbereiding per
ondernemingsactiviteit. Per ADR-024 Tier-4, no bespoke Vue
files.

#### Scenario: Vpb menu toggles with the feature flag

- **GIVEN** the manifest declares `featureFlags.mkb-vpb`
- **WHEN** the flag is ON
- **THEN** the Vpb menu MUST appear.
- **WHEN** the flag is OFF
- **THEN** the menu MUST NOT render.
