---
status: done
---

# Spec: bookkeeping-titel-9-jaarrekening

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T3 (intermediate bookkeeping engine)  
**Depends on:** bookkeeping-general-ledger (T1), bookkeeping-financial-statements (T1 output), bookkeeping-sbr-xbrl-reporting (T3)

---

## Purpose

This specification defines the requirements for bookkeeping titel 9 jaarrekening in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude pure backend/compliance: Titel 9 jaarrekening — not browser-testable


### REQ-T9-001: The system SHALL automatically determine groottecategorie per art. 2:395a–398 BW

The system MUST classify a commercial entity into one of four size categories (micro, klein, middelgroot, groot) based on the two-of-three criterion applied over two consecutive fiscal years: balanstotaal (balance sheet total), netto-omzet (net revenue), and gemiddeld aantal werknemers (average number of employees). Classification determines which jaarrekening sections are mandatory (e.g., micro files only verkorte balans; middelgroot+ files kasstroomoverzicht + bestuursverslag).

#### Scenario: Micro entity with all criteria below thresholds

- **GIVEN** a BV with 2024 and 2025 cijfers: balanstotaal 2024 €380k and 2025 €420k, netto-omzet 2024 €750k and 2025 €820k, gemiddeld 6 werknemers in beide jaren
- **WHEN** jaarrekening 2025 is created
- **THEN** the system classifies as "micro" (all three criteria under micro-grens €450k/€900k/10 in both years), displays classification with supporting numerics, and activates the micro-template (verkorte balans only, no V&W publicatie, no bestuursverslag, no accountant requirement).

#### Scenario: Entity at Klein-Middelgroot boundary with two-year rule

- **GIVEN** a BV with balanstotaal €15 miljoen, omzet €30 miljoen, 80 werknemers (exceeds klein €12m/€24m/50 but below middelgroot €25m/€50m/250)
- **WHEN** jaarrekening is created
- **THEN** the system classifies as "klein" for 2025 if 2024 was also klein; if 2024 was micro and 2025 is klein-range, the system keeps micro status (two-year rule) and warns operator that status will change in 2026 if criteria remain in klein-range.

#### Scenario: Boundary-crossing detection with warning

- **GIVEN** a BV with grenswaarde-cijfers transitioning from klein to middelgroot between year 1 and year 2
- **WHEN** classification logic runs
- **THEN** the system displays the classification with two-year rule explanation: "Groottecategorie changes to middelgroot only after TWO consecutive years in new range; status remains klein for 2025."

---

### REQ-T9-002: The system SHALL generate a Balans (balance sheet) conforming to art. 2:373 BW with wettelijke rubrieken and comparatief cijfers

The BalanceSheet entity MUST be generated from GL aggregations with the exact rubriek structure mandated by art. 2:373 BW (Titel 9 art. 2:373): activa side split into vaste activa (immateriële B.I, materiële B.II, financiële B.III) and vlottende activa (voorraden C.I, vorderingen C.II, effecten C.III, liquide middelen C.IV); passiva side split into eigen vermogen A, voorzieningen B, langlopende schulden C, kortlopende schulden D. Each rubriek line includes current-year amount, prior-year amount, and footnote references to linked toelichting-paragrafen.

#### Scenario: Balans generation from administered GL with correct totals

- **GIVEN** administratie with materiële vaste activa €450k, immateriële €120k, vorderingen €180k, liquide middelen €95k, eigen vermogen €380k, voorzieningen €45k, langlopende schulden €280k, kortlopende schulden €140k
- **WHEN** balans 2025 is generated
- **THEN** the system produces structured balans with rubrieken B.I (immateriële) €120k, B.II (materiële) €450k, B.III (financiële) €0 = totaal vaste activa €570k; C.I (voorraden) €0, C.II (vorderingen) €180k, C.III (effecten) €0, C.IV (liquide middelen) €95k = totaal vlottende activa €275k; Totaal activa €845k. Passiva: A (eigen vermogen) €380k, B (voorzieningen) €45k, C (langlopende schulden) €280k, D (kortlopende schulden) €140k = Totaal passiva €845k. Balans balances (activa = passiva).

#### Scenario: Balans with automatic toelichting linkage

