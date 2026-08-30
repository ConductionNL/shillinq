---
sidebar_position: 1
title: DBA compliance marker
description: Run a per-engagement Wet DBA intake, read the 0-100 risk score and its four risk bands, interpret monitoring flags, curate the evidence dossier, and export an audit report for the Belastingdienst.
---

# DBA compliance marker

Since the Wet DBA *handhavingsmoratorium* was lifted on 1 January 2025, the
Belastingdienst actively enforces the rules on schijnzelfstandigheid (false
self-employment). A single negative assessment can reclassify a ZZP engagement to
*werknemer* status, triggering loonheffing and sociale-zekerheid-premies across
multiple tax years. Shillinq's **DBA compliance marker** turns that legal regime
into a structured, evidence-driven workflow: a per-engagement intake, a rolling
risk score, automated monitoring flags, a curated evidence dossier, and an
audit-ready export.

This feature serves both audiences Shillinq supports: the **ZZP-perspectief**
(opdrachtnemer-zijde) and the **opdrachtgever-perspectief** (MKB inhuur-veiligheid).

## The six registers

| Register | What it holds |
|----------|---------------|
| **DBA Opdracht** | The per-engagement record — intake status, chosen modelovereenkomst, current risk score and band, open-flag count, evidence-dossier link. |
| **DBA Intake** | The questionnaire answers, scored on the three Wet-DBA pijlers plus the Deliveroo criteria. |
| **Modelovereenkomst Register** | Belastingdienst-approved templates and your own variants, with version history and a validity date. |
| **DBA Risicoflag** | Immutable monitoring flags (factuurpatroon, concentratie, langjarigheid, VBAR threshold, …), each with its legal basis and a suggested action. |
| **DBA Portfolio Risico** | The periodic roll-up across all your active engagements (revenue concentration, long-term relations, exclusivity). |
| **DBA Evidence Dossier** | The *stukkenlijst* per engagement, with file references, SHA-256 hashes and a completeness score. |

## Choosing a compliance mode

At first configuration each onderneming picks a compliance mode (REQ-DBA-000):

- **Soft mode** — high-risk engagements raise warnings but never block. The risk
  level is recorded in the audit log; monitoring flags appear on the dashboard.
- **Hard mode** — a HOOG-risk engagement blocks the first factuur until a manager
  records an override reason (kept in the audit trail).
- **Intermediair mode** — adds the stricter tussenkomst-driehoek assessment for
  brokered engagements.

The mode applies to every future engagement under that administration.

## The DBA intake

The DBA intake is **deferred until you send the first factuur** for an
engagement, and **enforced there** — no factuur leaves the system without a
completed intake (REQ-DBA-001). This keeps dead leads overhead-free while making
the assessment unavoidable once money changes hands.

For a one-off engagement under €5.000 you are offered a **verkorte intake** (3
questions instead of the full 20); its risk band is recorded as
`VERKORT_LAGE_DREMPEL`.

### How the risk score works

The score runs 0–100 and is the sum of four subtotals (REQ-DBA-003):

| Pijler | Range | What it measures |
|--------|-------|------------------|
| Gezagsverhouding | 0–20 | Instructions, fixed presence, mandatory team meetings (authority/control). |
| Persoonlijke arbeid | 0–20 | Whether you can — and actually do — let yourself be replaced. |
| Financieel risico | 0–20 | Invoice patterns, payment guarantee, own investment. |
| Deliveroo-criteria | 0–40 | Case-law factors: nature of work, duration, exclusivity, own clients, own marketing. |

The total maps onto **four risk bands**:

| Band | Score | Meaning |
|------|-------|---------|
| **LAAG** | 0–24 | Low risk. |
| **LAAG_MIDDEN** | 25–49 | Elevated — monitor. |
| **MIDDEN_HOOG** | 50–74 | High — advisory caution. |
| **HOOG** | 75–100 | Very high — blockable in hard mode. |

The score is computed from your intake answers. The band derivation, the
first-factuur gate and the VBAR check are the only logic that runs outside the
declarative engine; everything else is data.

## Modelovereenkomsten

When the intake is complete, link a modelovereenkomst to the engagement
(REQ-DBA-002). Shillinq ships the known Belastingdienst templates
(*tussenkomst-vrij v3 – 2024*, *leverancier-zelfstandig v2*), and you may upload
your own. On linking, the model's **essential bepalingen** are shown as a
checklist — confirm that your actual contract contains each clause. If a model is
past its validity date, monitoring raises a `MODELOVEREENKOMST_VERLOPEN` flag and
advises you to pick the current version.

