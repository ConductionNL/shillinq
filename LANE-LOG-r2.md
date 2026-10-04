# Lane r2-shillinq (sq-fees), learniq round 2

## Change: extracurricular-fee-to-shillinq
- Branch: `feat/extracurricular-fee-to-shillinq`, cut from origin/development 7781d5fbb, no upstream (push with -u).
- Status: in progress (started 2026-09-27 ~16:40).

### Recon findings (16:40-17:10)
- `case-payment-requests` tasks 1-3 built: `subjectKind`/`subject` exist; portal task 4.1 open. `abstract-order-primitive` is about purchase/subsidie orders; not used (no order).
- Uniqueness is one pending request per (subject, requestType): a bulk raise on one FeeItem would refuse the second guardian. Needs a `beneficiary` dimension.
- Reconciliation books a receipt for ANY object request, even with an invoice: invoice-backed contribution would double-book revenue. Needs the invoice branch when `invoiceReference` is set.
- INHERITED DEFECT on the critical path: ARInvoice lifecycle field is `lifecycleState`, but `PortalPaymentSessionService::findOwnedPayableInvoice` and `PaymentReconciliationService::settleLinkedInvoice` read/write `state`. So no issued invoice is payable from the portal and a capture never moves the invoice to paid (dunning would continue).
- Guardians log in to the portal with audience `parent` (learniq PortalContributionProvider); shillinq's portal contribution and pay flow serve only `customer`.
- Portaliq PR #746 contract says shillinq writes `activitySignup.paymentRequestRef` into portaliq's register: refused (ADR-066); portaliq writes it from the raise response.
- OR fires ObjectUpdatedEvent (old+new) on a plain save; ObjectTransitionedEvent only through TransitionEngine. integriq forwards every OR update as CloudEvent `com.nextcloud.openregister.object.updated` with data.attributes + data.previous.attributes; jsonlogic filters run over the whole CloudEvent.

### Artifacts (17:10-17:40)
- opsx-new + opsx-ff (headless, written in-lane): proposal, contract, specs/school-contributions (REQ-SCON-001..010), design (D1-D10, settled signal in D6), migration, test-plan, tasks (18 boxes). Discovery skipped (optional; recon answered the unknowns).
- `openspec validate --strict`: valid. Committed 47c07d072 and pushed (branch exists on origin).
- Next: opsx-apply tasks 1-9.

### Apply progress (17:40-18:45)
- Tasks 1-6 done, committed and pushed (b01d1c7a4 schema, d1930c75b validator, f14f4aa5b builder, 27249c64e resolver, c0ce3c8cf raise+endpoint, 8b90b51cb reconciliation+settled edge).
- Each task: php -l, phpcs, phpstan, phpmd on touched files clean; its PHPUnit classes green. Mutation checks done on the raise idempotency and on both reconciliation fixes (tests go red when the fix is removed).
- Next: task 7 voluntary dunning, task 8 portal parent audience, task 9 leaf paging.
- 19:25 vendor was stale vs composer.lock (hydra-gates v1.9.0 vs v1.18.0, nextcloud/ocp missing, 8 packages differ): psalm could not run (missing stub RegisterSlugResolution.php). Ran composer install --no-scripts to sync vendor to the lockfile (genuinely missing dependency, lane rule).
- 19:10 tasks 7-9 done (ed6d96453 dunning, 84f37ac63 portal parent, d2aa7fcd4 leaf paging), design aligned 517dc1c60.
- Pre-push run 1: check:strict EXIT=1, only psalm (config stub missing in stale vendor, never ran); PHPUnit 5292 OK, phpstan OK, phpmd clean, phpcs warnings only. npm lint 0 (98 inherited warnings), format 0, test:l10n 0, l10n parity 0, schema-l10n 0 (12217 = lowered baseline), seeds/fragment-required/registers 0. Gates on stale v1.9.0: gate-53 FAIL inherited (0 manifest inputs in diff).
- composer install (vendor synced to lock). Gates rerun on v1.18.0: 53/53 applicable ran, gate-108 FAIL was REAL: ARInvoice declares `invoiceLines`, not `lines` -> OR drops the line silently. Fixed: line in invoiceLines (EN 16931), revenueAccount in contribution group; parent manifest maps lines->invoiceLines. gate-108 standalone exit 0 after fix. gate-101 (newer checker from pq-guard vendor, read-only) exit 0, property-meta 0, cross-app-slug 0.
- opsx-verify (headless, code level): 1 CRITICAL (PaymentRequestFinder had no test) fixed with PaymentRequestFinderTest; WARNINGs fixed: contract 500 row + SLA wording, design D7 wording, docs/api/school-contributions.md. Re-verify clean. Commit d27694b3f.
- INHERITED noted, not fixed: quick draft + recurring generator + InvoicePdfGenerator use `lines` (undeclared on ARInvoice); customer portal manifest lists totalAmount/taxAmount (ARInvoice has grossAmount/vatAmount).
- 20:05 FINAL on d27694b3f: check:strict EXIT=0 (psalm no errors, phpstan no errors, 5296 tests OK), hydra gates EXIT=0 (53/53 applicable), npm lint/format/test:l10n/parity/schema-l10n/l10n-js 0, seeds/fragment/registers 0, gate-101 0, gate-108 0, openspec validate --strict 0.
- PR: https://github.com/ConductionNL/shillinq/pull/1704 (base development, not merged). opsx-verify verdict in the body.
- Status: DONE pending one CI read.
- 20:08 CI read once: 5 pass, 37 pending, 2 skipping, 0 fail. Not polled further (lane rule). LANE DONE.
