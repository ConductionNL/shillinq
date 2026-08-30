# Design — Rechtmatigheidsverantwoording

## Context

Per artikel 17a van het Besluit Begroting en Verantwoording (BBV), decentrale overheden sinds 2023 moeten een rechtmatigheidsverantwoording opnemen in de jaarrekening. Het college (B&W, Gedeputeerde Staten, Dagelijks Bestuur) verklaart zelf dat alle financiële handelingen rechtmatig tot stand zijn gekomen. De negen wettelijke criteria (begroting, voorwaarden, M&O, calculatie, valutering, adressering, volledigheid, aanvaardbaarheid, europees aanbesteden, staatssteun) moeten per journaalpost getoetst worden. Fouten en onzekerheden worden geaggregeerd en vergeleken tegen toleranties (default 3% fout, 1% onzekerheid van totaal lasten inclusief mutaties reserves, of scherper per raadsbesluit). Een audit-trail per toets en bevinding is verplicht per BADO.

The change is **spec-only**. Implementation lands later through `opsx-apply` and the standard Hydra pipeline; this design explains *why* the shape is what it is.

## Goals

- Express the entire rechtmatigheidsverantwoording surface as **declarative metadata** — schemas + lifecycle + aggregations + manifest entries — per ADR-031.
- Make the spec a **CFO/Controller-readable contract** — Dutch decentrale overheden rechtmatigheidsverantwoording flow recognisable end-to-end: geautomatiseerde toetsing → handmatige materiële criteria → bevindingen → tolerantiebewaking → paragraaf-aggregatie → jaarrekening-export.
- Support **automatic lightweight checks** (begroting, calculatie, valutering, adressering, volledigheid) without adding operational burden.
- Support **manual material checks** (europees aanbesteden, staatssteun, voorwaarden, M&O) via procest-workflow integration, with evidence-attachment (bewijsstukken).
- Provide **audit-trail closure** — every toets, status change, and bevinding mutation is immutable, queryable, and exportable per accountant request.
- Enforce **tolerance-based compliance** — fouten/onzekerheden aggregated per boekjaar against raadsbesluit-defined limits.
- Produce **jaarrekening-ready paragraph** — XBRL IV3 + PDF output with college verklaring, bevindingen, en status.

## Non-Goals

- No mass-correction of toetsen; errors are fixed via correctieboeking + new bevinding.
- No real-time aggregate alerts (aggregation at period-end).
- No automatic approval of manual toetsen (procest operator confirms).
- No consolidated-reporting scope (T5 scope; this is T2 single-entity).
- No VAT/BTW posting automation (T3 scope).

## Decisions

### D1 — Four schemas + one extension

**Rechtmatigheidstoets** is the atomic unit: one geautomatiseerde or handmatige assessment of one journaalpost against one criterium. Uitkomst: voldoet, voldoet_niet, onzeker, niet_van_toepassing. Optional FK to rechtmatigheidsbevinding if uitkomst ≠ voldoet.

**Rechtmatigheidsbevinding** aggregates the impact: when one or more toetsen yield voldoet_niet / onzeker, a bevinding tracks bedrag_fout or bedrag_onzekerheid, bevindingsnummer (RV-YYYY-NNNN), oorzaak, maatregel, status (open → in_behandeling → opgenomen_in_paragraaf → opgelost).

**Rechtmatigheidsparagraaf** is the jaarrekening statement: één per boekjaar, aggregates all openstaande bevindin­gen, compares totals against toleranties, generates college verklaring (standaard or gewijzigd), and transitions to vastgesteld_college → behandeld_raad → definitief.

**Tolerantiegrens** is configuration: één per boekjaar, captures fout_percentage + onzekerheid_percentage, raadsbesluit-referentie, geldig_vanaf/tot. Auto-defaults on boekjaar-open.

**Journaalpost extension:** new mandatory `rechtmatigheid` object (status, toetsen[], samenvattend_oordeel, laatste_toetsdatum). NOT a separate register — embedded in journaalpost to preserve the transaction-level audit-trail.

### D2 — Automatic lightweight + manual material

**Automatic (trigger on journaalpost.create):** begroting (check budget per programma), calculatie (debit = credit), valutering (date in boekjaar), adressering (tegenrekening filled), volledigheid (required fields present).

**Manual (procest-integrated or UI form):** europees_aanbesteden (drempelbedragen + clustering), staatssteun (de-minimis check), voorwaarden (subsidie-voorwaarden, ARBO, AVG), M&O (misbruik, oneigenlijk gebruik). These require onderbouwing + bewijsstukken.

