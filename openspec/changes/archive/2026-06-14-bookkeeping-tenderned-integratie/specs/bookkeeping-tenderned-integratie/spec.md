# Spec: bookkeeping-tenderned-integratie

**Status:** proposed
**Scope:** shillinq
**Tier:** cross-cutting integration (not in canonical 5-tier roadmap; see ADR-001)
**Depends on:** bookkeeping-obligation-financial-administration (T2), supplier-performance-management

## Summary

TenderNed Integratie enables Shillinq to automatically import publicly awarded procurement contracts from TenderNed (Dutch central platform, Logius) and manifest them as financial obligations (Verplichting) with real-time budget-impact visibility and milestone-based execution tracking. The integration supports two roles: aanbestedende dienst (public buyer organization) and inschrijvende leverancier (private vendor organization), each with role-specific workflows and visibility constraints.

> **Note (T2 dependency).** The `Verplichting` schema this spec "extends" does not yet exist in shillinq (no obligation-financial-administration change has landed). The hydra-build therefore declares `Verplichting` as a new schema in the register.d fragment with the `bron`/`bronReferentie`/`mijlpalen` fields built in. When the T2 obligation change lands it can reconcile against this declaration.

## ADDED Requirements

### Requirement: (REQ-001) The system SHALL import a TenderNed dossier as a concept Verplichting

The system MUST allow a contractmanager with the `tenderned:import` permission to enter a TenderNed `aanbestedingId`; the system fetches the dossier metadata (via the openconnector TenderNed source), creates a `TenderNedAanbesteding` record and a linked `Verplichting` with `status: concept`, `bron: tenderned`, and `bronReferentie: <aanbestedingId>`. The concept obligation does NOT lock budget until the contractmanager enriches it (kostenplaats, grootboekrekening) and activates it.

#### Scenario: Contractmanager imports a tender dossier

- **GIVEN** a contractmanager with the `tenderned:import` permission and a valid `aanbestedingId`
- **WHEN** they import the dossier
- **THEN** a `Verplichting` is created with `status: concept`, `bron: tenderned`, `bronReferentie: <aanbestedingId>`, it does not affect the budget widget until activated, and an audit-trail entry (user, timestamp, action: import, dossierReference) is recorded.

### Requirement: (REQ-002) The system SHALL auto-promote an awarded tender to an active obligation when the winner matches the organization

When the openconnector polling job detects a TenderNed dossier moving to `gegund` with a positive contract value and the winning KvK matches the tenant organization, the system MUST idempotently create (or update) the `Verplichting`, activate it, notify the contractmanagers, and set the `TenderNedAanbesteding` to `in-uitvoering`.

#### Scenario: Award detected for the tenant organization

- **GIVEN** a `TenderNedAanbesteding` with `status: open` whose winning KvK matches the organization
- **WHEN** the polling job detects the status change to `gegund` with contractWaarde ≥ €1
- **THEN** the linked `Verplichting` is created if absent (idempotent on `bronReferentie`) or updated, its status is set to `active`, the contractmanagers are notified, the tender is set to `in-uitvoering`, and an audit-trail entry (system user, action: auto-promote) is recorded.

### Requirement: (REQ-003) The system SHALL generate an initial milestone plan on activation

On activation of a `bron: tenderned` obligation with `looptijdStart`/`looptijdEind` and an opdrachttype, the system MUST generate an initial `Verplichting.mijlpalen[]` plan from the opdrachttype template (phased quarterly, recurring monthly, or fallback). The plan is editable by the contractmanager before confirmation.

#### Scenario: Milestone plan generated for a phased contract

- **GIVEN** an activated obligation with opdrachttype `levering-in-fases` and a 1-year term
- **WHEN** the milestone plan is generated
- **THEN** `mijlpalen[]` is populated with quarterly entries (datum within the term, percentage summing to 100, status `planned`), the contractmanager may edit them, and generation completes within 3 seconds for contracts up to 10 years.

### Requirement: (REQ-004) The system SHALL require at least one bewijsstuk before a milestone delivery can be completed

An `OpdrachtUitvoering` milestone MUST NOT transition to `status: completed` unless at least one bewijsstuk (proof-of-delivery file reference, stored in docudesk per ADR-022) is attached. A completion attempt without proof is rejected with a Dutch error message.

#### Scenario: Completion blocked without proof of delivery

- **GIVEN** an `OpdrachtUitvoering` in `status: in-progress` with no bewijsstukken
- **WHEN** a user attempts to mark it `completed`
- **THEN** the lifecycle precondition (`x-openregister-lifecycle.requires`) denies the transition with "Voeg minimaal één bewijsstuk toe voordat u de oplevering als voltooid markeert.", and only once a bewijsstuk with a non-empty documentId is attached does the completion succeed.

