---
status: done
---

# Spec: bookkeeping-tenderned-integratie

**Status:** proposed  
**Scope:** shillinq  
**Tier:** cross-cutting integration (not in canonical 5-tier roadmap; see ADR-001)  
**Depends on:** bookkeeping-obligation-financial-administration (T2), supplier-performance-management  

## Purpose

TenderNed Integratie enables Shillinq to automatically import publicly awarded procurement contracts from TenderNed (Dutch central platform, Logius) and manifest them as financial obligations (Verplichting) with real-time budget-impact visibility and milestone-based execution tracking. The integration supports two roles: aanbestedende dienst (public buyer organization) and inschrijvende leverancier (private vendor organization), each with role-specific workflows and visibility constraints.

## Requirements

@e2e exclude pure backend/integration: TenderNed connector — not browser-testable


### REQ-001: Manual TenderNed dossier import

The system SHALL satisfy this requirement: Manual TenderNed dossier import.

**GIVEN** a contractmanager user with role `tenderned:import`  
**WHEN** the user navigates to Procurement > TenderNed Import and enters a valid TenderNed aanbestedingId, then clicks "Import"  
**THEN** the system fetches the dossier metadata from TenderNed API (via openconnector source), validates the dossier (title, contract value, parties), creates a Verplichting record with status `concept`, sets `bron: tenderned` and `bronReferentie: <aanbestedingId>`, and displays the created obligation to the contractmanager for enrichment. The obligation remains in `concept` state and does not affect the budget widget until the contractmanager explicitly activates it.

**Acceptance Criteria:**
- Dossier fetch completes within 5 seconds
- All required fields (title, contract value, parties) are present and valid
- `Verplichting.status = concept` until contractmanager clicks "Activate"
- Audit-trail entry recorded (user, timestamp, action: import, source: TenderNed, dossierReference)

#### Scenario: Import a TenderNed dossier as a concept obligation

- **GIVEN** a contractmanager with role `tenderned:import` and a valid TenderNed `aanbestedingId`
- **WHEN** the user enters the id on Procurement > TenderNed Import and clicks "Import"
- **THEN** the system fetches and validates the dossier via the openconnector source and creates a Verplichting with `status: concept`, `bron: tenderned`, and `bronReferentie: <aanbestedingId>`, leaving the budget widget unaffected until the contractmanager activates it.

---

### REQ-002: Automatic gunning trigger on status change

The system SHALL satisfy this requirement: Automatic gunning trigger on status change.

**GIVEN** a TenderNedAanbesteding record with `status: open` and the organization is listed as the winning leverancier (inschrijver) OR as the aanbestedende dienst  
**WHEN** the openconnector polling job detects that the TenderNed dossier status has changed to `gegund` (awarded) and the contract value is ≥ €1  
**THEN** the system automatically creates a Verplichting if one does not already exist (idempotent check on `bronReferentie`), sets `status: active` (skipping the concept enrichment step, since this is an auto-promotion), sends a real-time notification to all contractmanagers in the organization's `inkoop` role with the message "TenderNed award detected: [contract title], €[amount], awarded to [leverancier]", and sets the TenderNedAanbesteding record to `status: in-uitvoering`.

**Acceptance Criteria:**
- Detection and promotion completes within the openconnector polling cycle (≤ 5 minutes from TenderNed award)
- Notification is delivered in real-time (in-app toast + email if opted-in)
- Verplichting is created and active within 15 seconds of CloudEvent receipt
- Idempotency: if the Verplichting already exists (e.g., from REQ-001 manual import), do not duplicate; update the existing record's status to `active` instead
- Audit-trail entry recorded (system user: openconnector, action: auto-promote, from-status: concept, to-status: active)

#### Scenario: Auto-promote an obligation on detected award

- **GIVEN** a TenderNedAanbesteding with `status: open` where the organization is the winning leverancier or the aanbestedende dienst
- **WHEN** the openconnector polling job detects the dossier status change to `gegund` with contract value ≥ €1
- **THEN** the system idempotently creates (or updates the existing) Verplichting to `status: active`, notifies all `inkoop`-role contractmanagers in real time, and sets the TenderNedAanbesteding to `status: in-uitvoering`.

---

### REQ-003: Milestone-planning generation

The system SHALL satisfy this requirement: Milestone-planning generation.

