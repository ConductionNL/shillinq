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

use OCA\Shillinq\Listener\AppointmentCreatedListener;
use OCA\Shillinq\Listener\BookingCreatedTimelinePublishListener;
use OCA\Shillinq\Listener\BookingLifecycleTransitionListener;
use OCA\Shillinq\Listener\DBAFactuurMonitorListener;
use OCA\Shillinq\Listener\DeepLinkRegistrationListener;
use OCA\Shillinq\Listener\GLTransactionComplianceCacheListener;
use OCA\Shillinq\Listener\InnovatieboxAuditTrailListener;
use OCA\Shillinq\Listener\OpdrachtUitvoeringTransitionListener;
use OCA\Shillinq\Listener\PeppolInboundUblInvoiceListener;
use OCA\Shillinq\Listener\ReconciliationMatchToReportListener;
use OCA\Shillinq\Listener\StockMoveTransitionedListener;
use OCA\Shillinq\Listener\TenderNedAwardDetectedListener;
use OCA\Shillinq\Listener\VerplichtingTransitionListener;
use OCA\Shillinq\Notification\Notifier;
use OCA\Shillinq\Service\Dunning\CreditScoreFetchAdapterInterface;
use OCA\Shillinq\Service\Dunning\DunningChannelAdapterInterface;
use OCA\Shillinq\Service\Dunning\IncassoBureauAdapterInterface;
use OCA\Shillinq\Service\Dunning\LogCreditScoreFetchAdapter;
use OCA\Shillinq\Service\Dunning\LogDunningChannelAdapter;
use OCA\Shillinq\Service\Dunning\LogIncassoBureauAdapter;
use OCA\Shillinq\Service\Dunning\LogPostNLAdapter;
use OCA\Shillinq\Service\Dunning\PostNLAdapterInterface;
use OCA\Shillinq\Service\External\Sisa\BzkSisaUploadAdapterInterface;
use OCA\Shillinq\Service\External\Sisa\LogBzkSisaUploadAdapter;
use OCA\Shillinq\Service\External\Cbs\CbsBestandenAdapterInterface;
use OCA\Shillinq\Service\External\Cbs\CbsIv3AdapterInterface;
use OCA\Shillinq\Service\External\Cbs\LogCbsBestandenAdapter;
use OCA\Shillinq\Service\External\Cbs\LogCbsIv3Adapter;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSbrAdapterInterface;
use OCA\Shillinq\Service\External\Digipoort\LogDigipoortSbrAdapter;
use OCA\Shillinq\Service\External\Ib47\Ib47AdapterInterface;
use OCA\Shillinq\Service\External\Ib47\LogIb47Adapter;
use OCA\Shillinq\Service\External\Kvk\KvkHandelsregisterAdapterInterface;
use OCA\Shillinq\Service\External\Kvk\LogKvkHandelsregisterAdapter;
use OCA\Shillinq\Service\External\Mollie\LogMolliePaymentAdapter;
use OCA\Shillinq\Service\External\Mollie\MolliePaymentAdapterInterface;
use OCA\Shillinq\Service\External\Bunq\BunqBankConnectorAdapterInterface;
use OCA\Shillinq\Service\External\Bunq\LogBunqBankConnectorAdapter;
use OCA\Shillinq\Service\External\Uwv\LogUwvLoonaangifteAdapter;
use OCA\Shillinq\Service\External\Uwv\UwvLoonaangifteAdapterInterface;
use OCA\Shillinq\Service\External\RvO\LogRvOAanvraagAdapter;
use OCA\Shillinq\Service\External\RvO\RvOAanvraagAdapterInterface;
use OCA\Shillinq\Service\External\Salarisbureau\LogSalarisbureauAdapter;
use OCA\Shillinq\Service\External\Salarisbureau\SalarisbureauAdapterInterface;
use OCA\Shillinq\Service\Pipelinq\LoggingPipelinqAdminNotifier;
use OCA\Shillinq\Service\Pipelinq\PersistentTimelineRetryQueue;
use OCA\Shillinq\Service\Pipelinq\PipelinqAdminNotifier;
use OCA\Shillinq\Service\Pipelinq\TimelineRetryQueue;
use OCA\Shillinq\Service\DoorsnijdingsVerbodValidator;
use OCA\Shillinq\Service\InnovatieboxAuditEventLogger;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Main application class for the Shillinq Nextcloud app.
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'shillinq';

    /**
     * Constructor for the Application class.
     *
     * @return void
     */
    public function __construct()
    {
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
    public function register(IRegistrationContext $context): void
    {
        // Register deep link patterns with OpenRegister's unified search provider.
        // Only fires when OpenRegister is installed and dispatches the event.
        $context->registerEventListener(
            event: DeepLinkRegistrationEvent::class,
            listener: DeepLinkRegistrationListener::class
        );

        // Bookings-confirm-flow REQ-BCF-001/010 — issue a ConfirmationToken
        // + dispatch the confirmation email when a new Appointment record is
        // created with status `pending_confirmation` (customer self-service).
        // Admin-created bookings start `confirmed` and the listener ignores
        // them. Idempotent: OR fires the event once per saveObject.
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: AppointmentCreatedListener::class
        );

        // Inventory-valuation-fifo-avg REQ-INV-003 / REQ-INV-004 / REQ-INV-007
        // — dispatch posted StockMove records into the valuation engine
        // (FIFO or moving-average per the InventoryValuation.valuationMethod)
        // and post a balanced COGS GLTransaction on outbound moves.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: StockMoveTransitionedListener::class
        );

        // Bookkeeping-purchase-order-3way slice 05 (REQ-PO3W-004) —
        // openconnector publishes a `PeppolInboundMessage` OR record for
        // every received Peppol message. This listener filters on the
        // documentType=Invoice slice of those events and dispatches the
        // UBL payload into SupplierInvoiceService::ingestUBLInvoice().
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: PeppolInboundUblInvoiceListener::class
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
        $context->registerService(
            TimelineRetryQueue::class,
            static function ($c): TimelineRetryQueue {
                return $c->get(PersistentTimelineRetryQueue::class);
            }
        );
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: BookingCreatedTimelinePublishListener::class
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

        // Bookkeeping-waterschappen-bbv-variant slice 08 — invalidate the
        // BBV-compliance cache when a GL transaction header or line is
        // created or updated. The slice-02 x-openregister-aggregations
        // block on BBVProgramme materialises totalBudget / ytdSpend /
        // utilization / complianceStatus over those very lines, and
        // ComplianceService caches the per-programme envelope for 1h
        // (REQ-BBVW-006). The listener drops the cache namespace so the
        // next dashboard render repopulates from the engine. Fail-soft:
        // a cache hiccup never blocks a GL write (giant D3).
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: GLTransactionComplianceCacheListener::class
        );
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: GLTransactionComplianceCacheListener::class
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
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: InnovatieboxAuditTrailListener::class
        );
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: InnovatieboxAuditTrailListener::class
        );

        // bookkeeping-reconciliation-reports (T4) — REQ-REC-010. T2's
        // bookkeeping-bank-reconciliation engine confirms a
        // ReconciliationMatch by transitioning its status to `confirmed`;
        // the listener stamps the T4-side fields (reconId, matchAlgorithm,
        // matchedAt, glTransactionId/arInvoiceId/apTransactionId, etc.) on
        // the same record so the open BankReconciliation session sees the
        // outcome within 1s. Fail-soft: never blocks the T2 write.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: ReconciliationMatchToReportListener::class
        );
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
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
                    $c,
                    $c->get(IAppConfig::class),
                    $c->get(InnovatieboxAuditEventLogger::class),
                );
            }
        );

        // bookkeeping-credit-control-dunning tasks 19/20/21 — wire the
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
        //   bookkeeping-financial-statements,
        //   bookkeeping-sbr-xbrl-reporting, bookkeeping-csrd-esrs)
        // - Salarisbureau (bookkeeping-detachering-payroll-administratie,
        //   bookkeeping-payroll-engine-nl)
        // - RvO (bookkeeping-investeringsaftrek,
        //   bookkeeping-wbso-sno-administratie)
        // - Belastingdienst IB47
        //   (bookkeeping-detachering-payroll-administratie,
        //   bookkeeping-btw-oss-eu).
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
        //   AR/AP debtor/creditor enrichment,
        //   bookkeeping-consolidation-commercial deelnemingen-graaf walk).
        // - Mollie Payments (bookings-deposits DepositPayment intent,
        //   bookkeeping-accounts-receivable-core invoice payment links +
        //   webhook verification).
        // - Bunq Bank Connector (bookkeeping-bank-connectors per-Source
        //   pull + consent-renewal action; ADR-031 ScheduledWorkflow
        //   delegates transport to this port; no aggregator JSON path —
        //   Bunq exposes CAMT.053 natively).
        // - UWV Loonaangifte + Werkhervattingskas (LHAfdracht acceptance
        //   pull + werkgever-setup sectorindeling validation).
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

        // Register the notifier for Shillinq in-app notifications (REQ-SUBV-010).
        $context->registerNotifierService(Notifier::class);

        // dba-compliance-marker T31/T32 — optional non-blocking hook into AP/AR
        // factuur creation. The listener runs the VBAR uurtarief-toets
        // (REQ-DBA-016) when an ARInvoice/APInvoice carries a
        // `dbaOpdrachtId` field; it is silently inert otherwise.
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: DBAFactuurMonitorListener::class
        );

        // bookkeeping-tenderned-integratie Tasks 5.1 / 5.2 / 5.3 — react to
        // the OR object-lifecycle events that materialise the
        // `tenderned.award.detected`, `obligation.activated`, and
        // `milestone.completed` CloudEvents (design D4). Every listener is
        // fail-soft: an exception is logged but never propagated back into
        // the originating OR write path.
        //
        // Task 5.1 — TenderNedAwardDetectedListener auto-promotes an
        // awarded TenderNed dossier into an active Verplichting when the
        // winning KvK matches the tenant org (REQ-002 idempotent on
        // bronReferentie + REQ-003 milestone plan generated from the
        // opdrachttype template).
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: TenderNedAwardDetectedListener::class
        );
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: TenderNedAwardDetectedListener::class
        );

        // Task 5.2 — VerplichtingTransitionListener emits the cross-app
        // `obligation.activated` CloudEvent on auto-promoted (created
        // active) AND manually-enriched (transitioned to active)
        // tenderned-sourced obligations (REQ-007 budget-impact pipeline).
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: VerplichtingTransitionListener::class
        );
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: VerplichtingTransitionListener::class
        );

        // Task 5.3 — OpdrachtUitvoeringTransitionListener emits
        // `milestone.completed` on every completed OpdrachtUitvoering and
        // (for the approved eindoplevering of a tenderned-sourced
        // obligation) triggers the outbound status-sync to TenderNed
        // (REQ-006). The buyer-side gate is enforced both server-side
        // (RBAC + TenderNedAanbestedingGuard::canAfronden) and inside
        // TenderNedStatusSync as a defence-in-depth tenant KvK check.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: OpdrachtUitvoeringTransitionListener::class
        );

    }//end register()

    /**
     * Boot the application.
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function boot(IBootContext $context): void
    {
    }//end boot()
}//end class
