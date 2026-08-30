---
status: done
---

# Spec: inventory-cogs-posting

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:**
`../add-shillinq-general-ledger/specs/bookkeeping-general-ledger/spec.md`
(T1 `GLTransaction` + `GLLine` pattern, REQ-JE-007 materialisation,
REQ-GL-003 `subLedgerType`),
`inventory-valuation-fifo-avg` (provides `InventoryValuation.unitCost`
for the posting amount)

## Purpose

This specification defines the requirements for inventory cogs posting in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude unbuilt UI: COGS posting configuration page not yet implemented


### REQ-CG-001: An `InventoryGLConfig` register SHALL map stock-movement event types to GL accounts per administration

The system MUST declare an `InventoryGLConfig` register in
`lib/Settings/shillinq_register.json` carrying the following fields
per administration:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | FK to the `Administration` owning this config |
| `cogsAccountNumber` | string | Yes | FK to `Account.accountNumber` — debit on sale/dispatch (e.g. RGS 7000 Kostprijs omzet) |
| `inventoryAssetAccountNumber` | string | Yes | FK to `Account.accountNumber` — debit on receipt, credit on COGS and adjustment (e.g. RGS 1400 Voorraden) |
| `grIrClearingAccountNumber` | string | Yes | FK to `Account.accountNumber` — credit on receipt, debited by AP invoice on matching (e.g. RGS 1800 GR/IR clearing) |
| `inventoryAdjustmentAccountNumber` | string | Yes | FK to `Account.accountNumber` — debit/credit on count variance (e.g. RGS 7100 Voorraadmutaties) |
| `isActive` | boolean | Yes | Whether posting is enabled for this administration |
| `description` | string | No | Operator notes |

All four account numbers MUST be validated at save time to resolve to
existing `Account` records within the same `administrationId`. The
system MUST NOT save an `InventoryGLConfig` that references a
non-existent or archived `Account`.

Schema.org annotation: `schema:Thing`.

#### Scenario: Valid config is accepted

- **GIVEN** an administration with active `Account` records 7000,
  1400, 1800, and 7100
- **WHEN** an `InventoryGLConfig` is saved referencing those four
  account numbers
- **THEN** validation MUST pass and the config MUST be saved in
  state `active`.

#### Scenario: Config referencing a missing account is rejected

- **GIVEN** an administration that has no `Account` with number `9999`
- **WHEN** an `InventoryGLConfig` is saved with
  `cogsAccountNumber: "9999"`
- **THEN** validation MUST fail with a "account 9999 does not exist in
  administration" error; no record is persisted.

#### Scenario: Reviewer confirms no hardcoded account numbers in code

- **GIVEN** the shillinq codebase
- **WHEN** scanned for PHP string literals or Vue constants containing
  `"7000"`, `"1400"`, `"1800"`, `"7100"` in service or controller files
- **THEN** no such hardcoded values SHALL exist; accounts are always
  read from `InventoryGLConfig`.

### REQ-CG-002: The system SHALL auto-post a COGS + Inventory Asset GLTransaction on every sale/dispatch event

The system SHALL satisfy this requirement: The system SHALL auto-post a COGS + Inventory Asset GLTransaction on every sale/dispatch event.

When an `InventoryValuation` record's quantity is decreased by a
sale or dispatch event, the lifecycle MUST fire a GL posting action
that materialises exactly one balanced `GLTransaction` with two
`GLLine` rows:

| Side | Account | Amount |
|---|---|---|
| Debit | `InventoryGLConfig.cogsAccountNumber` | `delta_quantity × unitCost` |
| Credit | `InventoryGLConfig.inventoryAssetAccountNumber` | `delta_quantity × unitCost` |

The `GLTransaction` MUST be posted with `journalCode: "inkoop"`,
`subLedgerType: "inventory"`, `subLedgerRef: <InventoryValuation UUID>`,
and a description of the form `"COGS verkoop <delta_quantity>× <sku>"`.
`InventoryValuation.glTransactionId` MUST be set to the new
`GLTransaction.id`.

