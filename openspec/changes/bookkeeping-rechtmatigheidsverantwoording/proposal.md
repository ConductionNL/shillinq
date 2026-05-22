# Proposal: bookkeeping-rechtmatigheidsverantwoording

`kind: spec-driven` per ADR-031 — declarative register design with lifecycle, aggregations, and audit-trail. Four new schemas (`rechtmatigheidstoets`, `rechtmatigheidsbevinding`, `rechtmatigheidsparagraaf`, `tolerantiegrens`) plus extension of `journaalpost` with mandatory `rechtmatigheid` field. Geautomatiseerde toetsing, handmatige workflow integration via `procest`, tolerance-based aggregation, and audit export for external accountant.

## Summary

Introduce the **Rechtmatigheidsverantwoording (mandatory since 2023)** capability for Shillinq as a T2 compliance backbone. Per artikel 17a van het Besluit Begroting en Verantwoording (BBV), decentrale overheden must declare that all financial transactions have been processed legitimately within BBV tolerances (3% fout / 1% onzekerheid van totaal lasten inclusief mutaties reserves, or stricter per raadsbesluit). This change declares:

- **Rechtmatigheidstoets** — automated or manual assessment of a single journaalpost against one of nine wettelijke criteria (begroting, voorwaarden, M&O, calculatie, valutering, adressering, volledigheid, aanvaardbaarheid, europees aanbesteden, staatssteun).
- **Rechtmatigheidsbevinding** — when a toets yields `voldoet_niet` or `onzeker`, a bevinding quantifies the impact, links to remediation, and rolls up into the jaarrekening-required rechtmatigheidsparagraaf.
- **Rechtmatigheidsparagraaf** — the aggregated jaarrekening statement with total fouten/onzekerheden vs. tolerance, college verklaring, and audit-trail.
- **Tolerantiegrens** — per-boekjaar tolerance configuration set by raadsbesluit (default 3% / 1% per BADO).
- **Journaalpost extension** — new mandatory `rechtmatigheid` sub-object tracking toets status, linked toetsen, and samenvattend oordeel.

Geautomatiseerde toetsing runs on journaalpost creation (begroting, calculatie, valutering, adressering, volledigheid); materiële criteria (europees aanbesteden, staatssteun, voorwaarden, M&O) are manual or workflow-integrated via `procest`. Audit-trail per toets and bevinding via OpenRegister, bewijsstukken via files-attached-to-object. Jaarrekening export via XBRL IV3 + PDF bijlage.

**Depends on:** `bookkeeping-general-ledger` (journaalpost source), `bookkeeping-bbv-compliance` (programma-indeling + budget), `bookkeeping-verplichtingenadministratie` (PO-level toetsing), `OpenRegister` audit + files, `procest` (handmatige toets-workflow), `TenderNed` (aanbestedings-verificatie), `CBS IV3` (jaarrekening-export).

## Motivation

BBV artikel 17a (in werking per 2023) is wettelijk verplicht. Zonder geautomatiseerde rechtmatigheidstoetsing per journaalpost en gesloten audit-trail per bevinding, kan de jaarrekening niet conform BBV en BADO op te leveren. De negen criteria en tolerantie-aggregatie zijn waarborgen tegen massafout; auditability voor externe accountant is een compliancevereiste.

Decentrale overheden die Shillinq gebruiken als bron-administratie kunnen niet zonder deze gating-functionaliteit.

## Affected Projects

- [x] **Project: shillinq** — adds 1 capability spec (`bookkeeping-rechtmatigheidsverantwoording`); declares 4 new registers (`rechtmatigheidstoets`, `rechtmatigheidsbevinding`, `rechtmatigheidsparagraaf`, `tolerantiegrens`); extends `journaalpost` schema; adds 5 manifest navigation entries (Rechtmatigheidstoetsing, Bevindingen, Toleranties, Rechtmatigheidsparagraaf, Audit Export) + scheduled aggregations per boekjaar-afsluiting.
- [ ] **Project: openregister** — no source changes; consumes `x-openregister-lifecycle`, `x-openregister-aggregations`, audit-log per ADR-031.
- [ ] **Project: procest** — workflow-status updates via `rechtmatigheidstoets.status` FK reference; optional escalation lane for materiële criteria.
- [ ] **Project: docudesk** — optional signing + export of `rechtmatigheidsparagraaf` as PDF/A-3 + digitale handtekening college.
- [ ] **Project: TenderNed connector** — optional OpenConnector for `europees_aanbesteden` toets (CPV-code mapping, announcement verification).
- [ ] **Project: CBS IV3 export** — consumes finalized `rechtmatigheidsparagraaf` for jaarrekening XBRL output.

## Scope

### In Scope

