---
status: proposed
source: specter
features: [sepa-direct-debit-mandate-management-and-collection, electronic-mandate-creation-and-management, multi-scheme-mandate-management-across-countries, po-change-management-with-supplier-notification-and-acknowledgment-workflow, policy-enforcement-through-workflow-embedded-compliance-checks]
---

# Authorization & Mandate Management — Shillinq

## Summary

Implements a mandate register and purchase order change workflow for Shillinq: a structured bevoegdhedenregister for SEPA Direct Debit mandates, electronic mandates, and multi-scheme cross-country mandates; a PO change management module with supplier notification and acknowledgment workflow; and policy enforcement through compliance checks embedded in the mandate and PO change approval flows. These capabilities address the five highest-demand authorization and mandate features identified in the Specter intelligence model and integrate with the core, access-control-authorisation, approval-workflow-management, supplier-management, and accounts-payable-receivable infrastructure already in place.

## Demand Evidence

Top features by market demand score:

- **PO change management with supplier notification and acknowledgment workflow** (demand: 1840) — the highest-ranked feature in this cluster. Procurement teams need a formal change request process for approved purchase orders: any material change (quantity, price, delivery date, line item addition or removal) must be raised as a `PurchaseOrderLineChange`, routed through internal approval, notified to the supplier, and acknowledged by the supplier before the change takes effect. Finance controllers need an immutable audit trail of every change event.
- **SEPA Direct Debit mandate management and collection** (demand: 1601) — organisations collecting recurring payments need to create, store, and track SEPA Core and B2B mandates with full SEPA mandate reference (UMR) lifecycle: pre-notification, collection scheduling, revocation, and expiry. Treasurers need automated collection date calculation and PAIN.008 export for bank submission.
- **Electronic mandate creation and management** (demand: 1594) — counterparts expect to sign mandates digitally via a shareable link. Finance teams need a digital signing flow with audit trail (IP, timestamp, channel) and PDF evidence generation, without requiring a third-party e-signature subscription.
- **Multi-scheme mandate management across countries** (demand: 1592) — multinational organisations operate under different debit scheme rules (SEPA Core, SEPA B2B, Bacs AUDDIS, BetalingsService). A unified mandate register must support country-specific scheme rules, currency, and collection windows while presenting a single view to the treasurer.
- **Policy enforcement through workflow-embedded compliance checks** (demand: 663) — mandates and PO changes must be validated against organisation policy before activation: maximum authorised amount, cost category restrictions, role-based ceiling rules, and duplicate-mandate detection. Violations must block progression and generate a compliance event in the audit trail.

Key stakeholder pain points addressed:

- **Treasurer**: SEPA mandate data scattered across spreadsheets with no collection-date calculator; missed pre-notification windows causing failed collections — addressed by the mandate register with automated next-collection-date calculation and pre-notification tracking.
- **Group Controller**: no visibility into which mandates are expiring or which PO changes are pending supplier acknowledgment; month-end reconciliation delayed — addressed by mandate expiry dashboards, upcoming-collection widgets, and PO change status reporting.
- **Customer**: receives PO amendments by email with no structured acknowledgment mechanism; disputes arise over whether a change was accepted — addressed by the supplier acknowledgment workflow with timestamped digital acceptance or rejection.

## What Shillinq Already Has

After the core, access-control-authorisation, collaboration, supplier-management, catalog-purchase-management, accounts-payable-receivable, document-management, scheduling, and approval-workflow-management changes:

- OpenRegister schemas for `Organization`, `AppSettings`, `AccessControl`, `Comment`, `SupplierProfile`, `Budget`, `Account`, `AuditTrail`, `ApprovalWorkflow`, `ApprovalRequest`, `ApprovalDecision`
- Nextcloud notification integration and mention engine
- Entity list, detail, and form views using `CnIndexPage` / `CnDetailPage` / `CnFormDialog`
- Purchase order schema (`PurchaseOrder`) from catalog-purchase-management
- Approval routing engine (`WorkflowRoutingService`) from approval-workflow-management
- Supplier portal token mechanism from supplier-management

### What Is Missing

- No `Mandate` schema for SEPA, electronic, and multi-scheme mandate records with full lifecycle tracking
- No `MandateScheme` schema for country-specific scheme configuration (rules, currency, collection windows)
- No `MandateCollection` schema for scheduled collection events linked to a mandate
- No `PurchaseOrderLineChange` schema for formal PO change requests with status lifecycle and supplier acknowledgment
- No `SupplierAcknowledgment` schema for supplier responses to PO change notifications
- No PAIN.008 XML export service for SEPA direct debit collection batches
- No mandate pre-notification tracking and window calculation
- No digital mandate signing flow (shareable link + IP/timestamp audit record)
- No PO change supplier notification email with structured acknowledgment link
- No compliance policy check engine for mandate ceiling and category rules
- No mandate expiry background job and upcoming-collection widget

