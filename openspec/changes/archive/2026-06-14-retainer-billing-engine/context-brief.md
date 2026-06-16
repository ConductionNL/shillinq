---
status: draft
---

# Retainer Billing Engine (drawdown, rollover, true-up)

## Purpose

Monthly retainer pool, drawdown from time entries, rollover policy, overage at standard rate, true-up at period end. Differentiator vs Yuki/Moneybird.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 9/26 competitors (gap!)
- **Dependencies:** rate-card-engine

## Competitor Evidence (from intelligence-db)

- akaunting :: No retainer engine :: No native retainer pool/drawdown
- bigtime :: Retainer billing engine :: Monthly retainer pools, drawdown, rollover, overage billing
- clio :: Retainer with drawdown :: Client retainer with replenishment alerts
- deltek-maconomy :: Retainer management with drawdown :: Monthly retainer with rollover and true-up
- everhour :: Recurring monthly budget (retainer) :: Reset monthly with rollover option; retainer-style
- kantata :: Retainer / subscription billing :: Monthly retainer with rollover and overage
- moneybird :: Subscription billing :: Basic recurring subscription; no rollover/pool
- replicon :: Retainer billing :: Retainer pool with drawdown and true-up
- yuki :: No retainer-specific module :: No native retainer pool/rollover; treated as recurring invoice

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 9 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
