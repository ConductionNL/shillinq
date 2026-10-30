# Migration: billing-inherited-defects

## Current State

ARInvoice 0.15.0 and PaymentRequest 0.5.0 do not declare `recurringProfileId`,
`billingPeriod`, `customerReference`, `invoiceLines[].glAccount` or `requestedBy`;
OpenRegister drops them on save.

## Target State

ARInvoice 0.16.0 and PaymentRequest 0.6.0 declare them, all nullable.

## Migration Class

None. The fragment signature is folded into the register version, so OpenRegister
re-imports the schemas on the next load; no row changes shape.

## Rollback

Revert; the properties are additive and optional.
