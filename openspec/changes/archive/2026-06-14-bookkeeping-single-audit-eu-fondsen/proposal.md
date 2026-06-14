# Proposal: bookkeeping-single-audit-eu-fondsen

`kind: config` per ADR-032 — the centre of mass is declarative schemas
(`eu-project`, `eligibility-rule`, `segregated-ledger`, `eu-expenditure`,
`supporting-document`, `irregularity-report`, `audit-trail`) + declarative
cost-category and VAT-treatment rules. No specialized PHP service beyond
entity validation and document-integrity checksums (SHA-256).

## Summary

Introduce the **Single Audit voor EU-fondsen (ERDF, ESF+, JTF, RRF)** 
capability for Shillinq as one of the T3 regulatory + compliance capabilities
(per `adr-001-bookkeeping-tier-roadmap.md`). This change declares seven new
registers aligned with Verordening (EU) 2021/1060 (Common Provisions Regulation),
INTOSAI audit standards, and the Dutch single-audit-strategie (ARC):

- `eu-project` — EU-project metadata (CCI-nummer, fonds, programma, eligibility)
- `eligibility-rule` — fund- and regulation-specific cost eligibility rules
- `segregated-ledger` — dedicated EU-fondsen sub-administration per project
- `eu-expenditure` — individual expenditure with cost-category, VAT treatment, declaration status
- `supporting-document` — bewijsstukken with digital signatures, hash validation, 15-year retention
- `irregularity-report` — onregelmatigheden-detection (OLAF €10k threshold, fraud, double-funding)
- `audit-trail` — complete event log (booking → declaration → certification → payment → correction)

The EU-fondsen administration flow is declarative: schemas + lifecycle state
machines + cost-eligibility rules (SCO: flat-rate, lump-sum, unit-cost). No
custom actuarial or econometric calculation services. Financial corrections
route via audit-evidence trails. Five-year document retention with hash
integrity is enforced by docudesk integration.

This change conforms to the shared `nextcloud-app` spec for app structure
and `ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-subsidie-verantwoording`](../openspec/changes/bookkeeping-subsidie-verantwoording/) — broader subsidy accounting layer; EU-fondsen is a specialized subset
- [`bookkeeping-sisa-reporting`](../openspec/changes/bookkeeping-sisa-reporting/) — Single Information Single Audit reporting to rijksoverheid
- [`bookkeeping-cost-accounting`](../openspec/changes/add-shillinq-cost-centers-dimensions/) — cost-center allocation for project-overhead distribution
- [`docudesk`](../../docudesk/) — long-term document storage, hash validation, 15-year retention
- [`openconnector`](../../openconnector/) — IMS integration, TenderNed/TED publication, SFC2021 feed to EC

## Motivation

EU-fondsen (ERDF €1.2B, ESF+ €700M, JTF €300M, RRF €5.4B for NL 2021–2027)
represent the largest source of funding for Dutch gemeenten, provincies,
onderwijsinstellingen, and SHV-partijen. The compliance burden is exceptional:
gelaagde controle (beneficiary → managementautoriteit → Auditdienst Rijk →
DG REGIO → ECA), originele bewijsstukken for every euro, zichtbaarheids-naleving,
gendermainstreaming-rapportage, 12–15 year bewaarplicht, and financial corrections
up to 100% voor geconstateerde onregelmatigheden.

Today, most entities run ad-hoc Excel + manual document folders. Mid-term &
final audits (biennial) require complete reconstruction of geldstroom from
EU-bijdrage to eindbegunstigde-uitgave, with every receipt, aanbestedings-
dossier, urenstaat, and foto accessible. A single missed stuk costs tens of
thousands in disallowed expenditure.

Per ADR-031, the cost-eligibility model, VAT treatment logic, and segregated-
administration reconciliation are declarative metadata: entry schemas + state
machines + cost-allocation rules. External authorities (MA, Auditdienst Rijk,
DG REGIO, OLAF) consume certified accounts-package (XBRL/Excel) from Shillinq
without requiring entity accountants to re-furnish data per auditor type.

This is one of the T3 regulatory changes; this proposal scopes only the
EU-fondsen-administratie slice.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-single-audit-eu-fondsen`);
  declares 7 new registers (`eu-project`, `eligibility-rule`, `segregated-ledger`,
  `eu-expenditure`, `supporting-document`, `irregularity-report`, `audit-trail`)
  with lifecycles + cost-eligibility rules + VAT-treatment logic; adds 3 manifest
  navigation entries (EU-projecten, Declaraties, Onregelmatigheden).
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-lifecycle`, `x-openregister-cost-allocation`,
  `x-openregister-aggregations` for segregated-ledger reconciliation, cost-category
  validation, declaration-period reporting.
- [ ] Project: docudesk — (optional) `eu-document-retention` policy: SHA-256
  hashing on upload, immutability enforcement, 5-year + 1-Jan retention
  (effective 12–15 years post-programme-closure).
