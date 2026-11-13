<?php

/**
 * Shillinq Application
 *
 * Main application class for the Shillinq Nextcloud app.
 *
 * @category AppInfo
 * @package  OCA\Shillinq\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Listener\InvoiceCommitmentListener;
use OCA\Shillinq\Middleware\ApiContractMiddleware;
use OCA\Shillinq\Listener\AppointmentCreatedListener;
use OCA\Shillinq\Listener\BookingCreatedTimelinePublishListener;
use OCA\Shillinq\Listener\BookingLifecycleTransitionListener;
use OCA\Shillinq\Listener\CommitmentMaterialisationListener;
use OCA\Shillinq\Listener\CommitmentTransitionListener;
use OCA\Shillinq\Listener\ContractObligationTaskListener;
use OCA\Shillinq\Listener\SupplierInvoiceWarningListener;
use OCA\Shillinq\Listener\DbaInvoiceMonitorListener;
use OCA\Shillinq\Listener\DeepLinkRegistrationListener;
use OCA\Shillinq\Listener\DeliveryDispatchListener;
use OCA\Shillinq\Listener\ExtractionCompletedListener;
use OCA\Shillinq\Listener\FixedAssetDisposalListener;
use OCA\Shillinq\Listener\GLTransactionComplianceCacheListener;
use OCA\Shillinq\Listener\GRIRClearingListener;
use OCA\Shillinq\Listener\ReconciliationMatchSettlementListener;
use OCA\Shillinq\Listener\InnovatieboxAuditTrailListener;
use OCA\Shillinq\Listener\IntegriqCloudEventListener;
use OCA\Shillinq\Listener\BankfeedSyncedListener;
use OCA\Shillinq\Listener\IntercompanyLinkListener;
use OCA\Shillinq\Listener\LeaseActivationListener;
use OCA\Shillinq\Listener\FeeScheduleValidationListener;
use OCA\Shillinq\Listener\OrderFulfilmentTransitionListener;
use OCA\Shillinq\Listener\OssPaymentReconciliationListener;
use OCA\Shillinq\Listener\PaymentRequestLeafRegistrationListener;
use OCA\Shillinq\Listener\PeppolDeliveryStatusListener;
use OCA\Shillinq\Listener\PeppolInboundUblInvoiceListener;
use OCA\Shillinq\Listener\PosStockDecrementListener;
use OCA\Shillinq\Listener\ReconciliationMatchToReportListener;
use OCA\Shillinq\Listener\StockMoveTransitionedListener;
use OCA\Shillinq\Listener\TenderNedAwardDetectedListener;
use OCA\Shillinq\Notification\DeadlineReminderNotifier;
use OCA\Shillinq\Notification\EInvoiceNotifier;
use OCA\Shillinq\Notification\PosStockUnmatchedLineNotifier;
use OCA\Shillinq\Notification\RoleFallbackResolver;
use OCA\Shillinq\Support\FleetAppId;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\Util;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\Shillinq\Guard\GuardRegistrar;
use OCA\Shillinq\Lifecycle\LifecycleRegistrar;
use OCA\Shillinq\Repair\RepairRegistrar;
use OCA\Shillinq\Service\ServiceRegistrar;

/**
 * Main application class for the Shillinq Nextcloud app.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * Pre-existing debt (issue #506): register() wires every service/listener/
 * guard the app defines, so its length and the class's coupling count scale
 * with the app's feature surface; splitting is out of scope for a mechanical
 * phpcs/phpmd cleanup. Deferred to a follow-up.
 */
class Application extends App implements IBootstrap {
	use FilteredObjectListenerTrait;

	public const APP_ID = AppIdentity::APP_ID;

