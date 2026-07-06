---
capability: portal-contribution
status: in-progress
built_by: openspec/changes/portal-contribution
---

# portal-contribution Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- [portal-contribution](../../changes/portal-contribution/) _(active)_ — Wave-1 ADR-046 provider class (customer + supplier audiences) + unit tests (kind: code)

## Purpose

Shillinq contributes customer and supplier sections to portaliq, the shared
external portal for people without Nextcloud accounts (hydra ADR-046 +
2026-07-06 amendment, contribution contract v2). The contribution is one
plain, dependency-free provider class
(`OCA\Shillinq\Portal\PortalContributionProvider`, duck-typed by FQCN — inert
without portaliq) declaring read-only OpenRegister collections scoped by
verified UUID domain references and matched against per-app claims
(`claims.shillinq.customerId` / `claims.shillinq.supplierId`). Wave 1 of the
ADR-046 fleet rollout (tracking: Conduction/shillinq#365).

## Requirements

Detailed requirements (REQ-SPC-001 … REQ-SPC-005) are defined in the active
change's delta spec —
[`openspec/changes/portal-contribution/specs/portal-contribution/spec.md`](../../changes/portal-contribution/specs/portal-contribution/spec.md)
— and are merged here by `openspec sync` when the change is archived. The
verified scoping map, claim-names contract, administrationId tenancy note,
and all exclusions (ARInvoice, PaymentRequest, dunning, goods receipts) live
in the change's
[`design.md`](../../changes/portal-contribution/design.md).

### Requirement: Shillinq contributes to portaliq via one plain duck-typed provider (REQ-SPC-000)

Shillinq MUST expose its entire portal contribution through the single plain
class `OCA\Shillinq\Portal\PortalContributionProvider` — duck-typed by
portaliq, dependency-free, inert without portaliq — declaring read-only,
UUID+claim-scoped collections for the `customer` and `supplier` audiences and
nothing else (no portal UI, routes, or endpoints inside shillinq). Normative
detail: REQ-SPC-001 … REQ-SPC-005 in the active change's delta spec.

#### Scenario: Provider is the only portal surface

- GIVEN the shillinq codebase at this capability's HEAD
- WHEN the portal contribution is inspected
- THEN it consists solely of `lib/Portal/PortalContributionProvider.php` (plus its unit tests) with no portaliq import, no info.xml dependency, and no shillinq-side portal route or UI
- @e2e exclude backend-only contract class; the external portal surface is rendered and e2e-tested in portaliq, not in shillinq — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)