- One new capability spec (`bookkeeping-rechtmatigheidsverantwoording`).
- Four new schemas: `rechtmatigheidstoets`, `rechtmatigheidsbevinding`, `rechtmatigheidsparagraaf`, `tolerantiegrens`.
- Extension of `journaalpost` with mandatory `rechtmatigheid` sub-object (status, toetsen[], samenvattend_oordeel, laatste_toetsdatum).
- Geautomatiseerde toetsing: begroting, calculatie, valutering, adressering, volledigheid triggered on `journaalpost.create`.
- Handmatige toetsing: europees_aanbesteden, staatssteun, voorwaarden, M&O via workflow assignment + bewijsstukken.
- Tolerantiegrens-beheer: automatic defaults per boekjaar (3% / 1%), raadsbesluit-update triggers re-aggregation.
- Audit-trail per toets + bevinding via OpenRegister audit-log.
- Bewijsstukken via files-attached-to-object (invoices, aanbestedingspublicaties, de-minimis-verklaringen, etc.).
- Aggregatie naar `rechtmatigheidsparagraaf` bij boekjaar-afsluiting.
- Jaarrekening-export: XBRL IV3 + PDF bijlage per `bookkeeping-financial-statements`.
- Drempelbedragen: 2024-2025 Europese thresholds (221k EUR leveringen, 5.538M EUR werken, 750k EUR sociale diensten); clustering-detection per leverancier per boekjaar.
- Rapportage: kwartaal-export, programma-breakdown, openstaande bevindingen, trend-analyse.
- Workflow-integratie: PO-level toetsing in `verplichtingenadministratie`, factuurfase light-check.

### Out of Scope

- Correctie van fouten buiten audit-trail (fout-correctie moet via correctieboeking + audit-event).
- Multi-currency drempelbedragen (Shillinq T1 is EUR-only).
- Automation van materiële criteria (europees aanbesteden, staatssteun) — handmatig of procest-integrated.
- Jaarrekening-signing via handtekening (DocuDesk integration optional; college-verklaring tekst is generated).
- Real-time signalering van tolerance-overschrijding (aggregation at period-end, not transaction-level alert).
- Rechtmatigheidsverantwoording in consolidated reporting (T5 scope; single-entity only here).

## Risks & Trade-offs

| Risk | Mitigation |
|---|---|
| Toleranties te streng gesteld door raad, causing aggregation failure | Per-boekjaar raadsbesluit allows flexibility; re-aggregatie on tolerance change; portefeuillehouder alerting |
| Factuur afwijkt > 10% van PO, requiring full re-toets | Logic implemented: PO-toets overgenomen unless factuur > 10% afwijking |
| Drempelbedragen change mid-boekjaar (EU wijzigingen) | System-wide drempelbedragen in Settings; manual update required per calendar-change |
| Clustering-detection misses cross-department inkoop | Systemwide clustering per leverancier per boekjaar; centralised via BVD/Finance role |
| Audit-export slow with 100k+ toetsen | Lazy-load aggregations; export in CSV/XBRL batches; performance gate in `opsx-apply` |
| Manual toetsen not completed → factuur hangs in betaling | Workflow escalation lane in procest (default 10 werkdagen); notificatie portef.houder |
| College verklaring text doesn't match tolerance status | Template logic validates: fouten < tol && onzekerheden < tol → standaard; else gewijzigd text |

## Rollback

Spec-only — implementation cycle handles rollback:
1. Remove 4 schemas from `lib/Settings/shillinq_register.json`.
2. Remove `rechtmatigheid` sub-object from `journaalpost` schema.
3. Remove 5 manifest navigation entries + their pages.
4. Remove `ScheduledAggregation` (boekjaar-einde trigger).
5. All toets/bevinding data is abandoned (non-destructive schema removal).

## Open Questions

1. **College/Raad signing** — is DocuDesk integration required for paragraph signing, or is the generated verklaring text sufficient (no wet signature)?
2. **PO-level escalation lane** — should procest allow "hold for PO-toets" before factuur toetsing, or is factuur-phase toets sufficient?
3. **Correctieboeking linking** — should `correctieboeking_id` FK auto-create a new bevinding (status: opgelost) or mutate the original bevinding.status?
4. **De-minimis evidence** — is a self-declaration + checkbox enough, or must a digitally signed attestation be attached?
5. **Kwartaalrapportage** — should quarterly export auto-mail to auditcommissie, or is manual download from UI sufficient?

## Open Spec Dependencies

- **bookkeeping-general-ledger** — provides `journaalpost` entity + creation trigger point.
- **bookkeeping-bbv-compliance** — lookup of programma per journaalpost; budget per programma for begroting-toets.
- **bookkeeping-verplichtingenadministratie** — PO-level `rechtmatigheid` status for factuur inheritance + 10%-delta logic.
- **OpenRegister audit + files extensions** — audit-trail + bewijsstukken per entity.
- **procest workflow** — assignment of manual toetsen; escalation + due-date tracking.
- **TenderNed connector (optional)** — CPV-code lookup, announcement verification for `europees_aanbesteden`.

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app structure and `ConfigurationService::importFromApp()` repair-step seeding.
