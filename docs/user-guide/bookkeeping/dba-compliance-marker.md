---
sidebar_position: 20
title: DBA Compliance Marker
description: Per-engagement Wet DBA and VBAR compliance — intake wizard, risk scoring, automatic flag generation, evidence dossier, and Belastingdienst audit report.
---

# DBA Compliance Marker

Operationalize Wet DBA (Wet deregulering beoordeling arbeidsrelaties, mei 2016)
and the upcoming VBAR (Verduidelijking Beoordeling Arbeidsrelaties en
Rechtsvermoeden) compliance per engagement.

Since the handhavingsmoratorium lift on 1 January 2025, the Belastingdienst
resumes active enforcement; for ZZP'ers and MKB-opdrachtgevers, a structured
DBA dossier is now business-critical. The DBA Compliance Marker translates
the legal regime into operational intake, scoring, monitoring, and audit
readiness.

## Goal

By the end of this guide you will be able to register a new opdracht, run the
DBA intake (full or verkort), interpret the risk score and band, manage the
modelovereenkomst register, respond to automatic flags, curate the evidence
dossier, and export an audit-ready rapport for Belastingdienst review.

## Prerequisites

- A Nextcloud account with **Shillinq** installed and enabled.
- An active **administration** linked to the operator (ZZP-onderneming or
  MKB-opdrachtgever).
- The OpenRegister `shillinq` register is reachable (Settings → Shillinq).
- Optional: **bookkeeping-accounts-payable-receivable** for automatic
  factuurfrequentie + uurtarief monitoring (REQ-DBA-004 / REQ-DBA-016).

## 1. Pick a compliance mode

Open **Compliance → DBA compliance → Settings** and choose one of:

| Mode             | Behaviour                                                                 |
|------------------|---------------------------------------------------------------------------|
| `soft` (default) | Waarschuwingen op het dashboard; geen blokkades.                          |
| `hard`           | HOOG-risico-opdrachten blokkeren de eerste factuur tot management-override. |
| `intermediair`   | Extra strenge toets voor tussenkomst-constructies (broker / detachering).  |

The mode is per administration. Soft mode is appropriate for early adoption;
switch to hard mode once your team is comfortable with the workflow.

## 2. Register a new opdracht

Navigate to **DBA compliance → DBA Intake Wizard** and create a new opdracht
with klant, opdrachtnaam, startdatum, verwachte einddatum, en verwachte omzet.

If the opdracht is one-off and < EUR 5.000, mark it `eenmaligLageDrempel` —
the wizard will offer a 3-question short form instead of the full 20-question
intake (REQ-DBA-001).

## 3. Run the DBA intake

The wizard collects answers across four blocks:

1. **Gezagsverhouding** — instructies, resultaatvrijheid, werkoverleg-integratie (max 20).
2. **Persoonlijke arbeid** — vervangbaarheid (contractueel + feitelijk, max 20).
3. **Financieel risico** — factuurfrequentie, betalingsrisico, eigen middelen (max 20).
4. **Deliveroo-criteria** — duur, exclusiviteit, eigen klanten, eigen reclame, model, feitelijke uitvoering (max 40).

The total score (0-100) is computed live and mapped onto a risk band:

| Band            | Range  | Action                                                                  |
|-----------------|--------|-------------------------------------------------------------------------|
| `LAAG`          | 0-24   | Continue without further action.                                        |
| `LAAG_MIDDEN`   | 25-49  | Monitor; review on intake-anniversary (REQ-DBA-009).                    |
| `MIDDEN_HOOG`   | 50-74  | Advisory caution; consider modelovereenkomst review.                    |
| `HOOG`          | 75-100 | In hard mode: first factuur blocked until management-override.          |

Save the intake — the opdracht transitions to `INTAKE_VOLTOOID` and a
DBAIntake record is persisted in the OpenRegister.

## 4. Couple a modelovereenkomst

Open **DBA compliance → Modelovereenkomst Register** and pick one of the
seeded Belastingdienst-templates:

- Tussenkomstvrij — algemeen (Belastingdienst v3 - 2024).
- Leverancier-zelfstandig (Belastingdienst v2 - 2023).
- Tussenkomst (Belastingdienst v1 - 2024).

The wizard displays the essentiele bepalingen as a checklist and asks
whether the actual contract contains all of them. Confirm each clause; your
confirmations are recorded for the audit dossier (REQ-DBA-002).

You can also upload your own model PDF via **Upload custom model**. The
file's SHA-256 hash is stored automatically.

## 5. Watch the flags

The daily monitoring job (`DBAFlagGenerationJob`) generates **DBARisicoflag**
records when high-risk patterns appear. Common types:

