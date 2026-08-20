# Design: consolidate-order-subsidie-collisions

## Context

`abstract-order-primitive` is blocked because two schema collisions must be
fixed first: the generic `Order` slug is occupied by the booking-context order,
and `Subsidie` is defined in three places with different field sets. This change
resolves both non-destructively at the register-definition level.

## D1 — How OpenRegister actually reads schemas (why Subsidie needs no object migration)

OpenRegister's `ImportHandler` imports schemas **only** from
`components.schemas` (`lib/Service/Configuration/ImportHandler.php` iterates
`$data['components']['schemas']`). The legacy sibling `components.<Name>`
blocks in `shillinq_register.json` are **never imported**. Therefore all three
historical `Subsidie` definitions —

- `shillinq_register.json` → `components.schemas.Subsidie` (rich-Dutch, live),
- `shillinq_register.json` → `components.Subsidie` (dead sibling, never imported),
- `add-shillinq-bookkeeping-operations.json` → `components.schemas.Subsidie`
  (English, deep-merged onto the monolith at load) —

resolve to **one live schema slug** (`Subsidie`), i.e. one table. Consolidating
the definitions leaves that single live slug untouched, so **no Subsidie objects
are orphaned** and no Subsidie object migration is required.

## D2 — Canonical Subsidie = the field union (no regulatory field dropped)

The canonical `Subsidie` lives at `components.schemas.Subsidie` and now carries
the **union** of every source definition's properties. `required` keeps the
Dutch regulatory minimum (the ASV-model set) — the English operations fields are
folded in as **optional** overlays so existing Dutch-world objects still
validate (avoiding the union-merge-corrupts-required trap).

Field mapping (same-name → richer/Dutch wins; different-name → both kept):

| Source | Fields folded into canonical |
|---|---|
| `components.schemas.Subsidie` (canonical base, 21) | kept as-is |
| `components.Subsidie` (dead sibling, 20) | `subsidieRegeling` (the only unique field; given a `title`) |
| `add-shillinq-bookkeeping-operations.json` (English, 17) | `approvingAuthority`, `attachmentUri`, `awardAmount`, `awardDate`, `budgetYear`, `currency`, `disbursementDate`, `grantProgram`, `granteeOrganization`, `hasRepaymentPlan`, `notes`, `purposeDescription`, `settlementDate`, `subsidieName` |

Regulatory fields preserved: regeling (`regelingNaam`/`regelingArtikel`/
`subsidieRegeling`), beschikking (`beschikkingDate`/`beschikkingUri`),
vaststelling (`vaststellingDate`/`vaststellingUri`), the five state-amounts
(`aangevraagdBedrag`/`verleendBedrag`/`vastgesteldBedrag`/`uitbetaaldBedrag`/
`teruggevorderdBedrag`), `prestatieverantwoording`, and repayment
(`repaymentPlanId` + `hasRepaymentPlan`). The auditor threshold lives on the
separate `SubsidieVerantwoording` schema (unchanged). The
`add-shillinq-audit-trail.json` `"Subsidie"` entry is an
`x-openregister-audit-trail` config keyed by schema name (not a schema
definition) and correctly still points at the canonical `Subsidie`.

The duplicate blocks are retired: the dead `components.Subsidie` sibling and the
operations-fragment `Subsidie` are removed. `grep '"slug": "Subsidie"'` now
returns exactly one definition.

## D3 — Order-family slug map (the freed generic Order slug)

The booking-context order in `bookings-deposit-to-invoice.json` is renamed
`Order` → `BookingOrder` (slug + JSON key + title + the self-referential
`x-openregister-relations.order.relatedSchema` + its seed object's
`@self.schema`). The validator whitelist entry (`tests/validate-registers.js`
`NON_BOOKKEEPING`) is renamed to match.

Canonical order-family slug map (so `abstract-order-primitive` can add `Order`
without collision):

| Slug | Owner |
|---|---|
| `Order` | **reserved** for the abstract Order primitive (added by `abstract-order-primitive`) |
| `SalesOrder` | AR sales order (`bookkeeping-quote-order-invoice.json` + monolith) — unchanged |
| `PurchaseOrder` | AP purchase order (3-way-match cluster) — unchanged |
| `BookingOrder` | the renamed booking/deposit order (`bookings-deposit-to-invoice.json`) |

## D4 — Migration: unit-tested core + documented live-run deviation

`SubsidieOrderConsolidationMigrator` (unit-tested) carries the object-migration
decision logic: the field-map that re-points a renamed schema's objects
(`mapObjectToRenamedSchema` / `migrateBatch`) and the source→target count guard
(`assertCountsMatch`) that **aborts with the source intact** on any mismatch —
the no-row-loss invariant. Subsidie needs no object move (D1); only booking
`Order` objects re-point to `BookingOrder`, a non-regulatory migration.

**Deviation (live register migration deferred).** The live re-point of persisted
`Order` objects to `BookingOrder` against a running OpenRegister — and its
interaction with the already-registered `FoldIntoOrder` /
`RetireSubsidieSchema` repair steps (owned by `abstract-order-primitive`, which
fold Subsidie→Grant and also touch the regulatory Subsidie data) — is **verified
on a live import**, exactly as the spec marks it (`@e2e exclude … the full
register migration is verified on a live import`). A competing live re-point
repair step is intentionally **not wired** in this change so it cannot run
untested against, and never risk, that regulatory Subsidie data. The buildable,
unit-tested half (map + count-abort) ships here; the live wiring lands with the
`abstract-order-primitive` fold it must coordinate with.

## Verification

- `tests/Unit/Settings/SubsidieOrderConsolidationSchemaTest.php` — one Subsidie
  definition, the field union (regulatory + English), the freed `Order` slug,
  `SalesOrder`/`PurchaseOrder`/`BookingOrder` intact.
- `tests/Unit/Service/SubsidieOrderConsolidationMigratorTest.php` — map +
  count-abort.
- `node tests/validate-registers.js` — net-neutral (no new schema flagged).
- `openspec validate --strict`.
