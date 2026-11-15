---
kind: code
---

# Proposal: reporting-ifrs-calculations

## Summary

Shillinq keeps the registers for dual GAAP, IAS 19 pensions and IFRS 16 lease disclosures, but nothing calculates them. No service derives the second framework's entries or the reconciling differences, no service computes service cost, net interest or remeasurements, and the lease disclosure service writes no table rows and is reached by no page. This change adds the three calculations.

## Motivation

Capability rows `led-dual-gaap`, `pln-pensions`, `pln-leases` were marked built. The closing round of 12 Nov 2026 (decision 389) found that the code does not do what the rows say, so they go back to specified with this change as their plan.

## Affected projects

- shillinq only.
