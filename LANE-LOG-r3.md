# Lane r3-shillinq (sq-fees), learniq round 3

Rules: LANE-RULES.md, -R2, -R3. Decision D28. Previous log LANE-LOG-r2.md (inherited bugs: `lines` vs `invoiceLines`, portal totalAmount/taxAmount).

## Change 1: voluntary-contribution-reminder (D28)
- Branch: `feat/voluntary-contribution-reminder`, cut --no-track from origin/development 88fb62a17.
- Status: in progress (started 2026-09-27 ~21:00).
- Recon: stage templates live in lib/Settings/docudesk-templates.json (nl only, stage 1 names IBAN + due date); executeStage is the choke point every run passes; invoice has no language field; no closed state for a refused contribution; PortalContributionProvider rowAction/actions pattern for `pay`. No parallel PR (gh pr list), no openspec change in the 4 OpenSpec passes covers it.
- Artifacts: proposal, contract, specs/school-contributions (REQ-SCON-011..014), design, migration, test-plan, tasks (11 boxes). `openspec validate --strict` valid.
- Apply (tasks 1-5 done, each test red first, then green; commits a92f35926, 91df3c074, 90c0f3d92, 021f8bc60, c52f8e420, all pushed):
  - T1 ARInvoice 0.15.0 overlay: contribution.language, contribution.declinedAt, lifecycleState enum += declined, lifecycle state + decline/decline-overdue guarded by VoluntaryDeclineGuard, isOverdue excludes declined. Dropped an arAging/creditExposure filter: those aggregations declare no metric and compute nothing (#1261), the overlay raised validate-registers' dead-aggregation count 137 -> 139. 3 schema strings got catalogue keys (schema-l10n back at 12217).
  - T2 two templates in docudesk-templates.json (inserted as text: the file does not round-trip through json.dumps), VoluntaryReminderTemplate, raise writes contribution.language.
  - T3 executeStage selects the letter via VoluntaryContributionPolicy::prepareRun (moved there: inline the class hit phpmd ExcessiveClassLength 1316 > 1300; base 1292-ish), tickInvoice skips declined.
  - T4 PortalSubjectResolver (extracted from PortalPaymentSessionService, pay tests unchanged green), ContributionDeclineService (find() by uuid, not findAll id filter), PortalContributionDeclineController, route, parent manifest `decline` action.
  - INHERITED found, not fixed: PortalPaymentSessionService::findOwnedPayableInvoice looks invoices up with findAll(['filters' => ['id' => ...]]), which matches nothing in real OpenRegister (ObjectIdentifier::findOne docblock), so a pay by invoice uuid would 403 live; only the slug arm can match. DunningTemplateRegistry has no call site and its ids (tpl-stage1-vriendelijk-nl) differ from the seeded ladder's (tpl-dunning-stage1-nl).
- Pre-push (2026-09-27/28): check:strict EXIT=0 on c52f8e420 (lint ok, phpcs warnings only none on touched files, phpmd clean, psalm no errors, phpstan no errors, 5317 tests OK). npm lint 0 (98 inherited warnings), format 0, test:l10n 0, l10n-js 0, check:registers/seeds/fragment-required/schema-l10n 0. check:manifest 1 INHERITED (src untouched; local nextcloud-vue schema 2.28.0 rejects the dev manifest).
- Gates run 1 (delegator resolved apps-extra/.github package): EXIT=1, gate-53 FAIL + gate-22/68 SKIPPED(wiring): its .js files load as ES modules because /workspace/server/package.json has type:module. Not a diff finding. Rerunning with HYDRA_GATES_HOME=vendor/conduction/hydra-gates/hydra-gates (v1.18.0, what r2 used).
- Resumed after the account limit reset (coordinator 09-28): state = branch pushed at c52f8e420, clean tree.
- Plan for change 2: STACK on feat/voluntary-contribution-reminder (it edits PortalPaymentSessionService and PortalContributionProvider, which change 1 changes; change 1 removed the private resolver methods change 2 would call). Say so in both PR bodies. Draft proposal in .tmp/c2-proposal-draft.md.
- Gates run 2 (HYDRA_GATES_HOME=vendor v1.18.0): EXIT=0, all 80 applicable ran. Diff-scoped run (--scope-to-diff --base origin/development c92d70222): EXIT=0, 53/53 applicable ran, gate-16/101/108 PASS. Dev moved (#1718, #1720, #1722: docs/parity only, no overlap with this branch).
- PR: https://github.com/ConductionNL/shillinq/pull/1724 (base development, not merged).
- opsx-verify headless: 3 WARNING + 1 SUGGESTION (spec said id or slug, design seed slug, controller test 2 methods / no customer audience, guard test one method), fixed in 018cdde04, re-verify clean; verdict in PR body.
- Change 1 status: DONE (head 018cdde04), pending the one CI read at lane end.

## Change 2: arinvoice-lines-and-portal-amounts
- Branch: `fix/arinvoice-lines-and-portal-amounts`, STACKED on feat/voluntary-contribution-reminder (018cdde04). Reason in the change 1 plan line above.
- Dedupe: no open change covers it (receivables-payment-links = providers; sales-invoice-document = later docudesk PDF re-render, complementary).
- Design: invoiceLines EN 16931 shape (like ContributionInvoiceBuilder), PDF normaliseLine (both shapes), customer manifest declared names + test that every listed field is declared, PaymentRequest 0.5.0 customerId (uuid, $ref CustomerMaster) stamped for request-only requests (portaliq verifyScope is flat-key only; a via through CustomerMaster would filter on id which findAll cannot match), requestPayments collection, pay endpoint paymentRequestId -> initiateForRequest.
- Artifacts committed 1d9ed5233 and pushed (branch fix/arinvoice-lines-and-portal-amounts on origin). openspec validate --strict valid. 13 task boxes.
- Apply (tasks 1-6 done, each red first; commits 1fcf83363 quick draft, d86d4c22f recurring, 60ca30462 PDF, 52959ea4f portal fields, 69a5e451a customerId + stamp + backfill, c2f2e442a requestPayments + pay path, then case-payment-requests 4.1 ticked). All pushed.
  - PDF finding: the hybrid PDF discarded the HTML (unset($html)), so no lines ever printed; now the page prints one text line per invoice line, and the HTML row reads both line shapes.
  - Portal: the customer manifest named totalAmount/taxAmount/lines/state/ublXml; now grossAmount/vatAmount/invoiceLines/lifecycleState/ublRef, PARENT_FIELD_MAP removed, and a test fails on any undeclared listed field.
  - Coordinator added change 3 `portal-pay-row-action-keys` (portaliq #805) mid-change. #805 forwards {rowField: proven row id}; so requestPayments got its own `pay-request` action (rowField paymentRequestId, rowWhen state in [pending]) in change 2, not the `pay` action; REQ-SPPI-006 (exactly one action) MODIFIED to two. Change 3 will do `pay` rowField/rowWhen, noticeField, drop paymentRequests rowAction, redirect setting.
  - INHERITED noted: RecurringInvoiceGenerator writes recurringProfileId/billingPeriod (undeclared on ARInvoice) and its idempotency probe filters on them (in-memory test store keeps them, live OR drops them); its customerId is a contact reference, not a CustomerMaster uuid; no invoiceNumber/periodId (required). Leaf API writes requestedBy (undeclared). Quick draft writes customerReference (undeclared on ARInvoice).
- Pre-push: check:strict EXIT=0 (5338 tests), npm lint/format/test:l10n/l10n-js/parity 0, registers/seeds/fragment/schema-l10n/job-registration 0, touched vitest specs 0. Diff gates run 1 EXIT=1: gate-110 FAIL (repair step without <version> bump) = NEW, fixed 9efb22a14 (0.5.3-unstable.20260928080000), rerun diff gates EXIT=0 (59/59), full gates EXIT=0 (80/80). Full vitest 1/291 red externalConnectionsPage.spec.js INHERITED (node_modules nextcloud-vue 2.31.1 vs ^2.57.1).
- PR: https://github.com/ConductionNL/shillinq/pull/1728 (base development, stacked on #1724, not merged).
- opsx-verify headless: 1 WARNING (design D3) + 1 SUGGESTION (migration version), fixed, re-verify clean; verdict in PR body.
- Change 2 status: DONE.

## Change 3: portal-pay-row-action-keys (coordinator, 09-28, portaliq #805)
- Branch: `feat/portal-pay-row-action-keys`, STACKED on fix/arinvoice-lines-and-portal-amounts (same provider file, and requestPayments/pay-request live there).
- #805 findings: rowWhen reads $row[field] (flat, ^[a-zA-Z][a-zA-Z0-9_]*$); ARInvoice rows have lifecycleState not state -> use lifecycleState. decline cannot be gated to voluntary by rowWhen (nested flag) -> stays a plain action. Draft proposal in .tmp/c3/proposal.md.
- Artifacts committed and pushed; openspec validate --strict valid; 6 task boxes.
- Apply: f6ce3f83a pay rowField invoiceId + rowWhen lifecycleState in receiver PAYABLE_STATES (test pins via reflection), parent salesInvoices noticeField invoiceNote, paymentRequests rowAction dropped; 7415b2209 portal_payment_redirect_url in SettingsService CONFIG_KEYS (https or empty, else InvalidArgumentException -> controller 400, nothing stored), Settings.vue field + error message, l10n en/nl, docs. Each red first.
- Pre-push: check:strict EXIT=0 (5341 tests), npm lint/stylelint/format/test:l10n/l10n-js 0, registers/schema-l10n 0, diff gates EXIT=0 (71/71 incl. gate-50), full gates EXIT=0 (80/80).
- PR: https://github.com/ConductionNL/shillinq/pull/1729 (base development, stacked on #1728 -> #1724, not merged).
- opsx-verify headless: 1 WARNING (settings key not tied to pay flow key), fixed 8c71acfa5, re-verify clean; verdict in PR body.
- Change 3 status: DONE. Next: one CI read per PR, then report.

## CI read (once, 2026-09-28)
- #1724: 46 pass, 8 skipping, 0 fail (54). #1728: 46 pass, 8 skipping, 0 fail (54), MERGEABLE/BLOCKED (review). #1729: 1 pass, 5 pending (6) at the one read; not polled.
- git merge-tree against origin/development a1436adf0: all three branches EXIT=0, 0 conflicts.
- LANE DONE. Landing order: #1724 -> #1728 -> #1729 (stacked).
