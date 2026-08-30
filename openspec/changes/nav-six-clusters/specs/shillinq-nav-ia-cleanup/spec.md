# Spec: shillinq-nav-ia-cleanup (delta — nav-six-clusters)

This delta MODIFIES REQ-NAVIA-002 only. REQ-NAVIA-001 and REQ-NAVIA-003
through REQ-NAVIA-008 (label, single-active-leaf, duplicate-detail-route,
dashboard stats-block requirements) are untouched by this change and are
re-verified, not re-specified, by `nav-six-clusters`' own tasks.

## Why this requirement is being replaced, not just updated

REQ-NAVIA-002's premise — that `menu-layout.json#removals` contains
`"ProjectenOverzicht"` (added by the earlier `retire-cost-project` change),
leaving `Bookkeeping > Projects` as the schema's sole nav home — is stale.
`src/menu-layout.json#removals` is empty today: the 160-entry list that
would have carried this retirement (among 159 others) was withdrawn wholesale
on 2026-08-10 (`menu-layout.json`'s own `_removals_note`) because 140 of its
entries orphaned pages with no other reachable route. `buildManifest()`
confirms `ProjectenOverzicht` is live today, alongside `Utilisatie`, under
the `Projecten` top-level group — the exact duplicate REQ-NAVIA-002 existed
to prevent has silently returned.

`nav-six-clusters` fixes this for real, as part of its schema #8 verdict
(design.md §4): `Projects` becomes the sole canonical page (relocated into
the Bookkeeping cluster), `ProjectenOverzicht` is deleted (not merely
removed from nav — the duplicate page itself goes away), and `Utilisatie`
relocates alongside `Projects` as a companion card rather than staying under
a now-dissolved `Projecten` top-level group.

## MODIFIED Requirements

### Requirement: REQ-NAVIA-002 — The system SHALL expose `Project` under exactly one navigation home — a `Projects` page inside the Bookkeeping cluster

The `Project` schema (RJ270/IFRS15 revenue-recognition register) MUST be
reachable from a single navigation home: the page id `Projects`, relocated
into the Bookkeeping cluster (`nav-six-clusters`). The former `Projecten`
top-level group and its `ProjectenOverzicht` page MUST NOT survive as a
second navigation home for the `Project` schema — `ProjectenOverzicht` MUST
be deleted, not merely unlinked from the menu, so a future manifest edit
cannot silently resurrect the duplicate the way the withdrawn `removals`
entry did. `Utilisatie` (the `Projecten` group's other child, a distinct
schema) relocates alongside `Projects` into the Bookkeeping cluster as a
companion card; it is not merged with `Projects` and is unaffected by this
requirement. The `CostProjects` leaf (schema `CostProject`, the distinct
analytical management-accounting register, already removed by
`retire-cost-project`) remains unaffected.

#### Scenario: Only one Project nav entry remains, and it survives a manifest re-merge
- **GIVEN** the restructured `src/manifest.json` + `src/manifest.d/*.json` +
  `src/menu-layout.json` (no `ProjectenOverzicht` page definition anywhere
  in any fragment)
- **WHEN** `buildManifest()` runs
- **THEN** the only navigation home for the `Project` schema is the
  `Projects` page inside the Bookkeeping cluster, and this holds regardless
  of `menu-layout.json#removals`'s contents (there is no page left for a
  withdrawn or re-added removals entry to accidentally resurrect)

@e2e nav-six-clusters::preset-deep-links-resolve

#### Scenario: Utilisatie is relocated, not merged
- **GIVEN** the restructured manifest
- **WHEN** the `Utilisatie` page is inspected
- **THEN** it still exists as its own page, now reachable as a companion
  card inside the Bookkeeping cluster landing page, and its schema/route are
  unchanged from before this change

@e2e nav-six-clusters::preset-deep-links-resolve

#### Scenario: CostProjects is untouched
- **GIVEN** the restructured manifest
- **WHEN** the Bookkeeping cluster renders
- **THEN** the `CostProjects` leaf (schema `CostProject`) is still absent
  (per `retire-cost-project`, unaffected by this change) and `Projects`
  (schema `Project`) is present exactly once
