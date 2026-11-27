# Migration: portal-pay-row-action-keys

## Current State

No stored data changes. `portal_payment_redirect_url` is already read from app
config by the pay flow; no screen or API could set it.

## Target State

The settings API reads and writes the key; the manifest carries the row keys.

## Migration Class

```
None. No register, table or stored value changes.
```

## Migration Steps

1. None.

## Data Impact

None.

## Rollback Procedure

Revert the PR.

## Validation

`PortalContributionProviderTest` and `SettingsServiceTest`.
