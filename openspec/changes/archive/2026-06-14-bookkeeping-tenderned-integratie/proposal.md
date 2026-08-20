# Proposal: bookkeeping-tenderned-integratie

`kind: integration` per ADR-032 — the centre of mass is external API integration + obligation lifecycle automation + cross-tenant audit-trail preservation.

## Summary

Introduce TenderNed (Dutch central procurement platform) integration into Shillinq's obligation and commitment management. This change enables automatic import of won tenders as financial obligations (verplichtingen), real-time budget-impact visibility, and continuous status synchronization during contract execution. Both aanbestedende dienst (public buyer) and inschrijvende leverancier (private vendor) roles are supported with role-specific workflows.

The integration consumes OpenConnector's TenderNed source definition and polling job (5-minute cadence), extends the Verplichting schema with `bron` and `bronReferentie` fields, and materialises a new OpdrachtUitvoering schema for milestone-based delivery tracking. Full audit-trail linkage to the original TenderNed dossier is preserved for ENSIA/BIO compliance.

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app structure, consumes OpenRegister's audit and file-attachment abstractions per ADR-022, and publishes budget-impact events via launchpad integration per ADR-024.

## Motivation

Municipal and provincial finance departments currently process won tenders entirely by hand: an inkoper exports a PDF from TenderNed, emails it to finance, and finance re-enters the data into Shillinq — with all attendant risks of transcription error, duplicate entry, and lag between award and financial recognition. MKB suppliers face similar friction when trying to forecast cashflow from publicly won contracts.

Context-brief.md requirements REQ-001 through REQ-008 define the desired state: the moment TenderNed registers a winning award, Shillinq automatically imports the contract details, surfaces the obligation to the contractmanager for enrichment (cost centre, GL account), and locks budget impact within 60 seconds. Milestone-based execution tracking (REQ-004) gates facturatie on proof of delivery. Status sync back to TenderNed (REQ-006) closes the loop for transparency.

The integration is necessarily two-way because the aanbestedende dienst (the entity running the procurement) needs to publish final outcomes, while inschrijvers (winning vendors) need to consume only their own awards and see payment milestones.

## Affected Projects

- [x] Project: shillinq — extends `Verplichting` schema, declares new `OpdrachtUitvoering` register, adds workflow routes in `src/manifest.json`, ships milestone-generation templates in `lib/Settings/seeds/`, integrates with openconnector's TenderNed source polling
- [x] Project: openconnector — *already shipped as of 2026-05-22*; TenderNed source and polling job exist and are stable. This change consumes them as-is.
- [x] Project: openregister — file-attachment and audit-trail abstractions consumed per ADR-022; no source changes required
- [x] Project: launchpad — consumes budget-impact events via event stream for real-time widget updates (REQ-007); integration points documented in design.md
- [x] Project: docudesk — no source changes; OpdrachtUitvoering bewijsstukken reference docudesk attachments by foreign-key URI per ADR-022
- [ ] Project: opencatalogi — future integration (post-implementation) to expose TenderNed as external catalogue

## Scope

### In Scope

- One new capability spec (`bookkeeping-tenderned-integratie`) — see the `specs/` folder
- New `TenderNedAanbesteding` schema registration with fields: `aanbestedingId`, `tenderNedUrl`, `titel`, `beschrijving`, `cpvCodes`, `aanbestedendeDienst`, `gunningsDatum`, `contractWaarde`, `looptijdStart`, `looptijdEind`, `gegundeLeverancier`, `status`, `verplichtingId`
- Extensions to `Verplichting`: new `bron` enum field (values: `tenderned`, `manual`, `inkooporder`), `bronReferentie` (TenderNed aanbestedingId), and `mijlpalen` array (milestone objects with datum, omschrijving, percentage, status, factuurnummer)
- New `OpdrachtUitvoering` schema with: `verplichtingId`, `mijlpaalId`, `opleveringsDatum`, `opleveringsType`, `goedgekeurd`, `goedkeurder`, `bewijsstukken`
- REQ-001: Manual TenderNed dossier import via concept-verplichting workflow
- REQ-002: Automatic gunning trigger — polling detects status change, auto-promotes concept to active, notifies contractmanager
- REQ-003: Milestone-planning generation based on contract type and duration
- REQ-004: Proof-of-delivery (bewijsstuk) enforcement per milestone
- REQ-005: ENSIA-compliant audit-trail linkage to original TenderNed dossier
- REQ-006: Status-sync back to TenderNed (only for aanbestedende dienst role)
- REQ-007: Real-time budget-impact widget refresh (60-second SLA)
- REQ-008: Vendor cashflow-forecast integration for MKB suppliers
- Manifest navigation: Procurement > TenderNed Aanbestedingen (index), Procurement > Mijn Contracten (vendor-filtered detail list), Obligation detail includes linked aanbestedingen and milestones
- Audit-trail immutable recording of all state transitions, sourcing, and approvals (consumed from OR per ADR-022)
- File attachments (bewijsstukken) stored in docudesk per ADR-022

### Out of Scope