### Requirement: (REQ-005) The system SHALL record an ENSIA-compliant immutable audit-trail for every mutation

The system MUST record, for every create / update / state-transition on `TenderNedAanbesteding`, `Verplichting`, and `OpdrachtUitvoering`, an entry in OpenRegister's immutable audit-trail (ADR-022 — no app-local audit tables), carrying timestamp, user, action, before/after, and the TenderNed dossier reference, so an ENSIA auditor can reconstruct the full lineage.

#### Scenario: Auditor reconstructs the obligation lineage

- **GIVEN** an obligation with `bron: tenderned` and `bronReferentie: <aanbestedingId>`
- **WHEN** an ENSIA auditor queries the audit-trail for the obligation
- **THEN** the immutable chain (import → enrichment → milestone completions → status-sync) is returned with the dossier reference on every entry, retrievable in under 10 seconds, with no app-local audit storage involved.

### Requirement: (REQ-006) The system SHALL sync final completion back to TenderNed only for the aanbestedende dienst

When the eindoplevering milestone of a `bron: tenderned` obligation is approved AND the organization is the aanbestedende dienst, the system MUST send a status update to TenderNed (via openconnector) so the public dossier reflects completion (Aanbestedingswet 2012 artikel 2.135). Vendors (inschrijvers) cannot trigger the sync. A sync failure logs a warning but does not fail the milestone completion.

#### Scenario: Buyer completes the final milestone

- **GIVEN** an aanbestedende dienst with an approved eindoplevering on a `bron: tenderned` obligation
- **WHEN** the tender is marked afgerond
- **THEN** completion is permitted only because an approved eindoplevering exists (`TenderNedAanbestedingGuard::canAfronden`), a status-update is sent to TenderNed via openconnector, a sync failure degrades gracefully with the "...niet verzonden (probeer over 5 minuten opnieuw)" notice, and an audit entry (action: status-sync-to-tenderned, success: true/false) is recorded.

### Requirement: (REQ-007) The system SHALL surface a new TenderNed obligation in the budget-impact view within 60 seconds

On activation of a `bron: tenderned` obligation, a budget-impact CloudEvent MUST be emitted asynchronously to launchpad carrying contractWaarde, period, kostenplaats, and the TenderNed dossier URL; the budget-utilization widget reflects the new committed expense within 60 seconds.

#### Scenario: Budget widget reflects a newly activated obligation

- **GIVEN** a `bron: tenderned` obligation being activated
- **WHEN** the activation completes
- **THEN** a non-blocking CloudEvent with contractWaarde/period/kostenplaats/dossierUrl is emitted, the launchpad budget widget re-renders within 60 seconds, and an unreachable launchpad degrades gracefully (warning logged, activation continues).

### Requirement: (REQ-008) The system SHALL show a vendor cashflow forecast for won TenderNed contracts

An MKB-leverancier tenant MUST see a read-only omzet-prognose distributing each won contract's `contractWaarde` across its milestone dates by percentage, scoped to the vendor's own awards (KvK match). The forecast totals exactly the contract value (no cent drift) and updates as milestones complete.

#### Scenario: Vendor plans cashflow from won contracts

- **GIVEN** an MKB-leverancier with one or more `bron: tenderned` obligations whose gegundeLeverancier KvK matches the organization
- **WHEN** the cashflow forecast is built
- **THEN** each obligation yields forecast entries summing exactly to `contractWaarde` (no double-counting, rounding error ≤ €0.01), only the vendor's own contracts are visible (RBAC + filtered "Mijn Contracten" view), and the forecast reflects milestone completions.

## Worked Examples (non-normative)

**Example — Gemeente imports and enriches a manually found TenderNed tender**

- **GIVEN** a contractmanager at a gemeente with aanbestedingId "2024-NL-00123" (€50,000 schoonmaak, 1-year)
- **WHEN** they import the dossier (REQ-001), enrich it (kostenplaats "Diensten", GL "8050"), and activate it
- **THEN** the obligation becomes `active`, a monthly milestone plan is generated (REQ-003), the budget widget updates within 60s (REQ-007), and the audit-trail records import + activate with the dossier reference (REQ-005).

**Example — Automatic award detection triggers obligation creation**

- **GIVEN** a TenderNed dossier "2025-EU-99999" (status open) where the gemeente is aanbestedende dienst
- **WHEN** TenderNed publishes an award and the next polling cycle detects it
- **THEN** a `Verplichting` is auto-created and activated (REQ-002), inkoper users are notified, and the budget widget updates within 60s.

**Example — Vendor sees their won contract in cashflow forecast**

