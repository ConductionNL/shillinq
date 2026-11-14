# Design: hours-to-humaniq

## 1. The split (task 1.1)

**Decision: shape 1. Shillinq derives a cost line per humaniq `TimeEntry`.**

The hour lives in humaniq, as a `TimeEntry` carrying `domainObjectRef` and
`domainObjectType`. Shillinq keeps a booking that references that entry by uuid
and carries what only the ledger needs: the snapshotted `recognisedRate`, the
`glTransactionId`, the WBSO tags (`wbsoTagId`, `activityCodeId`, `tagSource`,
`wbsoTaggedAt`), the analytical dimensions (`projectAssignmentId`,
`costProjectId`) and the derived `utilizationPercent`.

Why this shape and not the opaque allocation payload:

- **It is what ADR-107 decision 6 says.** Humaniq supplies the wage base and the
  hour; shillinq supplies the ledger-derived additions and the booking. Shape 1
  is that sentence as a data model. Shape 2 would need an argument for why the
  ADR does not mean what it says.
- **Decision 1 keeps the nine fields here.** Rate snapshots, GL links and WBSO
  tagging are ledger and subsidy concerns. Moving them into humaniq under an
  opaque name keeps them in shillinq's model in every way except where they are
  stored, which is the outcome `allocationKey`'s own `x-notes` warn against:
  humaniq would be holding data it is not allowed to interpret.
- **The statutory paths stay readable.** The urencriterium and WBSO guards keep
  reading shillinq rows with shillinq fields. Only where the hour count comes
  from changes, which is exactly what tasks 4.4 and 4.5 prove side by side.
- **The cross-app read already has one seam.** `HrmqCostRateAdapter` is the
  single place that knows humaniq's register slug, and
  `ReferencesAuditCommand` already reads humaniq through it. The new read path
  goes through the same adapter, so an install without humaniq fails in one
  place, which is where REQ-H2H-002's named empty state is decided.

Cost accepted: every booking needs a read of the humaniq entry it derives from.
Bookings are made in batches (invoice runs, WBSO export, the yearly
urencriterium count), so the read is batched per run, not per hour.

## 2. Proceeding on a Proposed ADR (task 1.2)

ADR-107 is still **Proposed**. This change proceeds anyway, and that is safe
because nothing here is irreversible until the data says it may be:

- The fields being retired have no writer anywhere in the fleet (see
  `proposal.md`, Investigation). Retiring them changes no recorded hour.
- Nothing is deleted before `occ shillinq:hours:subject-audit` reads zero on
  every reachable install (tasks 2.2 and 2.3). A non-zero count reopens this
  design instead of losing data.
- The statutory consumers cut over last, each only after the old and new counts
  agree over the same period and administration (REQ-H2H-003).
- Undoing the retirement is restoring one register overlay file
  (`uren-domain-subject-link.json`) and its test.

If ADR-107 is rejected or amended on decision 6, the work that is undone is the
read path of tasks 3.3 and 4.x; the audit command and this record stay valid.

## 3. Counts per install (task 2.2)

`occ shillinq:hours:subject-audit` prints, per administration, the entries with
a non-null `subjectApp` and, separately, with a non-null `subjectId` (an entry
with only one of them set is still data the retirement would drop). It exits 1
when either is non-zero.

| Install | Entries with `subjectApp` | Entries with `subjectId` | Run on |
|---|---|---|---|
| dev | not yet run | not yet run | |

Task 2.3 stays closed until every row of this table reads zero.