## Reading monitoring flags

A daily monitoring job inspects your active engagements and appends immutable
flags when a high-risk pattern appears. Flags are **advisory** (they never mutate
once raised) and each cites its fiscal basis and a concrete action.

| Flag | Raised when | Suggested action |
|------|-------------|------------------|
| `FACTUURFREQUENTIE_LIJKT_OP_LOON` | Same-day, same-amount monthly invoices for 6+ months (variation < 0.04). | Vary the amount to actual hours; invoice per deliverable. |
| `CONCENTRATIE_WAARSCHUWING` | One client is more than 70% of 12-month revenue. | Spread engagements across more clients. |
| `LANGJARIGE_HOOFDRELATIE` | A client relationship runs over 2 years with more than 50% revenue. | Document your ondernemerschap; diversify. |
| `VBAR_GRENS_ONDERSCHREDEN` | Effective hourly rate below the VBAR threshold (EUR 33, peil 2024). | Raise the rate or record a written justification. |
| `VERVANGBAARHEID_THEORETISCH` | The contract allows replacement but it never happened (18m+). | Exercise or document real substitution. |
| `MULTIPLE_ENGAGEMENT_ZELFDE_CONCERN` | Multiple engagements with entities sharing one UBO. | Review the concern-wide exposure. |
| `ICT_INTEGRATIE_IN_TEAM` | Daily scrum/stand-up participation with fixed employees (ICT-kader). | Reduce team integration where possible. |
| `HERBEOORDELING_OVERDUE` | A 12-month+ engagement's yearly reassessment is unanswered for 30 days. | Confirm or update the intake. |

### The VBAR threshold

For every outgoing factuur Shillinq computes the **effective hourly rate**
(amount ÷ hours). If it falls below the VBAR threshold (default **EUR 33**, peil
2024) a warning appears; in hard mode the factuur is blocked until override
(REQ-DBA-016). The threshold is a configurable administration setting, so when the
legislation indexes it you update one value — no migration needed.

## The evidence dossier

Each engagement builds a `DBAEvidenceDossier` — the *stukkenlijst* you would hand
the Belastingdienst in a control (REQ-DBA-007). A stuk has a type
(`GETEKENDE_OVEREENKOMST`, `FACTUUR_EERSTE`, `URENSTAAT_KWARTAAL`,
`EMAIL_ARCHIVE`, …), a file reference, the date it was added, and a **SHA-256
hash** as immutable proof.

The **completeness score** (0–1) reflects which required stukken are present. A
dossier with the signed agreement and first factuur but no quarterly urenstaten
scores about 0.67, and the UI lists exactly what is missing.

Including e-mail communication is **opt-in** (AVG art. 6): you explicitly consent
before wederpartij correspondence is archived. All evidence is kept **7 years**
from the engagement end-date (art. 52 AWR).

## Exporting an audit report

On request, generate an audit-ready PDF per engagement (REQ-DBA-008). It contains
the intake answers, the chosen modelovereenkomst with its bepalingen checklist,
the risk-score progression, the generated flags with their actions, and the
evidence inventory with SHA-256 hashes. The hash of the export itself is recorded
in the audit trail, so the document you produced can later be proven unchanged.

## Yearly reassessment and termination

Engagements running longer than 12 months trigger a **yearly herbeoordeling** on
the intake anniversary (REQ-DBA-009); if it goes unanswered for 30 days a
`HERBEOORDELING_OVERDUE` flag is raised.

When you mark an engagement **beëindigd**, Shillinq closes the evidence trail,
generates an end-report, and starts the 7-year retention clock — the dossier
becomes read-only and a delete-eligible date is computed (REQ-DBA-018).

## Legal basis

This feature is grounded in the Wet DBA (deregulering beoordeling
arbeidsrelaties, mei 2016), Burgerlijk Wetboek art. 7:610, the Deliveroo-arrest
(HR 24-3-2023, ECLI:NL:HR:2023:443), the draft VBAR (Verduidelijking Beoordeling
Arbeidsrelaties en Rechtsvermoeden) uurtarief-rechtsvermoeden, and the 7-year
bewaartermijn of art. 52 AWR. The risk model encodes published criteria; it does
not replace professional fiscal or legal advice.