- **GIVEN** balans rubriek "C.II Vorderingen" comprises subcategories (handelsdebiteuren €80k, intercompany €60k, overige €40k)
- **WHEN** balans is generated
- **THEN** the system displays the main rubriek C.II €180k in balans and automatically links a toelichting-paragraaf "Vorderingen" with detailed breakdown per subcategory conforming to art. 2:381 BW disclosure requirements.

#### Scenario: Comparatief balans (current year vs. prior year)

- **GIVEN** user requests comparatief view of 2025 vs. 2024 balans
- **WHEN** balans is rendered
- **THEN** each rubriek displays huidig jaar (2025) amount and vorig jaar (2024) amount side-by-side, with optional verschil (delta) column. Prior-year amounts come automatically from the prior jaarrekening (if available) or from the opening balans of 2025.

---

### REQ-T9-003: The system SHALL generate a Winst-en-Verliesrekening (P&L) using model A (categorisch) or E (functioneel), per art. 2:377 BW

The IncomeStatement entity MUST be generated according to the operator-selected model: Model A "categorisch" (art. 2:377 lid 2) groups costs by type (grond- en hulfstoffen, lonen, sociale lasten, afschrijvingen, etc.); Model E "functioneel" (lid 3) groups costs by function (kostprijs verkopen, verkoop- en distributiekosten, algemene beheerskosten). The model choice must be consistent year-on-year (stelselwijziging — change of accounting policy — is a disclosure requirement). All rubrieken follow art. 2:377 ordering; subtotals (bedrijfsresultaat, resultaat voor belasting) are calculated automatically.

#### Scenario: Model A V&W generation with correct subtotals

- **GIVEN** administratie selects model A "categorisch" with omzet €1.500k, mutatie voorraden €+50k, geactiveerde productie €0, overige bedrijfsopbrengsten €25k; kosten grond-/hulfstoffen €420k, lonen €380k, sociale lasten/pensioenen €95k, afschrijvingen €80k, overige bedrijfskosten €250k; rentebaten €4k, rentelasten €18k; belastingen €70k
- **WHEN** V&W is generated
- **THEN** the system produces: (1) Netto-omzet €1.500k, (2) Wijziging voorraden €50k, (3) Geactiveerde productie €0, (4) Overige bedrijfsopbrengsten €25k; **Subtotaal bedrijfsopbrengsten** €1.575k. (5) Kosten grond-/hulfstoffen €420k, (6) Lonen €380k, (7) Sociale lasten €95k, (8) Afschrijvingen €80k, (9) Overige bedrijfskosten €250k; **Subtotaal bedrijfslasten** €1.225k. **Bedrijfsresultaat** €350k. (10) Rentebaten €4k, (11) Rentelasten €(18k). **Resultaat voor belasting** €336k. (12) Belastingen €(70k). **Nettoresultaat** €266k.

#### Scenario: Model E V&W generation with functional grouping

- **GIVEN** administratie selects model E "functioneel"
- **WHEN** V&W is generated
- **THEN** the system groups kosten naar functie (kostprijs verkopen, verkoop- en distributiekosten, algemene beheerskosten) instead of naar categorie, with bruto-marge-intermediate-total after kostprijs verkopen, maintaining all required subtotals and nettoresultaat alignment with model A.

#### Scenario: Model change triggers stelselwijziging warning

- **GIVEN** administratie selects model E after previously using model A for 2024
- **WHEN** model is changed mid-year
- **THEN** the system warns "Consistente modeltoepassing is wettelijk verplicht (stelselwijziging)"; system requires a motivatie (reason) for the change and REQUIRES that prior-year (2024) V&W be re-rendered in new model E format so comparatief cijfers are consistent.

---

### REQ-T9-004: The system SHALL automatically generate wettelijk verplichte toelichting-paragrafen based on groottecategorie and GL content

The Note entity represents an individual toelichting-paragraaf (accounting note). The system MUST generate all mandatory notes per groottecategorie per RJ guidelines (RJ 210–272, 350, etc.), including: algemene grondslagen (basis of valuation and profit measurement), mutatieoverzicht materiële vaste activa (MVA mutatie-tabel per RJ 212), opbouw eigen vermogen (EV mutation matrix per RJ 240), uitsplitsing en condities schulden (debt schedule per RJ 250), niet-uit-balans-blijkende verplichtingen (off-balance-sheet commitments per art. 2:382), gebeurtenissen na balansdatum (post-balance-sheet events per RJ 160), and for middelgroot+ segmentinformatie (segment reporting per RJ 500-series) and bezoldigingen (director compensation per RJ 271).

