# Spec: Bookkeeping Inter-Company Posting

**Primary spec:** financial-reporting-accountability  
**Affected app:** shillinq  
**Tier:** T5 (specialized consolidation surface)

## Summary

Enable inter-company transaction tracking and elimination for consolidated groups. An `IntercompanyTransaction` record captures a sale or service between two group members; on consolidation, matching transaction pairs are automatically eliminated by configured account patterns and amount rules. Manual override (exclusion from elimination, forced elimination) is supported for edge cases.

## Context

In a consolidated financial statement, transactions between group members are eliminated to show the group's economic substance (not the mechanics of internal cash moves). A GR member A sells waste-collection services to member B for €50,000. Both members post the transaction in GL:
- Member A: debit Receivable, credit Revenue (4000)
- Member B: debit Expenses (7000), credit Payable

At consolidation, the €50,000 pair is identified by matching GL account codes (4000 ↔ 7000) and amount, and both postings are eliminated from the consolidated statement (the group's revenue and expenses don't include inter-company service fees).

### Assumptions

- Inter-company transactions are posted to GL at the time the service is delivered (T1–T4 GL flow handles the posting).
- `IntercompanyTransaction` records are created referencing the GL transaction (or manually created post-facto as routing metadata).
- Elimination rules are defined per `ConsolidationGroup` (separate schema, see bookkeeping-group-consolidation).
- Elimination happens at consolidation time, not at posting time.

## Features

### Inter-company posting with automatic consolidation eliminations
**demand: 46** | Category: other

Track inter-company transactions between group members and automatically eliminate matching pairs during consolidation, with configurable account-pair rules and manual override options.

## Requirements

### REQ-ICP-001: IntercompanyTransaction entity exists and can be created

**Demand link:** Inter-company posting with automatic consolidation eliminations  
**Related entities:** `Organization`, `GLTransaction`, `ConsolidationGroup`

An inter-company transaction record captures a service/sale between two group members. It serves as a routing label for the GL posting and provides a hook for elimination rules at consolidation time.

#### Properties

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| consolidationGroupId | string (FK) | Yes | FK to the ConsolidationGroup this transaction belongs to. |
| fromMemberId | string (FK) | Yes | FK to the selling/providing member Organization. |
| toMemberId | string (FK) | Yes | FK to the buying/receiving member Organization. |
| transactionDate | date | Yes | Date the service was delivered or sale occurred. |
| amount | number | Yes | EUR amount of the transaction. |
| accountFrom | string | Yes | GL account code on the selling member (e.g., "4000" for Revenue). |
| accountTo | string | Yes | GL account code on the buying member (e.g., "7000" for Expenses). |
| reference | string | No | Invoice/PO number or other external identifier for manual matching. |
| description | string | No | Free-text description of the service or goods. |
| glTransactionId | string (FK) | No | FK to the underlying GLTransaction in the GL (may be null if record is created post-facto). |
| eliminationStatus | enum | Yes | One of: `pending` (not yet consolidated), `eliminated` (matched and removed), `excluded` (manual override). Default: `pending`. |
| isManualOverride | boolean | No | If true, this transaction is excluded from elimination even if it matches a rule. |

#### Scenario: Waste-management GR member posts inter-company service

```gherkin
GIVEN I am a bookkeeper for "Gemeente Amsterdam"
AND "Gemeente Amsterdam" is a member of consolidation group "gr-afval-001"
AND "Gemeente Utrecht" is also a member of the same group
WHEN I create an IntercompanyTransaction with:
  - consolidationGroupId: "gr-afval-001"
  - fromMemberId: "amsterdam"
  - toMemberId: "utrecht"
  - transactionDate: 2026-05-10
  - amount: 50000
  - accountFrom: "4000" (Revenue)
  - accountTo: "7000" (Expenses)
  - reference: "INV-2026-001"
AND I save the record
THEN the IntercompanyTransaction is persisted to the register
AND eliminationStatus is set to "pending"
AND the audit trail records the creation
```

---

### REQ-ICP-002: Elimination rules define account-pair matching patterns

**Demand link:** Inter-company posting with automatic consolidation eliminations  
**Related entities:** `EliminationRule`, `ConsolidationGroup`

An `EliminationRule` defines which GL account pairs are eligible for elimination. Rules are per-group and allow different GRs to use different elimination logic.