**PO-level toetsing:** When a PO is created in verplichtingenadministratie, begroting + europees_aanbesteden toetsen run. When factuur matches PO ± 10%, toetsen are inherited; if > 10% delta, re-toets required.

### D3 — Bewijsstukken via OpenRegister files

`Rechtmatigheidstoets.bewijsstukken[]` is an array of file:uuid references. OpenRegister's files-attached-to-object enables attachment of invoices, aanbestedingspublicaties (from TenderNed), de-minimis-verklaringen, budgetstaten, etc. Bewijsstukken are immutable per audit-trail.

### D4 — Tolerantiegrens is raadsbesluit-derived configuration

Default: 3% fout, 1% onzekerheid per BADO. Raad may tighten via raadsbesluit. On tolerantie change, all existing toetsen for the boekjaar are re-aggregated (old values remain in audit-trail; new aggregate computes against new thresholds).

### D5 — Aggregatie naar paragraaf is boekjaar-einde event

When boekjaar closes (date-based trigger or manual UI action), `ScheduledAggregation` computes:
- SUM(bedrag_fout where status ≠ opgelost) vs. tolerantiegrens_fout_bedrag → within_tolerance: true/false
- SUM(bedrag_onzekerheid where status ≠ opgelost) vs. tolerantiegrens_onzekerheid_bedrag → within_tolerance: true/false
- Generate college verklaring: if both within, use standaard text; else use gewijzigd text citing the overages
- Collect all openstaande bevindin­gen into paragraaf.bevindingen[]
- Set paragraaf.status = concept, waiting for college vaststellings­besluit

### D6 — Audit-trail via OpenRegister

Every toets, status change (toets.uitkomst change, bevinding.status change, paragraaf.status change) is audit-logged with: old value, new value, user, timestamp, reason. Exportable per accountant request in CSV or signed XBRL envelope.

### D7 — Correctieboeking links to bevinding

When a fout is discovered (bevinding created), the operator may create a correctieboeking (debit/credit reversal) and link it to the bevinding via `bevinding.correctieboeking_id` FK. The bevinding transitions to status opgelost; the original bedrag_fout remains in the paragraaf aggregation for the year of discovery (compliance history), but the dashboard marks it "resolved."

### D8 — Drempelbedragen are settings-driven

2024-2025 EU thresholds: 221.000 EUR leveringen/diensten decentraal, 5.538.000 EUR werken, 750.000 EUR sociale/specifieke diensten. Stored in `lib/Settings/drempelbedragen.json`. Clustering detection runs per leverancier + boekjaar when posten cross threshold.

### D9 — Workflow escalation via procest

Manual toetsen (europees_aanbesteden, staatssteun, voorwaarden, M&O) are assigned to procest tasks with default 10 werkdagen deadline. If unresolved, escalation to portefeuillehouder + auditcommissie. Procest updates `rechtmatigheidstoets.status` to in_behandeling → getoetst when task completes.

### D10 — Kwartaalrapportage is aggregation snapshot

Export of rechtmatigheid status at quarter-end: SUM fouten/onzekerheden YTD, comparison vs. full-year tolerance, openstaande bevindin­gen > 25.000 EUR, trend (4-quarter history). Optional auto-email to auditcommissie; always downloadable from UI.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Journaalpost toetsing trigger | T1 `journaalpost.create` event | Lifecycle action on `journaalpost` extension; automatic toetsen via `x-openregister-actions` |
| Aggregation (tolerance check, quarterly roll-up) | OR `x-openregister-aggregations` | Aggregations declared on `rechtmatigheidstoets` + `rechtmatigheidsbevinding` schemas |
| Audit-trail per toets + bevinding | T2 `bookkeeping-audit-trail` | Automatic audit-log per OpenRegister on every mutation |
| Bewijsstukken attachment | OR files-attached-to-object | `rechtmatigheidstoets.bewijsstukken[]` as file:uuid FKs |
| Manual toets workflow | procest | Procest task creation for europees_aanbesteden / staatssteun / voorwaarden / M&O; status-sync to `rechtmatigheidstoets.status` |
| Budget check (`begroting` toets) | T1 `bookkeeping-bbv-compliance` (programma + budget lookup) | Aggregation query: SUM(journaalposten.bedrag where programma = X) vs. budget |
| PO-level toetsing (`verplichtingenadministratie`) | T2 `bookkeeping-verplichtingenadministratie` | On PO.create: run begroting + europees_aanbesteden toetsen; on factuur match ± 10%, inherit or re-toets |
| Jaarrekening export (IV3 + PDF) | T2 `bookkeeping-financial-statements` | Paragraaf exported as XBRL element + PDF bijlage when status = definitief |
| TenderNed verification (optional) | OpenConnector TenderNed | Query aanbestedingspublicaties for `europees_aanbesteden` toets if CV-code present |
| Manifest navigation + pages | T1 manifest pattern | 5 entries: Rechtmatigheidstoetsing (list), Bevindingen (list), Toleranties (admin), Rechtmatigheidsparagraaf (detail per boekjaar), Audit Export (download) |