| Flag                                     | Trigger                                                        |
|------------------------------------------|----------------------------------------------------------------|
| `FACTUURFREQUENTIE_LIJKT_OP_LOON`        | 6+ months of identical-date monthly facturen with low variation. |
| `CONCENTRATIE_WAARSCHUWING`              | One client > 70 % of 12-month revenue.                          |
| `LANGJARIGE_HOOFDRELATIE`                | Klant > 2 years AND > 50 % omzet.                               |
| `VBAR_GRENS_ONDERSCHREDEN`               | Effective hourly rate < EUR 33 (peil 2024).                     |
| `VERVANGBAARHEID_THEORETISCH`            | Vervangbaarheid claimed but never exercised in 18+ months.      |
| `MULTIPLE_ENGAGEMENT_ZELFDE_CONCERN`     | Multiple opdrachten across concern-related entities.            |
| `ICT_INTEGRATIE_IN_TEAM`                 | ICT branchekader + daily scrum participation.                   |
| `MODELOVEREENKOMST_VERLOPEN`             | Selected model is past its `geldigTot`.                          |
| `HERBEOORDELING_OVERDUE`                 | Intake older than 12 months + 30 days grace.                    |
| `WBA_VERLOPEN`                           | WBA-uitkomst past its 1-year validity.                          |

Each flag carries a `fiscaleBron` (statute or arrest) and `actieSuggestie`.
Flags are immutable; you can transition them OPEN → BEKEKEN → AFGEHANDELD /
VERVALLEN.

## 6. Manage the evidence dossier

Open **DBA compliance → Evidence Browser** and curate the **DBAEvidenceDossier**
for each opdracht. Add at least:

- Getekende modelovereenkomst (PDF).
- Eerste + laatste factuur (PDF).
- Urenstaten per kwartaal.

Each stuk gets a SHA-256 hash automatically. The `compleetheidScore` (0-1)
reflects how many of the four canonical stuk-types you have on file.

E-mail communication archive is **opt-in** per AVG (REQ-DBA-012); a
ConsentRecord is created the moment you flip the switch.

## 7. Belastingdienst audit rapport

When the Belastingdienst requests a dossier for a specific opdracht, click
**Audit-rapport exporteren** on the opdracht detail page. The system bundles:

- Intake answers + total score + band progression.
- Gekozen modelovereenkomst + essentiele bepalingen checklist.
- All generated flags + actie-suggesties + fiscal bron.
- Evidence-dossier inventory + SHA-256 hashes per stuk.

The export's SHA-256 hash is recorded in the audit-trail so the rapport can
be verified later (REQ-DBA-008).

## 8. Periodieke herbeoordeling

For opdrachten longer than 12 months, the system asks for a yearly
herbeoordeling on the intake's anniversary. If no response within 30 days,
a `HERBEOORDELING_OVERDUE` flag is generated (REQ-DBA-009). Re-run the
intake wizard to refresh the score and band.

## 9. Beeindiging procedure

When you mark an opdracht **BEEINDIGD**, the system:

1. Closes the evidence-trail (read-only).
2. Generates an end-report (intake + score-progression + flags + actions).
3. Starts the AWR art. 52 retention clock — delete-eligible 7 years after
   the `feitelijkeEindDatum`.

The retention deadline is visible on the opdracht detail page; an automatic
archive job will remove dossiers past their `retentieDeadline`.

## 10. Tussenkomst (intermediair) mode

For opdrachten via a broker / detacheringsbureau, flip
**intermediairMode** on the opdracht. The wizard then collects separate
intakes for the ZZP–intermediair en intermediair–eindklant relationships
and flags Waadi + Wka considerations (REQ-DBA-017).

## VBAR threshold (peil 2024)

The VBAR rechtsvermoeden-grens is configured under app-config key
`dba.vbar_grens_cents` (default `3300` = EUR 33,00). After indexation,
update the value via Settings → Shillinq → DBA → VBAR Threshold; no
migration is required.

## See also

- `openspec/specs/dba-compliance-marker/spec.md` — the authoritative spec.
- `openspec/changes/dba-compliance-marker/design.md` — design decisions.
- `lib/Enums/DBAConstants.php` — fiscal & policy constants in code.
- `lib/Settings/register.d/dba-compliance-marker.json` — register fragment.

## References

- Wet deregulering beoordeling arbeidsrelaties (Wet DBA), mei 2016.
- Wetsvoorstel Verduidelijking Beoordeling Arbeidsrelaties en
  Rechtsvermoeden (VBAR), 2025/2026 — uurtariefgrens EUR 33 (peil 2024).
- HR 24 maart 2023, ECLI:NL:HR:2023:443, NJ 2023/188 (Deliveroo-arrest).
- BW art. 7:610 (arbeidsovereenkomst-criteria).
- AWR art. 52 (bewaarplicht 7 jaar).
- AVG (Verordening (EU) 2016/679) — verwerking persoonsgegevens wederpartij.
- Wet allocatie arbeidskrachten door intermediairs (Waadi).
- Wet ketenaansprakelijkheid (Wka).