## Scope

### In Scope

1. **Mandate Schema** — OpenRegister `Mandate` schema (`schema:Authorization`) covering SEPA Core, SEPA B2B, electronic, and multi-scheme mandates. Properties include `mandateId`, `type`, `status` (draft, pending, active, expired, revoked), `authorizedAmount`, `frequency`, `country`, `signatureDate`, `expiryDate`, `reference` (UMR for SEPA), `creditorIdentifier`, and `nextCollectionDate`. Relations to `Contact` (debtor), `Organization` (creditor), `MandateScheme`, and `MandateCollection`. Views at `src/views/mandate/`; store at `src/store/modules/mandate.js`.

2. **MandateScheme Schema** — OpenRegister `MandateScheme` schema (`schema:Thing`) defining country-specific scheme parameters: scheme code (SEPA_CORE, SEPA_B2B, BACS_AUDDIS, BETALINGSSERVICE), country code, currency, minimum pre-notification days, maximum collection amount, and scheme-level validation rules. Allows the mandate register to support non-SEPA mandates with correct window calculations.

3. **MandateCollection Schema** — OpenRegister `MandateCollection` schema (`schema:PaymentChargeSpecification`) representing a single collection event against a mandate: scheduled date, amount, status (scheduled, submitted, settled, failed, returned), PAIN.008 batch reference, and failure reason. Links to the parent `Mandate`.

4. **Mandate Lifecycle Service** — `lib/Service/MandateLifecycleService.php` handles: activation (signature date validation, creditor identifier format check), pre-notification calculation (subtracts scheme-specific notice days from collection date), `nextCollectionDate` computation for recurring mandates, expiry detection, and revocation (with reason capture). Sends Nextcloud notifications on status changes.

5. **SEPA PAIN.008 Export** — `lib/Service/PainExportService.php` generates a PAIN.008 XML file for a batch of `MandateCollection` objects. Validates IBAN, BIC, and UMR format before export. Supports SDD Core and SDD B2B service levels. The export is available via `GET /api/v1/mandates/export/pain008?collectionDate=YYYY-MM-DD` and returned as a downloadable XML file.

6. **Mandate Digital Signing Flow** — `lib/Service/MandateSigningService.php` generates a time-limited shareable token (72-hour TTL) for a `Mandate` in `pending` status. A lightweight public-facing view (`MandateSignPage.vue`) presents the mandate summary to the counterpart and records acceptance (IP address, user-agent, timestamp, channel: `web_link`) in a new `MandateSignatureEvent` object stored via OpenRegister. On acceptance, the mandate status moves to `active` and a PDF evidence record is generated.

7. **Multi-Scheme Support** — the `Mandate` form (`MandateForm.vue`) presents scheme-specific fields dynamically based on the selected `MandateScheme`. IBAN and BIC fields appear for SEPA schemes; sort code and account number for BACS; FI registration number for BetalingsService. Scheme-level validation rules from `MandateScheme.validationRules` are applied client-side before submission.

8. **PurchaseOrderLineChange Schema** — OpenRegister `PurchaseOrderLineChange` schema (`schema:Thing`) for formal change requests on an approved `PurchaseOrder`. Properties: `changeId`, `changeType` (addition, removal, modification, quantity, delivery-date), `description`, `requestDate`, `status` (draft, submitted, approved, acknowledged, rejected, implemented), `approvalStatus`, `reason`, `impactAmount`, `priority`. Relations to `PurchaseOrder`, requesting `Contact`, approving `Employee`, `ApprovalWorkflow`, and `SupplierAcknowledgment`. Views at `src/views/purchaseOrderLineChange/`; store at `src/store/modules/purchaseOrderLineChange.js`.

9. **SupplierAcknowledgment Schema** — OpenRegister `SupplierAcknowledgment` schema (`schema:Thing`) for supplier responses to PO change notifications: acknowledgment status (pending, accepted, rejected, counter-proposed), response date, supplier contact, response notes, and counter-proposal details. Linked many-to-one to a `PurchaseOrderLineChange`.