- **GIVEN** an MKB supplier (KvK 12345678) that won a €30,000 2-year contract paid in 24 monthly installments
- **WHEN** their Shillinq instance lists `bron: tenderned` obligations
- **THEN** the omzet-prognose distributes €30,000 across 24 months (≈ €1,250/month, exact total) and reflects buyer-marked milestone completions (REQ-008).

**Example — Buyer marks delivery complete with proof document**

- **GIVEN** a 1-year service obligation with 12 monthly milestones, the first in-progress
- **WHEN** a bewijsstuk (PDF) is uploaded and the contractmanager marks the delivery complete
- **THEN** the bewijsstuk gate passes (REQ-004), the milestone is completed, a status-sync to TenderNed is offered if it is the final milestone (REQ-006), and the audit-trail records the completion with the bewijsstuk reference (REQ-005).

**Example — ENSIA auditor traces obligation back to TenderNed**

- **GIVEN** an obligation with `bron: tenderned`, `bronReferentie: "2024-NL-12345"`
- **WHEN** an ENSIA auditor queries the audit-trail
- **THEN** the full chain (import, enrichment, milestone generation, completions with bewijsstuk references, status-sync) is returned, the original dossier is reachable via `bronReferentie`, and retrieval takes under 10 seconds (REQ-005).

## Data Model Extensions

### Verplichting (declared here; extends the future T2 entity)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| bron | enum: ["manual", "tenderned", "inkooporder"] | Yes | Source of this obligation |
| bronReferentie | string | No | TenderNed aanbestedingId (if bron=tenderned); inkooporder ID (if bron=inkooporder); null if manual |
| mijlpalen | array of Mijlpaal | No | Milestone plan (populated on activation or auto-generation) |

### Mijlpaal (value object, nested in Verplichting.mijlpalen)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| mijlpaalId | string | Yes | Milestone identifier |
| datum | date | Yes | Expected completion date |
| omschrijving | string | Yes | Milestone description |
| percentage | number (0–100) | Yes | Percentage of contract value for this milestone |
| status | enum: ["planned", "in-progress", "completed", "cancelled"] | Yes | Current status |
| factuurnummer | string | No | Invoice number once payment is made |

### TenderNedAanbesteding (new)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| aanbestedingId | string | Yes | TenderNed identifier (unique per administration) |
| tenderNedUrl | string | No | Deep-link to TenderNed dossier |
| titel | string | Yes | Tender title |
| beschrijving | string | No | Tender description |
| cpvCodes | array of string | No | Common Procurement Vocabulary codes |
| aanbestedendeDienst | string (KvK + name) | Yes | Buyer organization |
| gunningsDatum | date | No | Award date |
| contractWaarde | number | No | Contract value excluding VAT |
| looptijdStart | date | No | Contract start date |
| looptijdEind | date | No | Contract end date |
| gegundeLeverancier | string (KvK + name) | No | Winning vendor |
| opdrachttype | enum | No | levering-in-fases / dienstverlening-doorlopend / other |
| verplichtingId | string | No | FK to linked Verplichting |
| status | enum | Yes | aangekondigd / open / gegund / in-uitvoering / afgerond / beeindigd |

### OpdrachtUitvoering (new)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| verplichtingId | string | Yes | FK to Verplichting |
| mijlpaalId | string | Yes | Reference to the milestone in Verplichting.mijlpalen |
| opleveringsDatum | date | No | Actual delivery date |
| opleveringsType | enum: ["deeloplevering", "eindoplevering", "tussentijdse-rapportage"] | Yes | Delivery classification |
| goedgekeurd | boolean | No | Whether the delivery is approved |
| goedkeurder | string (user) | No | Approving user |
| bewijsstukken | array of {app, documentId, omschrijving?} | No | File references (stored in docudesk) |

## Cross-App Dependencies

- **openconnector**: Consumes TenderNed source polling (5-min cadence) and emits CloudEvents on status change
- **openregister**: Uses audit-trail-immutable (REQ-005), RBAC, and file-attachment (REQ-004 bewijsstukken via docudesk)
- **launchpad**: Listens for obligation-activated events and updates the budget-widget (REQ-007)
- **docudesk**: Stores bewijsstukken (proof documents) via the ADR-022 file-attachment mechanism

## Standards & Compliance

- **Aanbestedingswet 2012, artikel 2.135**: Status-sync back to TenderNed (REQ-006) ensures public transparency
- **TenderNed-API** (Logius): Polling + status updates via openconnector
- **CPV 2008**: Procurement category codes stored as-is (no reclassification)
- **ENSIA / BIO**: Audit-trail preservation (REQ-005) ensures compliance audits can reconstruct the financial flow
- **NEN 2748**: Dutch facilities procurement terminology recognized in milestone templates