	/**
	 * Constructor for the Application class.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct(appName: self::APP_ID);
	}//end __construct()

	/**
	 * Register event listeners and services.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function register(IRegistrationContext $context): void {
		// The API contract (version header, deprecation of unversioned paths,
		// problem details). Registered first: Nextcloud runs afterController in
		// reverse order, so it sees the final response of every other middleware.
		$context->registerMiddleware(class: ApiContractMiddleware::class);

		$this->registerOpenRegisterContracts(context: $context);
		// OpenRegister AppHost adoption (adopt-apphost) — the mechanical
		// controllers/settings that are byte-for-byte the fleet skeleton are
		// re-pointed at the engine's generics, and observability is served by
		// the engine from the `observability` block in src/manifest.json.
		//
		// Every alias below is registered through a closure that references the
		// OCA\OpenRegister\AppHost\… classes only by STRING (no top-level use /
		// ::class), so a disabled OpenRegister never fatals NC bootstrap — the
		// closure only runs when a route is dispatched, and a missing generic
		// surfaces as a 5xx (the correct DEGRADED behaviour; health then
		// reports orAvailable: failed).
		//
		// Bespoke plumbing that is NOT mechanical is deliberately KEPT
		// (SettingsController/SettingsService — fragment-merge config loading;
		// PreferencesController — no generic ships yet; InitializeSettings —
		// 13-phase domain seeding; DeepLinkRegistrationListener — dynamic
		// register-slug resolution). Bootstrap::register() is intentionally not
		// used: it would alias those onto generics that do not match shillinq's
		// behaviour. See openspec/changes/adopt-apphost.
		$this->registerGenericControllers(context: $context);
		(new AppHostRegistration())->register(context: $context);

		// Register deep link patterns with OpenRegister's unified search provider.
		// Only fires when OpenRegister is installed and dispatches the event.
		// KEPT bespoke: resolves the configured register slug dynamically from
		// app config, which the manifest-driven GenericDeepLinkRegistrationListener
		// does not do (adopt-apphost).
		$context->registerEventListener(
			event: DeepLinkRegistrationEvent::class,
			listener: DeepLinkRegistrationListener::class
		);

		// Spec case-payment-requests REQ-SOPR-003 / REQ-SOPR-004 — contribute the
		// payment-request leaves to OpenRegister's catalogue, so a case app can
		// ask for money on its own object without shillinq knowing the app.
		// Guarded on the event class: shillinq boots without OpenRegister.
		if (class_exists('OCA\\OpenRegister\\Event\\RegisterLeafProvidersEvent') === true) {
			$context->registerEventListener(
				event: \OCA\OpenRegister\Event\RegisterLeafProvidersEvent::class,
				listener: PaymentRequestLeafRegistrationListener::class
			);
		}

		// Inventory-valuation-fifo-avg REQ-INV-003 / REQ-INV-004 / REQ-INV-007
		// — dispatch posted StockMove records into the valuation engine
		// (FIFO or moving-average per the InventoryValuation.valuationMethod)
		// and post a balanced COGS GLTransaction on outbound moves.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: StockMoveTransitionedListener::class
		);

		// Inventory-sales-issue-cogs-trigger REQ-001 / REQ-006 — the missing
		// outbound trigger: a confirmed Delivery issues stock (feeding the
		// StockMoveTransitionedListener pipeline above unchanged); a
		// cancelled Delivery reverses any StockMove it issued.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: DeliveryDispatchListener::class
		);

		// Bookkeeping-purchase-order-3way slice 05 (REQ-PO3W-004) —
		// openconnector publishes a `PeppolInboundMessage` OR record for
		// every received Peppol message. This listener filters on the
		// documentType=Invoice slice of those events and dispatches the
		// UBL payload into SupplierInvoiceService::ingestUBLInvoice().
		//
		// NOT narrowed (deliberate): the only slug the handler accepts is
		// `PeppolInboundMessage`, owned by openconnector and resolving to no
		// schema here. A declaration that resolves to nothing means the
		// listener never fires — worse than keeping it global.
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: PeppolInboundUblInvoiceListener::class
		);

		// Grir-accrual-wiring (shillinq#412) — member 09's GRIRClearingService
		// (REQ-PO3W-009) fully implements the clearing/settlement GL
		// postings but had zero callers. This listener wires the existing,
		// unmodified GoodsReceiptNote/SvcReceipt accept transitions and the
		// SupplierInvoice matching->matched transition (already fired by
		// ThreeWayMatchingEngine::evaluateMatch()) to
		// GRIRClearingService::postGRIRForGoodsReceiptAccept() /
		// postGRIRForServiceReceiptAccept() / settleGRIRForMatchedInvoice().
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: GRIRClearingListener::class
		);

		// Banking-manual-match REQ-BMM-003: a confirmed ReconciliationMatch,
		// by a person, a rule or the bank feed, moves the invoices it names to
		// paid through their own declared transitions.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: ReconciliationMatchSettlementListener::class
		);

		// Planning-commitment-year-end REQ-PCYE-002/003: an approved supplier
		// invoice lowers the commitment of its order, and the last one closes it.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: InvoiceCommitmentListener::class
		);

		// Revive-gl-tax-capabilities (shillinq#417/#446) REQ-GLTAX-001 — the
		// missing fixed-asset disposal trigger. DisposalJournalEmitter fully
		// implements the closing GLTransaction for a retired FixedAsset and
		// had zero callers, so an asset could be retired while its gross
		// value and accumulated depreciation both stayed on the balance
		// sheet. This listener wires the (now executable — see the repaired
		// FixedAsset x-openregister-lifecycle) `active -> retired` transition
		// to FixedAssetDisposalService, which normalises the record, reverses
		// the depreciation ACTUALLY posted (DepreciationSchedule) and
		// persists the balanced journal. Fail-soft.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: FixedAssetDisposalListener::class
		);

		// Revive-gl-tax-capabilities (shillinq#418/#446) REQ-GLTAX-002 — the
		// missing intercompany mirror + reconciliation trigger.
		// IntercompanyJournalService (REQ-MA-004) implements buildMirror() /
		// reconcileVariance() / isBalanced() and NONE of them had a caller.
		// This listener wires the `IntercompanyJournalEntry` `concept ->
		// gekoppeld` transition — the one whose own description promises "the
		// mirrored journal entry is created in the destination
		// administration" — to IntercompanyLinkService. Fail-soft.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: IntercompanyLinkListener::class
		);

		// Change add-invoice-pdf-export-with-ubl-peppol-support REQ-EINV-005 — consume
		// the `nl.conduction.peppol.delivery.status` CloudEvent integriq's Peppol
		// access point emits and advance ARInvoice.deliveryStatus (REQ-AR-011).
		// integriq dispatches no Nextcloud event by that name: it saves the
		// CloudEvent as an OpenRegister object (register `integriq`, schema
		// `event`), so the listener hears ObjectCreatedEvent and matches the
		// type itself (#1111).
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: PeppolDeliveryStatusListener::class
		);

		// Change receivables-payment-links design D3 / REQ-RPL-003 (#1681): integriq
		// saves every CloudEvent as an OpenRegister object in register
		// `integriq`, schema `event`, and dispatches no Nextcloud event of its
		// own. So a `nl.conduction.payment.status` outcome reaches shillinq's
		// reconciliation through ObjectCreatedEvent, matched by slug.
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: IntegriqCloudEventListener::class
		);

		// Banking-connected-accounts REQ-BCON-002: integriq's bank feed pull
		// arrives the same way, as an `integriq` / `event` object of type
		// nl.conduction.bankfeed.transactions.synced.
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: BankfeedSyncedListener::class
		);

		(new RepairRegistrar())->register(context: $context);
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: BookingLifecycleTransitionListener::class
		);

		// Bookkeeping-reconciliation-reports (T4) — REQ-REC-010. T2's
		// bookkeeping-bank-reconciliation engine confirms a
		// ReconciliationMatch by transitioning its status to `confirmed`;
		// the listener stamps the T4-side fields (reconId, matchAlgorithm,
		// matchedAt, glTransactionId/arInvoiceId/apTransactionId, etc.) on
		// the same record so the open BankReconciliation session sees the
		// outcome within 1s. Fail-soft: never blocks the T2 write.
		//
		// This ObjectTransitionedEvent registration stays global; the narrowed
		// create path is subscribed from boot().
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: ReconciliationMatchToReportListener::class
		);

		(new ServiceRegistrar())->register(context: $context);

		// Migrate-legacy-notification-dialect (task 1.3) — register one
		// RoleFallbackResolver instance per (primary role, fallback role)
		// pair used by the canonical `recipients: [{kind: "expression",
		// resolver: "..."}]` rules migrated off the legacy singular
		// `"recipient": {resolver, fallback}` shape. Each alias is a DI
		// service id (not a literal FQCN::method call — the OR dispatcher
		// resolves it via IServerContainer::get($resolverTag)), resolved by
		// Nextcloud's container the same way any other registered service
		// is. Group ids follow the `shillinq_<role>` convention established
		// by WbsoRbacResolver::GROUP_TO_ROLE.
		$context->registerService(
			RoleFallbackResolver::class . '::financeOfficer',
			static function ($c): RoleFallbackResolver {
				return new RoleFallbackResolver(
					groupManager: $c->get(IGroupManager::class),
					logger: $c->get(LoggerInterface::class),
					primaryGroup: 'shillinq_finance_officer',
					fallbackGroup: 'shillinq_subsidie_coordinator',
				);
			}
		);
		$context->registerService(
			RoleFallbackResolver::class . '::subsidieCoordinator',
			static function ($c): RoleFallbackResolver {
				return new RoleFallbackResolver(
					groupManager: $c->get(IGroupManager::class),
					logger: $c->get(LoggerInterface::class),
					primaryGroup: 'shillinq_subsidie_coordinator',
					fallbackGroup: 'shillinq_administration_treasurer',
				);
			}
		);
		$context->registerService(
			RoleFallbackResolver::class . '::payrollOfficer',
			static function ($c): RoleFallbackResolver {
				return new RoleFallbackResolver(
					groupManager: $c->get(IGroupManager::class),
					logger: $c->get(LoggerInterface::class),
					primaryGroup: 'shillinq_payroll_officer',
					fallbackGroup: 'shillinq_administration_treasurer',
				);
			}
		);

		// Dba-compliance-marker T31/T32 — optional non-blocking hook into AP/AR
		// factuur creation. The listener runs the VBAR uurtarief-toets
		// (REQ-DBA-016) when an ARInvoice/APInvoice carries a
		// `dbaOpdrachtId` field; it is silently inert otherwise.
		//
		// NOT narrowed (deliberate): the handler carries no schema guard at
		// all — its interest is the PRESENCE of a `dbaOpdrachtId` field,
		// whatever schema carries it. Declaring ARInvoice/APInvoice would
		// narrow behaviour on an assumption the code does not state.
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: DbaInvoiceMonitorListener::class
		);

		// Bookkeeping-tenderned-integratie Tasks 5.1 / 5.2 / 5.3 — react to
		// the OR object-lifecycle events that materialise the
		// `tenderned.award.detected`, `obligation.activated`, and
		// `milestone.completed` CloudEvents (design D4). Every listener is
		// fail-soft: an exception is logged but never propagated back into
		// the originating OR write path.
		//
		// Task 5.1 — TenderNedAwardDetectedListener auto-promotes an
		// awarded TenderNed dossier into an active Commitment when the
		// winning KvK matches the tenant org (REQ-002 idempotent on
		// bronReferentie + REQ-003 milestone plan generated from the
		// opdrachttype template).
		//
		// The create path is narrowed and therefore subscribed from boot(); the
		// ObjectTransitionedEvent registration below stays global.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: TenderNedAwardDetectedListener::class
		);

		// Task 5.2 — CommitmentTransitionListener emits the cross-app
		// `obligation.activated` CloudEvent on auto-promoted (created
		// active) AND manually-enriched (transitioned to active)
		// tenderned-sourced obligations (REQ-007 budget-impact pipeline).
		//
		// The create path is narrowed and therefore subscribed from boot(); the
		// ObjectTransitionedEvent registration below stays global.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: CommitmentTransitionListener::class
		);

		// Task 5.3 — OrderFulfilmentTransitionListener emits
		// `milestone.completed` on every completed OrderFulfilment and
		// (for the approved eindoplevering of a tenderned-sourced
		// obligation) triggers the outbound status-sync to TenderNed
		// (REQ-006). The buyer-side gate is enforced both server-side
		// (RBAC + TenderNedProcurementGuard::canAfronden) and inside
		// TenderNedStatusSync as a defence-in-depth tenant KvK check.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: OrderFulfilmentTransitionListener::class
		);

		// REQ-004 bewijsstuk-required completion gate, both halves.
		(new OrderFulfilmentGateRegistration())->register(context: $context);
		(new PurchasingControlsRegistration())->register(context: $context);
		// #516/#1103: the ledger posting guards; then the reporting listeners.
		(new LedgerPostingRegistration())->register(context: $context);
		(new PaymentControlsRegistration())->register(context: $context);
		(new ReportingRegistration())->register(context: $context);
		(new FieldRequirementRegistration())->register(context: $context);

		// REQ-SOPR-006 fee-schedule rules on the write path. No controller in
		// this app writes a FeeSchedule: they go straight into OpenRegister, so
		// the pre-save veto is the only place the overlap, legal-basis and
		// default-amount rules can run at all. Until this listener existed
		// FeeScheduleService::assertNoOverlap() had tests and no caller.
		foreach (['OCA\\OpenRegister\\Event\\ObjectCreatingEvent', 'OCA\\OpenRegister\\Event\\ObjectUpdatingEvent'] as $preSaveEvent) {
			$context->registerEventListener(
				event: $preSaveEvent,
				listener: FeeScheduleValidationListener::class
			);
		}

		// REQ-SIGN-001/005/006 — the decidesk DECISION and docudesk DOCUMENT
		// signing request+outcome listeners, registered as one unit.
		(new SigningDelegationRegistration())->register(context: $context);
		(new ReportScheduleControlsRegistration())->register(context: $context);

		// Change receipt-extraction-consume (REQ-RXC-001) — consume filinq's
		// cross-app FinancialExtractionCompletedEvent (the canonical
		// extraction.completed wire contract, owned by filinq's
		// financial-document-field-extraction spec) into an uncommitted,
		// confidence-scored SupplierInvoice/Receipt draft
		// (ExtractionCompletedListener + ExtractionPrefillService). Registering
		// by the event FQCN is safe even when the class is not autoloadable —
		// NC only needs the string key; handle() itself is guarded so the
		// listener is inert when filinq is not installed. Fail-soft: never
		// blocks filinq's synchronous dispatch.
		//
		// ⚠️ Registered under EVERY candidate namespace, not one. filinq moved
		// OCA\DocuDesk -> OCA\Filinq in August 2026 and this listener, bound to
		// the old name alone, went dark without an error: dispatch matches on
		// the concrete event class, so a name the producer no longer uses
		// simply never fires. Candidate names that never materialise are inert,
		// which is why registering both is the safe shape and picking one is not.
		foreach (FleetAppId::classCandidates(canonical: 'filinq', relative: 'Event\FinancialExtractionCompletedEvent') as $eventClass) {
			$context->registerEventListener(
				event: $eventClass,
				listener: ExtractionCompletedListener::class
			);
		}

		// Change inventory-pos-decrement (shillinq#504) — consume pipelinq's
		// cross-app OCA\Pipelinq\Event\PosStockMovedEvent (nl.pipelinq.pos.stock.moved),
		// the companion producer event pipelinq emits from its POS-sale settle
		// path. PosStockDecrementListener delegates every stock-tracked line to
		// the EXISTING, UNMODIFIED SalesDispatchStockIssueService::issueForDelivery()
		// -> StockMoveTransitionedListener -> valuation -> CogsPosterService
		// pipeline (the same one DeliveryDispatchListener above already drives
		// for shillinq's own Delivery-triggered sales) — no decrement/COGS logic
		// is reimplemented. Registering by the pipelinq event FQCN is safe even
		// when the class is not autoloadable — NC only needs the string key;
		// handle() itself is class_exists-guarded so the listener is inert when
		// pipelinq is not installed (the event never fires). Fail-soft: never
		// blocks pipelinq's synchronous dispatch.
		$context->registerEventListener(
			event: \OCA\Pipelinq\Event\PosStockMovedEvent::class,
			listener: PosStockDecrementListener::class
		);

		// Change verplichtingen-commitment-accounting Tasks 1/2 (REQ-VPL-010) —
		// CommitmentMaterialisationListener auto-materialises a Commitment
		// when a PurchaseOrder reaches `approved` or a Contract reaches
		// `active`, reusing the existing BudgetBlocker/MandateEnforcer
		// guards. PO path is fail-closed (denial propagates); Contract path
		// is fail-soft (design.md Open Question: no separate signed/executed
		// state exists on the shipped Contract schema, so `active` is
		// treated as the trigger and any denial is only logged).
		//
		// NOT narrowed (deliberate): the handler matches on
		// `str_ends_with($schema, 'contract')`, a suffix wildcard covering six
		// distinct schemas today (contract, employmentcontract, fxcontract,
		// leasecontract, suppliercontract, synchronization_contract) plus any
		// future `*contract` slug — not statically enumerable.
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: CommitmentMaterialisationListener::class
		);
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: CommitmentMaterialisationListener::class
		);

		// Compliance-deadline-calendar REQ-CDC-007 — render the
		// `deadline_reminder` notifications raised by
		// ComplianceDeadlineCalendarService / DeadlineReminderJob in the
		// notification centre. Without a registered INotifier the raised
		// notifications would be discarded at display time.
		$context->registerNotifierService(DeadlineReminderNotifier::class);

		// REQ-EINV-005 (#1111) — render the rejection notice
		// PeppolDeliveryStatusListener raises when the Peppol network refuses an
		// e-invoice. Without a registered INotifier it is discarded at display time.
		$context->registerNotifierService(EInvoiceNotifier::class);

		// Change inventory-pos-decrement (shillinq#504) — render the
		// `pos_stock_unmatched_line` notifications PosStockDecrementListener
		// raises for a POS-sale line that could not be matched to a shillinq
		// inventory item. Without a registered INotifier those notifications
		// would be discarded at display time — the reconciliation/audit
		// surface would be silently unreachable.
		$context->registerNotifierService(PosStockUnmatchedLineNotifier::class);

		// Shillinq#425 fix — register every lifecycle guard tag this change
		// adds so it actually resolves via OpenRegister's
		// LifecycleGuardRegistry::resolve(), instead of merely existing as
		// an unreachable class. LifecycleGuardRegistry treats the ENTIRE
		// `requires` string (including any `::method` suffix) as a single
		// DI container tag and calls `->check()` on whatever resolves; a
		// tag containing `::` can NEVER autowire (PHP class names cannot
		// contain `::`; confirmed empirically — `new ReflectionClass('Foo::
		// bar')` always throws ReflectionException), so every one of these
		// tags MUST be explicitly registered here, mapped through the
		// shared RegisterRequiresGuardAdapter onto each guard's existing
		// `bool <method>(array $object): bool` precondition method. This
		// mirrors the already-established pattern this file uses for
		// notification resolver tags (see the `RoleFallbackResolver::class.
		// '::financeOfficer'` registrations above) — arbitrary string tags
		// are ordinary Nextcloud container aliases once registered.
		//
		// This is deliberately scoped to the 17 guards + PeriodCloseGuard
		// method shillinq#425 covers. Dozens of pre-existing guards
		// (PeriodCloseGuard's other three
		// methods, InventoryPostingGuard, KorThresholdGuard, ...) reference
		// tags shaped the same way and are NOT registered — every one of
		// those transitions also hard-fails today. That fleet-wide gap is
		// filed separately as shillinq#433 and intentionally not fixed here.
		// The commitment (REQ-PCYE-001) and import batch guard tags.
		(new GuardTagServices())->register(context: $context);

		(new GuardRegistrar())->register(context: $context);

		(new LifecycleRegistrar())->register(context: $context);

		// Budget-scenarios REQ-BSC-004/REQ-BSC-006 — the BudgetScenarioModifier
		// guard alias and the KnownCostScheduleExpanderInterface binding,
		// registered as one unit. See BudgetScenarioRegistration's own
		// docblock for why this lives in its own class.
		(new BudgetScenarioRegistration())->register(context: $context);
		(new BbvValidationRegistration())->register(context: $context);

	}//end register()

	/**
	 * Bind the OpenRegister interfaces this app's services type-hint to their implementations.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerOpenRegisterContracts(IRegistrationContext $context): void {
		// ADR-084: services type-hint OpenRegister's PUBLISHED interface, never its
		// concrete class, so this app's unit tests can mock a type they are able to
		// load. Nextcloud autowires concrete classes across apps but not interfaces,
		// so the binding has to be stated — and the composition root is where this
		// app says how it is wired.
		//
		// An ALIAS, not a factory: it resolves when something actually asks for the
		// interface, so an instance without OpenRegister fails at the route that
		// needed the data rather than at registration. Both names are strings and
		// neither triggers an autoload, which is what keeps ADR-083 rule 3's promise
		// that the start screen still boots.
		$context->registerServiceAlias(
			ObjectServiceInterface::class,
			'OCA\OpenRegister\Service\ObjectService'
		);

		// The register-slug resolver, bound the same way and for the same reason.
		//
		// Register slugs live in `openregister_registers`, and nine fleet apps ship
		// a repair step that renames theirs. The step is per instance, so both
		// slugs are live across the estate at once and a literal is wrong on half
		// of it. The old-slug case is the quiet one: OpenRegister finds no
		// register, matches no rows, and returns an empty set that is byte for byte
		// what a healthy empty register returns. No exception, no 404, no log line.
		// This app once read Integriq's connector register that way, from the
		// external-adapters roster that adopt-connection-registry removed.
		//
		// Verified against this container, not assumed: OpenRegister registers the
		// resolver in its OWN container, so nothing of that registration reaches
		// here. What reaches here is the alias stated here plus autowiring of the
		// concrete class, whose only dependencies are `RegisterMapper` and
		// `LoggerInterface`. Both resolve from a leaf app's DIContainer, and the
		// interface then answers with a live resolution. Resolved live in this
		// app's own DIContainer on the running instance before this was written.
		//
		// The one thing lost is OpenRegister's shared-instance registration: a leaf
		// container autowires a fresh resolver per injection point, so the
		// request-scoped memo is per consumer rather than per request. That costs
		// one indexed read per consumer and changes no answer.
		$context->registerServiceAlias(
			RegisterSlugResolverInterface::class,
			'OCA\OpenRegister\Service\RegisterSlugResolver'
		);
	}//end registerOpenRegisterContracts()

	/**
	 * Point the dashboard, health and metrics controllers at the AppHost generics.
	 *
	 * These stay in the composition root: their factories look OpenRegister
	 * classes up by name, which only the composition root may do unguarded
	 * (ADR-083).
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerGenericControllers(IRegistrationContext $context): void {
		$appId = self::APP_ID;

		$context->registerService(
			'OCA\\Shillinq\\Controller\\DashboardController',
			static function (ContainerInterface $c) use ($appId) {
				$class = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericDashboardController';
				return new $class(
					appName: $appId,
					request: $c->get('OCP\\IRequest')
				);
			}
		);

		$context->registerService(
			'OCA\\Shillinq\\Controller\\HealthController',
			static function (ContainerInterface $c) use ($appId) {
				$class = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericHealthController';
				return new $class(
					appName: $appId,
					request: $c->get('OCP\\IRequest'),
					manifestLoader: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader'),
					executor: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\HealthCheckExecutor')
				);
			}
		);

		$context->registerService(
			'OCA\\Shillinq\\Controller\\MetricsController',
			static function (ContainerInterface $c) use ($appId) {
				$class = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericMetricsController';
				return new $class(
					appName: $appId,
					request: $c->get('OCP\\IRequest'),
					manifestLoader: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader'),
					engine: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\MetricsEngine')
				);
			}
		);

	}//end registerGenericControllers()

	/**
	 * Boot the application.
	 *
	 * Every object-event listener that declares a schema interest is subscribed
	 * here rather than in register(): OpenRegister's `ObjectEventSubscription`
	 * is only guaranteed autoloadable once every app's register() has run. See
	 * {@see FilteredObjectListenerTrait::registerFilteredObjectListener()}.
	 *
	 * @param IBootContext $context The boot context
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OCP\Util exposes script registration
	 * (addInitScript) as a static method only. Nextcloud ships no injectable
	 * service for it, and boot() is the only place an app-wide init script can
	 * be registered, so a seam class would relocate the identical static call
	 * rather than remove it. Verified against nextcloud lib/public/Util.php.
	 */
	public function boot(IBootContext $context): void {
		$dispatcher = $context->getServerContainer()->get(IEventDispatcher::class);

		// Put shillinq's two finance panels on every page, not just shillinq's
		// own (ADR-019 / ADR-066, REQ-SOPR-004 and REQ-FPCR-006). The leaves
		// are meant to be read on ANOTHER app's object: a case, a record, a
		// contract party. Nextcloud loads an app's bundle only on that app's
		// routes, so a leaf registered from src/main.js alone never reaches the
		// page it exists for. This tiny entry registers the two descriptors and
		// nothing else; the panels load their data only once mounted.
		Util::addInitScript(self::APP_ID, self::APP_ID.'-integration-init');

		// Bookings-confirm-flow REQ-BCF-001/010 — issue a ConfirmationToken
		// + dispatch the confirmation email when a new Appointment record is
		// created with status `pending_confirmation` (customer self-service).
		// Admin-created bookings start `confirmed` and the listener ignores
		// them. Idempotent: OR fires the event once per saveObject.
		//
		// Interest declared up front: `isAppointmentSchema()` accepts
		// `appointment` or `…/appointment`, i.e. exactly the `Appointment`
		// schema slug. No register is declared — every register slug in this
		// app comes from deployment configuration.
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectCreatedEvent::class,
			listener: AppointmentCreatedListener::class,
			schemas: ['Appointment']
		);

