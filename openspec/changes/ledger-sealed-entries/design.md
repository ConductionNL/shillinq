# Design: ledger-sealed-entries

Read at shillinq development `79f438f33` and openregister development
`84352bae` on 2026-09-27.

## Context

- **What is sealed today.** `lib/Service/AuditExportService.php:31` and `lib/Service/AccountantsdossierExportService.php:23` stamp a SHA-256 over an exported package. `lib/Lifecycle/AuditTrailGuard.php` keeps shillinq's own `AuditTrail` register append-only.
- **OpenRegister's chain.** OpenRegister seals each audit-trail row into a SHA-256 chain (`lib/Db/AuditTrailMapper.php`, "Insert an audit-trail entry sealed into the SHA-256 hash chain", verified by `AuditHashService::verifyChain()`), sweeps unsealed rows with `lib/BackgroundJob/AuditSealJob.php`, and repairs fan-out with `lib/Command/RechainAuditTrailCommand.php`. The chain covers audit rows. A change made to a stored object without going through OpenRegister writes no audit row, so the chain cannot see it, and the chain never compares an object's current content with what was posted.
- **The object.** `GLTransaction` (`lib/Settings/shillinq_register.json`) has `administrationId`, `transactionNumber`, `postingDate`, `periodId`, `currency`, `description`, `journalEntryId`, `sourceReference`, `reversesTransactionId`, `lines`, `state`; its lines are `GLLine` objects with `accountNumber`, `amount`, `side`, `costCenterCode`, `projectCode` and more.
- **Event wiring.** `lib/AppInfo/Application.php:271` and `:280` already register listeners for OpenRegister's `ObjectTransitionedEvent`.
- **Posting.** Posting from the ledger pages works once `ledger-posting-path` lands, hence `depends_on`.

## Goals / Non-Goals

**Goals**
- Every posted transaction carries a seal chained to the previous one of its administration.
- A controller can prove, for any administration, that no posted transaction changed since posting, or see the first one that did.

**Non-Goals**
- Sealing drafts; replacing OpenRegister's audit chain.

## Decisions

### D1. The sealed content is the posted record, canonicalised

The seal is `SHA-256(previousSealHash || canonical)`, where `canonical` is
the JSON of `administrationId`, `transactionNumber`, `postingDate`,
`periodId`, `currency`, `description`, `sourceReference`, `journalEntryId`,
`reversesTransactionId` and the lines sorted by `lineNumber`, each with
`accountNumber`, `side`, `amount` (as integer cents), `currency`,
`costCenterCode`, `costCarrierCode`, `projectCode` and `dimensions`, keys
sorted, no whitespace. `state` is excluded so a reversal does not break it.
The first seal of an administration chains onto a fixed genesis value of 64
zeros.

Alternative considered: hash the whole stored object. Rejected: OpenRegister
adds metadata (`@self`, timestamps) that changes on reads and migrations,
which would break seals nobody tampered with.

### D2. A listener seals on posted, under a lock

`lib/Listener/GLTransactionSealListener.php` handles
`ObjectTransitionedEvent` for schema `GLTransaction` to `posted`. Under a
per-administration lock (`OCP\Lock\ILockingProvider`, key
`shillinq-gl-seal-<administrationId>`), it reads the highest
`sealSequence` of the administration, writes `sealSequence + 1`,
`previousSealHash`, `sealHash` and `sealedAt`. When the lock cannot be taken
it fails the post rather than leaving a gap, because an unsealed posted
entry is the gap OpenRegister's `AuditSealJob` had to be written to sweep.

### D3. The check walks the chain and names the first break

`lib/Service/LedgerSealService.php::verify(administrationId)` reads posted
transactions in `sealSequence` order, recomputes each seal from current
content and the previous stored seal, and returns either "intact, N
entries" or the first transaction whose recomputed seal differs, whose
`previousSealHash` does not match its predecessor, or whose sequence skips.
It is reached by a `GeneralLedger` header action "Check ledger seal" and by
`occ shillinq:ledger:verify-seal <administrationId>`.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Sealing on post | Imperative, an object-event listener (ADR-078) | A hash over related objects under a lock is not a derived field an aggregation can compute. |
| Seal fields | Declarative: schema properties, read-only in the UI | Data on the record. |
| Checking | Imperative, a service behind an action and a command | Recomputing a chain is code. |

## Seed Data

Gemeente Voorbeeld, three posted transactions in September 2026: memorial
entry 2026-0901 (EUR 1,200 rent), purchase posting 2026-0902 (EUR 363 incl.
EUR 63 btw) and its reversal 2026-0903. The test fixture then changes the
amount of one line of 2026-0902 directly in storage and expects the check
to name 2026-0902.

## Risks / Trade-offs

- [Existing posted transactions have no seal] → the genesis pass in the Migration Plan seals them once, in posting order, and records that it did.
- [Canonical JSON drifts between PHP versions] → the canonicaliser has its own unit tests with fixed expected hashes.

## Migration Plan

A one-off `occ shillinq:ledger:seal-existing <administrationId>` seals the
posted transactions of an administration that has none, in
`postingDate, transactionNumber` order, and logs the count at warning
level. It refuses to run on an administration that already has seals,
following OpenRegister's rule that rewriting seals must be asked for by a
person.

## Open Questions

None.
