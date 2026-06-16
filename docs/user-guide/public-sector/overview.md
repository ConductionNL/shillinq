---
sidebar_position: 1
title: Public sector accounting
description: BBV compliance, IV3/SISA reporting, EMU-rapportage, waterschapsbelastingen, BCF claims, and treasury for Dutch government organisations in Shillinq.
---

# Public sector accounting

Shillinq includes a full suite of **Dutch public-sector accounting** modules for municipalities (*gemeenten*), water authorities (*waterschappen*), provinces (*provincies*), and other public-law organisations. These modules implement the statutory reporting requirements of the **BBV** (Besluit Begroting en Verantwoording) and related regulations.

## BBV compliance

The **BBV** is the Dutch framework for municipal budgeting and accounting. Shillinq implements:

- **BBV mapping** — maps your GL accounts to the BBV-mandated account structure
- **Meerjarenraming** — multi-year financial estimates (4-year horizon)
- **Programmaplan** — programme-based budget view (linking activities to outcomes)
- **Reserves & voorzieningen** — manage statutory reserves and provisions
- **Rechtmatigheid** — compliance declarations and evidence
- **Paragrafen** — the statutory narrative paragraphs in the annual accounts

Go to **Public sector → BBV mapping** to configure the account-to-BBV mapping.

## IV3 rapportages

**IV3** (*Informatie voor Derden*) is the mandatory quarterly statistical report that municipalities submit to the CBS (Statistics Netherlands). Shillinq generates the IV3 XML submission directly from the general ledger.

Go to **Public sector → IV3 aanlevering** to prepare and submit the quarterly report.

## SISA rapportages

**SISA** (*Single Information Single Audit*) is the annual accountability for specific grants from the central government. Each SISA indicator maps to a grant condition. Shillinq tracks the actual expenditure per indicator and generates the SISA attachment for the annual accounts.

## EMU-rapportage

The **EMU-rapportage** shows the municipality's contribution to the Dutch EMU balance (Maastricht deficit criterion). Shillinq calculates this from the balance sheet movements and provides the report in the format required by the Ministry of Finance.

## Gemeenschappelijke regelingen (GR)

The **GR** module supports consolidated reporting across joint public-law bodies:
- **Deelnemers** — the participating organisations in the GR
- **Verdeelsleutels** — the contribution keys (how costs and services are split)
- **Geconsolideerde view** — the consolidated financial position across all participants

## BCF claims (BTW compensatiefonds)

Dutch municipalities can reclaim non-reclaimable VAT via the **BCF** (BTW Compensatiefonds). Go to **Public sector → BCF claims** to see eligible transactions, prepare the claim, and submit to the Belastingdienst.

## Waterschapsbelasting

Dutch water authorities levy the **waterschapsbelasting** (water board tax). Shillinq handles:
- **Heffingen** — recording tax levies due
- **Inning** — tracking collections
- **Kwijtschelding** — means-tested remissions

Go to **Bookkeeping → Waterschapsbelastingen** to manage the levy postings.

## Schatkistbankieren

The **schatkistbankieren** (treasury banking) module tracks balances held at the Dutch National Government Cashier (*Rijksbetalingsverkeer*) — mandatory for most municipalities and water authorities.

Go to **Public sector → Schatkist positie** for the real-time treasury position.

## Subsidies

The **Subsidies** section tracks grants received from the central government, EU, or provinces:
- **Overzicht** — all active and historical grants
- **Aanvragen** — open grant applications
- **Verleend** — approved grants
- **Teruggevorderd** — repayment claims

## Related

- [Chart of accounts](../bookkeeping/chart-of-accounts.md) — configure BBV GL account mapping
- [Balance sheet](../bookkeeping/balance-sheet.md) — the BBV-compliant balance sheet view
- [Tax (BTW/VAT)](../tax/btw-vat.md) — BCF connects to the VAT module
