# Design — DBA Compliance Marker

## Context

Since the Wet DBA handhavingsmoratorium was lifted on 1 January 2025, Belastingdienst
active enforcement resumes with back-tax and correction-notice risk measured in tens of
thousands of euros per engagement. For ZZP'ers, a DBA reclassification can trigger
werknemer status (loonheffing, sociale-zekerheid-premies) across multiple tax years.
For MKB opdrachtgevers, riskless inhuur is now a compliance requirement. Shillinq
operates in both domains and must offer structured, evidence-driven DBA compliance
administration.

Wet DBA compliance is grounded in three pillars (gezagsverhouding, persoonlijke arbeid,
financieel risico), jurisprudentie from the Deliveroo-arrest (HR 24-3-2023), and the
upcoming VBAR legislation (automatic werknemer-presumption at uurtarief < EUR 33,
peil 2024). This design translates that legal complexity into six declarative registers,
automated monitoring, intake workflows, and audit readiness.

Per ADR-031, the centre of mass is declarative: scoring, flagging, and aggregation are
expressed via `x-openregister-calculations` and `x-openregister-aggregations`. PHP
service classes are avoided except where ADR-031 exception applies (one single-method
guard if scoring is not expressible declaratively).

## Goals

- Operationalize Wet DBA compliance into a **per-engagement intake flow**, **rolling risk
  monitoring**, **evidence aggregation**, and **audit-ready export**.
- Ground the system in **Dutch legal sources** (Wet DBA articles, Burgerlijk Wetboek
  art. 7:610, Deliveroo-arrest criteria, VBAR draft) and jurisprudentie-case taxonomy.
- Serve **both ZZP-perspektief** (opdrachtnemer-zijde) and **opdrachtgever-perspektief**
  (MKB inhuur-safety).
- Express scoring, flagging, and aggregation **declaratively** per ADR-031; avoid bespoke
  PHP services.
- Enable **evidence-dossier curation** (overeenkomst, facturen, urenstaten, communicatie)
  with **7-year AWR retention** and **Belastingdienst-audit export**.
- Support **optional intermediair-driehoek** (ZZP–intermediair–eindklant) modelling for
  Waadi/Wka compliance.
- Offer **soft-launch VBAR monitoring** (warnings) with a switch to hard-blokkering once
  legislation takes effect (Jan 1, 2026).

## Non-Goals

- No PHP service layer (`DBAService`, `ScoringService`, `FlagEngine`).
- No live Belastingdienst WBA-webmodule API calls (upload of WBA results is manual).
- No Correctieverplichting herclassificatie-berekening (na-heffen logic) in MVP.
- No real-time bank-account category tagging based on GL posting.
- No multi-tenant intermediair administration (broker-side shillinq instances, future T5).

## Decisions

### D1 — DBA compliance is a per-opdracht intake + monitoring lifecycle

Each opdracht (per klant, per project, or per doorlopende relatie) triggers a
DBA-compliance assessment. The `DBAOpdracht` register carries intake-answers,
risk-score, open-flags, and evidence-dossier-ref. The `DBAIntake` register
stores the questionnaire answers (three-pillar scoring + Deliveroo-criteria).