#### Scenario: MVA (Materiële Vaste Activa) mutatie-overzicht for middelgrote entity

- **GIVEN** middelgrote BV with materiële vaste activa (gebouwen, machines, inventaris) and applied depreciation methods
- **WHEN** toelichting is generated
- **THEN** the system produces a mutatieoverzicht MVA per RJ 212 with per-category rows: aanschafwaarde (acquisition cost) beginstand, investeringen (additions), desinvesteringen (disposals), afschrijvingen (depreciation), impairments, aanschafwaarde eindstand; cumulatieve afschrijving begin- and eindstand; boekwaarde begin- and eindstand. For each asset category, the depreciation method and annual percentage rates are disclosed.

#### Scenario: Eigen Vermogen (EV) mutation matrix generation

- **GIVEN** BV with onverdeelde winst (retained earnings), statutaire reserves, agioreserve, herwaarderingsreserve (revaluation)
- **WHEN** toelichting eigen vermogen is generated
- **THEN** the system produces a verloopoverzicht (mutation matrix) in tabular form: columns per EV-component (geplaatst kapitaal, agio, herwaardering, wettelijke reserve, overige reserves, onverdeeld resultaat), rows for beginstand, mutaties (result allocation from prior year, dividend distributions, net result current year, revaluations), eindstand. Row sums must equal total equity on balans.

#### Scenario: Schulden (Debt) detailed disclosure with amortization schedules

- **GIVEN** BV with langlopende lening from bank (€200k) with aflossingsschema (amortization schedule) and zekerheden (collateral — hypotheek on property)
- **WHEN** toelichting schulden is generated
- **THEN** the system produces toelichting with per-debt entries: bedrag (€200k), rentevoet (3.5%), einddatum (2035-12-31), aflossingsschema split per maturity (< 1 jaar / 1–5 jaren / > 5 jaren), zekerheden (hypotheek on property valued at €X). Multi-debt table row per loan with all fields populated from administration.

#### Scenario: Micro entity minimal toelichting (no MVA, no EV detail)

- **GIVEN** micro BV (balans < €450k)
- **WHEN** jaarrekening is generated
- **THEN** the system produces minimal toelichting (grondslagen only; no MVA-verloopstaat, no EV-matrix, no debt-schedule detail per RJ 212/240/250); instead, minimal narrative on valuation basis and any exceptions. Micro-template compresses notes per art. 2:396 lid 9 BW.

---

### REQ-T9-005: The system SHALL generate a Kasstroomoverzicht (cash flow statement) per RJ 350 for middelgroot+ entities

CashFlowStatement is mandatory only for middelgrote en grote rechtspersonen (middelgroot+); klein and micro may prepare it voluntarily. The statement MUST use the indirect method (standaard; direct method optional on request, with data-availability warnings). The structure MUST follow RJ 350: three main categories (operationele activiteiten, investeringsactiviteiten, financieringsactiviteiten), each with line items; bottom line reconciles to the change in liquide middelen on the balans.

#### Scenario: Indirect cash flow generation for middelgrote BV

- **GIVEN** middelgrote BV with nettoresultaat €266k, afschrijvingen €80k, mutaties werkkapitaal (vorderingen +€30k, voorraden +€15k, crediteuren −€20k), investeringen €120k, langlopende-lening-aflossing €50k, dividend-uitkering €100k
- **WHEN** kasstroomoverzicht (indirect method) is generated
- **THEN** the system produces: **Operationele kasstroom** = nettoresultaat €266k + afschrijvingen €80k − vorderingen-toename €30k − voorraad-toename €15k − crediteuren-afname €20k = €281k. **Investeringskasstroom** = − investeringen €120k = €(120k). **Financieringskasstroom** = − aflossing €50k − dividend €100k = €(150k). **Netto-mutatie geldmiddelen** = €281k − €120k − €150k = €11k. This matches the balans-liquide-middelen change from 2024 (€85k) to 2025 (€95k) = €10k (within rounding).

#### Scenario: One-off transaction (asset disposal) elimination in cash flow

- **GIVEN** administratie has one-off boekwinst from asset sale: proceeds €200k, boekwaarde €150k, boekwinst €50k
- **WHEN** kasstroomoverzicht is generated
- **THEN** the boekwinst is eliminated from nettoresultaat in the operationele-kasstroom line, and the full €200k disposal proceeds appear as investeringskasstroom (sale of asset), avoiding double-counting.