**GIVEN** a newly activated Verplichting with `bron: tenderned` and fields `looptijdStart`, `looptijdEind`, and an opdrachttype (inferred from TenderNed's CPV codes or user selection)  
**WHEN** the contractmanager activates the obligation (or the system auto-promotes per REQ-002)  
**THEN** the system generates an initial milestone plan based on the opdrachttype template (e.g., for `levering-in-fases`, quarterly deelfacturen; for `dienstverlening-doorlopend`, monthly invoices). The system populates `Verplichting.mijlpalen[]` with entries: `{ datum: <calculated>, omschrijving: <template-label>, percentage: <% of contract value>, status: planned }`. The contractmanager can edit the plan before confirming the final obligation structure. Milestone dates are calculated based on template intervals and contract duration.

**Acceptance Criteria:**
- Milestone generation completes within 3 seconds for contracts up to 10 years in duration
- Milestone percentages sum to 100% (or less if explicitly configured as partial contract)
- Each milestone has a valid due date between contract start and end
- Contractmanager can edit/delete/add milestones in a detail view before confirmation
- Audit-trail entry recorded (action: milestone-plan-generated, template-id: <id>, interval-count: <N>)

#### Scenario: Generate a milestone plan on activation

- **GIVEN** a newly activated Verplichting with `bron: tenderned`, `looptijdStart`, `looptijdEind`, and an opdrachttype
- **WHEN** the contractmanager activates the obligation (or REQ-002 auto-promotes it)
- **THEN** the system populates `Verplichting.mijlpalen[]` from the opdrachttype template with `{ datum, omschrijving, percentage, status: planned }` entries whose dates fall within the contract duration and whose percentages sum to 100% (or less if configured partial), editable before confirmation.

---

### REQ-004: Proof-of-delivery (bewijsstuk) enforcement

The system SHALL satisfy this requirement: Proof-of-delivery (bewijsstuk) enforcement.

**GIVEN** an OpdrachtUitvoering record for a milestone that is marked as `status: in-progress` or `goedgekeurd: true`  
**WHEN** the contractmanager attempts to mark the milestone as `status: completed` (endpoint: PATCH OpdrachtUitvoering/{id}, body: { status: "completed" })  
**THEN** the system validates that `bewijsstukken.length > 0` (at least one file is attached). If no files are attached, the system rejects the request with HTTP 422 and error message "Bewijsstuk is required before marking delivery complete." The contractmanager must upload a proof document (oplevering protocol, acceptance email, invoice reference) via the file-attachment interface (docudesk). Accepted file types: PDF, DOCX, XLS, images (JPG, PNG).

**Acceptance Criteria:**
- Validation is enforced via `x-openregister-lifecycle.requires` (declarative constraint, not PHP guard)
- Error message is user-friendly Dutch: "Voeg minimaal één bewijsstuk toe voordat u de oplevering als voltooid markeert."
- File attachment uses docudesk API (per ADR-022); files are stored in docudesk, not locally
- Audit-trail entry recorded (action: delivery-marked-complete, bewijsstuk-count: <N>, bewijsstuk-ids: […])

#### Scenario: Block delivery completion without a proof document

- **GIVEN** an OpdrachtUitvoering for a milestone with `status: in-progress` and no attached bewijsstukken
- **WHEN** the contractmanager PATCHes the record to `status: completed`
- **THEN** the system enforces `bewijsstukken.length > 0` via `x-openregister-lifecycle.requires` and rejects the request with HTTP 422 and a Dutch error until at least one docudesk proof document (PDF, DOCX, XLS, JPG, PNG) is attached.

---

### REQ-005: ENSIA-compliant audit-trail

The system SHALL satisfy this requirement: ENSIA-compliant audit-trail.

**GIVEN** any state transition on a TenderNedAanbesteding, Verplichting, or OpdrachtUitvoering record  
**WHEN** the record is saved (POST / PATCH)  
**THEN** the system records an immutable audit-trail entry (via OR's built-in audit-trail mechanism, not app-local logging). The entry MUST include:
- `timestamp` (ISO 8601, server-side generated, tamper-proof)
- `user` (system user ID or name; if system-triggered, use "openconnector" or "scheduled-job")
- `action` (created / updated / deleted / state-transition / approved / rejected)
- `oldValue` and `newValue` (for changed fields; null if creation)
- `tenderNedDossierReference` (the `bronReferentie` or `aanbestedingId` for traceability to the original TenderNed dossier)
- `kostenplaats` / `budgetCode` (cost centre for allocation audit)

An ENSIA auditor querying the audit-trail can reconstruct the full lineage: award-date (TenderNed) → import-date (Shillinq) → enrichment changes (contractmanager edits) → milestone completions → payment facts.

**Acceptance Criteria:**
- Audit-trail uses OR's immutable storage (no app-local tables)
- Entries are queryable by entity-id, user, action, timestamp (OR audit-trail API)
- TenderNed dossier reference is included on every obligation-related audit entry
- Audit-trail cannot be tampered with (OR's guarantee, not this app's responsibility)
- Test: ENSIA auditor can retrieve full chain for a sample obligation in <10 seconds

#### Scenario: Record an immutable audit entry on state transition

- **GIVEN** any state transition on a TenderNedAanbesteding, Verplichting, or OpdrachtUitvoering record
- **WHEN** the record is saved via POST or PATCH
- **THEN** the system writes an immutable OR audit-trail entry containing `timestamp`, `user`, `action`, `oldValue`/`newValue`, `tenderNedDossierReference`, and `kostenplaats`/`budgetCode`, so an ENSIA auditor can reconstruct the full lineage from award to payment.

---

### REQ-006: Status-sync back to TenderNed

The system SHALL satisfy this requirement: Status-sync back to TenderNed.

**GIVEN** a Verplichting with `bron: tenderned` that has reached a final milestone status (e.g., `status: completed` on the last OpdrachtUitvoering where `opleveringsType: eindoplevering`)  
**WHEN** the contractmanager marks the final milestone as complete and the organization is the aanbestedende dienst (only the buyer can write status, not the vendor)  
**THEN** the system sends a status-update message to TenderNed's API (via openconnector, route: POST /api/dossiers/{aanbestedingId}/status, body: { status: "afgerond" }). TenderNed updates the public dossier to show the contract is closed, satisfying article 2.135 (Aanbestedingswet) publication requirements.

**Acceptance Criteria:**
- Status-sync only available to users with role `inkoper` (aanbestedende dienst)
- Vendors (inschrijvers) cannot trigger status-sync (read-only visibility of their own awards)
- Status-sync message is sent via openconnector's TenderNed source connector (not direct HTTP)
- If TenderNed API is unreachable, the system logs a warning but does NOT fail the milestone-completion. User sees a tooltip: "Bewijsstuk opgeslagen; status-sync naar TenderNed niet verzonden (herprob over 5 min)."
- Audit-trail entry recorded (action: status-sync-to-tenderned, status: afgerond, success: true/false)

#### Scenario: Sync final status back to TenderNed as the buyer

- **GIVEN** a Verplichting with `bron: tenderned` whose final `eindoplevering` milestone is completed and whose organization is the aanbestedende dienst
- **WHEN** an `inkoper` marks the final milestone complete
- **THEN** the system sends a status-update to TenderNed via the openconnector source (POST /api/dossiers/{aanbestedingId}/status, status `afgerond`); vendors cannot trigger it, and if TenderNed is unreachable the milestone completion still succeeds with a logged warning.

---

### REQ-007: Real-time budget-impact widget update (60-second SLA)

The system SHALL satisfy this requirement: Real-time budget-impact widget update (60-second SLA).

**GIVEN** a Verplichting with `bron: tenderned` that is activated (status: active)  
**WHEN** the obligation is created or status-changed, a CloudEvent is emitted to the launchpad event stream with payload: { event: "obligation-activated", contractWaarde: <amount>, periode: <FY>, kostenplaats: <code>, tenderNedDossierUrl: <url> }  
**THEN** the launchpad budget-widget listener receives the event and re-calculates the budget-utilization percentage for the relevant cost centre. The widget refreshes within 60 seconds, showing the new obligation as a committed expense.

**Acceptance Criteria:**
- CloudEvent is emitted asynchronously (does NOT block obligation creation)
- Event payload includes contract value, period, cost centre, and TenderNed dossier URL
- Widget update SLA is 60 seconds from event emit to re-render
- Test: Import 10 obligations in parallel; verify all 10 appear in widget within 60s
- Failure mode: if launchpad is unreachable, log warning but continue (graceful degradation)

#### Scenario: Refresh the budget widget within the SLA

- **GIVEN** a Verplichting with `bron: tenderned` that becomes `status: active`
- **WHEN** the obligation is created or status-changed and an `obligation-activated` CloudEvent is emitted asynchronously to the launchpad event stream
- **THEN** the launchpad budget-widget listener recalculates the cost-centre budget utilization and re-renders within 60 seconds, degrading gracefully with a logged warning if launchpad is unreachable.

---

### REQ-008: Vendor cashflow-forecast integration (omzet-prognose)

The system SHALL satisfy this requirement: Vendor cashflow-forecast integration (omzet-prognose).

**GIVEN** an MKB-leverancier tenant that has won one or more TenderNed contracts  
**WHEN** the vendor's Shillinq instance syncs Verplichting records where `bron: tenderned` and `gegundeLeverancier.kvkNumber == organization.kvkNumber`  
**THEN** the system populates the omzet-prognose (revenue forecast) pipeline with one entry per Verplichting, distributing the contractWaarde across the milestone dates. Each milestone-period gets a revenue forecast value = (contractWaarde × milestone-percentage). The vendor's CFO can use the forecast to plan cash position and invoice timing. Forecast is read-only; vendor cannot edit (milestones are buyer-defined).

**Acceptance Criteria:**
- Only vendor's own contracts are visible (RBAC enforces kvkNumber matching)
- Revenue forecast values sum to contractWaarde (no double-counting, no rounding errors >€0.01)
- Forecast is live-updated when milestones are completed (OpdrachtUitvoering.status → completed)
- Test with 5 overlapping contracts (different durations, payment schedules); verify forecast aggregation is correct

#### Scenario: Populate a vendor revenue forecast from won contracts

- **GIVEN** an MKB-leverancier tenant whose `organization.kvkNumber` matches `gegundeLeverancier.kvkNumber` on one or more TenderNed Verplichtingen
- **WHEN** the vendor's Shillinq instance syncs those `bron: tenderned` Verplichtingen
- **THEN** the system populates a read-only omzet-prognose entry per Verplichting, distributing `contractWaarde` across milestone dates as `contractWaarde × milestone-percentage`, visible only for the vendor's own contracts and live-updated as milestones complete.

---

## Scenarios

#### Scenario 1: Gemeente imports and enriches a manually found TenderNed tender

```gherkin
Given a contractmanager at a gemeente with TenderNed aanbestedingId: "2024-NL-00123"
  and the tender has €50,000 contract value, schoonmaakdiensten, 1-year duration
When the contractmanager imports the dossier (REQ-001)
Then a Verplichting is created with status: concept, bron: tenderned, bronReferentie: "2024-NL-00123"
  and the contractmanager sees a detail form for enrichment (kostenplaats, GL account)
  and the contractmanager selects kostenplaats "Diensten", GL account "8050 Operationeel"
  and the system generates a milestone plan (monthly invoices, 12 x €4,167)
When the contractmanager clicks "Confirm"
Then the Verplichting status → active
  and the budget-widget updates within 60s (REQ-007)
  and the audit-trail records: user, timestamp, action: import + activate, dossierReference: 2024-NL-00123
```

#### Scenario 2: Automatic award detection triggers obligation creation

```gherkin
Given a TenderNed dossier: "2025-EU-99999", status: open, gemeente is aanbestedende dienst
  and the openconnector polling job runs every 5 minutes
When TenderNed publishes an award (status → gegund) for this dossier
  and the next polling cycle detects the change
Then a Verplichting is auto-created (REQ-002), status: active, bron: tenderned
  and all inkoper users receive a notification: "TenderNed award: [contract], €[value], [winner]"
  and the budget-widget updates within 60s
  and the system begins polling for milestone-status updates
```

#### Scenario 3: Vendor sees their won contract in cashflow forecast

```gherkin
Given an MKB supplier (kvkNumber: 12345678) that won a TenderNed contract
  and the contract: €30,000, 2-year dienstverlening, paid in 24 monthly installments
When the supplier's Shillinq instance syncs Verplichtingen where bron: tenderned
Then the omzet-prognose widget shows a revenue-forecast entry
  and the forecast distributes €30,000 / 24 months = €1,250/month
  and the supplier's CFO can see cash inflows predicted for the next 24 months
  and if a milestone is marked complete by the buyer, the supplier sees it reflected in payment-probability (planned → likely → paid)
```

#### Scenario 4: Buyer marks delivery complete with proof document

```gherkin
Given a Verplichting for a 1-year service contract with 12 monthly milestones
  and the first milestone is marked status: in-progress
When the supplier uploads an oplevering protocol (PDF) as a bewijsstuk
  and the contractmanager clicks "Mark delivery complete"
Then the system validates bewijsstukken.length > 0 (REQ-004)
  and the milestone status → completed
  and the system emits a status-sync request to TenderNed (REQ-006) if this is the final milestone
  and the audit-trail records: action: milestone-completed, bewijsstuk-id: <uuid>, approver: <user>
```

#### Scenario 5: ENSIA auditor traces obligation back to TenderNed

```gherkin
Given an obligation (Verplichting) with bron: tenderned, bronReferentie: "2024-NL-12345"
When an ENSIA auditor queries the audit-trail for this obligation
Then the system returns a complete chain:
  - import timestamp, importer user, TenderNed dossier URL
  - enrichment changes (kostenplaats, GL account changes) by contractmanager
  - milestone-generation with template ID
  - each milestone completion with bewijsstuk reference
  - status-sync event back to TenderNed (if applicable)
  and the auditor can access the original TenderNed dossier via bronReferentie link
  and the total audit-trail retrieval takes <10 seconds
```

---

## Data Model Extensions

### Verplichting (extended)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| bron | enum: ["manual", "tenderned", "inkooporder"] | Yes | Source of this obligation |
| bronReferentie | string | No | TenderNed aanbestedingId (if bron=tenderned); opkooporder ID (if bron=inkooporder); null if manual |
| mijlpalen | array of Mijlpaal | No | Milestone plan (populated on activation or auto-generation) |

### Mijlpaal (new, nested in Verplichting)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| mijlpaalId | uuid | Yes | Unique milestone identifier |
| datum | date | Yes | Expected completion date |
| omschrijving | string | Yes | Milestone description (e.g., "Month 1 Invoice", "Final Delivery") |
| percentage | number (0–100) | Yes | Percentage of contract value for this milestone |
| status | enum: ["planned", "in-progress", "completed", "cancelled"] | Yes | Current status |
| factuurnummer | string | No | Invoice number once payment is made |

### TenderNedAanbesteding (new)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| aanbestedingId | string | Yes | TenderNed identifier (unique key) |
| tenderNedUrl | string | Yes | Deep-link to TenderNed dossier |
| titel | string | Yes | Tender title |
| beschrijving | string | No | Tender description |
| cpvCodes | array of string | No | Common Procurement Vocabulary codes |
| aanbestedendeDienst | string (KvK) | Yes | Buyer organization KvK number |
| gunningsDatum | date | Yes | Award date (when winner was announced) |
| contractWaarde | decimal | Yes | Contract value excluding VAT |
| looptijdStart | date | Yes | Contract start date |
| looptijdEind | date | Yes | Contract end date |
| gegundeLeverancier | string (KvK + name) | Yes | Winning vendor KvK and legal name |
| status | enum | Yes | TenderNed status: aangekondigd / open / gesloten / gegund / in-uitvoering / afgerond / beëindigd |
| verplichtingId | uuid | No | FK to linked Verplichting (created on activation) |

### OpdrachtUitvoering (new)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| verplichtingId | uuid | Yes | FK to Verplichting |
| mijlpaalId | uuid | Yes | FK to milestone (from Verplichting.mijlpalen) |
| opleveringsDatum | date | Yes | Actual delivery date |
| opleveringsType | enum: ["deeloplevering", "eindoplevering", "tussentijdse-rapportage"] | Yes | Delivery classification |
| goedgekeurd | boolean | Yes | Whether contractmanager has approved |
| goedkeurder | string (user ID) | No | User who approved (if goedgekeurd=true) |
| bewijsstukken | array of {app: "docudesk", documentId: uuid} | No | File references (stored in docudesk) |

---

## Cross-App Dependencies

- **openconnector**: Consumes TenderNed source polling (5-min cadence) and emits CloudEvents on status change
- **openregister**: Uses audit-trail-immutable (REQ-005) and file-attachment (REQ-004, bewijsstukken via docudesk)
- **launchpad**: Listens for obligation-activated events and updates budget-widget (REQ-007)
- **docudesk**: Stores bewijsstukken (proof documents) via ADR-022 file-attachment mechanism

---

## Standards & Compliance

- **Aanbestedingswet 2012, artikel 2.135**: Status-sync back to TenderNed (REQ-006) ensures public transparency
- **TenderNed-API** (Logius): Polling + status updates via openconnector
- **CPV 2008**: Procurement category codes stored as-is (no reclassification)
- **ENSIA / BIO**: Audit-trail preservation (REQ-005) ensures compliance audits can reconstruct financial flow
- **NEN 2748**: Dutch facilities procurement terminology recognized in milestone templates

---

## Open Questions for Reviewers

1. Should milestone-percentage auto-sum validation allow <100% (e.g., partial-contract scenarios), or strictly enforce 100%?
2. Is the 60-second SLA for budget-widget update (REQ-007) achievable with openconnector's 5-minute polling interval? (Answer: yes, within the polling window; SLA is from CloudEvent emit to widget re-render, not from TenderNed publish to widget.)
3. Should we auto-generate milestone templates for all CPV categories, or only ship the three major ones (goods, services, works)?

---

## Acceptance & Sign-Off

- [ ] Spec reviewed by procurement domain expert (inkoper or contractmanager persona)
- [ ] Spec reviewed by ENSIA auditor or compliance officer
- [ ] Cross-app dependencies verified with openconnector, launchpad, docudesk teams
- [ ] Design.md Q1–Q3 open questions resolved
