# Specification: bookkeeping-consolidation-commercial

| Property | Value |
|----------|-------|
| **Status** | proposed |
| **Scope** | shillinq |
| **Tier** | T3 (regulatory + compliance) |
| **App** | shillinq |
| **Depends on** | bookkeeping-multi-administratie, bookkeeping-intercompany-elimination, bookkeeping-financial-statements |
| **Kind** | config (10 new registers, lifecycle + aggregation automation) |

## Overview

This specification defines the **commercial consolidation (RJ 217 / IAS 27 / IFRS 10)** capability for Shillinq. It enables Dutch MKB holding companies (holding + werkmaatschappijen, the classic BV-BV-structuur) to consolidate multi-entity financial statements into a single group-level view per the wettelijke consolidatieplicht (Titel 9 Boek 2 BW, art. 2:406), eliminating internal transactions, revaluing foreign currencies, adjusting for goodwill acquisitions, and splitting results between moeder-aandeelhouders and minderheidsbelangen.

The capability declares ten registers — `ConsolidationGroup`, `GroupEntity`, `IntercompanyRelation`, `ConsolidationPeriod`, `EliminationEntry`, `TranslationAdjustment`, `MinorityInterest`, `Goodwill`, `ConsolidatedBalance`, `ConsolidatedIncomeStatement` — in the modular register fragment `lib/Settings/register.d/bookkeeping-consolidation-commercial.json` (ADR-037; the monolith `shillinq_register.json` is never edited). All quantitative logic is declarative per ADR-031 (`x-openregister-aggregations` / `x-openregister-calculations`); no `ConsolidationCalculator.php` / `EliminationMatcher.php` service is authored. Cross-field completeness and arithmetic-equation preconditions that the declarative DSL cannot yet express are delegated to `OCA\Shillinq\Lifecycle\ConsolidationGuard` (ADR-031 exception path), referenced from the schema lifecycle transitions. A person (executor, reviewer, accountant) is a Nextcloud user-directory entity — never an invented schema (ADR-022).

## Requirements

### REQ-CONS-001: Consolidatiegroep-definitie

**Title:** Define a consolidation group with parent + subsidiaries, ownership and method.

**Description:**
The system MUST allow a user (typically accountant or controller) to create a consolidation group by selecting the parent administration, adding one or more subsidiary administrations with eigendomspercentage and consolidatiemethode, and recording reporting parameters (reporting currency, reporting framework RJ217/IFRS10, uniform fiscal-year end). The consolidation method per entity MUST be consistent with the ownership percentage (integral for controlling >50%, proportional for a ~50% joint venture, equity for a <50% associate; RJ 217 §4-7 / IFRS 10 §19). Divergent fiscal years between entities MUST be flagged for harmonisation.

#### Scenario: Create a 100%-subsidiary group

**GIVEN:**
- Three administrations exist in Shillinq (Holding BV, Werk BV, Vastgoed BV).

**WHEN:**
- The user creates consolidation group "Acme Group" with Holding BV as moeder, Werk BV (100%) and Vastgoed BV (100%) as dochters, EUR as reporting currency, RJ217 as framework.

**THEN:**
- The group is created (`state=active` after activation), all three entities are linked with consolidationMethod `integral`, and the group overview shows three entity blocks under the parent.

#### Scenario: Joint-venture entity uses the equity method

**GIVEN:**
- The user defines a group with a joint-venture subsidiary (50% belang).

**WHEN:**
- The user sets ownershipPercentage to 50 and consolidationMethod to `equity`.

**THEN:**
- The system registers it as a geassocieerde deelneming; consolidation includes only the proportional result and the equity-method boekwaarde, not the individual balansposten. `ConsolidationGuard::canActivateEntity` enforces method/ownership consistency.

#### Scenario: Divergent fiscal year is flagged

**GIVEN:**
- The user adds a subsidiary whose boekjaar diverges from the moeder (gebroken vs kalender).

**WHEN:**
- The user saves the group.

**THEN:**
- The system warns that boekjaren must be harmonised (interim figures per moeder-eindedatum) before consolidation can run.

### REQ-CONS-002: Aggregatie per-administratie balans + V&W

**Title:** Aggregate per-administration balance and P&L into a pre-elimination werkpapier.

**Description:**
For a chosen consolidation period the system MUST fetch the individual balances and winst-en-verliesrekeningen of all active group entities (`firstConsolidationDate ≤ periodEnd`), harmonise to the reporting currency and the group RGS chart, and aggregate to a pre-elimination total (a classic consolidation werkpapier with a column per entity plus a subtotal). The `ConsolidationPeriod.totalEliminationCount` and `totalEliminationAmount` are declarative aggregations over `EliminationEntry`. The consolidated balance MUST satisfy `totalAssets = totalLiabilities + totalEquity`.

