<?php

/**
 * Shillinq Payment Request Webhook Controller (shared deposits + invoice links)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Receives async payment-confirmation webhooks from Mollie and Stripe (routed by
 * OpenConnector) and idempotently reconciles the matching payment record. This is
 * the GENERALIZED, shared webhook surface (REQ-APL-004 "ONE shared surface —
 * never a fork"): one route, one signature-verification implementation, one
 * reconciliation service, serving BOTH PaymentRequest (AR invoice payment links)
 * AND DepositPayment (booking deposits) through a single code path. It models the
 * proven DepositWebhookController exactly.
 *
 * This is the documented ADR-031 single-method exception: provider signature
 * verification (HMAC over the raw body with a shared secret) and idempotent state
 * reconciliation cannot be expressed as declarative x-openregister metadata — they
 * require a server-side, signature-gated endpoint. All business outcomes that CAN
 * be declarative (AR invoice settlement via the AR lifecycle, notifications,
 * aggregations) live on the schemas; this controller only verifies the caller and
 * hands off to the shared PaymentReconciliationService.
 *
 * HONEST DEFER: the live gateway leg (real Mollie/Stripe verification secrets +
 * OpenConnector hosted-UI token minting) is provider-config-dependent and is
 * wired through IAppConfig shared secrets exactly as DepositWebhookController does.
 * The signature-verification + idempotent state-flip code here is REAL and
 * complete; when the per-gateway shared secret is unconfigured the endpoint
 * FAIL-CLOSES (rejects) — that is correct and honest, never an "always-valid"
 * stub. No raw body is ever logged (PCI — REQ-APL-001).
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ar-invoice-payment-links/specs/ar-invoice-payment-links/spec.md (REQ-APL-004)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\PaymentReconciliationService;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Signature-verified shared payment webhook endpoint (REQ-APL-004).
 *
 * @spec openspec/changes/ar-invoice-payment-links/specs/ar-invoice-payment-links/spec.md (REQ-APL-004)
 */
class PaymentRequestWebhookController extends PaymentWebhookReceiver {
	/**
	 * What this webhook reconciles, as written in its log messages.
	 */
	protected const WEBHOOK_KIND = 'payment';

	/**
	 * Outcome per Stripe event type; other types are not handled.
	 *
	 * @var array<string,string>
	 */
	protected const STRIPE_OUTCOMES = [
		'payment_intent.amount_capturable_updated' => PaymentReconciliationService::OUTCOME_AUTHORIZED,
		'payment_intent.succeeded' => PaymentReconciliationService::OUTCOME_CAPTURED,
		'charge.captured' => PaymentReconciliationService::OUTCOME_CAPTURED,
		'payment_intent.payment_failed' => PaymentReconciliationService::OUTCOME_FAILED,
		'charge.refunded' => PaymentReconciliationService::OUTCOME_VOIDED,
		'payment_intent.canceled' => PaymentReconciliationService::OUTCOME_VOIDED,
	];

	/**
	 * Outcome per Mollie payment status; other statuses are not handled.
	 *
	 * @var array<string,string>
	 */
	protected const MOLLIE_OUTCOMES = [
		'paid' => PaymentReconciliationService::OUTCOME_CAPTURED,
		'failed' => PaymentReconciliationService::OUTCOME_FAILED,
		'expired' => PaymentReconciliationService::OUTCOME_FAILED,
		'canceled' => PaymentReconciliationService::OUTCOME_VOIDED,
	];

	/**
	 * Constructor for PaymentRequestWebhookController.
	 *
	 * @param IRequest $request The request object.
	 * @param IAppConfig $appConfig App config for the per-gateway shared secrets.
	 * @param PaymentReconciliationService $reconciliation Shared idempotent reconciliation service
	 *                                                     (handles BOTH PaymentRequest and
	 *                                                     DepositPayment — REQ-APL-004).
	 * @param LoggerInterface $logger Logger (never receives raw payment data).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private PaymentReconciliationService $reconciliation,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The logger (never receives raw payment data).
	 *
	 * @return LoggerInterface
	 */
	protected function webhookLogger(): LoggerInterface {
		return $this->logger;
	}//end webhookLogger()

	/**
	 * Reconcile a normalised event through the shared service, which handles
	 * both PaymentRequest and DepositPayment (REQ-APL-004).
	 *
	 * 200 when applied (or captured_unapplied — the gateway succeeded and the
	 * exception is recorded), 202 when an idempotent no-op so the gateway stops
	 * retrying.
	 *
	 * @param string $gateway The gateway slug.
	 * @param array{paymentIntentId: string, outcome: string, errorCode: ?string, errorMessage: ?string} $event The normalised event.
	 *
	 * @return string The reconciliation result.
	 */
	protected function reconcileEvent(string $gateway, array $event): string {
		$outcome = $this->reconciliation->reconcile(gateway: $gateway, event: $event);
		return $outcome['result'];
	}//end reconcileEvent()

	/**
	 * Handle a Mollie or Stripe payment webhook for a PaymentRequest or DepositPayment.
	 *
	 * The endpoint is public (gateways are unauthenticated callers) but is gated by
	 * a mandatory provider signature check: the request is rejected with 400 unless
	 * the HMAC of the raw body matches the configured shared secret (malformed/
	 * untrusted payload, not an auth failure — the deposits convention, ADR-005).
	 * This is NOT an open endpoint: a #[PublicPage] webhook MUST verify a shared
	 * secret/signature before doing ANY work.
	 *
	 * @param string $gateway The gateway slug from the route: 'mollie' or 'stripe'.
	 *
	 * @return JSONResponse 200 on success, 202 when an idempotent no-op, 400 on bad
	 *                      signature or malformed payload, 404 when no record matches.
	 *
	 * @spec openspec/changes/ar-invoice-payment-links/specs/ar-invoice-payment-links/spec.md (REQ-APL-004)
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function handle(string $gateway): JSONResponse {
		return $this->receive(
			intake: new PaymentWebhookIntake(request: $this->request, appConfig: $this->appConfig),
			gateway: $gateway
		);
	}//end handle()
}//end class
