# Migration: model-or-references-pilot

This change modifies OpenRegister **schema definitions** and **seed data** in
`lib/Settings/shillinq_register.json`. It introduces **no** Nextcloud DB tables and **no** PHP
migration class — shillinq owns no tables (ADR-001); all data lives in OpenRegister. The "migration"
here is a register re-import plus a re-seed of the GL cluster. Conversion of **pre-existing live**
objects is explicitly deferred to a separate `kind: code` follow-up (see Data Impact).

## Current State

- `GLLine.transactionId`: plain string holding a GLTransaction **slug** (e.g. `gl-txn-2026-q1-revenue`).
- `GLLine.accountNumber`: plain string holding an RGS account **code** (e.g. `1300`).
- `GLTransaction`: no inverse `lines` property.
- Seeds: 3 `GLTransaction`, 8 `GLLine`, **0 `Account`** objects. None carry UUID references.
- `/uses` and `/used` return empty for every shillinq object (no declared `$ref`).

## Target State

- `GLLine.transactionId`: `$ref: GLTransaction`, `format: uuid`, `inversedBy: lines`; value = the
  GLTransaction object's UUID.
- `GLLine.accountRef`: new property, `$ref: Account`, `format: uuid`; value = the Account object's
  UUID. `accountNumber` RGS string retained.
- `GLTransaction.lines`: new inverse array of `GLLine` references.
- Seeds: `Account` objects added; GLTransaction/GLLine seeds re-pointed to carry UUIDs.
- `/used` on the seeded GLTransaction returns its GLLines; `/uses` on a GLLine returns its
  GLTransaction + Account.

## Migration Class

None. There is no `lib/Migration/` class. The register is (re)imported via the existing repair
step (`ConfigurationService::importFromApp()`), which is the established shillinq mechanism for
applying register changes.

## Migration Steps

1. Edit schema properties in `lib/Settings/shillinq_register.json` (Tasks 1-3).
2. Add/repoint seed objects in `objects[]` so reference fields carry target UUIDs (Task 4).
3. Re-import the register via the repair step.
4. Verify `/uses`/`/used` resolve for the seeded cluster (Task 5).

## Data Impact

- **Seed (demo) data:** re-seeded — Account objects created, GL seeds re-pointed to UUIDs.
- **Pre-existing live data:** NOT changed by this change. Live objects created before the pilot keep
  their slug/RGS-code values; `/uses`/`/used` will not resolve for them until a separately declared
  `kind: code` migration/repair change maps business keys/slugs → UUIDs. That change `depends_on`
  this one and is out of scope here (ADR-031 imperative exception; ADR-032 mixed-kind avoidance).
- No data loss: `accountNumber` is retained; `transactionId` changes representation (slug → UUID)
  only within the re-seeded demo cluster.

## Rollback Procedure

Revert the single-file diff to `lib/Settings/shillinq_register.json` (schema-property additions +
seed objects) and re-import. No tables, no code, no non-seed data are touched.

## Validation

- `GLLine.properties.transactionId` declares `$ref: GLTransaction` + `inversedBy: lines`.
- `GLTransaction.properties.lines` exists as the inverse array.
- `GLLine.properties.accountRef` declares `$ref: Account`; `accountNumber` still present.
- `objects[]` contains Account objects and UUID-carrying GL seeds (placeholders = nil UUID).
- `/used` on the seeded GLTransaction returns ≥1 GLLine; `/uses` on a GLLine returns its
  GLTransaction + Account.
- Register JSON re-validates with no duplicate keys.
