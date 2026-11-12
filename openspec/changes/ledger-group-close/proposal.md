---
kind: code
---

# Proposal: ledger-group-close

## Summary

A group with several administrations wants the intercompany balances eliminated and one set of group statements. Shillinq mirrors intercompany bookings, but the nightly soft close calls a matching method that does not exist (`SoftCloseExecutor.php:469` asks for `matchForPeriod`, the service has `matchRelationPeriod`), so no elimination entry is ever written. `ConsolidationMappingService` has no caller, so no code sums the subsidiaries. The swing check (`FluxService`) only runs from an API route no page calls. This change wires the three into the nightly close and the consolidation run.

## Motivation

Capability rows `led-intercompany`, `rep-consolidation`, `led-soft-close` were marked built. The closing round of 12 Nov 2026 (decision 389) found that the code does not do what the rows say, so they go back to specified with this change as their plan.

## Affected projects

- shillinq only.