#### Properties

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| consolidationGroupId | string (FK) | Yes | FK to the ConsolidationGroup this rule applies to. |
| ruleType | enum | Yes | One of: `auto-match` (exact account + amount), `reference-match` (invoice number), `manual-review` (pause for approval). |
| accountPairFrom | string | Yes | GL account code on the selling member (e.g., "4000"). |
| accountPairTo | string | Yes | GL account code on the buying member (e.g., "7000"). |
| amountTolerance | number | No | For `auto-match`: maximum EUR difference allowed. Default: 0 (exact match). |
| description | string | No | Free-text note on the rule (e.g., "Revenue ↔ Expenses inter-company pairs"). |
| isActive | boolean | Yes | Whether this rule is enforced at consolidation time. Default: true. |

#### Scenario: Administrator defines elimination rule for revenue/expense pairs

```gherkin
GIVEN a ConsolidationGroup "gr-afval-001" exists
WHEN I create an EliminationRule with:
  - consolidationGroupId: "gr-afval-001"
  - ruleType: "auto-match"
  - accountPairFrom: "4000" (Revenue)
  - accountPairTo: "7000" (Expenses)
  - amountTolerance: 0 (exact match)
THEN the rule is saved to the register
AND at the next consolidation, IntercompanyTransactions matching (4000, 7000) with exact amounts are eliminated
```

---

### REQ-ICP-003: Elimination matching is performed at consolidation time

**Demand link:** Inter-company posting with automatic consolidation eliminations  
**Related entities:** `IntercompanyTransaction`, `EliminationRule`, `ConsolidatedReport`

During a consolidation run, the system:
1. Retrieves all `IntercompanyTransaction` records for the group.
2. For each active `EliminationRule`, finds matching transaction pairs (from A→B and B→A with matching accounts and amount within tolerance).
3. Marks matched transactions as `eliminationStatus = "eliminated"`.
4. Excludes eliminated transactions from the `ConsolidatedReport` GL aggregation.

#### Scenario: Consolidation matching identifies and eliminates reciprocal pair

```gherkin
GIVEN IntercompanyTransaction T1:
  - fromMemberId: "amsterdam", toMemberId: "utrecht"
  - accountFrom: "4000", accountTo: "7000", amount: 50000
  - eliminationStatus: "pending"
AND IntercompanyTransaction T2:
  - fromMemberId: "utrecht", toMemberId: "amsterdam"
  - accountFrom: "4000", accountTo: "7000", amount: 50000
  - eliminationStatus: "pending"
AND an EliminationRule exists matching (4000, 7000) with amountTolerance 0
WHEN consolidation runs
THEN both T1 and T2 are marked eliminationStatus: "eliminated"
AND the ConsolidatedReport GL aggregation excludes these €50,000 pairs
AND the audit trail records "2 transactions eliminated by rule [rule-id]"
```

---

### REQ-ICP-004: Manual override allows excluding or forcing elimination of specific transactions

**Demand link:** Inter-company posting with automatic consolidation eliminations  
**Related entities:** `IntercompanyTransaction`

An operator can manually override automatic elimination:
- **Exclude from elimination** (isManualOverride = true): The transaction matches a rule but is not eliminated (e.g., an inter-company sale at fair-market-value that should appear in consolidated statements).
- **Forced elimination** (explicit action): An inter-company transaction can be manually marked `eliminationStatus = "eliminated"` even if it does not match any rule (e.g., a transaction from a prior period that was missed).

#### Scenario: Operator excludes a specific inter-company transaction from elimination

```gherkin
GIVEN an IntercompanyTransaction T1 matching an EliminationRule
AND the operator determines T1 represents a real inter-company sale at FMV
WHEN I set isManualOverride = true on T1
AND I add a note explaining why (e.g., "FMV transaction per contract")
THEN at consolidation, T1 is NOT eliminated despite matching the rule
AND the ConsolidatedReport includes T1's amount
AND the audit trail records the override with the operator's note
```

---

### REQ-ICP-005: Inter-company transactions are linked to GL postings (forward reference)

**Demand link:** Inter-company posting with automatic consolidation eliminations  
**Related entities:** `IntercompanyTransaction`, `GLTransaction`

When a GL posting is made (T1 general ledger), the corresponding `IntercompanyTransaction` record should reference it via `glTransactionId` FK. This enables traceability: accountant can click on a consolidated line item → see eliminated transactions → drill down to the original GL posting.

