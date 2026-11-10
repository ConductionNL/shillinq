<?php

/**
 * Shillinq Payment Request Webhook Controller (shared deposits + invoice links)
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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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
class PaymentRequestWebhookController extends Controller {
	/**
	 * Outcome per Stripe event type; other types are not handled.
	 *
	 * @var array<string,string>
	 */
	private const STRIPE_OUTCOMES = [
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
	private const MOLLIE_OUTCOMES = [
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
		$gateway = strtolower($gateway);
		if (PaymentWebhookIntake::isKnownGateway(gateway: $gateway) === false) {
			return new JSONResponse(['status' => 'unknown-gateway'], Http::STATUS_NOT_FOUND);
		}

		// ADR-005: verify the provider signature before doing ANY work. The
		// signature is part of the payload (not user auth), so a mismatch is
		// surfaced as 400 (malformed/untrusted payload) on this #[PublicPage] route.
		$payload = $this->intake()->verifiedPayload(gateway: $gateway, rawBody: $this->getRawBody());
		if (is_string($payload) === true) {
			return $this->refuse(gateway: $gateway, status: $payload);
		}

		$event = $this->extractEvent(gateway: $gateway, payload: $payload);
		if ($event === null) {
			return new JSONResponse(['status' => 'unparseable-event'], Http::STATUS_BAD_REQUEST);
		}

		return $this->dispatch(gateway: $gateway, event: $event);
	}//end handle()

	/**
	 * Answer a refused call with 400; a bad signature is logged first, without
	 * the body, to avoid persisting payment data.
	 *
	 * @param string $gateway The gateway slug.
	 * @param string $status The refusal status.
	 *
	 * @return JSONResponse
	 */
	private function refuse(string $gateway, string $status): JSONResponse {
		if ($status === PaymentWebhookIntake::INVALID_SIGNATURE) {
			$this->logger->warning(
				'Shillinq: rejected payment webhook with invalid signature',
				['gateway' => $gateway]
			);
		}

		return new JSONResponse(['status' => $status], Http::STATUS_BAD_REQUEST);

	}//end refuse()

	/**
	 * Build the shared signature and payload intake for this request.
	 *
	 * @return PaymentWebhookIntake
	 */
	private function intake(): PaymentWebhookIntake {
		return new PaymentWebhookIntake(request: $this->request, appConfig: $this->appConfig);

	}//end intake()

	/**
	 * Reconcile a normalised event through the shared service and map the result
	 * to an HTTP response.
	 *
	 * @param string $gateway The gateway slug.
	 * @param array<string, mixed> $event The normalised event.
	 *
	 * @return JSONResponse
	 */
	private function dispatch(string $gateway, array $event): JSONResponse {
		try {
			$outcome = $this->reconciliation->reconcile(gateway: $gateway, event: $event);
		} catch (\Throwable $e) {
			// Never leak a stack trace to the gateway (ADR-005).
			$this->logger->error(
				'Shillinq: payment webhook reconciliation failed',
				['gateway' => $gateway, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['status' => 'error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return PaymentWebhookIntake::resultResponse(result: $outcome['result']);
	}//end dispatch()

	/**
	 * Read the raw request body. Extracted to a seam so the signature path can be
	 * exercised in unit tests without a real php://input stream.
	 *
	 * @return string The raw request body, or '' when empty/unreadable.
	 *
	 * @spec openspec/changes/ar-invoice-payment-links/specs/ar-invoice-payment-links/spec.md (REQ-APL-004)
	 */
	protected function getRawBody(): string {
		return PaymentWebhookIntake::readRawBody();
	}//end getRawBody()

	/**
	 * Normalise a Mollie/Stripe webhook payload into a gateway-agnostic event.
	 *
	 * @param string $gateway The gateway slug.
	 * @param array<mixed> $payload The decoded JSON payload.
	 *
	 * @return array{paymentIntentId: string, outcome: string, errorCode: ?string, errorMessage: ?string}|null
	 */
	private function extractEvent(string $gateway, array $payload): ?array {
		if ($gateway === 'stripe') {
			return PaymentWebhookIntake::stripeEvent(payload: $payload, outcomes: self::STRIPE_OUTCOMES);
		}

		return PaymentWebhookIntake::mollieEvent(payload: $payload, outcomes: self::MOLLIE_OUTCOMES);
	}//end extractEvent()
}//end class
