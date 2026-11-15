---
kind: code
---

# Proposal: purchasing-call-offs-and-reorder-proposals

## Summary

Framework agreements are kept and a call-off past the ceiling is refused, but the purchase order form never sends `frameworkAgreementId`, so no order can be drawn against an agreement, and agreed prices are not applied. Reorder rules are kept, but no service or job reads them. This change lets a buyer order against an agreement and has low stock propose a purchase.

## Motivation

Capability rows `pur-framework`, `inv-reorder` were marked built. The closing round of 12 Nov 2026 (decision 389) found that the code does not do what the rows say, so they go back to specified with this change as their plan.

## Affected projects

- shillinq only.