#### Scenario: Direct method request with data-availability warning

- **GIVEN** user requests kasstroomoverzicht via directe methode (direct method)
- **WHEN** method is switched
- **THEN** the system warns "Directe methode requires detailed cash-receipt and cash-payment data per source (sales collections, vendor payments, salaries, etc.); if rekeningschema-mapping to these categories is unavailable, fallback to indirecte methode" and offers to either set up mapping or revert to indirect.

---

### REQ-T9-006: The system SHALL generate a Bestuursverslag (Director's Report) for middelgroot+ entities per art. 2:391 BW

DirectorReport entity is mandatory for middelgrote+ entities. The template MUST include six required sections per art. 2:391: (1) Algemeen (rechtsvorm, vestiging, activiteiten — auto-populated), (2) Financiële gang van zaken (auto-text with omzet, marge, resultaat YoY comparison + optional auto-charts), (3) Risico's en onzekerheden (template prompts with example categories), (4) Verwachte gang van zaken (toekomstparagraaf — operator-authored), (5) Personeel (gemiddeld aantal werknemers auto-populated from HR/administratie, ziekteverzuim if available), (6) Ondertekening (datum + bestuur-namen auto-populated). Additional optional sections: Onderzoeks- en ontwikkelings (R&D) if R&D costs are capitalized, ESG (milieu, duurzaamheid, sociaal) if entity is groot or CSRD-subject.

#### Scenario: Auto-populated financiële-gang section for middelgrote BV

- **GIVEN** middelgrote BV generates jaarrekening 2025
- **WHEN** bestuursverslag-template is created
- **THEN** section 2 (Financiële gang van zaken) is auto-filled with: omzet 2025 €45M vs. omzet 2024 €40M (delta +12.5%), bruto-marge 2025 35% vs. 2024 33%, EBITDA 2025 €8.5M, netto-resultaat 2025 €2.8M vs. 2024 €2.4M (delta +16.7%). Optional: auto-generated simple bar-chart (omzet, marge, EBITDA trend 3-year).

#### Scenario: R&D disclosure for BV with capitalized development costs

- **GIVEN** BV with R&D-activiteiten en geactiveerde ontwikkelings-kosten totaling €500k
- **WHEN** bestuursverslag is generated
- **THEN** automatically a dedicated R&D-paragraaf is added with: overview of capitalized development costs (€500k), list of active projects and their objectives, statement of sustainable meerwaarde (durability of capitalized value per RJ 210 impairment-risk assessment).

#### Scenario: ESG section for grote rechtspersoon (CSRD-subject)

- **GIVEN** BV classified as groot (balans > €25M) subject to CSRD (Corporate Sustainability Reporting Directive)
- **WHEN** bestuursverslag template is created
- **THEN** an ESG-paragraaf is automatically added with placeholder-velden for ESRS-thema's (E1–E5 milieu, S1–S4 sociaal, G1 governance) and a link to the aparte CSRD-module for detailed reporting; operator may cross-reference or defer ESG content to separate CSRD dossier.

---

### REQ-T9-007: The system SHALL support an accountant-review workflow for middelgroot+ (mandatory) or klein (optional)

The system SHALL satisfy this requirement: The system SHALL support an accountant-review workflow for middelgroot+ (mandatory) or klein (optional).

ReviewWorkflow schema orchestrates the progression: `concept` → `in-review` (accountant review) → `vastgesteld` (AV assembly approval) → `gedeponeerd` (KVK filed). For middelgrote+, an accountantsverklaring (audit opinion or compilation statement) is mandatory; for kleine, optional. During `in-review`, the bestuur cannot edit source data without cancelling the review. Review comments are immutable; changes are logged per issue. Accountant attaches their verklaring (NV-COS 700 controle-verklaring, NV-COS 4410 samenstellingsverklaring, or NV-COS 2400 beoordelingsopdracht).

#### Scenario: Concept jaarrekening submitted to accountant for review

- **GIVEN** middelgrote BV concept-jaarrekening ready; status = "concept"
- **WHEN** bestuur selects "Submit for accountant review"
- **THEN** the system transitions status to `in-review`, creates a ReviewWorkflow record, assigns accountant user(s) by role, grants read-access to GL and all jaarrekening-output data, opens review interface for accountant to place line-by-line or section-level comments (e.g., "Balans rubriek B.II: provide detail on asset disposal €150k").

