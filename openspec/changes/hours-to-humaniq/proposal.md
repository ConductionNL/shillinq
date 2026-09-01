# Change: hours-to-humaniq

## Why

ADR-107 decision 6 (`hydra/openspec/architecture/adr-107-money-and-effort-ownership.md`)
assigns booked hours to one app, and it is not this one:

> **Effort is recorded against the domain object and costed by hrmq.** Hours
> logged on a case are hrmq time entries carrying the case reference. Hours ×
> the composed hourly cost becomes a cost allocation dispatched to Shillinq,
> taakveld taken from the case's `caseType`. The domain app supplies **context
> and classification**; hrmq supplies **the wage base**; Shillinq supplies
> **the ledger-derived additions and the booking**.

Shillinq ships the opposite arrangement. `lib/Settings/register.d/uren-domain-subject-link.json`
adds `subjectApp` + `subjectId` to `UrenRegistratie` so a domain app can book
hours against a case here. Its own `_meta` cites the same ADR:

> Per hydra ADR-081, the domain app CLASSIFIES and Shillinq AGGREGATES.

Both readings cite ADR-081. That number was claimed by two documents until the
2026-08-26 renumbering, so the citation resolves to whichever the reader
assumed. Read against ADR-107 as it now stands, decision 6 is explicit about
where an hour lives, and this overlay contradicts it.

Note the ADR's status is **Proposed**, not Accepted. This change proceeds on it
anyway, for the reason in the next section: the arrangement it replaces does
not work.

## Investigation: nobody writes these fields, and nobody reads them

Before designing a migration, we checked what would have to migrate. The answer
is nothing.

**`UrenRegistratie.subjectApp` has no writer.** A fleet-wide grep across every
PHP, JS, Vue and JSON file in the workspace finds the field in exactly three
places: the schema overlay that declares it, a shillinq unit test asserting the
declaration, and one consumer.

**That consumer is a dossiq KPI, and it can only ever read zero.** Dossiq's
`CaseDetail` manifest carries a `case-kpis-hours` tile summing
`shillinq.UrenRegistratie.hours` filtered on `subjectApp: "dossiq"` and
`subjectId: @objectId`. Nothing writes a row matching that filter, so the tile
reports 0 hours on every case in every install. It has done so since it
shipped.

**Humaniq's side is unwired too.** `TimeEntry.domainObjectRef` and
`domainObjectType` are declared in `hr-cost-rate.json` and nothing in the fleet
writes or reads them either. The `x-notes` still give `procest:case` as the
example, an app id that was renamed to `dossiq`.

So the fleet holds two competing designs for case hours, and neither has ever
carried a record. This is the same failure ADR-107 itself documents about
procest's IV3 report: an aggregator reading a field almost nothing filled.

**Caveat.** We checked the source tree, not a production database. An operator
could have created rows by hand through the OpenRegister UI. Any install
running this change should count `UrenRegistratie` rows with a non-null
`subjectApp` before upgrading. The verification task below does that.

## What changes

`UrenRegistratie` stops being a place to book hours against a domain object.
Humaniq's `TimeEntry` becomes the only one, and it grows the case reference it
was already declared for.

Shillinq keeps everything ADR-107 decision 1 gives it. It remains the only
general ledger and the only statutory reporter. What it stops doing is holding
the hour.

### The design question this change must answer

`UrenRegistratie` carries nine fields `TimeEntry` has no equivalent for:

| Field | What it is for |
|---|---|
| `recognisedRate` | RateCard hourly rate snapshotted at booking time |
| `glTransactionId` | The GL transaction this hour posted to |
| `wbsoTagId`, `activityCodeId`, `tagSource`, `wbsoTaggedAt` | WBSO subsidy tagging |
| `projectAssignmentId`, `costProjectId` | Analytical dimensions |
| `utilizationPercent` | Derived utilization per REQ-CPA-109 |

These are ledger and subsidy concerns, and ADR-107 decision 1 keeps them here.
So the answer is not "copy them to humaniq". Two shapes are open, and
`design.md` decides between them:

1. **Shillinq derives a cost line per humaniq TimeEntry.** The hour lives in
   humaniq. Shillinq holds a booking that references it and carries the rate,
   the GL link and the WBSO tags. Matches decision 6 exactly. Costs a
   cross-app read on every booking.
2. **Humaniq grows an opaque allocation payload.** `TimeEntry.allocationKey`
   already exists as an opaque ledger dimension that humaniq refuses to
   interpret. Extending that idea keeps the hour in one row. Risks smuggling
   shillinq's model into humaniq under an opaque name, which is what
   `allocationKey`'s own `x-notes` warn against.

### Scope

In scope, in this app:

- Retire `subjectApp` / `subjectId` from `UrenRegistratie`.
- Repoint the four specs that read hours: `invoice-from-time-and-expense`,
  `time-expense-invoice-intake`, `wbso-uren-tagging-and-export`,
  `zzp-urencriterium-tracker`.
- Repoint the six code consumers: `TimeIntakeService` (10 references),
  `WBSOExportValidationGuard` (5), `FinancialDashboardService` (3),
  `InvoiceGenerationService` (2), `UrencriteriumGuard` (2),
  `SubjectCostAggregator` (1).
- Keep the ledger, the WBSO export and the urencriterium guard working. These
  are statutory. A Dutch self-employed person loses a tax deduction if the
  1225-hour count is wrong.

Out of scope, tracked elsewhere:

- The humaniq write path and the shared hours widget ship with the dossiq case
  detail work.
- ADR-107's composed `hourlyCost` model is a separate programme.

## Risks

**The urencriterium and WBSO paths are statutory.** Both feed a tax position.
Neither may read a partial hour set during the migration. Tasks below keep the
old read path alive until the new one is proven against the same numbers.

**Humaniq becomes a hard dependency for hours.** Today shillinq books hours
alone. After this it cannot, and no fleet app declares an `<app>` dependency in
`info.xml`. An install without humaniq must degrade to a visible empty state,
never a silent zero. The dossiq KPI's current behaviour is exactly the failure
to avoid repeating.