**Alternative considered**: A ZZP-level compliance portfolio (one assessment per
ZZP'er across all opdrachtnen). Rejected — Wet DBA applies PER ENGAGEMENT; a ZZP'er
may have one high-risk and one low-risk client simultaneously. Per-opdracht scoping
is legally precise.

### D2 — Risk scoring is declarative, 0–100 scale, four bands

The risk-score is computed from:
- **Gezagsverhouding** (authority/control: instructions, presence, integration): 0–20 points
- **Persoonlijke arbeid** (personal service: substitutability): 0–20 points
- **Financieel risico** (financial risk: invoice patterns, payment guarantee): 0–20 points
- **Deliveroo-criteria** (case law: aard werk, duur relatie, exclusiviteit, ondernemerschap, model, feitelijke uitvoering): 0–40 points

**Total: 0–100**, with bands:
- **LAAG** (0–24): low risk
- **LAAG_MIDDEN** (25–49): elevated risk, monitor
- **MIDDEN_HOOG** (50–74): high risk, advisory caution
- **HOOG** (75–100): very high risk, blokkable in hard-mode

Scoring is a declarative `x-openregister-calculations` on `DBAIntake.totaalScore`,
summing subtotals from intake answers. If OR's engine can express weighted sums +
conditionals, no PHP service is needed (ADR-031 compliant). If not, a single-method
`DBAScoreCalculator::computeTotal()` ships (~50 LOC).

**Alternative considered**: Machine-learning-based risk classification. Rejected —
Wet DBA is a legal regime with published criteria; human-auditable scoring is
required.

### D3 — Automated flag generation replaces manual judgment

A rolling job (daily) inspects active DBAOpdrachten and generates `DBARisicoflag`
records when patterns match:
- **FACTUURFREQUENTIE_LIJKT_OP_LOON**: maandfactuur, vast bedrag, low variatie (0.04)
- **CONCENTRATIE_WAARSCHUWING**: één klant >70% omzet (12-maand rolling)
- **LANGJARIGE_HOOFDRELATIE**: klant >2 jaar, >50% omzet
- **VBAR_GRENS_ONDERSCHREDEN**: effectief uurtarief < EUR 33 (peil 2024, geïndexeerd)
- **VERVANGBAARHEID_THEORETISCH**: contract zegt vervangbaar, maar nooit gebeurd (18m+)
- **MULTIPLE_ENGAGEMENT_ZELFDE_CONCERN**: ZZP met meerdere opdrachten bij concern-gerelateerde entiteiten
- **ICT_INTEGRATIE_IN_TEAM**: ICT'ers met dagelijkse scrum/standup met vaste team (branchekader)
- **MODELOVEREENKOMST_VERLOPEN**: gekozen model is niet meer actueel

Flags are immutable audit records (once generated, only additional flags are added).
Each flag cites fiscal-grondslag + actie-suggestie.

**Alternative considered**: Real-time blocking on flag detection (refuse factuur,
block PO). Rejected — operators need context; flags are advisory. Blokkering is
opt-in (hard-mode) and admin-configurable.

### D4 — Intake is verplicht before first factuur, with skip-rules

When a new opdracht is created, the DBA intake is deferred until first factuur
(efficient: not all leads close). When first factuur is attempted, DBA intake
is enforced.

Skip-rule: If opdracht is marked "eenmalig, totaal <€5000", the abbreviated intake
(3 vragen instead of 20) is offered. Risk-score is marked `VERKORT_LAGE_DREMPEL`.

**Alternative considered**: Intake at new-opdracht time. Rejected — overhead for
dead leads; deferral is more user-friendly.

### D5 — Modelovereenkomst register is a curated list with versioning

`DBAModelovereenkomst` holds Belastingdienst-approved templates (tussenkomstvrij,
leverancier-zelfstandig, etc.) + operator-uploaded custom variants. Each model has:
- Publication URL (Belastingdienst or internal)
- Approval date + validity-end date
- Essential bepalingen (clauses that MUST be in the actual contract)
- Version number + actuelle-versie flag

Expired models are flagged in compliance scans (RISICOflag: MODELOVEREENKOMST_VERLOPEN).

**Alternative considered**: Inline model selection without a register. Rejected —
models evolve (Belastingdienst updates, legislative changes); a versioned register
enables audit trail + retroactive assessment.

### D6 — Portfolio-risico is annual aggregation across active opdrachtnen

`DBAPortfolioRisico` is computed once per year (or on-demand) and aggregates:
- Omzetconcentratie: één klant % of 12-maand omzet; thresholds (70%, 80%)
- Langjarige relaties: klanten >2 jaar + omzetaandeel
- Exclusiviteit-patroon: # opdrachtnen vs. # relaties (exclusief = low ratio)
- Multiple-engagement-zelfde-concern: concern-gerelateerde juridische entiteiten

Overall portfolio-risico is LAAG, MIDDEN, or HOOG based on aggregations.

**Alternative considered**: Per-opdracht concentration check. Rejected — concentration
is a portfolio-level phenomenon; per-opdrachtperiode aggregation is imprecise.

### D7 — Evidence-dossier is a stukkenlijst with file-refs + hashes

`DBAEvidenceDossier` is a collection-register referencing:
- Getekende modelovereenkomst (PDF, date)
- Eerste + laatste factuur (PDF refs, dates)
- Urenstaat per kwartaal (if applicable)
- E-mail archive (optional, opt-in per AVG art. 6)
- Intern memo's / contractwijzigingen

Each stuk has:
- Type enum (GETEKENDE_OVEREENKOMST, FACTUUR_EERSTE, URENSTAAT_KWARTAAL, EMAIL_ARCHIVE, etc.)
- File reference (openregister file-api URI or docudesk FK)
- SHA-256 hash (immutable proof)
- Datum (when added to dossier)

Compleetheids-score: 0–1 scale based on stuk-inventory. Missing urenstaat = lower score.
Belastingdienst audit-export includes compleetheids-score + missing-stukken list.

**Alternative considered**: Auto-archiving of all communication. Rejected — privacy
(AVG art. 6 requires explicit opt-in). Dossier is operator-curated.

### D8 — Intermediair-mode is optional, not MVP

The tussenkomst-driehoek (ZZP–intermediair–eindklant) requires separate DBA intakes
for two relationships:
1. ZZP–intermediair (broker relationship)
2. Intermediair–eindklant (placement relationship)

Each gets its own risk-score + flags. Additionally, Waadi (Wet allocatie arbeidskrachten
door intermediairs) + Wka (ketenaansprakelijkheid) compliance is noted.

Intermediair-mode is optional: set `DBAOpdracht.intermediairMode: true`. If false,
simple two-party relationship is assumed.

Intermediair-mode ships in a later `opsx-apply` slice; not in MVP.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Per-opdracht lifecycle | OR `x-openregister-lifecycle` (ADR-031) | `DBAOpdracht` and `DBAIntake` inherit lifecycle (draft → voltooid); no approval gate (intake is owner-driven) |
| Risk scoring | OR `x-openregister-calculations` (ADR-031) | Summing subtotals from intake answers; if complex rules needed, single-method PHP guard per ADR-031 |
| Rolling flag generation | OR `x-openregister-aggregations` + background job | Daily job inspects active opdrachtnen + generates `DBARisicoflag` records; aggregation-engine groups flags by (opdrachtId, flagType) |
| Portfolio aggregation | OR `x-openregister-aggregations` (ADR-031) | Annual job groups `DBAOpdracht` by ZZP-onderneming; computes omzetconcentratie, langjarigheid via SUM + GROUP BY |
| Factuur-monitoring | T2 AP/AR optional hooks | Bookkeeping AP/AR can trigger flag-generation on factuurfrequentie + uurtarief; Shillinq DBA listens for events, non-blocking if not present |
| Evidence-file storage | OpenRegister file-api or docudesk | `DBAEvidenceDossier.stukken[].fileRef` is URI (FK); file is stored externally; immutable by design |
| Audit trail | OR audit-trail-immutable (ADR-031) | Automatic on `DBAOpdracht`, `DBAIntake`, `DBARisicoflag` transitions |
| Manifest navigation | T1 manifest pattern | 3 entries (DBA Intake Wizard, DBA Portfolio Dashboard, Evidence Browser) |
| AVG-compliant communication archive | OR consent-record + AVG audit | `DBAEvidenceDossier.emailArchive` gated by explicit opt-in (`ConsentRecord`); 7-year retention per AWR |

**Net new code in implementation cycle**: 6 schema declarations, 3 calculated fields
(risk-score, compleetheids-score, portfolio-aggregatie), 1 background job (daily flag-generation +
monthly portfolio-aggregatie). At most 1 single-method PHP class
(`DBAScoreCalculator`) gated by ADR-031 exception.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| DBAOpdracht + DBAIntake lifecycle | Declarative (`x-openregister-lifecycle`) | Simple state machine (draft, submitted, voltooid) |
| Risk-score calculation | Declarative if engine supports weighted sums; else single-method PHP guard | Arithmetic: sum subtotals from intake answers |
| Deliveroo-criteria conditional scoring | Declarative if engine supports conditionals; else single-method PHP guard | E.g. "if exclusief AND langjarig>2y then boost gezagsverhouding by 5" |
| Flag generation | Background job (external to OR lifecycle) | Monitoring rule-engine running daily; generates immutable `DBARisicoflag` records |
| Portfolio-aggregatie | Declarative (`x-openregister-aggregations`) | GROUP BY ZZP-onderneming, SUM omzet, compute % per klant |
| Modelovereenkomst versioning | Register-native (immutable history, actuelle-versie flag) | No service |
| Evidence-dossier curation | Operator-driven (register UI) | No service |
| Belastingdienst audit-export | Lifecycle action (one-shot PDF generation) | OR's report-generation extension if available; else single-method PDF-composer |

No service class authored (subject to ADR-031 exception: at most one
`DBAScoreCalculator` for complex scoring).

## Seed Data

None initially. ZZP'ers and opdrachtgevers author opdrachtnen + intakes on first use.

**Modelovereenkomsten**: Seed list of known Belastingdienst templates (tussenkomstvrij
v3 – 2024, leverancier-zelfstandig v2 – 2023, etc.). Operators can upload custom models.
Optional `openconnector` sync keeps seed fresh.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Scoring not expressible declaratively | ADR-031 exception: single-method PHP guard `DBAScoreCalculator::computeTotal()` (~50 LOC, no state) |
| VBAR grens threshold updates | Store as PHP constant + mutable admin setting; updateable without migration |
| Evidence-dossier file bloat | Per-fdossier size cap + archive-after-retention job; AWR art. 52 governs retention-end deletion |
| Intermediair-driehoek complexity | Optional, gated by `intermediairMode` flag; ships in later `opsx-apply` if prioritized |
| Over-flagging (noise) | Flags are advisory; operators configure flag-thresholds per administration; blokkering is opt-in (hard-mode) |
| AVG compliance (email archive) | Explicit opt-in via `ConsentRecord`; 7-year retention period clear; deletion job runs post-expiry |
| Opdrachtgever-inhuur binding | Two-way sync with hrmq is optional; advisory-only if hrmq not deployed |

## Trade-offs

- **Declarative-first approach** trades some flexibility (complex custom rules) for auditability
  and maintainability. Operators cannot write custom scoring formulas; Belastingdienst
  audit criteria are canonical.
- **Per-opdracht scoping** (not per-ZZP'er) is legally precise but adds operational overhead
  for ZZP'ers with many clients (intake fatigue). Mitigated by abbreviated intake for small
  eenmalige opdrachten.
- **Manual evidence curation** (not auto-archiving all communication) respects privacy but
  requires discipline. Operators must actively maintain dossier.
- **Soft-launch VBAR monitoring** (warnings pre-Jan-2026) maintains user experience but
  introduces two-mode behavior (advisory then hard). Clearly documented in release notes.