#### Scenario: Accountant review iteration with change tracking

- **GIVEN** accountant placed 12 review-comments; bestuur processes each comment and makes jaarrekening edits (e.g., additional toelichting disclosure, revised MVA-verloop-table)
- **WHEN** changes are saved
- **THEN** the system logs per review-comment whether status is "verwerkt" (addressed/fixed), "afgewezen met motivatie" (rejected with reason), or "ter discussie" (under discussion). Revision history is immutable; accountant can see all change-log entries linked to their comments.

#### Scenario: Accountant signs off with goedkeurende verklaring

- **GIVEN** accountant is satisfied with all jaarrekening adjustments and review is closed
- **WHEN** accountant authors a goedkeurende controleverklaring (unqualified audit opinion, NV-COS 700 format)
- **THEN** the system renders the verklaring with standard NV-COS 700 text, accountant-naam + RA-nummer (profession registration), signature date, and attaches the verklaring as an immutable document linked to AnnualReport. Status transitions from `in-review` → ready for `vastgesteld`.

#### Scenario: Optional samenstelling-verklaring for kleine BV

- **GIVEN** kleine BV optionally engages samenstel-accountant
- **WHEN** accountant author compilation statement (NV-COS 4410)
- **THEN** the system accepts compilation verklaring (less stringent than controle; no opinion on balans accuracy, only on assembly/presentation conformance) and attaches it; bestuur still must sign off on final jaarrekening per art. 2:391 BW.

---

### REQ-T9-008: The system SHALL convert the final jaarrekening to SBR-XBRL format and support electronic filing at KVK via Digipoort

The system SHALL satisfy this requirement: The system SHALL convert the final jaarrekening to SBR-XBRL format and support electronic filing at KVK via Digipoort.

The jaarrekening, once `vastgesteld` (approved by AV assembly) and ready for `gedeponeerd` status, is converted to SBR-XBRL per the KVK's Nederlandse Taxonomie (NT) entry point (NT16 or later version, selected by groottecategorie: NT-Klein-KVK for klein, NT-Middelgroot-KVK for middelgroot, etc.). The XBRL instance must validate against the entry-point schema (all mandatory contexts and numeric precisions correct, totals balanced, segment reporting consistent). Digipoort submission is automated; status updates (verzonden, ontvangen, formeel verwerkt, openbaar) are captured.

#### Scenario: XBRL generation and validation for klein entity filing

- **GIVEN** jaarrekening 2025 for kleine BV is `vastgesteld` (status approved)
- **WHEN** bestuur selects "Deponeer bij KVK"
- **THEN** the system generates XBRL-instance-document conforming to NT16-Klein-KVK entry-point, validates all mandatory contexts (balans date 2025-12-31, business-unit identifier, KVK-number), checks arithmetic (balans totals match), and displays a preview with line-item mapping (GL account → XBRL tag). System confirms "Ready for submission."

#### Scenario: Digipoort submission with status tracking

- **GIVEN** preview is approved and deponering is initiated
- **WHEN** system submits XBRL via Digipoort API
- **THEN** bestuur receives async status-notifications: (1) "Verzonden naar KVK" (submitted), (2) "Ontvangen" (received by KVK), (3) "Formeel verwerkt" (formal processing completed), (4) "Openbaar gemaakt" (published in public register). KVK ontvangstbevestiging (receipt confirmation) is saved in durable dossier; deponering-datum is recorded.

#### Scenario: XBRL validation error with field-level correction

- **GIVEN** KVK rejects deponering with error: "SBI-code missing" or "Rekenkundig totaal balans inconsistent"
- **WHEN** error-response is received
- **THEN** the system parses error-details, displays user-friendly message ("SBI-code not set on Corporation record; please update via Bedrijfsgegevens"), locates the problematic field, and allows direct correction without re-generating the entire jaarrekening. After correction, re-submit is offered.

---

### REQ-T9-009: The system SHALL apply wettelijke vrijstellingen (relief rules) for kleine entities

The system SHALL satisfy this requirement: The system SHALL apply wettelijke vrijstellingen (relief rules) for kleine entities.