#### Scenario: Three EUR entities aggregate to a subtotal

**GIVEN:**
- "Acme Group" with three entities, all in EUR; consolidation requested for 2025.

**WHEN:**
- The system aggregates.

**THEN:**
- A werkpapier with four columns (Holding BV, Werk BV, Vastgoed BV, Subtotaal-pre-eliminatie) is shown; each row is an RGS-rapportageregel; the subtotal equals the sum of the three entity columns.

#### Scenario: Divergent chart of accounts is mapped

**GIVEN:**
- A subsidiary uses local account 4310 (telefoonkosten) vs group account 6210.

**WHEN:**
- Aggregation runs.

**THEN:**
- 4310 maps to 6210 via the per-entity mapping table; unknown accounts are logged in an exception queue.

### REQ-CONS-003: Eliminatie intercompany sales en kostprijs

**Title:** Detect and eliminate intercompany trade transactions with a matching tolerance.

**Description:**
The system MUST detect intercompany trade transactions per `IntercompanyRelation` (debtorEntity, creditorEntity, transactionType) and eliminate the intercompany-omzet against the intercompany-inkoop so the consolidated V&W shows only external transactions. Differences within the matching tolerance (default €10 absolute or 0.5% relative) are eliminated; out-of-tolerance differences are queued in `ConsolidationPeriod.mismatches` for manual accountant resolution. Each generated `EliminationEntry` MUST carry balanced debit/credit lines.

#### Scenario: Matched intercompany sales eliminate cleanly

**GIVEN:**
- Werk BV booked €100,000 sales to Holding BV on account 8200; Holding BV booked €100,000 purchases from Werk BV on account 7200.

**WHEN:**
- Consolidation runs.

**THEN:**
- The system generates an elimination: debit 8200 €100,000, credit 7200 €100,000. Consolidated revenue and purchases each drop by €100,000.

#### Scenario: Out-of-tolerance mismatch queues for review

**GIVEN:**
- Werk BV booked €100,000 but Holding BV booked €99,500 (€500 difference).

**WHEN:**
- Consolidation runs.

**THEN:**
- The system detects the €500 mismatch, compares it to the tolerance (€10 / 0.5%), classifies it as out-of-tolerance, and places it in the exception queue (`status=pending`). The period cannot move to review while a mismatch is pending (`ConsolidationGuard::canSubmitForReview`).

#### Scenario: Margin-in-inventory elimination

**GIVEN:**
- Werk BV sold €100k with €20k margin to Holding BV; €60k still in Holding BV inventory.

**WHEN:**
- Consolidation runs.

**THEN:**
- The system detects unrealised intercompany profit (60% × €20k = €12k) and books an additional elimination: debit kostprijs €12k, credit voorraad €12k.

### REQ-CONS-004: Eliminatie intercompany vorderingen en schulden

**Title:** Eliminate intercompany receivables, payables, loans and dividends.

**Description:**
The system MUST offset intercompany balansposten (vorderingen op groepsmaatschappijen vs schulden aan groepsmaatschappijen) so consolidated debiteuren and crediteuren contain only external parties, and MUST eliminate intercompany loans (principal + interest) and intercompany dividend.

#### Scenario: Intercompany AR/AP elimination

**GIVEN:**
- Werk BV holds a €25,000 receivable on Holding BV (account 1310); Holding BV holds a €25,000 payable to Werk BV (account 1610).

**WHEN:**
- Consolidation runs.

**THEN:**
- The system generates: debit 1610 €25,000, credit 1310 €25,000. No intercompany receivables/payables remain on the consolidated balance.

#### Scenario: Intercompany loan and interest elimination

**GIVEN:**
- Holding BV lent €500,000 to Werk BV at 5%; Werk BV booked €25,000 interest expense for 2025.

**WHEN:**
- Consolidation runs.

**THEN:**
- The system eliminates both principal (debit schuld €500k, credit vordering €500k) and interest (debit rentebaten €25k, credit rentekosten €25k); consolidated result is unchanged on balance.

### REQ-CONS-005: Currency translation en CTA

**Title:** Translate foreign-currency administrations by the current-rate method with CTA.

**Description:**
The system MUST translate vreemde-valuta administrations to the reporting currency per the current-rate method (RJ 122 / IAS 21): balansposten at slotkoers, V&W-posten at gemiddelde koers, eigen-vermogen at historical rate, with the saldoverschil booked as Cumulative Translation Adjustment (CTA) in eigen vermogen via a `TranslationAdjustment` record. CTA is non-recycling until desinvestering, when the cumulative CTA recycles to the V&W. The CTA component MUST be posted to OCI/eigen vermogen, never to the P&L.

