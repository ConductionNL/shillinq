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
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Guard\BcfSubmissionGuard;
use OCA\Shillinq\Guard\IntercompanyEliminationGuard;
use OCA\Shillinq\Guard\Iv3SubmissionGuard;
use OCA\Shillinq\Guard\Iv3XmlValidationGuard;
use OCA\Shillinq\Guard\KorLockoutGuard;
use OCA\Shillinq\Guard\OssPaymentGuard;
use OCA\Shillinq\Guard\ProjectActivationGuard;
use OCA\Shillinq\Guard\ProjectCloseGuard;
use OCA\Shillinq\Guard\ProjectTransitionGuard;
use OCA\Shillinq\Guard\RateScheduleOverlapGuard;
use OCA\Shillinq\Guard\SubsidieRepaymentGuard;
use OCA\Shillinq\Guard\VatSubmissionGuard;
use OCA\Shillinq\Lifecycle\APGuard;
use OCA\Shillinq\Lifecycle\AnnualBudgetDefaultGuard;
use OCA\Shillinq\Lifecycle\FiscalYearGuard;
use OCA\Shillinq\Lifecycle\FourEyesPaymentRunGuard;
use OCA\Shillinq\Lifecycle\GLReversalGuard;
use OCA\Shillinq\Lifecycle\PaymentRunDuplicateGuard;
use OCA\Shillinq\Lifecycle\PeriodCloseGuard;
use OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter;
use OCA\Shillinq\Lifecycle\WBSOExportValidationGuard;
use OCA\Shillinq\Lifecycle\WriteOffReasonGuard;
use OCA\Shillinq\Listener\AppointmentCreatedListener;
use OCA\Shillinq\Listener\BookingCreatedTimelinePublishListener;
use OCA\Shillinq\Listener\BookingLifecycleTransitionListener;
use OCA\Shillinq\Listener\CommitmentMaterialisationListener;
use OCA\Shillinq\Listener\ContractObligationTaskListener;
use OCA\Shillinq\Listener\DbaInvoiceMonitorListener;
use OCA\Shillinq\Listener\DeepLinkRegistrationListener;
use OCA\Shillinq\Listener\DeliveryDispatchListener;
use OCA\Shillinq\Listener\ExtractionCompletedListener;
use OCA\Shillinq\Listener\FixedAssetDisposalListener;
use OCA\Shillinq\Listener\GLTransactionComplianceCacheListener;
use OCA\Shillinq\Listener\GRIRClearingListener;
use OCA\Shillinq\Listener\InnovatieboxAuditTrailListener;
use OCA\Shillinq\Listener\IntercompanyLinkListener;
use OCA\Shillinq\Listener\LeaseActivationListener;
use OCA\Shillinq\Listener\OrderFulfilmentTransitionListener;
use OCA\Shillinq\Listener\OssPaymentReconciliationListener;
use OCA\Shillinq\Listener\PeppolDeliveryStatusListener;
use OCA\Shillinq\Listener\PeppolInboundUblInvoiceListener;
use OCA\Shillinq\Listener\PosStockDecrementListener;
use OCA\Shillinq\Listener\ReconciliationMatchToReportListener;
use OCA\Shillinq\Listener\StockMoveTransitionedListener;
use OCA\Shillinq\Listener\TenderNedAwardDetectedListener;
use OCA\Shillinq\Listener\CommitmentTransitionListener;
use OCA\Shillinq\Notification\DeadlineReminderNotifier;
use OCA\Shillinq\Notification\PosStockUnmatchedLineNotifier;
use OCA\Shillinq\Notification\RoleFallbackResolver;
use OCA\Shillinq\Repair\DbValueMigrationPort;
use OCA\Shillinq\Repair\ValueMigrationPort;
use OCA\Shillinq\Service\DoorsnijdingsVerbodValidator;
use OCA\Shillinq\Service\Dunning\CreditScoreFetchAdapterInterface;
use OCA\Shillinq\Service\Dunning\DunningChannelAdapterInterface;
use OCA\Shillinq\Service\Dunning\IncassoBureauAdapterInterface;
use OCA\Shillinq\Service\Dunning\LogCreditScoreFetchAdapter;
use OCA\Shillinq\Service\Dunning\LogDunningChannelAdapter;
use OCA\Shillinq\Service\Dunning\LogIncassoBureauAdapter;
use OCA\Shillinq\Service\Dunning\LogPostNLAdapter;
use OCA\Shillinq\Service\Dunning\PostNLAdapterInterface;
use OCA\Shillinq\Service\External\Bunq\BunqBankConnectorAdapterInterface;
use OCA\Shillinq\Service\External\Bunq\LogBunqBankConnectorAdapter;
use OCA\Shillinq\Service\External\Cbs\CbsBestandenAdapterInterface;
use OCA\Shillinq\Service\External\Cbs\CbsIv3AdapterInterface;
use OCA\Shillinq\Service\External\Cbs\LogCbsBestandenAdapter;
use OCA\Shillinq\Service\External\Cbs\LogCbsIv3Adapter;
use OCA\Shillinq\Service\External\CcmRuleEngine\CcmRuleEngineAdapterInterface;
use OCA\Shillinq\Service\External\CcmRuleEngine\LogCcmRuleEngineAdapter;
use OCA\Shillinq\Service\External\CsrdEsrsXbrl\CsrdEsrsXbrlAdapterInterface;
use OCA\Shillinq\Service\External\CsrdEsrsXbrl\LogCsrdEsrsXbrlAdapter;
use OCA\Shillinq\Service\External\DepositPayment\DepositPaymentAdapterInterface;
use OCA\Shillinq\Service\External\DepositPayment\LogDepositPaymentAdapter;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSbrAdapterInterface;
use OCA\Shillinq\Service\External\Digipoort\LogDigipoortSbrAdapter;
use OCA\Shillinq\Service\External\Ib47\Ib47AdapterInterface;
use OCA\Shillinq\Service\External\Ib47\LogIb47Adapter;
use OCA\Shillinq\Service\External\Kvk\KvkHandelsregisterAdapterInterface;
use OCA\Shillinq\Service\External\Kvk\LogKvkHandelsregisterAdapter;
use OCA\Shillinq\Service\External\Mollie\LogMolliePaymentAdapter;
use OCA\Shillinq\Service\External\Mollie\MolliePaymentAdapterInterface;
use OCA\Shillinq\Service\External\RvO\LogRvOAanvraagAdapter;
use OCA\Shillinq\Service\External\RvO\RvOAanvraagAdapterInterface;
use OCA\Shillinq\Service\External\Salarisbureau\LogSalarisbureauAdapter;
use OCA\Shillinq\Service\External\Salarisbureau\SalarisbureauAdapterInterface;
use OCA\Shillinq\Service\External\Sisa\BzkSisaUploadAdapterInterface;
use OCA\Shillinq\Service\External\Sisa\LogBzkSisaUploadAdapter;
use OCA\Shillinq\Service\External\TreasuryRate\LogTreasuryRateAdapter;
use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateAdapterInterface;
use OCA\Shillinq\Service\External\Uwv\LogUwvLoonaangifteAdapter;
use OCA\Shillinq\Service\External\Uwv\UwvLoonaangifteAdapterInterface;
use OCA\Shillinq\Service\InnovatieboxAuditEventLogger;
use OCA\Shillinq\Service\Payment\MolliePaymentProvider;
use OCA\Shillinq\Service\Payment\PaymentProviderInterface;
use OCA\Shillinq\Service\Peppol\LogPeppolTransmissionAdapter;
use OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface;
use OCA\Shillinq\Service\Pipelinq\CustomerBridgeMetricsService;
use OCA\Shillinq\Service\Pipelinq\LoggingPipelinqAdminNotifier;
use OCA\Shillinq\Service\Pipelinq\PersistentTimelineRetryQueue;
use OCA\Shillinq\Service\Pipelinq\PipelinqAdminNotifier;
use OCA\Shillinq\Service\Pipelinq\TimelineRetryQueue;
use OCA\Shillinq\Service\Sms\LogSmsProviderAdapter;
use OCA\Shillinq\Service\Sms\SmsProviderAdapterInterface;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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

	public const APP_ID = 'shillinq';

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
		$this->registerAppHostGenerics(context: $context);

		// Register deep link patterns with OpenRegister's unified search provider.
		// Only fires when OpenRegister is installed and dispatches the event.
		// KEPT bespoke: resolves the configured register slug dynamically from
		// app config, which the manifest-driven GenericDeepLinkRegistrationListener
		// does not do (adopt-apphost).
		$context->registerEventListener(
			event: DeepLinkRegistrationEvent::class,
			listener: DeepLinkRegistrationListener::class
		);

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
		// the cross-app `nl.conduction.peppol.delivery.status` cloud event
		// openconnector's Peppol access point emits and advance
		// ARInvoice.deliveryStatus (REQ-AR-011). Registered against the literal
		// event-name STRING (IRegistrationContext::registerEventListener()
		// accepts string|class-string<T> — mirrors the emit side in
		// BudgetImpactEmitter, which dispatches plain string event names via
		// IEventDispatcher::dispatch()).
		$context->registerEventListener(
			event: PeppolDeliveryStatusListener::EVENT_NAME,
			listener: PeppolDeliveryStatusListener::class
		);

		// Bookings-pipelinq-customer-bridge slice 07 — when a new
		// Appointment carries a non-empty `pipelinqContactId`, publish a
		// `booking.created` event to pipelinq's klantbeeld-360 timeline.
		// The synchronous publish uses the shared retry + circuit
		// breaker; a failure hands the event to TimelineRetryQueue so the
		// booking commit is never blocked. ADR-032 chain member 7 of 11.
		//
		// Slice 09 swaps the slice-07 LoggingTimelineRetryQueue stub for
		// the PersistentTimelineRetryQueue: failed publishes now write a
		// TimelinePublishRetryEntry OR record + add a
		// PipelinqTimelineRetryJob tick to the IJobList. The job retries
		// with exponential backoff (1m/5m/30m) and dead-letters into
		// TimelineDeadLetter on exhaustion (D3 in the giant).
		//
		// Only the queue binding lives here: the listener itself
		// (BookingCreatedTimelinePublishListener) declares a schema interest and
		// is therefore subscribed from boot().
		$context->registerService(
			TimelineRetryQueue::class,
			static function ($c): TimelineRetryQueue {
				return $c->get(PersistentTimelineRetryQueue::class);
			}
		);

		// Storage seam for the Dutch-to-English value migration. The repair step
		// depends on the interface so its own logic can be exercised against a
		// fake; only this binding knows the database.
		$context->registerService(
			ValueMigrationPort::class,
			static function ($c): ValueMigrationPort {
				return $c->get(DbValueMigrationPort::class);
			}
		);

		// Bookings-pipelinq-customer-bridge slice 08 — extend the timeline
		// publish pattern to every booking lifecycle transition
		// (confirmed / cancelled / completed). The `cancellationReason`
		// is forwarded into the timeline metadata when present. A 401
		// from pipelinq is treated as permanent: the admin notifier port
		// surfaces an alert ("Invalid pipelinq API token") and the event
		// is NOT requeued — retrying with the same invalid token would
		// only repeat the failure. Until slice 09 lands the persistent
		// notification surface, the default binding is the logging-only
		// {@see LoggingPipelinqAdminNotifier}. ADR-032 chain member 8 of
		// 11.
		$context->registerService(
			PipelinqAdminNotifier::class,
			static function ($c): PipelinqAdminNotifier {
				return $c->get(LoggingPipelinqAdminNotifier::class);
			}
		);
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

		// Wire the DoorsnijdingsVerbodValidator with the optional audit
		// logger so every validateNoDuplication run emits a
		// DoorsnijdingsVerbod.check_run event (task 5.4, REQ-IBA-008).
		// The constructor's third argument is nullable so the existing
		// unit tests can build the validator without the OR event chain.
		$context->registerService(
			DoorsnijdingsVerbodValidator::class,
			static function (ContainerInterface $c): DoorsnijdingsVerbodValidator {
				return new DoorsnijdingsVerbodValidator(
					appConfig: $c->get(IAppConfig::class),
					// ADR-084 replaced the `$container` parameter with the
					// published contract. This factory bypasses autowiring, so
					// it does not follow a constructor automatically: passing
					// `container:` here was `Error: Unknown named parameter
					// $container` on every request that resolved the service —
					// i.e. every InnovatieboxController route. See
					// tests/Unit/AppInfo/CompositionRootArgumentsTest.php.
					objectService: $c->get(ObjectServiceInterface::class),
					auditLogger: $c->get(InnovatieboxAuditEventLogger::class),
				);
			}
		);

		// Bookkeeping-credit-control-dunning tasks 19/20/21 — wire the
		// narrow ports used by CreditScoreService + DunningRunService to the
		// log-backed default bindings. The openconnector-backed bindings
		// (Graydon/Creditsafe/Atradius, Bos/Atradius Collections/Intrum,
		// PostNL Track & Trace) swap these in production via the same
		// registerService call.
		$context->registerService(
			CreditScoreFetchAdapterInterface::class,
			static function ($c): CreditScoreFetchAdapterInterface {
				return $c->get(LogCreditScoreFetchAdapter::class);
			}
		);
		$context->registerService(
			DunningChannelAdapterInterface::class,
			static function ($c): DunningChannelAdapterInterface {
				return $c->get(LogDunningChannelAdapter::class);
			}
		);
		$context->registerService(
			IncassoBureauAdapterInterface::class,
			static function ($c): IncassoBureauAdapterInterface {
				return $c->get(LogIncassoBureauAdapter::class);
			}
		);
		$context->registerService(
			PostNLAdapterInterface::class,
			static function ($c): PostNLAdapterInterface {
				return $c->get(LogPostNLAdapter::class);
			}
		);

		// REQ-EINV-003/005 + bookings-sms-reminder-channel. Both ports below
		// were UNBOUND, and both are type-hinted non-nullably with no default
		// (EInvoiceValidationService, SmsReminderDispatcher), so NC's
		// SimpleContainer could not build them or anything downstream —
		// POST /api/ar-invoices/{invoiceNumber}/send-einvoice answered 500
		// before any controller code ran. See
		// tests/Unit/AppInfo/ContainerResolvableConstructorsTest.php, which
		// fails when a required in-app interface dependency has no binding.
		$context->registerService(
			PeppolTransmissionPortInterface::class,
			static function ($c): PeppolTransmissionPortInterface {
				return $c->get(LogPeppolTransmissionAdapter::class);
			}
		);
		$context->registerService(
			SmsProviderAdapterInterface::class,
			static function ($c): SmsProviderAdapterInterface {
				return $c->get(LogSmsProviderAdapter::class);
			}
		);

		// External-API adapter ports — every binding below is dormant by
		// default (log-only), so the regulatory-filing lifecycles can
		// advance into `submitted` without contacting an external party.
		// Override each binding in a downstream Application::register()
		// (or via a runtime configuration hook) once the matching
		// openconnector source slug + credential is provisioned.
		//
		// - CBS Bestanden (bookkeeping-cbs-bestanden-extended)
		// - CBS Iv3 (bookkeeping-cbs-iv3 / provincies + gemeenten Iv3)
		// - BZK SiSa (bookkeeping-sisa-reporting)
		// - Digipoort/SBR (bookkeeping-vat-btw-filing,
		// bookkeeping-financial-statements,
		// bookkeeping-sbr-xbrl-reporting, bookkeeping-csrd-esrs)
		// - Salarisbureau (bookkeeping-detachering-payroll-administratie,
		// bookkeeping-payroll-engine-nl)
		// - RvO (bookkeeping-investeringsaftrek,
		// bookkeeping-wbso-sno-administratie)
		// - Belastingdienst IB47
		// (bookkeeping-detachering-payroll-administratie,
		// bookkeeping-btw-oss-eu).
		$context->registerService(
			CbsBestandenAdapterInterface::class,
			static function ($c): CbsBestandenAdapterInterface {
				return $c->get(LogCbsBestandenAdapter::class);
			}
		);
		$context->registerService(
			CbsIv3AdapterInterface::class,
			static function ($c): CbsIv3AdapterInterface {
				return $c->get(LogCbsIv3Adapter::class);
			}
		);
		$context->registerService(
			BzkSisaUploadAdapterInterface::class,
			static function ($c): BzkSisaUploadAdapterInterface {
				return $c->get(LogBzkSisaUploadAdapter::class);
			}
		);
		$context->registerService(
			DigipoortSbrAdapterInterface::class,
			static function ($c): DigipoortSbrAdapterInterface {
				return $c->get(LogDigipoortSbrAdapter::class);
			}
		);
		$context->registerService(
			SalarisbureauAdapterInterface::class,
			static function ($c): SalarisbureauAdapterInterface {
				return $c->get(LogSalarisbureauAdapter::class);
			}
		);
		$context->registerService(
			RvOAanvraagAdapterInterface::class,
			static function ($c): RvOAanvraagAdapterInterface {
				return $c->get(LogRvOAanvraagAdapter::class);
			}
		);
		$context->registerService(
			Ib47AdapterInterface::class,
			static function ($c): Ib47AdapterInterface {
				return $c->get(LogIb47Adapter::class);
			}
		);

		// Wave-4 external-API ports (low-volume families):
		//
		// - KvK Handelsregister (bookkeeping-multi-administratie onboarding,
		// AR/AP debtor/creditor enrichment,
		// bookkeeping-consolidation-commercial deelnemingen-graaf walk).
		// - Mollie Payments (bookings-deposits DepositPayment intent,
		// bookkeeping-accounts-receivable-core invoice payment links +
		// webhook verification).
		// - Bunq Bank Connector (bookkeeping-bank-connectors per-Source
		// pull + consent-renewal action; ADR-031 ScheduledWorkflow
		// delegates transport to this port; no aggregator JSON path —
		// Bunq exposes CAMT.053 natively).
		// - UWV Loonaangifte + Werkhervattingskas (LHAfdracht acceptance
		// pull + werkgever-setup sectorindeling validation).
		$context->registerService(
			KvkHandelsregisterAdapterInterface::class,
			static function ($c): KvkHandelsregisterAdapterInterface {
				return $c->get(LogKvkHandelsregisterAdapter::class);
			}
		);
		$context->registerService(
			MolliePaymentAdapterInterface::class,
			static function ($c): MolliePaymentAdapterInterface {
				return $c->get(LogMolliePaymentAdapter::class);
			}
		);

		// Portal-payment-initiation REQ-SPPI-001 — the payment-provider port
		// the subject-initiated pay-now flow drives. Sits one layer ABOVE
		// MolliePaymentAdapterInterface (already wired immediately above):
		// the shipped MolliePaymentProvider binding delegates EVERY call to
		// whichever MolliePaymentAdapterInterface is currently bound, so this
		// port is dormant-by-default (LogMolliePaymentAdapter) and turns live
		// automatically the moment the openconnector `mollie-payments` source
		// is bound — no further change needed here.
		$context->registerService(
			PaymentProviderInterface::class,
			static function ($c): PaymentProviderInterface {
				return new MolliePaymentProvider(mollie: $c->get(MolliePaymentAdapterInterface::class));
			}
		);
		$context->registerService(
			BunqBankConnectorAdapterInterface::class,
			static function ($c): BunqBankConnectorAdapterInterface {
				return $c->get(LogBunqBankConnectorAdapter::class);
			}
		);
		$context->registerService(
			UwvLoonaangifteAdapterInterface::class,
			static function ($c): UwvLoonaangifteAdapterInterface {
				return $c->get(LogUwvLoonaangifteAdapter::class);
			}
		);

		// Bookings-deposits REQ-DP-001/005/007/008 — DepositPayment
		// lifecycle adapter port (request / status / refund). Sits one
		// layer ABOVE MolliePaymentAdapterInterface (which is already
		// wired): the lifecycle code never sees a Mollie vs. Stripe
		// branch, only the projected DepositPayment lifecycle state.
		// Dormant LogDepositPaymentAdapter returns `pending` /
		// PAYMENT_DEFERRED with dormant=true so
		// DepositReconciliationService::pollPending() MUST inspect the
		// dormant flag before advancing the lifecycle. The production
		// binding delegates to MolliePaymentAdapterInterface and
		// projects the Mollie state onto the DepositPayment lifecycle.
		$context->registerService(
			DepositPaymentAdapterInterface::class,
			static function ($c): DepositPaymentAdapterInterface {
				return $c->get(LogDepositPaymentAdapter::class);
			}
		);

		// Bookkeeping-csrd-esrs Tasks 30/31/32 — EFRAG ESRS XBRL taxonomy
		// mapping + mandatory-data-point validation + iXBRL instance build.
		// Per ADR-022 the XBRL pipeline itself lives in
		// bookkeeping-sbr-xbrl-reporting (cross-app dependency); this port
		// is the seam. Dormant LogCsrdEsrsXbrlAdapter
		// SAFETY-CRITICAL: validateMandatoryDataPoints() always returns
		// VALIDATION_BLOCKED with a LOG_DEFERRED sentinel so a deferred
		// binding cannot let an unvalidated CSRD report slip past EFRAG
		// IG-3. The produced iXBRL instance is handed to the existing
		// DigipoortSbrAdapterInterface (filingType: csrd-xbrl-pack) for
		// KvK / AFM transport.
		$context->registerService(
			CsrdEsrsXbrlAdapterInterface::class,
			static function ($c): CsrdEsrsXbrlAdapterInterface {
				return $c->get(LogCsrdEsrsXbrlAdapter::class);
			}
		);

		// Bookkeeping-ccm-rule-engine REQ-CCM-002 — cross-app rule-engine
		// delegation port. The local CcmRuleEngine (ADR-031 exception) runs
		// v1 sync/async DSL evaluation in-process; this port is the swap-out
		// seam for the future OpenRegister native rule engine (or a
		// third-party evaluator). Dormant LogCcmRuleEngineAdapter returns
		// DEFERRED + fired=false (fail-soft) so binding the openconnector
		// source slug `ccm-rule-engine` never raises a false finding.
		$context->registerService(
			CcmRuleEngineAdapterInterface::class,
			static function ($c): CcmRuleEngineAdapterInterface {
				return $c->get(LogCcmRuleEngineAdapter::class);
			}
		);

		// Bookkeeping-treasury-ihb Tasks 14/15/17/22 — reference-rate
		// (EURIBOR-3M / SOFR / SARON / ESTR) + FX-spot snapshots for the
		// declarative interest-accrual, FX-revaluation, and liquidity-KPI
		// aggregations. The dormant LogTreasuryRateAdapter returns
		// SNAPSHOT_DEFERRED so the aggregation host stays observable until
		// openconnector source slug `treasury-rates` (ECB SDMX / Bloomberg /
		// Refinitiv) is bound; the IntercompanyLoan + FXPosition manual-entry
		// path remains the v1 fallback per REQ-IHB-004.
		$context->registerService(
			TreasuryRateAdapterInterface::class,
			static function ($c): TreasuryRateAdapterInterface {
				return $c->get(LogTreasuryRateAdapter::class);
			}
		);

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

		// REQ-SIGN-001/005/006 — the decidesk DECISION and docudesk DOCUMENT
		// signing request+outcome listeners, registered as one unit.
		(new SigningDelegationRegistration())->register(context: $context);

		// Change receipt-extraction-consume (REQ-RXC-001) — consume docudesk's
		// cross-app OCA\DocuDesk\Event\FinancialExtractionCompletedEvent (the
		// canonical nl.conduction.docudesk.extraction.completed wire contract,
		// owned by docudesk's financial-document-field-extraction spec) into an
		// uncommitted, confidence-scored SupplierInvoice/Receipt draft
		// (ExtractionCompletedListener + ExtractionPrefillService). Registering
		// by the docudesk event FQCN is safe even when the class is not
		// autoloadable — NC only needs the string key; handle() itself is
		// class_exists-guarded so the listener is inert when docudesk is not
		// installed. Fail-soft: never blocks docudesk's synchronous dispatch.
		$context->registerEventListener(
			event: \OCA\DocuDesk\Event\FinancialExtractionCompletedEvent::class,
			listener: ExtractionCompletedListener::class
		);

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
		// (MandateEnforcer, BudgetBlocker, PeriodCloseGuard's other three
		// methods, InventoryPostingGuard, KorThresholdGuard, ...) reference
		// tags shaped the same way and are NOT registered — every one of
		// those transitions also hard-fails today. That fleet-wide gap is
		// filed separately as shillinq#433 and intentionally not fixed here.
		$context->registerService(
			'OCA\Shillinq\Guard\Iv3XmlValidationGuard::requireValidXml',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(Iv3XmlValidationGuard::class),
					method: 'requireValidXml',
					denyMessage: 'The export must have a generated XML attachment and at least one aggregated bucket before it can be validated.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
		$context->registerService(
			'OCA\Shillinq\Guard\Iv3SubmissionGuard::requireApproval',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(Iv3SubmissionGuard::class),
					method: 'requireApproval',
					denyMessage: 'This export has already been submitted, or is missing its generated XML/buckets.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
		$context->registerService(
			'OCA\Shillinq\Guard\KorLockoutGuard::requireLockoutExpired',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(KorLockoutGuard::class),
					method: 'requireLockoutExpired',
					denyMessage: 'The 3-year KOR re-entry lock-out (Wet OB 1968 art. 25 lid 3) has not yet elapsed.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
		$context->registerService(
			'OCA\Shillinq\Guard\ProjectActivationGuard::requireStartDate',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(ProjectActivationGuard::class),
					method: 'requireStartDate',
					denyMessage: 'startDate must be set before the project can be activated.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
		$context->registerService(
			'OCA\Shillinq\Guard\ProjectTransitionGuard::requireReason',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(ProjectTransitionGuard::class),
					method: 'requireReason',
					denyMessage: 'closureJustification must be set before the project can be put on hold.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
		$context->registerService(
			'OCA\Shillinq\Guard\ProjectCloseGuard::requireWipJustificationOrZero',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(ProjectCloseGuard::class),
					method: 'requireWipJustificationOrZero',
					denyMessage: 'This project has an open WIP balance; record closureJustification before closing.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
		$context->registerService(
			'OCA\Shillinq\Lifecycle\FiscalYearGuard::requireAllPeriodsClosedForYear',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(FiscalYearGuard::class),
					method: 'requireAllPeriodsClosedForYear',
					denyMessage: 'Every FiscalPeriod within this fiscal year must be closed before the year-end close can begin.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
		$context->registerService(
			'OCA\Shillinq\Lifecycle\GLReversalGuard::isReversed',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(GLReversalGuard::class),
					method: 'isReversed',
					denyMessage: 'The linked GLTransaction must already be reversed before this record can be voided.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
		$context->registerService(
			'OCA\Shillinq\Lifecycle\WriteOffReasonGuard::requireReason',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(WriteOffReasonGuard::class),
					method: 'requireReason',
					denyMessage: 'writeOffReason must be set before the invoice can be written off.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
		$context->registerService(
			'OCA\Shillinq\Guard\VatSubmissionGuard::requireApproval',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(VatSubmissionGuard::class),
					method: 'requireApproval',
					denyMessage: 'This return exceeds the configured approval threshold and cannot be submitted yet.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
		$context->registerService(
			'OCA\Shillinq\Guard\BcfSubmissionGuard::requireApproval',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(BcfSubmissionGuard::class),
					method: 'requireApproval',
					denyMessage: 'This claim exceeds the configured approval threshold and cannot be submitted yet.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
		$context->registerService(
			'OCA\Shillinq\Lifecycle\APGuard::isInvoiceNumberUnique',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(APGuard::class),
					method: 'isInvoiceNumberUnique',
					denyMessage: 'A vendor invoice with this invoice number already exists for this administration.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
		$context->registerService(
			'OCA\Shillinq\Lifecycle\APGuard::requireWriteOffReason',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(APGuard::class),
					method: 'requireWriteOffReason',
					denyMessage: 'writeOffReason must be set before the AP transaction can be written off.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
		$context->registerService(
			'OCA\Shillinq\Lifecycle\WBSOExportValidationGuard',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(WBSOExportValidationGuard::class),
					method: 'requireEligibleEntries',
					denyMessage: 'Every included Urenregistratie entry must carry wbsoTagId and activityCodeId before this export can be validated.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
		$context->registerService(
			'OCA\Shillinq\Guard\SubsidieRepaymentGuard::requireZeroRepaymentBalance',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(SubsidieRepaymentGuard::class),
					method: 'requireZeroRepaymentBalance',
					denyMessage: 'The outstanding repayment balance must be zero before this dossier can be closed.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
		$context->registerService(
			'OCA\Shillinq\Guard\RateScheduleOverlapGuard::requireNonOverlappingWindow',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(RateScheduleOverlapGuard::class),
					method: 'requireNonOverlappingWindow',
					denyMessage: 'Another active rate schedule for this tier/entity already covers an overlapping effective-date window.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
		$context->registerService(
			'OCA\Shillinq\Lifecycle\PeriodCloseGuard::trialBalanceVerifies',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(PeriodCloseGuard::class),
					method: 'trialBalanceVerifies',
					denyMessage: 'Total posted debits must equal total posted credits for this period before the close can begin.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		// Revive-gl-tax-capabilities design D2 — the OSS money transitions
		// (`OssReturn.pay`, `OssPayment.reconcile`) have named
		// `OCA\Shillinq\Service\OssPaymentReconciliation::canMarkPaid` as
		// their `requires` guard since the bookkeeping-btw-oss-eu register
		// shipped, but the tag was never registered: OssPaymentReconciliation
		// is a plain pure-logic class and its canMarkPaid() takes TWO arrays,
		// so LifecycleGuardRegistry::resolve() threw and BOTH transitions
		// hard-failed with HTTP 500 (the shillinq#425/#433 defect class).
		// OssPaymentGuard is the single-array adapter that string always
		// implied — it resolves the counterpart record (payment -> its
		// OssReturn, return -> its OssPayment) and delegates to the
		// unmodified kernel. Registered under the EXACT literal tag the
		// register.d names. Fail-closed via RegisterRequiresGuardAdapter.
		$context->registerService(
			'OCA\Shillinq\Service\OssPaymentReconciliation::canMarkPaid',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(OssPaymentGuard::class),
					method: 'canMarkPaid',
					denyMessage: 'A matching bank transaction settling this OSS return in full must be linked before it can be marked paid.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		// Revive-gl-tax-capabilities REQ-GLTAX-002 — the `eliminate`
		// transition on IntercompanyJournalEntry may only fire once the pair
		// reconciles (zero variance between the two booked sides). Fail-closed
		// when the counter-side entry cannot be resolved.
		$context->registerService(
			'OCA\Shillinq\Guard\IntercompanyEliminationGuard::requireReconciledPair',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(IntercompanyEliminationGuard::class),
					method: 'requireReconciledPair',
					denyMessage: 'The two sides of this intercompany pair do not reconcile; resolve the variance before booking the elimination.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		// Payment-run-four-eyes REQ-PR4E-001 — segregation of duties on the
		// outgoing SEPA payment-run `approve` transition. This guard
		// implements LifecycleGuardInterface directly (it needs the caller
		// uid, which the shared RegisterRequiresGuardAdapter does not forward),
		// so it is registered under its own FQCN tag — the exact string the
		// PaymentRun schema's `transitions.approve.requires` names. Registering
		// explicitly (rather than relying on container autowiring) matches the
		// shillinq#425 convention and guarantees LifecycleGuardRegistry can
		// resolve it. Fail-closed: an indeterminate preparer blocks the
		// release (ADR-022 audit trail is the sole preparer-of-record).
		$context->registerService(
			FourEyesPaymentRunGuard::class,
			static function ($c): FourEyesPaymentRunGuard {
				return new FourEyesPaymentRunGuard(
					container: $c->get(ContainerInterface::class),
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		// Payment-control-guards REQ-PCG-001 — duplicate-payment control on the
		// outgoing SEPA payment-run `export` transition. Rejects exporting a
		// batch whose line settles an AP invoice that is already paid or already
		// queued in another open/executed batch. Implements
		// LifecycleGuardInterface directly and is registered under its own FQCN
		// tag — the exact string the PaymentRun schema's
		// `transitions.export.requires` names — so LifecycleGuardRegistry can
		// resolve it (the shillinq#425 convention). Fail-closed.
		$context->registerService(
			PaymentRunDuplicateGuard::class,
			static function ($c): PaymentRunDuplicateGuard {
				return new PaymentRunDuplicateGuard(
					container: $c->get(ContainerInterface::class),
					appConfig: $c->get(IAppConfig::class),
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		// Budget-core-schema REQ-BCS-006 — the AnnualBudget.activate
		// transition's `requires` tag
		// (OCA\Shillinq\Lifecycle\AnnualBudgetDefaultGuard::isUniqueDefault)
		// is a `Class::method`-shaped string, which per shillinq#425/#433
		// never autowires through Nextcloud's container without an explicit
		// alias. Registered under the exact literal tag the register.d
		// fragment names, mapped through the shared
		// RegisterRequiresGuardAdapter, matching the convention every guard
		// registered since #425 follows.
		$context->registerService(
			'OCA\Shillinq\Lifecycle\AnnualBudgetDefaultGuard::isUniqueDefault',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(AnnualBudgetDefaultGuard::class),
					method: 'isUniqueDefault',
					denyMessage: 'Another AnnualBudget already claims isDefault for this administration and fiscal year.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		// Budget-scenarios REQ-BSC-004/REQ-BSC-006 — the BudgetScenarioModifier
		// guard alias and the KnownCostScheduleExpanderInterface binding,
		// registered as one unit. See BudgetScenarioRegistration's own
		// docblock for why this lives in its own class.
		(new BudgetScenarioRegistration())->register(context: $context);

	}//end register()

	/**
	 * Wire the OpenRegister AppHost generic controllers + the metrics provider.
	 *
	 * Re-points shillinq's mechanical skeleton classes at the engine generics:
	 *
	 *   - `Controller\DashboardController` → GenericDashboardController (the SPA
	 *     shell + history-mode catch-all — identical behaviour).
	 *   - `Controller\HealthController` → GenericHealthController (now a real
	 *     `database` + `orAvailable` check driven by the manifest, replacing the
	 *     hardcoded `{status:ok}` literal).
	 *   - `Controller\MetricsController` → GenericMetricsController (Prometheus
	 *     0.0.4, replacing the ADR-006-violating JSON snapshot — the documented
	 *     intentional contract change).
	 *
	 * The customer-bridge metrics service is registered as the app's
	 * {@see \OCA\OpenRegister\AppHost\IMetricsProvider} under the ADR-035 alias
	 * `…IMetricsProvider::shillinq`, so the manifest `{"kind":"provider"}`
	 * descriptor merges its series into the generic Prometheus exposition.
	 *
	 * All references to OCA\OpenRegister\AppHost\… are STRINGS resolved inside
	 * closures, so a disabled OpenRegister never fatals bootstrap.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	private function registerAppHostGenerics(IRegistrationContext $context): void {
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

		// Mechanical admin-settings + section → engine generics (the generic
		// AdminSettings also upgrades the plain ISettings to the
		// IDelegatedSettings #299 pattern). info.xml references the LEAF class
		// names (OCA\Shillinq\Settings\AdminSettings / Sections\SettingsSection)
		// so Nextcloud's settings Manager resolves them through Shillinq's own
		// DI container — where these factories inject the leaf metadata (appId,
		// section id "shillinq", priority, icon). Registering under the generic
		// OCA\OpenRegister\... names instead would route resolution to
		// OpenRegister's container, which has no appId bound, fatally blanking
		// every settings page in the instance.
		$context->registerService(
			'OCA\\Shillinq\\Settings\\AdminSettings',
			static function (ContainerInterface $c) use ($appId) {
				$class = 'OCA\\OpenRegister\\AppHost\\Settings\\GenericAdminSettings';
				return new $class(
					appId: $appId,
					sectionId: $appId,
					priority: 10,
					appManager: $c->get('OCP\\App\\IAppManager'),
					initialState: $c->get('OCP\\AppFramework\\Services\\IInitialState'),
					appConfig: $c->get('OCP\\IAppConfig')
				);
			}
		);

		$context->registerService(
			'OCA\\Shillinq\\Sections\\SettingsSection',
			static function (ContainerInterface $c) use ($appId) {
				$class = 'OCA\\OpenRegister\\AppHost\\Settings\\GenericSettingsSection';
				return new $class(
					sectionId: $appId,
					name: 'Shillinq',
					appId: $appId,
					iconFile: 'app-dark.svg',
					priority: 75,
					urlGenerator: $c->get('OCP\\IURLGenerator')
				);
			}
		);

		// ADR-035 provider discovery: expose the customer-bridge counters to the
		// observability engine's `{"kind":"provider"}` metric descriptor.
		$context->registerService(
			'OCA\\OpenRegister\\AppHost\\IMetricsProvider::' . $appId,
			static function (ContainerInterface $c): CustomerBridgeMetricsService {
				return $c->get(CustomerBridgeMetricsService::class);
			}
		);

	}//end registerAppHostGenerics()

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
	 */
	public function boot(IBootContext $context): void {
		$dispatcher = $context->getServerContainer()->get(IEventDispatcher::class);

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

		// Bookkeeping-innovatiebox-administratie — append an immutable
		// InnovatieboxAuditEvent per relevant lifecycle transition on the
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