The action MUST NOT fire if:
- `InventoryGLConfig` does not exist or `isActive: false` for the
  administration.
- `InventoryValuation.unitCost` is null (valuation method not yet run).

In both skip cases, the system MUST emit a structured warning event;
no partial GL entry is created.

#### Scenario: Sale of 5 items posts a balanced COGS entry

- **GIVEN** an `InventoryValuation` with `unitCost: 45.00`, `sku:
  "KZA-001"` and an active `InventoryGLConfig` mapping `cogsAccountNumber:
  "7000"`, `inventoryAssetAccountNumber: "1400"`
- **WHEN** 5 units are sold (quantity reduced by 5)
- **THEN** one balanced `GLTransaction` MUST be posted with:
  - Dr `7000 Kostprijs omzet` € 225,00
  - Cr `1400 Voorraden` € 225,00
  - `subLedgerType: "inventory"`, `subLedgerRef` pointing to the
    `InventoryValuation` UUID
  - `InventoryValuation.glTransactionId` updated to the new transaction ID.

#### Scenario: Posting skipped when unitCost is absent

- **GIVEN** an `InventoryValuation` with `unitCost: null` (valuation
  not yet computed)
- **WHEN** 5 units are sold
- **THEN** no `GLTransaction` is created; a structured warning event
  MUST be emitted containing the `InventoryValuation UUID` and reason
  `"unitCost_missing"`.

#### Scenario: Posting skipped when InventoryGLConfig is inactive

- **GIVEN** an `InventoryGLConfig` with `isActive: false`
- **WHEN** a sale dispatch event fires
- **THEN** no `GLTransaction` is created; a structured warning event
  MUST be emitted with reason `"posting_disabled"`.

### REQ-CG-003: The system SHALL auto-post an Inventory Asset + GR/IR GLTransaction on every goods receipt event

The system SHALL satisfy this requirement: The system SHALL auto-post an Inventory Asset + GR/IR GLTransaction on every goods receipt event.

When an `InventoryValuation` record's quantity is increased by a
goods receipt event (linked to a `GoodsReceipt` confirmation), the
lifecycle MUST fire a GL posting action that materialises exactly
one balanced `GLTransaction` with two `GLLine` rows:

| Side | Account | Amount |
|---|---|---|
| Debit | `InventoryGLConfig.inventoryAssetAccountNumber` | `received_quantity × unitCost` |
| Credit | `InventoryGLConfig.grIrClearingAccountNumber` | `received_quantity × unitCost` |

The `GLTransaction` MUST be posted with `journalCode: "inkoop"`,
`subLedgerType: "inventory"`, `subLedgerRef: <InventoryValuation UUID>`,
and a description of the form `"Ontvangst <received_quantity>× <sku>
van leverancier"`.

The GR/IR clearing account entry MUST subsequently be settled by the
AP invoice posting per `bookkeeping-accounts-payable-core` REQ-AP-003
(the AP invoice debits GR/IR and credits the AP Control account). The
combined effect is: Dr Inventory Asset, Cr AP Control — standard
perpetual inventory double-entry.

The same skip conditions as REQ-CG-002 apply (absent config, absent
`unitCost`).

#### Scenario: Receipt of 20 items posts a balanced inventory-asset entry

- **GIVEN** an `InventoryGLConfig` with `inventoryAssetAccountNumber:
  "1400"`, `grIrClearingAccountNumber: "1800"`, and an
  `InventoryValuation` with `unitCost: 12.50`, `sku: "MSC-002"`
- **WHEN** 20 units are received (quantity increased by 20 via a confirmed
  `GoodsReceipt`)
- **THEN** one balanced `GLTransaction` MUST be posted with:
  - Dr `1400 Voorraden` € 250,00
  - Cr `1800 GR/IR clearing` € 250,00
  - `subLedgerType: "inventory"`, `subLedgerRef` pointing to the
    `InventoryValuation` UUID.

#### Scenario: GR/IR clearing clears when AP invoice is posted