#### Scenario: USD subsidiary produces a CTA

**GIVEN:**
- A US subsidiary with a USD administration; slotkoers USD/EUR 0.92, gemiddelde koers 0.93, openingskoers 0.94.

**WHEN:**
- Consolidation runs.

**THEN:**
- Balansposten translate at 0.92, V&W at 0.93; the difference between the translated EV via balans and via V&W roll-forward is booked as CTA under eigen vermogen, recorded in a `TranslationAdjustment` with `translationMethod=currentRate`.

#### Scenario: CTA recycles on desinvestering

**GIVEN:**
- The same subsidiary is sold in 2026.

**WHEN:**
- The entity transitions to `desinvested`.

**THEN:**
- The cumulative CTA recycles to the V&W (taken out of OCI).

### REQ-CONS-006: Non-controlling interest (minderheidsbelang)

**Title:** Compute and present the minority interest for subsidiaries with ownership <100%.

**Description:**
For subsidiaries where the group holds <100%, the system MUST compute the aandeel derden (minority/non-controlling interest) and present it separately in both the consolidated balance (a component of eigen vermogen) and the V&W (a line under nettowinst). The `MinorityInterest` register rolls `closingBalance = openingBalance + periodResultShare − dividendToMinority` (declarative calculation). The income-statement split `netProfitTotal = netProfitAttributedToParent + netProfitAttributedToMinority` MUST reconcile (`ConsolidationGuard::canFinalizeIncomeStatement`).

#### Scenario: 70/30 subsidiary splits the result

**GIVEN:**
- A subsidiary in which the group holds 70% (30% derden); subsidiary net profit 2025 is €100,000.

**WHEN:**
- Consolidation runs.

**THEN:**
- The consolidated result shows the full €100k, then two lines: "Toe te rekenen aan aandeelhouders moeder: €70k" and "Toe te rekenen aan minderheidsbelang: €30k". The balance shows €30k aandeel-derden as a separate EV component.

### REQ-CONS-007: Acquisitie-accounting (goodwill, badwill)

**Title:** Recognise goodwill/badwill on first consolidation of an acquired subsidiary.

**Description:**
On first consolidation of a newly acquired subsidiary, the system MUST compare the purchase price with the fair value of the acquired net assets and recognise the difference as goodwill (positive) or badwill (negative) per RJ 216 / IFRS 3. `Goodwill.goodwillAmount = purchasePrice − fairValueNetAssetsAcquired` is a declarative calculation. The amortisation treatment is gated by `ConsolidationGroup.reportingFramework`: RJ linear (default 10 years, max 20 with onderbouwing) vs IFRS annual impairment (IAS 36).

#### Scenario: Goodwill on a RJ acquisition

**GIVEN:**
- Holding BV buys 100% of Target BV for €1,500,000 on 2025-07-01; fair value of identifiable net assets is €1,000,000; reportingFramework RJ217.

**WHEN:**
- First consolidation of Target BV runs.

**THEN:**
- The system computes goodwill €500,000, capitalises it under immateriële vaste activa, and starts a 10-year linear amortisation (`amortizationMethod=RJ-linear-10yr`).

#### Scenario: Badwill is booked to the P&L

**GIVEN:**
- The same acquisition but purchase price €800k and fair value €1,000k.

**WHEN:**
- First consolidation runs.

**THEN:**
- The system computes badwill −€200k and books it per RJ 216 directly as a bate in the verwervingsjaar V&W (after fair-value re-assessment).

### REQ-CONS-008: Eliminatie-audit-trail en accountant-review

**Title:** Maintain a full audit trail per elimination and an accountant review workflow.

**Description:**
The system MUST keep a full audit trail per generated or manual `EliminationEntry` (who/when/why) and allow accountants to review, approve or reject each elimination, with comments recorded in the permanent dossier. Approval requires a recorded reviewer and balanced lines (`ConsolidationGuard::canApproveElimination`); rejection requires a reviewer and a rationale (`canRejectElimination`). A `ConsolidationPeriod` may close only once every elimination is approved (`canClosePeriod`); the closed snapshot is immutable for 7+ years (art. 2:404-405 BW). A reversal is a new entry, never an overwrite.

#### Scenario: Accountant reviews 47 eliminations

**GIVEN:**
- Consolidation generated 47 elimination bookings for 2025.

**WHEN:**
- The accountant opens the werkpapier.

**THEN:**
- Each elimination shows type, clickable source-transactions, debit/credit lines, generatedBy, generatedAt (timestamp), reviewStatus and reviewComment.

