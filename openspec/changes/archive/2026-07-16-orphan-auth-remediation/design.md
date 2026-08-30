# Design: orphan-auth-remediation

## Context

Gate 6 (`orphan-auth`) enumerates `public function (is|requires?|validate|authorize|check|
ensure|verify|assert)[A-Z]…()` methods across `lib/Service/**` + `lib/Controller/**` and
fails any with zero `->method(` callers in `lib/` or `src/`. A caller in `tests/` does NOT
count (the gate scans production trees only). The un-blinding (non-recursive → recursive
enumeration) surfaced 5 pre-existing methods in nested service directories.

`class-injected ≠ method-called`: the Mollie and CSRD adapters are DI-registered
(`Application.php`) and displayed in the admin adapter catalogue, but neither is *injected into
a service that calls the flagged method*. The `->method(` grep is the reliable signal and was
run per-method against `lib/ src/`.

## Verdict table

| File:line | Method | Verdict | Superseder / rationale |
|-----------|--------|---------|------------------------|
| `lib/Service/External/Mollie/MolliePaymentAdapterInterface.php:116` | `verifyWebhook` | **DELETE (superseded)** | `PaymentRequestWebhookController::verifySignature()` (`lib/Controller/PaymentRequestWebhookController.php:210`) + `DepositWebhookController::verifySignature()` (`:193`) — real HMAC over raw body, fail-closed when secret unconfigured. REQ-APL-004: "ONE shared surface, never a fork" — the adapter method IS the prohibited fork. Zero callers. |
| `lib/Service/External/Mollie/LogMolliePaymentAdapter.php:103` | `verifyWebhook` | **DELETE (superseded)** | Same. Dormant always-`PAYMENT_DEFERRED` stub — never verified anything. Zero callers; no test caller. |
| `lib/Service/External/CsrdEsrsXbrl/CsrdEsrsXbrlAdapterInterface.php:123` | `validateMandatoryDataPoints` | **SEAM (retain + document)** | Dormant XBRL submission port. No live submit path: sibling methods `mapTaxonomy`/`buildInstance` are ALSO uncalled; activation requires the unmerged cross-app `bookkeeping-sbr-xbrl-reporting` dependency. The *live* CSRD lifecycle (data-point approval, assurance opinion) is guarded independently by `lib/Lifecycle/CsrdEsrsGuard.php` (a different concern: source-reference/findings, not IG-3 mandatory-point coverage). Deleting would remove the documented SAFETY-CRITICAL `VALIDATION_BLOCKED`-on-dormant contract with no replacement. No live financial mutation bypassed. |
| `lib/Service/External/CsrdEsrsXbrl/LogCsrdEsrsXbrlAdapter.php:115` | `validateMandatoryDataPoints` | **SEAM (retain + document)** | Same dormant port. |
| `lib/Service/Sms/SmsSendResult.php:84` | `isDelivered` | **SEAM (retain + document)** | Not an authorization check — a DTO status accessor (`status ∈ {sent,pending}`) mis-matched by the gate's `is*` verb. Public DTO contract with test coverage (`SmsReminderDispatcherTest:168,226`); no production caller branches on it today. Not a guard — deletion cannot remove any protection; retained as supported API. |

**Result: 2 DELETE, 3 SEAM, 0 WIRE, 0 UNSURE.** No dead guard sat on a live financial
mutation. The residual 3 SEAM findings are the two intentional dormant/DTO methods; they are
untouched by this change, so gate-6 (diff-scoped, ADR-020) does not flag them on this PR, and
the body-diff preexisting filter downgrades them to informational on future PRs.

## Why not delete the two SEAM clusters

- **CSRD** — deleting `validateMandatoryDataPoints` from an in-development dormant port is
  YAGNI-in-reverse: the method's documented contract ("dormant MUST return
  `VALIDATION_BLOCKED` so a deferred binding cannot accidentally publish an unvalidated
  report") is a *fail-closed safety property* of the future submit path. There is no live path
  bypassing it, so keeping it is safe; deleting it forfeits an already-specified control.
- **SMS** — `isDelivered()` is a tested public accessor; deleting it breaks 2 green tests and
  removes a documented DTO method to satisfy a false-positive verb match. Wrong trade.

## The surviving Mollie port

```
interface MolliePaymentAdapterInterface {
    public function createPayment(array $payload): MolliePaymentResult;  // outbound intent
    public function isDormant(): bool;
}
```

Inbound verification is out of scope for the port by construction — the `#[PublicPage]`
controllers own it. This matches REQ-APL-004 and the app's own docblock ("one
signature-verification implementation").

## Seed Data

None. No schema, register fragment, or object seed changes. The change deletes two PHP methods
and their docblocks, corrects one admin-catalogue description string, and adds one unit test.
No `lib/Settings/*_register.json` or `register.d` fragment is touched.

## ADR-031 alignment

ADR-031 (schema-declarative business logic over service classes) is respected: this change
does not add imperative service logic. The retained CSRD seam is an ADR-031 *documented
exception* (external XBRL transport cannot be declarative) exactly as the existing dormant
external-adapter ports are; the deleted Mollie method removes a service-layer duplicate of a
control that already lives at the correct `#[PublicPage]` HTTP boundary. No new
`x-openregister-*` metadata is required — the payment lifecycle transitions remain declarative
and the webhook signature gate remains the sole ADR-031 imperative exception (fail-closed HMAC
at the network boundary), unchanged.

## Risks / Trade-offs

- **Risk:** a future real Mollie binding might expect `verifyWebhook()` on the port. **Mitigation:**
  the activation checklist already routes inbound verification through openconnector + the
  controller gate; re-adding a port method later (with a live caller) is trivial and would then
  pass gate-6 legitimately.
- **Trade-off:** the 3 SEAM findings keep gate-6 non-zero on a *full* (non-diff) scan. Accepted:
  they are documented, non-regressive, and correctly downgraded on diff-scoped PRs.