- **GIVEN** a posted `GLTransaction` from the goods receipt above
  (Cr 1800 € 250,00)
- **WHEN** the matching AP invoice is posted (per REQ-AP-003)
- **THEN** the AP `GLTransaction` MUST contain Dr 1800 GR/IR clearing
  € 250,00 and Cr crediteuren € 250,00; **AND** the 1800 account
  balance MUST net to zero for this line.

### REQ-CG-004: The system SHALL auto-post an Inventory Adjustment + Inventory Asset GLTransaction on every count-variance event

The system SHALL satisfy this requirement: The system SHALL auto-post an Inventory Adjustment + Inventory Asset GLTransaction on every count-variance event.

When an inventory count correction produces a non-zero variance
(`actual_quantity − book_quantity ≠ 0`), the lifecycle MUST fire a
GL posting action that materialises exactly one balanced `GLTransaction`
with two `GLLine` rows. The debit/credit direction MUST reflect the
sign of the variance:

**Positive variance** (more stock than book — stock increase):

| Side | Account | Amount |
|---|---|---|
| Debit | `InventoryGLConfig.inventoryAssetAccountNumber` | `|variance| × unitCost` |
| Credit | `InventoryGLConfig.inventoryAdjustmentAccountNumber` | `|variance| × unitCost` |

**Negative variance** (less stock than book — stock decrease):

| Side | Account | Amount |
|---|---|---|
| Debit | `InventoryGLConfig.inventoryAdjustmentAccountNumber` | `|variance| × unitCost` |
| Credit | `InventoryGLConfig.inventoryAssetAccountNumber` | `|variance| × unitCost` |

The direction MUST be determined declaratively from the sign of
`(actual_quantity − book_quantity)`. If the lifecycle engine cannot
express directional conditionals inline, the ADR-031 exception path
applies: a single-method
`OCA\Shillinq\Lifecycle\InventoryPostingGuard::direction(int $delta):
string` (returns `"positive"` or `"negative"`) called by the lifecycle
engine; cited in this spec under "Declarative-vs-imperative note".

The `GLTransaction` MUST be posted with `journalCode: "memo"`,
`subLedgerType: "inventory"`, `subLedgerRef: <InventoryValuation UUID>`,
and a description of the form `"Telkorting/telmeerdering
<|variance|>× <sku>"`.

No posting MUST be made if variance is zero (no net change).

The same skip conditions as REQ-CG-002 apply.

#### Scenario: Negative count variance posts a debit to Inventory Adjustment

- **GIVEN** an `InventoryValuation` with book quantity 120, `unitCost:
  8.75`, `sku: "KBN-003"` and an `InventoryGLConfig` with
  `inventoryAssetAccountNumber: "1400"`,
  `inventoryAdjustmentAccountNumber: "7100"`
- **WHEN** an inventory count finds only 110 items (variance = −10)
- **THEN** one balanced `GLTransaction` MUST be posted with:
  - Dr `7100 Voorraadmutaties` € 87,50
  - Cr `1400 Voorraden` € 87,50
  - `subLedgerType: "inventory"`, `subLedgerRef` pointing to the
    `InventoryValuation` UUID.

#### Scenario: Positive count variance posts a credit to Inventory Adjustment

- **GIVEN** an `InventoryValuation` with book quantity 110, `unitCost:
  8.75`, `sku: "KBN-003"`
- **WHEN** a subsequent count finds 115 items (variance = +5)
- **THEN** one balanced `GLTransaction` MUST be posted with:
  - Dr `1400 Voorraden` € 43,75
  - Cr `7100 Voorraadmutaties` € 43,75.

#### Scenario: Zero variance produces no GL entry

- **GIVEN** an `InventoryValuation` with book quantity 115
- **WHEN** a count confirms exactly 115 items (variance = 0)
- **THEN** NO `GLTransaction` is created; the audit trail MUST record
  the count event with `variance: 0` but no GL reference.

### REQ-CG-005: Every inventory GL posting SHALL materialise a balanced GLTransaction per the T1 REQ-JE-007 pattern