- [ ] Project: openconnector — (optional) IMS bridge for OLAF >€10k meldingen;
  SFC2021 feed to EC; TenderNed/TED aanbestedingsdossier validation.
- [ ] Project: purchaseq — (optional) automated aanbestedings-threshold detection
  (€143k / €221k) for EU-procurement-compliance toetsing on EuExpenditure.

## Scope

### In Scope

- One new capability spec (`bookkeeping-single-audit-eu-fondsen`) — see the `specs/` folder.
- 7 new registers: `eu-project` (CCI-nummer, fonds, managing authority, beneficiary, budget),
  `eligibility-rule` (fund + regulation article → cost-category eligibility, SCO,
  geographical/temporal scope), `segregated-ledger` (per-project sub-administratie with
  EU-vlag on every transaction), `eu-expenditure` (project_id, cost-category, gross amount,
  VAT treatment, declared amount, eu_co_funding, declaration_period, status),
  `supporting-document` (expenditure_id, document_type, source_uri, hash, retention_until_date),
  `irregularity-report` (project_id, detection_source, nature, amount, recovery_amount, ims_reference,
  status), `audit-trail` (event_type, actor, timestamp, before_state, after_state, evidence_uri).
- Segregated-ledger reconciliation: dual posting (regular GL + EU-administratie) with project-specific
  tussenrekeningen to maintain separate but reconciliable accounts.
- Cost-eligibility enforcement: per-fonds eligibility rules with cost-category mapping (personeel,
  kapitaal, externe_dienstverlening, reis_verblijf, indirecte_kosten) + Simplified Cost Option (SCO)
  support (flat-rate 15% indirecte, lump-sum milestones, unit_cost).
- VAT treatment: vlag terugvorderbaar/niet-terugvorderbaar per cost-category; validation that VAT
  terugvorderbaar → not subsidizable.
- Kwartaal-realisatie-rapportage: period-based declaration (art 73 CPR) with RCO- + RCR-indicatoren,
  XBRL/Excel export per MA requirements, digitale handtekening.
- Bewijsstukken-management: document-type validation per cost-category (contract + aanbestedingsdossier
  for >€143k leveringen), SHA-256 hash integrity, docudesk retention until 31-12 year-5-post-closure.
- Aanbestedings-compliance: threshold detection (€143k decentrale overheid, €221k centrale) for
  2026 rates; aanbestedingsdossier completeness validation; single-programming-document-reference;
  declaration-blocking for incomplete aanbestedingsdocumentatie.
- Accounts-package generation (jaarlijks + final): complete export per programma-jaar (1 juli–30 juni)
  with gecertificeerde-uitgaven-tabel, management-declaration, samenvatting controles + audits,
  uitsplitsing priority/specifiek doel; audit-portaal for doorklikbare uitgaven + bewijsstukken.
- Onregelmatigheden-melding: OLAF-drempel €10k, IMS-schema compliance, terugvorderings-plan met
  betalingsregeling, declaratie-blocking totdat correctie verwerkt.
- Financiële-correctie en terugvordering: negative-expenditure boeken, terugvorderings-administratie
  tegen begunstigde, budget reduction for toekomstige declaraties, correctie-verwerking in volgend
  accounts-package.
- Audit-trail reconstructie: 5+ jaren na project-closure, complete transactie-reconstructie met
  audit-trail (boeking → declaratie → certificering → betaling → audit-bevindingen), bewijsstukken
  met onveranderde hashes, read-only auditor-account met session-logging.
- Zichtbaarheids- en communicatie-naleving (Annex IX CPR): EU-embleem, placebijdrage-disclaimer,
  billboards/posters met foto + GPS, mediabewijs inventory, auditdossier completeness.

### Out of Scope

- No PHP service for PUC-style pension/provision calculations (EU-fondsen are non-actuarial;
  simplified cost options are declarative).
- No real-time European Commissie API integration for fund-balance checking — T4 (via openconnector).
- No multi-currency FX revaluation within EU-administratie (all EUR; co-funding amounts nominaal).
- No automated beneficiary-roster reconciliation with HRMQ (manual upload per REQ-EUF-010) — T4.
- No automated aanbestedingsdossier extraction from TenderNed/TED (purchaseq owns integration) — T4.
- No governance workflows (projectleider approval gates, managementautoriteit sign-off) per decidesk — T4.
- No OLAF investigation workflows beyond irregularity reporting and correctie-tracking.

## Risks & Trade-offs

