# Design — Detachering + Payroll Administratie

**status: pr-created**

## Context

Salarisbureaus (ADP, Loket, Visma, Nmbrs) own the per-medewerker
loonberekening. Shillinq's role is to ingest the salaris-feed,
materialise balanced journal entries (loonkosten DR / nettoloon CR
/ sociale-premies CR / loonheffing CR / pensioen CR), and bridge
ZZP + Wet DBA + IB47 administratie. Without dedicated primitives,
every salarisbureau needs a bespoke importer + hand-mapped entries.

This change is **spec-only**. Implementation lands later through
`opsx-apply`; this doc explains *why* the shape is what it is.

## Decisions

### D1 — All external HTTP via openconnector — NO app-local payroll clients

Per ADR-019, every external call (salarisbureau feeds,
Belastingdienst IB47 upload) MUST be an openconnector source row.
No `lib/Service/AdpClient.php`, no `lib/Service/LoketClient.php`,
no comparable PHP clients. The openconnector source owns the
endpoint URL, OAuth2 flow, and mapping target.

### D2 — `SalarisFeed` raw-import register decouples ingestion from materialisation

The incoming salaris-feed batch materialises as a `SalarisFeed`
register (with `schema:DataFeed` annotation) carrying the raw
import data before mapping to journal entries. This decouples
"what came in" from "what got booked" and gives an audit trail
for reconciliation when a batch fails halfway.

### D3 — Feed-to-JournalEntry mapping as a declarative `x-openregister-mappings`

The mapping from salarisbureau-feed to balanced `JournalEntry`
lines is an `x-openregister-mappings` declaration — NO PHP mapper
service. Each materialised journal MUST produce a balanced GL
transaction per T1 REQ-GL-001.

### D4 — Wet DBA via `OpdrachtgeversVerklaring` + standaard docudesk template

For ZZP-detachering, the opdrachtgevers-verklaring per assignment
is recorded administratively in an `OpdrachtgeversVerklaring`
register with `verklaringStatus` (`concept | overeengekomen |
beëindigd`), `modelOvereenkomst` URI (the Belastingdienst model
overeenkomst), and `risicoBeoordeling` (`geen | laag | midden |
hoog`). The standaard opdrachtgeversverklaring renders via docudesk.
No PHP DBA service.

### D5 — IB47 with mandatory BSN encryption + RBAC

`IB47Record.ontvangerBSN` is declared with `x-openregister-encryption`
(at-rest field encryption) AND RBAC restricting read to
`payroll-officer`. Every access logs to audit-trail-immutable
per ADR-022. The IB47 yearly aggregation MUST produce totals
equal to the sum of 12 monthly dry-runs (€0 tolerance) — a
testable invariant.

### D6 — Belastingdienst IB47 submission via openconnector

The IB47 yearly batch submission to the Belastingdienst MUST be
an openconnector source row; the docudesk template renders the
IB47 form per the Belastingdienst XML schema 2026. Shillinq
references the source by id from the docudesk template's
output-channel.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| AP register | T2 `bookkeeping-accounts-payable-core` | Salaris-feed materialises into JournalEntry that posts against AP for nettoloon / premies / loonheffing. |
| JournalEntry | T1 `bookkeeping-journal-entries` | Salaris-feed mapping produces balanced JournalEntry of subtype `loonkosten`. |
| Mapping engine | `x-openregister-mappings` | Declarative feed → journal-entry mapping. |
| Lifecycle engine | `x-openregister-lifecycle` (ADR-031) | `OpdrachtgeversVerklaring` lifecycle `concept → overeengekomen → beëindigd`. |
| Document rendering | docudesk (ADR-022) | Opdrachtgeversverklaring + IB47 form templates. |
| External feeds + submission | openconnector (ADR-019) | 5 source rows (ADP / Loket / Visma / Nmbrs + Belastingdienst IB47). |
| RBAC + field encryption | OR authorization + x-openregister-encryption | `IB47Record.ontvangerBSN` encrypted + restricted to payroll-officer. |
| Audit trail | OR audit-trail-immutable (ADR-022) | Submission events + access events. |
| Aggregation engine | `x-openregister-aggregations` | Per-tax-year IB47 totals. |
| Retention class | T3 `bookkeeping-archiefwet-retention` | Personnel records inherit existing 7-year retention class. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | 1 entry behind `featureFlags.mkb-detachering` with 3 sub-pages. |

**Net new code in implementation cycle**: 3 schema declarations
(`SalarisFeed`, `OpdrachtgeversVerklaring`, `IB47Record`) + 1
mapping declaration + 1 aggregation declaration + 2 docudesk
templates + 5 openconnector source rows + 1 manifest entry. No
new PHP service.

## Seed Data

None. Detachering + payroll administratie is operationally
authored.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Salarisbureau API contracts drift | Mapping declarations are schema-only edits when a field renames. |
| Wet DBA risico-beoordeling subjective | Operator-classified enum; docudesk template renders the chosen risico. |
| BSN privacy footprint | Encryption at-rest + RBAC restricting read to `payroll-officer` + audit-trail logging on every access. |
| IB47 monthly dry-run vs annual final reconciliation | Aggregation invariant: final totals MUST equal sum of 12 monthly dry-runs (€0 tolerance) — testable in implementing cycle. |