The system SHALL satisfy this requirement: Every inventory GL posting SHALL materialise a balanced GLTransaction per the T1 REQ-JE-007 pattern.

All `GLTransaction` records created by REQ-CG-002, REQ-CG-003, and
REQ-CG-004 MUST conform to the T1 general-ledger constraints:

- Debit total MUST equal credit total (T1 REQ-GL-005 balance
  invariant enforced as lifecycle precondition).
- `GLTransaction.currency` MUST equal the administration's base
  currency (EUR for NL administrations in T2; T5 adds multi-currency).
- `GLLine.periodId` MUST be auto-resolved to the active fiscal period
  at posting time per T1 REQ-GL-006.
- `GLTransaction.state` MUST transition `draft → posted` in one
  atomic action (no intermediate manual approval required for
  system-generated inventory postings).
- `GLLine.subLedgerType` MUST be `"inventory"`;
  `GLLine.subLedgerRef` MUST carry the `InventoryValuation UUID`.

No PHP balance-checking service. The balance invariant is declared
on `GLTransaction.post` per T1 REQ-GL-005.

#### Scenario: Reviewer confirms balance invariant on inventory GLTransaction

- **GIVEN** a `GLTransaction` materialised from a sale dispatch event
- **WHEN** debit and credit amounts are summed across its `GLLine` rows
- **THEN** debit total MUST equal credit total; the transaction MUST
  be in state `posted`.

#### Scenario: Reviewer confirms subLedger references are set

- **GIVEN** a `GLTransaction` materialised from a goods receipt event
- **WHEN** its `GLLine` rows are inspected
- **THEN** both lines MUST carry `subLedgerType: "inventory"` and the
  same `subLedgerRef` UUID pointing to the source `InventoryValuation`.

### REQ-CG-006: Inventory GL Posting MUST be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare:

- `Voorraad > Posting Configuratie` — `type: index` + `type: detail`
  on `InventoryGLConfig`; detail page surfaces the four account-
  number fields as FK pickers from the administration's `Account`
  register.
- `Voorraad > Posting Historie` — `type: index` on `GLTransaction`
  filtered by `subLedgerType: "inventory"`; columns include
  `entryNumber`, `description`, `debitAmount`, `creditAmount`,
  `entryDate`, `subLedgerRef`.

Rendering MUST use `@conduction/nextcloud-vue` generic components
per ADR-024 Tier-4 — no bespoke Vue files.

#### Scenario: Posting configuration page lists accounts

- **GIVEN** the manifest declares the Posting Configuratie pages
- **WHEN** an operator opens the Inventory Posting Config index
- **THEN** `CnIndexPage` MUST render columns including
  `administrationId`, `cogsAccountNumber`, `inventoryAssetAccountNumber`,
  `isActive`.

#### Scenario: Posting history page lists inventory GLTransactions

- **GIVEN** three `GLTransaction` records with `subLedgerType:
  "inventory"` and two with `subLedgerType: "ap"`
- **WHEN** an operator opens the Inventory Posting History page
- **THEN** only the three inventory-type transactions MUST be displayed.

---

> **Declarative-vs-imperative note (ADR-031):**
>
> | Behaviour | Decision |
> |---|---|
> | COGS posting on sale | Declarative — `x-openregister-lifecycle` action |
> | Inventory-asset posting on receipt | Declarative — `x-openregister-lifecycle` action |
> | Count-variance posting direction | Declarative if engine supports sign conditionals; else single-method `InventoryPostingGuard::direction(int $delta): string` per ADR-031 exception |
> | Account resolution | Declarative — FK lookup from `InventoryGLConfig` at action time |
> | Balance invariant | Declarative — T1 REQ-GL-005 precondition on `GLTransaction.post` |
> | Audit trail | Automatic — OR audit-trail-immutable per ADR-022 |
> | Manifest navigation | Declarative — `src/manifest.json` per ADR-024 |
>
> No PHP posting service class is authored in this envelope (subject to
> ADR-031 exception: at most one single-method `InventoryPostingGuard::
> direction()`).