- **OpenConnector source implementation** — the TenderNed source and polling job already exist in openconnector as of 2026-05-22. This change *consumes* them; it does not author them.
- **Frontend UI for milestone-detail editing** — first implementation surfaces milestones as read-only on the obligation detail page. Interactive milestone amendments are T+2 work.
- **Multi-year contract rollover automation** — REQ-008 notes that long-running contracts may span multiple budget years. Splitting contracts across fiscal years is out of scope here; documented as a T+1 enhancement.
- **EForms-NL outbound publishing** — REQ-006 sends status updates back to TenderNed; EForms-NL compliance (EU stanaard) is verified but the outbound publishing format is TenderNed-API-native, not full EForms-NL XSD generation.

## Constraints & Standards

- **Aanbestedingswet 2012** (artikel 2.135) — gunning publication is mandatory; this change ensures Shillinq reflects the award promptly so a follow-up status sync can update TenderNed's public dossier
- **TenderNed-API** (Logius, REST/JSON) — stable as of 2026-05-22; polling interval is 5 minutes per openconnector's job definition
- **CPV 2008** — procurement categories parsed from TenderNed dossier and stored on `TenderNedAanbesteding.cpvCodes`; no reclassification
- **eForms-NL** (EU notice standard, mandatory from 25 Oct 2023) — recognised but not generated by this change; TenderNed source already handles EU compliance
- **NEN 2748** (facilities procurement terminology) — referenced in context-brief.md; no special handling (standard terms map to existing Verplichting fields)
- **ENSIA / BIO** — audit-trail requirement (REQ-005) enforced by recording TenderNed dossier reference on every mutation; immutable log consumed from OR
- **NLCS / NLCIUS** (eFacturatie) — milestone invoices link back to TenderNed contract; structure follows UBL/Peppol BIS 3.0 standard (T5 scope)

## Rollback Plan

- Disable TenderNed polling job in openconnector (one-line config change in openconnector's admin UI)
- Mark all `bron: tenderned` obligations as `status: archived` — contractmanagers manually recreate as `bron: manual` if needed
- Delete `OpdrachtUitvoering` register from shillinq's `lib/Settings/shillinq_register.json`
- Revert `Verplichting` schema extensions via migration (restore old schema, move existing `bronReferentie` + `mijlpalen` data to an external archive if audit-trail discovery is needed)

## Open Questions

1. **Partial vs. full milestone matching (design.md Q1)**: When a milestone is marked complete in Shillinq but the vendor hasn't uploaded a bewijsstuk, does the system auto-create a dunning reminder or wait for manual intervention? Spec assumes manual intervention; implementation may optimize to auto-escalate after N days.
2. **Concurrent aanbestedingen with same leverancier (design.md Q2)**: If a single MKB supplier wins multiple TenderNed tenders in rapid succession, does the omzet-prognose widget correctly aggregate across all concurrent obligations? Spec treats each as independent; implementation should verify no double-counting in UI.
3. **Cross-tenant visibility (design.md Q3)**: A multi-tenant gemeente instance may have multiple kostenplaatsen / departments. Does the contractmanager's permissions model naturally partition TenderNed imports by kostenplaats, or is role-based filtering needed? Spec assumes OR's RBAC handles this; design.md documents the assumption.

## Risks

1. **Network dependency on TenderNed**: Polling job failure or API instability could prevent obligation creation. Mitigation: openconnector's polling includes 3-retry exponential backoff; manual-import fallback (REQ-001) allows contractmanager to unblock.
2. **Stale contractWaarde due to polling interval**: TenderNed-side price amendments may not sync within the 5-minute polling window. Mitigation: contractmanager can manually edit Verplichting after audit (design.md), and ENSIA audit-trail shows the amendment timestamp + user.
3. **ENSIA audit-trail volume**: Each state transition on TenderNedAanbesteding + Verplichting + OpdrachtUitvoering generates a log entry. High-volume municipalities could see thousands of entries per month. Mitigation: OR's immutable audit table is indexed by entity ID + timestamp; no application-level performance risk expected, but design.md recommends testing with 500+ obligations.

## Success Criteria

- Manual TenderNed import (REQ-001) successfully creates concept-verplichting within 5 seconds
- Automatic gunning trigger (REQ-002) detects award and promotes obligation within 15 seconds of TenderNed status change (subject to 5-minute polling cadence)
- Milestone-planning generation (REQ-003) completes within 3 seconds for 10-year contracts
- Budget-widget update (REQ-007) shows new obligation within 60 seconds
- ENSIA auditor can trace obligation back to TenderNed dossier via `bronReferentie` link in under 10 seconds
- Vendor cashflow-forecast (REQ-008) accurately distributes contractWaarde across milestone dates (tested with 5 sample contracts)

## Related Specs / ADRs

- See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md) — this change is NOT in the canonical 5-tier breakdown; it is a cross-cutting procurement integration (T-extra or T5 scope depending on future organization)
- ADR-022: Apps consume OpenRegister abstractions (audit, RBAC, file attachments) — this change follows that pattern
- ADR-024: Manifest shape — navigation entries and detail-page bindings go in `src/manifest.json`
- ADR-031: Declarative business logic — the milestone-generation rules and approval-gating are declared on the schema via `x-openregister-lifecycle`