10. **PO Change Workflow Service** — `lib/Service/PoChangeWorkflowService.php` orchestrates the PO change lifecycle: validates the change request against the parent PO, routes it through `ApprovalWorkflow` (reusing the existing routing engine), on internal approval sends a supplier notification email with an acknowledgment link (using the supplier portal token mechanism from supplier-management), records the `SupplierAcknowledgment` response, and on acceptance updates the `PurchaseOrder` line items and re-calculates totals. On supplier rejection, the change request is returned to `draft` for revision.

11. **Compliance Policy Engine** — `lib/Service/MandatePolicyService.php` evaluates a `Mandate` or `PurchaseOrderLineChange` against configurable policy rules stored in `AppSettings`: maximum authorised amount per mandate type, allowed cost category codes, role-based ceiling overrides, and duplicate-mandate detection (same debtor + creditor + scheme combination). Violations are collected and returned as structured policy findings. A passing check is a prerequisite for mandate activation and PO change approval submission. Each policy check outcome is recorded in `AuditTrail`.

12. **Mandate Expiry Background Job** — `lib/BackgroundJob/MandateExpiryJob.php` runs daily (interval 86400 s), finds `Mandate` objects where `expiryDate` is in the past and `status` is `active`, sets them to `expired`, notifies the mandate owner, and records the event in `AuditTrail`. Also identifies mandates expiring within 90 days and emits a Nextcloud notification for the treasurer to trigger renewal.

13. **Mandate Register Export** — `lib/Service/MandateRegisterExportService.php` exports the mandate register as PDF or XLSX. Supports active-only or full-history export. Each page includes the generation date, authorising officer name, and version number (incremented on each export). Available via `GET /api/v1/mandates/export?format=pdf|xlsx&scope=active|all`. Satisfies the audit export user story.

14. **Upcoming-Collection Widget** — `src/components/UpcomingCollectionWidget.vue` is embedded in the Shillinq home dashboard and lists `MandateCollection` objects with `status: scheduled` in the next 14 days, sorted by collection date. Shows mandate reference, debtor name, amount, and pre-notification status (sent / pending).

15. **Seed Data** — demo records for all new schemas (ADR-016): 3 Mandate objects, 2 MandateScheme objects, 3 MandateCollection objects, 3 PurchaseOrderLineChange objects, 2 SupplierAcknowledgment objects. Loaded via the repair step idempotently.

### Out of Scope

- Full PAIN.002 return message parsing and automated failure reconciliation — deferred to bank-reconciliation change
- SMS or postal pre-notification delivery — Nextcloud email notifications only
- Integration with external e-signature platforms (DocuSign, Sign.nl) — the built-in signing flow is used
- SEPA Instant Credit Transfer (SCT Inst) collection — PAIN.008 only (direct debit)
- Supplier self-registration for mandate counterpart role — handled by the supplier-management change
- AI-assisted mandate renewal recommendations — deferred

## Acceptance Criteria

1. GIVEN a `Mandate` in `draft` status passes all `MandatePolicyService` checks WHEN the mandate owner activates it THEN status changes to `active`, `signatureDate` is recorded, `nextCollectionDate` is computed, and the treasurer receives a Nextcloud notification
2. GIVEN a `Mandate` with `frequency: monthly` and `nextCollectionDate: 2026-05-01` WHEN `MandateLifecycleService.advanceCollectionDate()` is called after settlement THEN `nextCollectionDate` advances to `2026-06-01`
3. GIVEN a `Mandate` in `pending` status has a shareable signing token WHEN the counterpart opens the link and accepts THEN status changes to `active`, a `MandateSignatureEvent` is recorded with IP address and timestamp, and a PDF evidence file is attached to the mandate
4. GIVEN a `PurchaseOrderLineChange` with `status: approved` is notified to the supplier WHEN the supplier follows the acknowledgment link and accepts THEN `SupplierAcknowledgment.status: accepted` is recorded, the `PurchaseOrderLineChange.status` moves to `acknowledged`, and the `PurchaseOrder` lines are updated
5. GIVEN a `Mandate` with the same debtor, creditor, and scheme as an existing active mandate is submitted WHEN `MandatePolicyService` evaluates it THEN a policy finding "Dubbel mandaat gedetecteerd" is returned and the mandate cannot be activated until the finding is resolved or overridden by an admin
6. GIVEN `GET /api/v1/mandates/export/pain008?collectionDate=2026-05-01` is called WHEN the service runs THEN a valid PAIN.008 XML file is returned containing all `MandateCollection` objects with `scheduledDate: 2026-05-01` and `status: scheduled`, with correct UMR, IBAN, BIC, and creditor identifier fields