		// Every saved supplier invoice gets its duplicate and IBAN warnings
		// (purchasing-supplier-invoice-intake REQ-PSII-003/004).
		$this->registerFilteredObjectWriteListener(
			dispatcher: $dispatcher,
			listener: SupplierInvoiceWarningListener::class,
			schemas: ['SupplierInvoice']
		);

		// --- gate-57 region: ContractObligation task trigger (REQ-CDC-005).
		// The bridge shipped, its trigger never did — see
		// ContractObligationTaskListener's docblock for the rationale and the
		// re-entry guard its own write-back depends on.
		$this->registerFilteredObjectWriteListener(
			dispatcher: $dispatcher,
			listener: ContractObligationTaskListener::class,
			schemas: ['ContractObligation']
		);
		// --- end gate-57 region ---
		// IFRS-16 lease schedule trigger (revive-lease-capabilities,
		// shillinq#446). When a LeaseContract is created already `active` or is
		// updated across the `draft → active` edge, materialise its
		// amortization schedule. OR has no lifecycle action executor and the
		// lease's list-form transitions block ObjectTransitionedEvent
		// (design D1), so the executing trigger is the create/update event
		// dispatched by MagicMapper on the generic lease CRUD save path.
		//
		// Interest declared up front: the handler's own
		// `isLeaseContractSchema()` accepts `leasecontract` or
		// `…/leasecontract`, i.e. exactly the `LeaseContract` schema slug.
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectCreatedEvent::class,
			listener: LeaseActivationListener::class,
			schemas: ['LeaseContract']
		);
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectUpdatedEvent::class,
			listener: LeaseActivationListener::class,
			schemas: ['LeaseContract']
		);

		// Revive-gl-tax-capabilities (shillinq#446) REQ-GLTAX-004 — the
		// missing OSS-VAT distribution check.
		// OssPaymentReconciliation::reconcileDistribution() (REQ-OSS-008) is
		// the only control that catches the Belastingdienst distributing a
		// consolidated EU-VAT payment differently from what was declared, and
		// it had zero callers. An OssPayment carries the confirmed
		// per-country distribution, so its creation is the trigger: the
		// listener reconciles it against the linked OssReturn and drives the
		// record to `reconciled` or `discrepancy` (both declared transitions
		// out of `pending`). Fail-soft.
		//
		// Interest declared up front: the handler compares the normalised
		// schema against `osspayment` / `oss-payment`, so both spellings are
		// declared (the hyphenated one is unused here; declaring it is
		// over-inclusive and therefore fail-safe where it is seeded).
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectCreatedEvent::class,
			listener: OssPaymentReconciliationListener::class,
			schemas: ['OssPayment', 'oss-payment']
		);

		// Bookings-pipelinq-customer-bridge slice 07 — publish a
		// `booking.created` event to pipelinq's klantbeeld-360 timeline (the
		// TimelineRetryQueue / PipelinqAdminNotifier bindings this listener
		// uses are registered in register()).
		//
		// Interest declared up front: the handler's own
		// `isAppointmentSchema()` mirrors AppointmentCreatedListener's and
		// accepts `appointment` or `…/appointment`.
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectCreatedEvent::class,
			listener: BookingCreatedTimelinePublishListener::class,
			schemas: ['Appointment']
		);

		// Bookkeeping-waterschappen-bbv-variant slice 08 — invalidate the
		// BBV-compliance cache when a GL transaction header or line is
		// created or updated. The slice-02 x-openregister-aggregations
		// block on BBVProgramme materialises totalBudget / ytdSpend /
		// utilization / complianceStatus over those very lines, and
		// ComplianceService caches the per-programme envelope for 1h
		// (REQ-BBVW-006). The listener drops the cache namespace so the
		// next dashboard render repopulates from the engine. Fail-soft:
		// a cache hiccup never blocks a GL write (giant D3).
		//
		// Interest declared up front: the declared slugs are the listener's own
		// WATCHED_SCHEMAS constant verbatim. `GLTransactionLine` resolves to
		// nothing on the reference instance but is declared anyway because the
		// guard names it — over-inclusion is fail-safe.
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectCreatedEvent::class,
			listener: GLTransactionComplianceCacheListener::class,
			schemas: ['GLTransaction', 'GLLine', 'GLTransactionLine']
		);
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectUpdatedEvent::class,
			listener: GLTransactionComplianceCacheListener::class,
			schemas: ['GLTransaction', 'GLLine', 'GLTransactionLine']
		);

		// Bookkeeping-innovatiebox-administratie — record a row on the
		// subject's OpenRegister audit trail per relevant transition on the
		// three innovatiebox subject schemas (NexusCalculation,
		// IBProfitAttribution, CarryForwardLoss). Captures *.created,
		// IBProfitAttribution.finalized (vso_locked: false -> true) and
		// IBProfitAttribution.amendment_attempt_blocked when an update
		// arrives on a VSO-locked year (REQ-IBA-008). Tasks 5.1-5.3.
		// Fail-soft: the listener never bubbles an error into OR's write
		// path; a logging failure becomes a Psr warning.
		//
		// Interest declared up front: the three slugs are the listener's own
		// SCHEMA_NEXUS / SCHEMA_PROFIT / SCHEMA_LOSS constants.
		$this->registerFilteredObjectWriteListener(
			dispatcher: $dispatcher,
			listener: InnovatieboxAuditTrailListener::class,
			schemas: ['NexusCalculation', 'IBProfitAttribution', 'CarryForwardLoss']
		);

		// Bookkeeping-reconciliation-reports (T4) — REQ-REC-010. Interest
		// declared on the create path only: the handler requires
		// `strcasecmp($schema, 'ReconciliationMatch') === 0`. The
		// ObjectTransitionedEvent registration in register() stays global —
		// this change deliberately narrows only the create/update firehose.
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectCreatedEvent::class,
			listener: ReconciliationMatchToReportListener::class,
			schemas: ['ReconciliationMatch']
		);

		// Bookkeeping-tenderned-integratie Task 5.1 — interest declared on the
		// create path: `isAanbestedingSchema()` resolves to the
		// `TenderNedProcurement` schema, the same slug the handler writes back
		// with `->setSchema('TenderNedProcurement')`. The
		// ObjectTransitionedEvent registration in register() stays global.
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectCreatedEvent::class,
			listener: TenderNedAwardDetectedListener::class,
			schemas: ['TenderNedProcurement']
		);

		// Bookkeeping-tenderned-integratie Task 5.2 — interest declared on the
		// create path: `isCommitmentSchema()` resolves to the `Commitment`
		// schema, the same slug TenderNedAwardDetectedListener writes with
		// `->setSchema(…)`. The ObjectTransitionedEvent registration in
		// register() stays global.
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectCreatedEvent::class,
			listener: CommitmentTransitionListener::class,
			schemas: ['Commitment']
		);

	}//end boot()
}//end class