| Risk | Mitigation |
|---|---|
| Beneficiary accidentally books non-eligible cost (e.g., VAT terugvorderbaar) into EU-project; audit detects after declaration sent | Eligibility-rule validation on booking blocks non-eligible cost-categories per project-fonds; approver sees vlag and can redirect to regular GL |
| Aanbestedings-drempel €143k/€221k changes biannually per EU regulation; Shillinq's static thresholds become stale | drempel-setting in `eligibility-rule` per fonds + year; annual audit-gate checks obsolete thresholds; T4 connector to EU-drempel database |
| Document-retention bewaarplicht begins 5 years post-programme-closure (effectively 12–15 years post-boeking for long-running projects); docudesk cost/compliance risk | Docudesk owns retention policy; Shillinq encodes retention_until_date per regulation; cost externalized to document-storage contract |
| IMS-melding €10k threshold may change; OLAF definition of "fraude" vs "dubbelfinanciering" vs "ondeugdelijke aanbesteding" unclear in edge cases | Irregularity-report form prompts for nature classification; audit-trail preserves detection source (interne_audit, OLAF, DG_REGIO); manual classification overrideable by controller |
| Mid-term audit by Auditdienst Rijk demands sample-controle of 12 expenditures; Shillinq must reconstruct complete audit-trail + bewijsstukken 5 years later | Audit-trail immutable (append-only, no edit/delete); document hashes + URIs preserved; read-only auditor-account with session-logging provides traceability |

## Rollback

EU-fondsen accounting is non-reversible once declared to managementautoriteit
(art 73 CPR declaration is a legal submission). Rollback occurs only if the spec
is rejected before any entity enters production EU-project data. Once live and
declared, corrections are journalised as IrregularityReport + financiële-correctie
records, not deletions.

## Open Questions

1. **Eligibility-rule governance**: Who maintains the eligibility rules per fund?
   Ministerie EZK for ERDF? SZW for ESF+? RWS for JTF? Shillinq provides edit
   UI but rules are external — should rules be auto-imported from EU-database
   (T4 openconnector task) or manually curated?

2. **Aanbestedingsdossier sourcing**: Should Shillinq auto-fetch aanbestedingsdossier
   from TenderNed/TED (purchaseq owns) or require manual upload? Manual v1, T4 connector.

3. **Declaration-period alignment**: Most entities declare kwartaal; some declare monthly
   or halfjaarlijks. Should Shillinq enforce kwartaal periods (art 73 CPR standard) or allow
   custom periods per MA requirement?

4. **Audit-portaal multi-language**: Should audit-portaal UI support English (for DG REGIO /
   ECA auditors)? Dutch-first v1, English T4.

## Dependencies

- **bookkeeping-subsidie-verantwoording**: EU-fondsen is a specialized subset of broad
  subsidie-verantwoording; SegregatedLedger extends the subsidy-account model with EU-specific
  cost-categories + VAT treatment.
- **bookkeeping-cost-accounting**: CostCenter + CostProject allocate overhead across projects;
  EU-projecten are cost-objects with budget tracking.
- **docudesk**: SupportingDocument file URIs point to docudesk storage; hash validation +
  retention enforcement delegated to docudesk policy.
- **openconnector**: IMS bridge (IrregularityReport.ims_submitted_at), SFC2021 feed,
  TenderNed/TED validation (T4).
- **purchaseq**: Aanbestedings-compliance detection at source; Shillinq validates
  aanbestedingsdossier-completeness in EuExpenditure workflow.

## Success Criteria

- Projectleider can set up a new ERDF-project ("Kansen voor West III"), auto-load
  eligibility rules per Verordening 2021/1058, and block non-eligible bookings without
  manual auditor review.
- Controller can book a factuur to an EU-project; Shillinq splits posting across regular
  GL + segregated EU-administratie, flags VAT treatment automatically, and maintains
  reconciliation.
- Projectleider generates kwartaal-realisatie-rapportage (RCO + RCR indicators, XBRL export,
  digitale handtekening) and submits to managementautoriteit without manual spreadsheet work.
- Projectleider uploads bewijsstukken (contract, urenstaat, foto); Shillinq validates
  document-types per cost-category, calculates SHA-256 hash, and blocks declaration until
  all verplichte stukken present.
- Ausgave >€143k triggers aanbestedings-compliance check; missing aanbestedingsdossier
  blocks declaration; presence blocks until completeness validated.
- Interne controle detects dubbelfinanciering €15.4k; controller creates IrregularityReport;
  system validates >€10k OLAF threshold, generates IMS-bericht, blocks verdere declaraties.
- Certificeringsautoriteit verwerks financiële correctie €42k (5% flat-rate); Shillinq books
  as negative-expenditure, initiates terugvorderings-administratie, reduces budget for
  toekomstige declaraties.
- 5 jaren na project-closure, DG REGIO auditor requests sample-controle; Shillinq reconstructs
  complete audit-trail + bewijsstukken (with unmodified hashes), provides read-only audit-portaal
  with session-logging.
- Projectleider reports zichtbaarheids-naleving (EU-embleem on website + posters); Shillinq
  inventory billboards + mediabewijs, delivers complete dossier for audit.