**Net new code in implementation cycle:** 4 schema declarations + 10 automatic + manual toets actions + 1 lifecycle on journaalpost extension + 3 aggregations (tolerance check, quarterly roll-up, paragraaf generation) + 1 scheduled task (boekjaar-einde) + 5 manifest entry pairs + optional procest task-creator + optional TenderNed query. Zero new service classes (ADR-031 declarative-first).

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Geautomatiseerde toetsing (begroting, calculatie, valutering, adressering, volledigheid) | Declarative (`x-openregister-actions` on journaalpost.create) | Pure business rules; no state machine complexity |
| Handmatige toetsing (europees_aanbesteden, staatssteun, voorwaarden, M&O) | Procest task assignment + UI form; status sync to `rechtmatigheidstoets.status` | Workflow state machine; leverage procest infrastructure |
| Bevinding creation | Lifecycle action on toets.uitkomst = voldoet_niet / onzeker | Automatic roll-up; no service |
| Paragraaf aggregatie on boekjaar-einde | `ScheduledAggregation` (declarative trigger + query) | Pure SUM + comparison; no business logic |
| Tolerance re-aggregation on raadsbesluit change | Declarative aggregation re-compute | Lookup new tolerantiegrens; re-run all aggregations for boekjaar |
| Correctieboeking → bevinding opgelost | Lifecycle action triggered by operator link | Simple status transition |
| Audit-trail | OpenRegister audit-log (automatic per mutation) | Immutable by design |

No service class authored in this envelope (ADR-031 exception: none needed; all logic is declarative or procest-integrated).

## Seed Data

**Three seeds per new administration:**

```json
{
  "tolerantiegrens": {
    "boekjaar": 2026,
    "fout_percentage": 3.0,
    "onzekerheid_percentage": 1.0,
    "vastgesteld_bij_raadsbesluit": null,
    "vastgesteld_op": null,
    "geldig_vanaf": "2026-01-01",
    "geldig_tot": "2026-12-31",
    "berekeningsbasis": "totaal_lasten_inclusief_mutaties_reserves",
    "status": "concept"
  }
}
```

(Boekjaar progression: 2026, 2027, 2028 created on demand; 2027 tolerantiegrens auto-created when boekjaar 2027 record is first opened.)

No rechtmatigheidstoets / bevinding / paragraaf seed data. These are transaction-derived.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Raad changes toleranties mid-boekjaar, causing aggregate swings | Audit-trail of tolerance change; old aggregates remain visible; quarterly snapshots provide trend history |
| Manual toets remains unfinished → factuur can't close | Procest escalation at 10 werkdagen; portefeuillehouder notificatie + auditcommissie; manual override option (documented risk) |
| Clustering detection misses cross-department leverancier spend | Systemwide leverancier ID (BVD number); clustering query is central; Finance controls the threshold enforcement |
| Drempelbedragen change (EU wijzigingen mid-year) | Manual update to `lib/Settings/drempelbedragen.json` + audit event; re-evaluation of pending toetsen optional |
| Audit export 100k+ toetsen is slow | Lazy-load aggregations; export in CSV batches (e.g., 1k rows per HTTP chunk); signed XBRL envelope generated offline |
| Correctieboeking semantics unclear: does it auto-create bevinding or mutate original? | **Design choice:** link via FK only; operator manually creates new bevinding (status: opgelost) if desired. Audit-trail shows both original error + correction |
| College verklaring text doesn't grammatically fit tolerance status | Template has fallback: if fouten/onzekerheden with­in tolerance, use standaard; else cite figures. Manual override field (max 500 chars) for edge cases |
| PO ± 10% delta threshold may be too loose / tight | Configurable per administration in `lib/Settings/procurementSettings.json`; default 10% per Dutch best-practice |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with the four schemas + tolerantiegrens seeds (additive — no existing schema changes).
2. `journaalpost` schema is extended with mandatory `rechtmatigheid` sub-object (backward compatible: existing posten get `rechtmatigheid: { status: 'niet_getoetst' }`).
3. `lib/Settings/drempelbedragen.json` is created with 2024-2025 EU thresholds.
4. `src/manifest.json` is patched with 5 new menu entries + their pages (additive).
5. `lib/Settings/procurementSettings.json` is updated with PO-delta threshold (default 10%).
6. Scheduled task (boekjaar-einde trigger) is registered in the task scheduler.
7. Procest integration (optional): task-template definitions for manual toetsen.

