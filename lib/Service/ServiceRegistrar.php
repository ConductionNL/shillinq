<?php

/**
 * Container bindings of the Service component
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude Structural composition root, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Dunning\CreditScoreFetchAdapterInterface;
use OCA\Shillinq\Service\Dunning\DunningChannelAdapterInterface;
use OCA\Shillinq\Service\Dunning\IncassoBureauAdapterInterface;
use OCA\Shillinq\Service\Dunning\LogCreditScoreFetchAdapter;
use OCA\Shillinq\Service\Dunning\LogIncassoBureauAdapter;
use OCA\Shillinq\Service\Dunning\LogPostNLAdapter;
use OCA\Shillinq\Service\Dunning\MailDunningChannelAdapter;
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
use OCA\Shillinq\Service\Billing\BillableHoursSource;
use OCA\Shillinq\Service\Billing\UnavailableBillableHours;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSbrAdapterInterface;
use OCA\Shillinq\Service\External\Digipoort\LogDigipoortSbrAdapter;
use OCA\Shillinq\Service\External\Ib47\Ib47AdapterInterface;
use OCA\Shillinq\Service\External\Ib47\LogIb47Adapter;
use OCA\Shillinq\Service\External\Kvk\KvkHandelsregisterAdapterInterface;
use OCA\Shillinq\Service\External\Kvk\LogKvkHandelsregisterAdapter;
use OCA\Shillinq\Service\External\Integriq\IntegriqPaymentAdapter;
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
use OCA\Shillinq\Service\Payment\MolliePaymentProvider;
use OCA\Shillinq\Service\Payment\PaymentProviderInterface;
use OCA\Shillinq\Service\Peppol\LogPeppolTransmissionAdapter;
use OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface;
use OCA\Shillinq\Service\Pipelinq\LoggingPipelinqAdminNotifier;
use OCA\Shillinq\Service\Pipelinq\PersistentTimelineRetryQueue;
use OCA\Shillinq\Service\Pipelinq\PipelinqAdminNotifier;
use OCA\Shillinq\Service\Pipelinq\TimelineRetryQueue;
use OCA\Shillinq\Service\Sms\LogSmsProviderAdapter;
use OCA\Shillinq\Service\Sms\SmsProviderAdapterInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;

/**
 * Container bindings of the Service component.
 *
 * Application::register() hands the registration context to this class, so
 * the Service component states its own container bindings and AppInfo does not
 * name the classes behind them. The bindings are unchanged.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec exclude Structural composition root, no functional requirement.
 */
final class ServiceRegistrar {