#### Scenario: Accountant traces eliminated transaction back to GL

```gherkin
GIVEN a ConsolidatedReport showing revenue of €X (after elimination)
WHEN I click "Show eliminated transactions" for that line
THEN I see the €50,000 eliminated pair
AND each transaction shows `glTransactionId` link
WHEN I click the GL link
THEN I navigate to the original `GLTransaction` record in the member's GL
AND I can verify the debit/credit sides and amounts
```

---

### REQ-ICP-006: Elimination status is immutable once consolidation is finalized

**Demand link:** Inter-company posting with automatic consolidation eliminations  
**Related entities:** `IntercompanyTransaction`, `ConsolidatedReport`

Once a `ConsolidatedReport` is `finalized` or `published`, the `eliminationStatus` of transactions included in that report cannot change. This ensures auditability (the published consolidated statement is based on a fixed set of eliminations).

#### Scenario: Operator attempts to override elimination after report is finalized

```gherkin
GIVEN a ConsolidatedReport with status = "finalized"
AND it includes IntercompanyTransaction T1 with eliminationStatus = "eliminated"
WHEN I attempt to set isManualOverride = true on T1
THEN the system rejects the change
AND shows a message: "Cannot modify elimination status of transactions in a finalized or published report"
AND I must create a new ConsolidatedReport to apply different elimination logic
```

---

### REQ-ICP-007: Audit trail records elimination decisions and overrides

**Demand link:** Inter-company posting with automatic consolidation eliminations  
**Related entities:** `IntercompanyTransaction`

Every elimination decision (automatic match, manual exclusion, forced elimination) is logged to the audit trail with the rule/logic applied and the operator responsible.

#### Scenario: Audit trail shows elimination history

```gherkin
GIVEN an IntercompanyTransaction with eliminationStatus history
WHEN I view the audit trail
THEN I see entries for:
  - Transaction creation (actor, timestamp, initial status: "pending")
  - Automatic elimination by rule (consolidation run, rule ID, timestamp)
  - Manual override exclusion (actor, reason, timestamp)
  - Restoration to elimination by new rule (consolidation run, new rule ID)
AND each entry includes a hash for tamper-detection
```

---

### REQ-ICP-008: Unmatched inter-company transactions remain in pending status

**Demand link:** Inter-company posting with automatic consolidation eliminations  
**Related entities:** `IntercompanyTransaction`

If a transaction does not match any active elimination rule, it remains `eliminationStatus = "pending"` and is included in the consolidated GL totals. This allows transactions that are intentionally not eliminated (e.g., inter-company loan interest, service fees not expected to pair) to flow through.

#### Scenario: Inter-company loan interest is not eliminated

```gherkin
GIVEN an IntercompanyTransaction:
  - fromMemberId: "parent", toMemberId: "child"
  - accountFrom: "8100" (Interest Income)
  - accountTo: "8200" (Interest Expense)
  - amount: 5000
AND no EliminationRule matches (8100, 8200)
WHEN consolidation runs
THEN the transaction remains eliminationStatus: "pending"
AND both the €5,000 interest income and expense appear in the ConsolidatedReport
```

---

## Out of Scope

- Automatic GL posting generation from `IntercompanyTransaction` records (GL is authored in T1–T4; this spec only labels/routes existing GL).
- Complex matching algorithms (fuzzy, threshold-based) — those are deferred to follow-up specs or optional enhancements.
- Multi-currency inter-company transactions — assume all transactions are in EUR.
- Consolidation of inter-company receivables/payables accounts across periods — balance-sheet netting is a separate future feature.

## Acceptance Criteria

- [ ] `IntercompanyTransaction` schema is declared in `lib/Settings/shillinq_register.json` with all required properties.
- [ ] `EliminationRule` schema is declared with `ruleType` and account-pair matching.
- [ ] Consolidation run (trigger point in bookkeeping-group-consolidation) applies elimination rules and updates `eliminationStatus`.
- [ ] Manual override (isManualOverride, forced elimination) is supported and audited.
- [ ] Immutability rule prevents modification of elimination status in finalized/published reports.
- [ ] Audit trail entries are created for all elimination decisions and overrides.
- [ ] Manifest navigation includes "Consolidation > Inter-Company Transactions" index page showing all transactions and their elimination status.
- [ ] Spec is readable by a Dutch GR accountant without confusion.
