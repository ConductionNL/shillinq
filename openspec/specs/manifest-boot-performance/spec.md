# manifest-boot-performance Specification

## Purpose
TBD - created by archiving change shillinq-manifest-boot-payload-reduction. Update Purpose after archive.
## Requirements
### Requirement: REQ-MBP-001 — Manifest JSON delivered on boot SHALL be code-split by feature area, not bundled whole

shillinq's frontend SHALL NOT statically bundle the entirety of `src/manifest.json` plus every
`src/manifest.d/*.json` fragment into the `main` webpack chunk. At HEAD (2026-07-07) this is
1,033,569 bytes (424,089 in `manifest.json` + 599,480 across 74 fragment files) transferred
and synchronously merged via `buildManifest()` on every single app boot regardless of which
feature area the user opens. The merged manifest SHALL instead be assembled so that a fragment
belonging to a feature area the current user session never navigates to (e.g. WBSO, treasury,
CBS-bestanden, `bookkeeping-purchase-order-3way-*`) is not downloaded as part of that session's
boot payload.

#### Scenario: First paint does not require the full fragment set

- **WHEN** a user opens shillinq and lands on the default dashboard route
- **THEN** the network payload for manifest data required to render that first route is
  measurably smaller than the full 1.03 MB combined `manifest.json` + `manifest.d/*.json`
  total, and fragments for feature areas not on the current route are not yet loaded

#### Scenario: Navigating into a feature area loads only that area's fragment

- **WHEN** a user navigates to a page whose route/menu entry originates from a specific
  `manifest.d/*.json` fragment not yet loaded in the session
- **THEN** only that fragment (or a small batch covering its menu group) is fetched at that
  point, not the remaining fragments for unrelated feature areas

### Requirement: REQ-MBP-002 — No duplicate manifest fragments for the same feature

shillinq's `src/manifest.d/` SHALL NOT contain two fragments that both declare navigation
and/or pages for what is functionally the same feature, because `buildManifest()`'s
`mergePages`/`mergeMenuItems` merge BOTH into the live manifest (last-fragment-wins is only
applied when the `page.id` collides; a differently-`id`'d duplicate menu entry renders
alongside the canonical one instead of being dropped). At HEAD, `bookings-resource-calendar.json`
(unprefixed, 481 bytes, "Verkoop → Bookings calendar") and `10-bookings-resource-calendar.json`
(8,005 bytes, "Bookings → Resources/Calendars/BookingList") both declare navigation for the
bookings-calendar feature and both currently merge and render.

#### Scenario: Exactly one navigation entry per feature

- **WHEN** the merged manifest's `menu[]` is inspected after boot
- **THEN** there is exactly one navigation path to the bookings-calendar feature, not two
  separate menu leaves under different parent groups

