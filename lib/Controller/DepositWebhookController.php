<?php

/**
 * Shillinq Deposit Webhook Controller
 *
 * Receives async payment-confirmation webhooks from Mollie and Stripe (routed by
 * OpenConnector) and idempotently reconciles the matching DepositPayment record.
 *
 * This is the documented ADR-031 single-method exception: provider signature
 * verification (HMAC over the raw body with a shared secret) and idempotent state
 * reconciliation cannot be expressed as declarative x-openregister metadata — they
 * require a server-side, signature-gated endpoint. All business outcomes that CAN
 * be declarative (AR invoice materialisation, notifications, aggregations) live on
 * the DepositPayment schema; this controller only verifies the caller and flips the
 * lifecycle state.
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
 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-006, REQ-DP-011)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\DepositReconciliationService;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Signature-verified deposit-payment webhook endpoint (REQ-DP-006).
 *
 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-006)
 */
class DepositWebhookController extends PaymentWebhookReceiver {
	/**
	 * What this webhook reconciles, as written in its log messages.
	 */
	protected const WEBHOOK_KIND = 'deposit';

	/**
	 * Outcome per Stripe event type; other types are not handled.
	 *
	 * @var array<string,string>
	 */
	protected const STRIPE_OUTCOMES = [
		'payment_intent.succeeded' => DepositReconciliationService::OUTCOME_AUTHORIZED,
		'payment_intent.payment_failed' => DepositReconciliationService::OUTCOME_FAILED,
		'charge.refunded' => DepositReconciliationService::OUTCOME_VOIDED,
		'payment_intent.canceled' => DepositReconciliationService::OUTCOME_VOIDED,
	];

	/**
	 * Outcome per Mollie payment status; other statuses are not handled.
	 *
	 * @var array<string,string>
	 */
	protected const MOLLIE_OUTCOMES = [
		'paid' => DepositReconciliationService::OUTCOME_AUTHORIZED,
		'failed' => DepositReconciliationService::OUTCOME_FAILED,
		'expired' => DepositReconciliationService::OUTCOME_FAILED,
		'canceled' => DepositReconciliationService::OUTCOME_VOIDED,
	];

	/**
	 * Constructor for DepositWebhookController.
	 *
	 * @param IRequest $request The request object.
	 * @param IAppConfig $appConfig App config for the per-gateway shared secrets.
	 * @param DepositReconciliationService $reconciliation Idempotent deposit reconciliation service.
	 * @param LoggerInterface $logger Logger (never receives raw payment data).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private DepositReconciliationService $reconciliation,
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
	 * Reconcile a normalised event against its DepositPayment.
	 *
	 * @param string $gateway The gateway slug.
	 * @param array{paymentIntentId: string, outcome: string, errorCode: ?string, errorMessage: ?string} $event The normalised event.
	 *
	 * @return string The reconciliation result.
	 */
	protected function reconcileEvent(string $gateway, array $event): string {
		return $this->reconciliation->reconcile(
			paymentIntentId: $event['paymentIntentId'],
			outcome: $event['outcome'],
			gateway: $gateway,
			errorCode: $event['errorCode'],
			errorMessage: $event['errorMessage'],
		);
	}//end reconcileEvent()

	/**
	 * Handle a Mollie or Stripe payment webhook.
	 *
	 * The endpoint is public (gateways are unauthenticated callers) but is gated by
	 * a mandatory provider signature check: the request is rejected with 401 unless
	 * the HMAC of the raw body matches the configured shared secret. This is NOT an
	 * open endpoint (ADR-005 Rule: a #[PublicPage] webhook MUST verify a shared
	 * secret/signature).
	 *
	 * @param string $gateway The gateway slug from the route: 'mollie' or 'stripe'.
	 *
	 * @return JSONResponse 200 on success, 202 when queued, 400 on bad signature
	 *                      or malformed payload, 404 when no deposit matches.
	 *
	 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-006)
	 * Rate limit: payment-gateway callback. The caller retries on its own
	 * schedule and authenticates by its own signature. Generous ceiling —
	 * dropping a deposit notification is worse than absorbing a burst.
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