#### Scenario: Rejected elimination is backed out

**GIVEN:**
- The accountant finds an elimination suspect.

**WHEN:**
- They reject it with rationale "intercompany classificatie onjuist, dit was externe sale" (reviewedBy + reviewComment set).

**THEN:**
- The system marks it `rejected`, backs it out of the consolidation, and logs the change. The period cannot close while it is not approved.

#### Scenario: Closed snapshot is immutable

**GIVEN:**
- The accountant has approved every elimination and signed off.

**WHEN:**
- They set the period to `closed`.

**THEN:**
- The system snapshots the consolidation and archives it immutably for 7+ years.

### REQ-CONS-009: Comparatieve periodes en herclassificatie

**Title:** Present comparative figures and reclassify the prior year on group changes.

**Description:**
The system MUST present consolidated figures comparatively (current + prior year) and, on reclassification or stelselwijziging, adjust the comparative figures with an explicit notice. When a subsidiary joins the circle mid-year, the system MUST consolidate it pro-rata from acquisition date and disclose the wijziging in groepssamenstelling.

#### Scenario: Two-column comparative balance

**GIVEN:**
- Consolidation for 2025 is generated.

**WHEN:**
- The user views the consolidated balance.

**THEN:**
- Each rapportageregel shows both 2025 and 2024 (`comparativePriorYear`); a variance column (€ and %) is optionally visible.

#### Scenario: Mid-year acquisition consolidates pro-rata

**GIVEN:**
- A new subsidiary is added mid-2025 (`firstConsolidationDate` within the year).

**WHEN:**
- Consolidation runs.

**THEN:**
- The subsidiary is consolidated pro-rata from acquisition date; the toelichting reports the change in group composition.

### REQ-CONS-010: Geconsolideerde toelichting en uitsplitsing

**Title:** Generate the consolidated toelichting with the legally required disclosures.

**Description:**
The system MUST generate a consolidated toelichting (financial-statement notes) with the wettelijk verplichte uitsplitsingen: consolidatiegrondslag, list of groepsmaatschappijen, verloop eigen vermogen (incl. minority interest and CTA), goodwill-verloop, and intercompany-eliminaties per category. The notes are derived from `ConsolidatedBalance` + `ConsolidatedIncomeStatement` + metadata.

#### Scenario: Standard toelichting paragraphs

**GIVEN:**
- The consolidated jaarrekening 2025 is generated.

**WHEN:**
- The toelichting is composed.

**THEN:**
- Standard paragraphs are generated for: (1) consolidatiegrondslag (RJ217/IFRS10), (2) list of consolidated maatschappijen (name/zetel/belang/method/functional currency/first-consolidation), (3) verloop eigen vermogen matrix (geplaatst kapitaal, agio, reserves, herwaardering, CTA, onverdeeld resultaat, aandeel derden), (4) verloop goodwill (beginstand, acquisities, afschrijvingen/impairments, eindstand), (5) intercompany-eliminaties per category.

#### Scenario: Foreign-currency paragraph

**GIVEN:**
- The group has foreign-currency subsidiaries.

**WHEN:**
- The toelichting is generated.

**THEN:**
- A "Vreemde valuta" paragraph lists the rates applied (closing/average/historical), the year's CTA movement and the cumulative CTA balance per balansdatum.

## Standards & Sources

- **Burgerlijk Wetboek Boek 2 Titel 9** — consolidatieplicht (art. 2:406), vrijstellingen (2:407, 2:408, 2:403), presentatie-eisen, bewaarplicht (2:404-405).
- **RJ 217 Geconsolideerde Jaarrekening** — Nederlandse commerciële norm; integraal/proportioneel/equity, eliminatie-regels, goodwill.
- **RJ 216 Fusies en Overnames** — purchase-accounting, fair value, goodwill/badwill.
- **RJ 122 Prijsgrondslagen voor vreemde valuta** — current-rate methode, CTA.
- **IAS 27 / IFRS 10 / IFRS 3 / IAS 21 / IAS 36** — IFRS counterparts for groups reporting under IFRS.

## ADR Conformance

- **ADR-037** — schemas + seed objects live in `lib/Settings/register.d/bookkeeping-consolidation-commercial.json`; the monolith `shillinq_register.json` is never edited.
- **ADR-031** — quantitative logic is declarative (`x-openregister-aggregations` / `x-openregister-calculations`); cross-field/equation preconditions delegated to `ConsolidationGuard` (documented exception path).
- **ADR-022** — object reads use the real OpenRegister ObjectService API (`setRegister`/`setSchema`/`findAll`); a person is an NC user-directory entity; the existing Administration register is reused for per-entity GL.