Kleine rechtspersonen (klein category) benefit from verlichte regels (relief): verkorte balans (no detailed subcategory breakdown), beperkte toelichting (no separate V&W disclosure, minimal notes per RJ 210k — the "RJk" light version), no kasstroomoverzicht verplicht, no bestuursverslag verplicht, no accountantsverklaring verplicht (art. 2:396 lid 7–9 BW). Micro entities benefit from even greater relief: only verkorte balans (no V&W, no toelichting, no bestuursverslag).

#### Scenario: Kleine BV klein-template with verkorte balans

- **GIVEN** BV classified as klein
- **WHEN** jaarrekening is generated
- **THEN** the system activates klein-template: verkorte balans with only main rubrieken (no sub-detail), beperkte toelichting (grondslagen + verplichte EV-section + niet-uit-balans-verplichtingen; no MVA-verloopstaat, no V&W-toelichting), no kasstroomoverzicht, no bestuursverslag, no verplichte accountantsverklaring. The file deposited at KVK contains only verkorte balans + beperkte toelichting (V&W is not filed for klein).

#### Scenario: Kleine BV voluntary uitgebreide jaarrekening for financier request

- **GIVEN** kleine BV, but bank (financier) requests full jaarrekening (volledige V&W + full toelichting)
- **WHEN** bestuur selects "Uitgebreide jaarrekening" option
- **THEN** the system generates the uitgebreide version (klein-category groottecategorie rules, but with full V&W, full toelichting, optional kasstroomoverzicht) and offers to share this separately (PDF or portal) while the KVK-deponering still contains only the wettelijk-minimale verkorte balans.

#### Scenario: Micro BV ultra-minimal filing

- **GIVEN** micro BV (balans < €450k, omzet < €900k, <10 employees over 2 years)
- **WHEN** deponering is prepared
- **THEN** the system produces micro-template (balans only, no V&W, no toelichting, no bestuursverslag, no kasstroomoverzicht) and uses micro-XBRL-entry-point (NT16-Micro-KVK or equivalent) for KVK-aanlevering. Micro entity can still voluntarily prepare V&W and full toelichting; only balans is required for filing.

---

### REQ-T9-010: The system SHALL orchestrate the complete jaarrekening workflow with termijn-tracking and audit trail

The ReviewWorkflow and AnnualReport status progression is: `concept` (earliest form, updates as GL changes) → `opgemaakt` (bestuur-signed, immutable snapshot) → `in-review` (if accountant required) → `vastgesteld` (AV-approved, snapshot frozen) → `gedeponeerd` (KVK-filed, immutable final). The system MUST track wettelijke termijnen per art. 2:391 BW: opmaak by bestuur uiterlijk 5 months after fiscal-year-end (extendable by max 5 months to month 10 under request); vaststelling by AV uiterlijk 2 months after opmaak; deponering within 8 days after vaststelling; absolute deadline 12 months after fiscal-year-end. An audit trail (x-openregister-audit-trail-immutable) records every state transition, user, date, and any comments.

#### Scenario: Jaarrekening workflow with termijn-warnings

- **GIVEN** boekjaareinde 31 December 2025
- **WHEN** jaarrekening-workflow for BV starts in February 2026
- **THEN** the system displays wettelijke termijnen: opmaak deadline = 31 May 2026 (5 months), with option to request uitstel to 31 October 2026 (max extension); vaststelling deadline 2 months after opmaak; deponering deadline 8 days after vaststelling; absolute deadline 31 December 2026. Status-bar shows current date, remaining days, and next milestone.

#### Scenario: Real-time concept updates with immutability lock at opmaak

- **GIVEN** concept-jaarrekening is active, bestuur reviewing preliminary balans
- **WHEN** GL lines are corrected (afsluitings-correcties), the concept jaarrekening auto-recalculates balans/V&W totals
- **THEN** the system displays real-time updated figures with banner "CONCEPT — niet vastgesteld; cijfers wijzigen nog". Once bestuur marks "Opgemaakt door bestuur" with signature/timestamp, a snapshot is created; all figures freeze. Any subsequent GL corrections after snapshot trigger a "foutherstel" (post-signature correction) recorded separately with art. 2:389 BW audit trail.

#### Scenario: AV vaststelling with immutable snapshot and deponering activation

