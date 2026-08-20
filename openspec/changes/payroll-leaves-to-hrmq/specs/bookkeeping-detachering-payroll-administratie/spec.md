# Spec: bookkeeping-detachering-payroll-administratie (delta — payroll-leaves-to-hrmq)

This delta REMOVES REQ-DPA-003 and MODIFIES REQ-DPA-006. REQ-DPA-001,
REQ-DPA-002, REQ-DPA-004, and REQ-DPA-005 are untouched — the pages they
describe (`OpdrachtgeversVerklaring`/`IB47Record`) survive this change,
re-homed but not otherwise changed (`payroll-leaves-to-hrmq/design.md` §3).

## Why this delta exists

`design.md` §6 (of `payroll-leaves-to-hrmq`) found this spec stale against
its own register fragment before writing this delta: `OpdrachtgeversVerklaring`
and `IB47Record` (REQ-DPA-001/002, unaffected here) are actually declared in
`lib/Settings/register.d/add-shillinq-detachering-payroll-administratie.json`,
not the identically-titled `bookkeeping-detachering-payroll-
administratie.json` fragment this spec's filename maps to — that file
instead declares `Employee`/`Payroll`/`Deduction`/`DeterminationLetter` (all
retiring per `payroll-leaves-to-hrmq`, undocumented by this spec, and not
given spec text here since they are being removed, not preserved). Neither
correction changes REQ-DPA-001/002/004/005's own text, which never named a
fragment file. REQ-DPA-003 (the salarisbureau-import requirement) and
REQ-DPA-006 (the feature-flag requirement) do need real changes, below.

## REMOVED Requirements

### Requirement: REQ-DPA-003 — Salarisbureau openconnector sources

This requirement described `SalarisFeed`'s ADP/Loket/Visma/Nmbrs
salarisbureau-import behaviour, materialising loonkosten journal entries.
Removed per `payroll-leaves-to-hrmq` `design.md` §4/§5 — `SalarisFeed` is
retiring (gap-flagged: no hrmq equivalent exists today; deletion is
contingent on `payroll-leaves-to-hrmq` REQ-PLH-005 resolving before this
requirement's removal ships).

## MODIFIED Requirements

### Requirement: REQ-DPA-006 — Manifest navigation entry

`OpdrachtgeversVerklaringen` and `IB47Jaarbatch` MUST be reachable from the
`DBACompliance` top-level navigation group (`payroll-leaves-to-hrmq`
`design.md` §3), with their existing page ids and routes unchanged. The
previous text's `featureFlags.mkb-detachering` gate is removed as fictional
— it was never implemented (`payroll-leaves-to-hrmq` `design.md` §6: zero
matches for `mkb-detachering` anywhere in the manifest at the time of that
change), so these pages have always rendered unconditionally, both before
and after this change.

#### Scenario: Detachering navigation is unconditional, at its new DBA Compliance home

- **GIVEN** the manifest after `payroll-leaves-to-hrmq`
- **WHEN** the app navigation renders for any authenticated user with access
  to the `DBACompliance` group
- **THEN** `OpdrachtgeversVerklaringen` and `IB47Jaarbatch` are shown as
  children of `DBACompliance`, with no feature-flag gate required

@e2e payroll-leaves-to-hrmq::dba-pages-reachable-at-new-home