Down-direction: registers are non-destructive — reverting removes schemas and disables UI; all toets/bevinding data is retained in audit-trail for compliance history.

---

## Appendix: Dutch Entities from Context-Brief

### Entity: Rechtmatigheidstoets

```json
{
  "id": "uuid",
  "journaalpost": "uuid|ref:journaalpost",
  "criterium": "begroting|voorwaarden|misbruik_oneigenlijk_gebruik|calculatie|valutering|adressering|volledigheid|aanvaardbaarheid|europees_aanbesteden|staatssteun",
  "uitkomst": "voldoet|voldoet_niet|onzeker|niet_van_toepassing",
  "toetsdatum": "2026-03-15",
  "toetser": "uuid|ref:gebruiker",
  "toetstype": "automatisch|handmatig|extern",
  "onderbouwing": "Factuur 2026-441 valt onder raamovereenkomst RO-2024-12...",
  "bedrag_betrokken": 12450.00,
  "bewijsstukken": ["file:uuid", "file:uuid"],
  "regelverwijzing": "BBV art. 17a lid 2; gemeentelijk inkoopbeleid 2024 art. 8.3",
  "rechtmatigheidsbevinding": "uuid|ref:rechtmatigheidsbevinding|nullable"
}
```

### Entity: Rechtmatigheidsbevinding

```json
{
  "id": "uuid",
  "bevindingsnummer": "RV-2026-0142",
  "soort": "fout|onzekerheid",
  "criterium": "europees_aanbesteden",
  "bedrag_fout": 47800.00,
  "bedrag_onzekerheid": 0,
  "boekjaar": 2026,
  "programma": "5.1",
  "omschrijving": "Onderhandse gunning aan leverancier X...",
  "oorzaak": "Inkoper niet bekend met clustering meerjarige opdrachten.",
  "maatregel": "Inkoopproces herzien; clustering-check toegevoegd per Q3.",
  "status": "open|in_behandeling|opgenomen_in_paragraaf|opgelost",
  "gemeld_aan": ["college", "auditcommissie"],
  "meldingsdatum": "2026-04-02",
  "verantwoordelijke_portefeuillehouder": "uuid|ref:bestuurder",
  "correctieboeking_id": "uuid|ref:journaalpost|nullable"
}
```

### Entity: Rechtmatigheidsparagraaf

```json
{
  "id": "uuid",
  "boekjaar": 2026,
  "totaal_lasten_inclusief_mutaties_reserves": 142500000.00,
  "tolerantiegrens_fout_percentage": 3.0,
  "tolerantiegrens_fout_bedrag": 4275000.00,
  "tolerantiegrens_onzekerheid_percentage": 1.0,
  "tolerantiegrens_onzekerheid_bedrag": 1425000.00,
  "totaal_geconstateerde_fouten": 213400.00,
  "totaal_geconstateerde_onzekerheden": 89200.00,
  "binnen_tolerantie": true,
  "verklaring_college": "Het college verklaart...",
  "bevindingen": ["uuid", "uuid"],
  "vastgesteld_door_college_op": "2027-05-12",
  "behandeld_in_raad_op": "2027-06-20",
  "status": "concept|vastgesteld_college|behandeld_raad|definitief"
}
```

### Entity: Tolerantiegrens

```json
{
  "id": "uuid",
  "boekjaar": 2026,
  "fout_percentage": 3.0,
  "onzekerheid_percentage": 1.0,
  "vastgesteld_bij_raadsbesluit": "RB-2025-117",
  "vastgesteld_op": "2025-11-14",
  "geldig_vanaf": "2026-01-01",
  "geldig_tot": "2026-12-31",
  "berekeningsbasis": "totaal_lasten_inclusief_mutaties_reserves"
}
```