- **GIVEN** concept is opgemaakt; bestuur now calls AV assembly, which approves jaarrekening 2025
- **WHEN** bestuur marks "Vastgesteld door AV" and uploads AV-besluit-PDF or records approval-date
- **THEN** the system records vaststelling-datum, creates final immutabel-snapshot of all jaarrekening documents (balans, V&W, toelichting, bestuursverslag, accountantverklaring if attached) with cryptographic hash, transitions status to `vastgesteld`, and activates deponering-button for KVK filing. All fields are now locked (read-only); post-vaststelling changes require an explicit foutherstel-procedure.

---

## MODIFIED Requirements

*No existing entities or specs are modified by this change. All requirements are ADDED.*

---

## Reference Standards

- **Burgerlijk Wetboek Boek 2 Titel 9** — wettelijke basis voor jaarrekening (art. 2:361 e.v.); balans-model art. 2:373; V&W-modellen art. 2:377; toelichting art. 2:381–388; bestuursverslag art. 2:391; openbaarmaking art. 2:394; groottecriteria art. 2:395a–398
- **Raad voor de Jaarverslaggeving (RJ) Guidelines** — RJ 210 (immateriële activa), RJ 212 (materiële activa), RJ 220 (financiële activa), RJ 240 (eigen vermogen), RJ 250 (schulden), RJ 252 (voorzieningen), RJ 271 (bezoldigingen), RJ 272 (belastingen), RJ 350 (kasstroomoverzicht), RJ 500-series (segment reporting), RJ 160 (post-balance-sheet events)
- **NV-COS (Nadere Voorschriften Controle- en Overige Standaarden)** — accountantsstandaarden; NV-COS 700 (controle-verklaring), NV-COS 4410 (samenstel-verklaring), NV-COS 2400 (beoordelingsopdracht)
- **KVK SBR-Taxonomie (Nederlandse Taxonomie, NT)** — XBRL-schema voor jaarrekening-deponering; entry-points per groottecategorie (NT16-Klein-KVK, NT16-Middelgroot-KVK, NT16-Groot-KVK)
- **EU Accounting Directive 2013/34/EU** — Europese harmonisatie van jaarrekening-regels geïmplementeerd in Titel 9 BW
- **CSRD (Corporate Sustainability Reporting Directive)** — ESG-rapportage-eisen voor grote rechtspersonen vanaf BJ 2024/2025 via ESRS-standaarden (handled by dedicated CSRD module)

---

## Cross-App Integration Points

- **`bookkeeping-financial-statements` (T1 output, REQUIRED)**  
  Provides aggregated balans en V&W data per rubriek; this module structures into statutory wettelijke presentatie.

- **`bookkeeping-sbr-xbrl-reporting` (T3 module, REQUIRED)**  
  Consumes final `AnnualReport` snapshot and converts to SBR-XBRL per KVK-taxonomie; handles Digipoort submission and status tracking.

- **`bookkeeping-grootboek` (T1 GL, REQUIRED)**  
  Provides general-ledger lines; rekeningschema → wettelijke-rubriek mapping is configuration (seeds).

- **`openregister` (platform, REQUIRED)**  
  Provides `x-openregister-lifecycle` for workflow state transitions, `audit-trail-immutable` for jaarrekening snapshots, RBAC for role-based access (bestuur, accountant, viewer).

- **`bookkeeping-consolidation-commercial` (future T4 module, NOT this change)**  
  For groepsjaarrekening (consolidated) — separate change; this spec is enkelvoudige only.

- **`bookkeeping-sbr-esg-csrd-reporting` (future T4 module, NOT this change)**  
  For grote rechtspersoon ESG/CSRD mandates — separate module; bestuursverslag ESG-section in this spec is placeholder.

---

## Testing Strategy (by implementation cycle)

- **PHPUnit**: groottecategorie classification algorithm (two-of-three over two years), rubriek-mapping validation, toelichting-template registry lookups
- **Integration tests**: end-to-end jaarrekening generation (GL → balans/V&W/toelichting), snapshot creation at opmaak, immutability enforcement
- **Playwright MCP**: ReviewWorkflow UI (comment placement, status transitions, bestuur/accountant role boundaries)
- **XBRL validation**: conversion to SBR-XBRL, schema validation against NT16 entry points
- **Persona tests**: bookkeeper (jaarrekening gen), accountant (review + verklaring), bestuur (sign-off), compliance (wettelijke checklist)

---

## Verification & Acceptance

Spec-only change. `openspec validate` must exit clean. No source code is modified by this change. A separate `opsx-apply` cycle implements the generators, templates, and workflows.