	/**
	 * Register the Service component's services.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec exclude Structural composition root, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		(new ContractRegistrar())->register(context: $context);

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
		$context->registerServiceAlias(TimelineRetryQueue::class, PersistentTimelineRetryQueue::class);

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
		$context->registerServiceAlias(PipelinqAdminNotifier::class, LoggingPipelinqAdminNotifier::class);

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
		$context->registerServiceAlias(CreditScoreFetchAdapterInterface::class, LogCreditScoreFetchAdapter::class);

		$context->registerServiceAlias(DunningChannelAdapterInterface::class, MailDunningChannelAdapter::class);

		$context->registerServiceAlias(IncassoBureauAdapterInterface::class, LogIncassoBureauAdapter::class);

		$context->registerServiceAlias(PostNLAdapterInterface::class, LogPostNLAdapter::class);

		// REQ-EINV-003/005 + bookings-sms-reminder-channel. Both ports below
		// were UNBOUND, and both are type-hinted non-nullably with no default
		// (EInvoiceValidationService, SmsReminderDispatcher), so NC's
		// SimpleContainer could not build them or anything downstream —
		// POST /api/ar-invoices/{invoiceNumber}/send-einvoice answered 500
		// before any controller code ran. See
		// tests/Unit/AppInfo/ContainerResolvableConstructorsTest.php, which
		// fails when a required in-app interface dependency has no binding.
		$context->registerServiceAlias(PeppolTransmissionPortInterface::class, LogPeppolTransmissionAdapter::class);

		$context->registerServiceAlias(SmsProviderAdapterInterface::class, LogSmsProviderAdapter::class);

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
		$context->registerServiceAlias(CbsBestandenAdapterInterface::class, LogCbsBestandenAdapter::class);

		$context->registerServiceAlias(CbsIv3AdapterInterface::class, LogCbsIv3Adapter::class);

		$context->registerServiceAlias(BzkSisaUploadAdapterInterface::class, LogBzkSisaUploadAdapter::class);

		$context->registerServiceAlias(DigipoortSbrAdapterInterface::class, LogDigipoortSbrAdapter::class);
		// Until humaniq's hours read is bound (sales-time-and-expense-billing
		// task 2.3), the invoice generator is told hours are unavailable (task 2.1).
		$context->registerServiceAlias(BillableHoursSource::class, UnavailableBillableHours::class);

		$context->registerServiceAlias(SalarisbureauAdapterInterface::class, LogSalarisbureauAdapter::class);

		$context->registerServiceAlias(RvOAanvraagAdapterInterface::class, LogRvOAanvraagAdapter::class);

		$context->registerServiceAlias(Ib47AdapterInterface::class, LogIb47Adapter::class);

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
		$context->registerServiceAlias(KvkHandelsregisterAdapterInterface::class, LogKvkHandelsregisterAdapter::class);

		// Receivables-payment-links REQ-RPL-001/002: payments are created by
		// integriq's payment providers through its typed payment command
		// event. Without integriq, an unanswered command, a refusal or a
		// log-provider source the adapter answers dormant with the reason.
		$context->registerServiceAlias(MolliePaymentAdapterInterface::class, IntegriqPaymentAdapter::class);

		// Portal-payment-initiation REQ-SPPI-001 — the payment-provider port
		// the subject-initiated pay-now flow drives. Sits one layer ABOVE
		// MolliePaymentAdapterInterface (wired immediately above): the
		// MolliePaymentProvider delegates every call to that binding, so it is
		// live exactly when integriq answers the payment command with a live
		// payment source, and dormant otherwise.
		$context->registerService(
			PaymentProviderInterface::class,
			static function ($c): PaymentProviderInterface {
				return new MolliePaymentProvider(mollie: $c->get(MolliePaymentAdapterInterface::class));
			}
		);

		$context->registerServiceAlias(BunqBankConnectorAdapterInterface::class, LogBunqBankConnectorAdapter::class);

		$context->registerServiceAlias(UwvLoonaangifteAdapterInterface::class, LogUwvLoonaangifteAdapter::class);

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
		$context->registerServiceAlias(DepositPaymentAdapterInterface::class, LogDepositPaymentAdapter::class);

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
		$context->registerServiceAlias(CsrdEsrsXbrlAdapterInterface::class, LogCsrdEsrsXbrlAdapter::class);

		// Bookkeeping-ccm-rule-engine REQ-CCM-002 — cross-app rule-engine
		// delegation port. The local CcmRuleEngine (ADR-031 exception) runs
		// v1 sync/async DSL evaluation in-process; this port is the swap-out
		// seam for the future OpenRegister native rule engine (or a
		// third-party evaluator). Dormant LogCcmRuleEngineAdapter returns
		// DEFERRED + fired=false (fail-soft) so binding the openconnector
		// source slug `ccm-rule-engine` never raises a false finding.
		$context->registerServiceAlias(CcmRuleEngineAdapterInterface::class, LogCcmRuleEngineAdapter::class);

		// Bookkeeping-treasury-ihb Tasks 14/15/17/22 — reference-rate
		// (EURIBOR-3M / SOFR / SARON / ESTR) + FX-spot snapshots for the
		// declarative interest-accrual, FX-revaluation, and liquidity-KPI
		// aggregations. The dormant LogTreasuryRateAdapter returns
		// SNAPSHOT_DEFERRED so the aggregation host stays observable until
		// openconnector source slug `treasury-rates` (ECB SDMX / Bloomberg /
		// Refinitiv) is bound; the IntercompanyLoan + FXPosition manual-entry
		// path remains the v1 fallback per REQ-IHB-004.
		$context->registerServiceAlias(TreasuryRateAdapterInterface::class, LogTreasuryRateAdapter::class);

		// REQ-BSC-006 — BudgetScenarioEvaluator depends on
		// KnownCostScheduleExpanderInterface rather than on budget-known-costs's
		// concrete KnownCostScheduleExpander, and this is the binding between them.
		//
		// This used to be a runtime-probing factory. When budget-scenarios was
		// written, the concrete class lived on an unmerged sibling branch (PR #967)
		// and was absent from this checkout, so the factory tested for it at
		// container-resolution time and threw a descriptive RuntimeException when it
		// was missing — deliberately loud, so a missing integration could not read
		// as a passing test.
		//
		// That scaffolding has done its job and is now dead. The class has landed
		// and declares `implements KnownCostScheduleExpanderInterface`, which makes
		// the probe unconditionally true (PHPStan: `function.alreadyNarrowedType`)
		// and the throw unreachable. Keeping a guard that can no longer fail is
		// worse than not having one: it reads like an active safety check while
		// testing nothing.
		//
		// A plain alias is now strictly stronger than the probe ever was. The
		// `implements` clause is checked by PHP at class-load time and by PHPStan
		// at analysis time, so a class that stops satisfying the contract fails
		// earlier and louder than any container-resolution check could manage.
		$context->registerServiceAlias(
			KnownCostScheduleExpanderInterface::class,
			KnownCostScheduleExpander::class
		);
	}//end register()
}//end class
