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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\DepositReconciliationService;
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
 * Signature-verified deposit-payment webhook endpoint (REQ-DP-006).
 *
 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-006)
 */
class DepositWebhookController extends Controller {
	/**
	 * Outcome per Stripe event type; other types are not handled.
	 *
	 * @var array<string,string>
	 */
	private const STRIPE_OUTCOMES = [
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
	private const MOLLIE_OUTCOMES = [
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
				'Shillinq: rejected deposit webhook with invalid signature',
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
	 * Reconcile a normalised event and map the result to an HTTP response.
	 *
	 * @param string $gateway The gateway slug.
	 * @param array{paymentIntentId: string, outcome: string, errorCode: ?string, errorMessage: ?string} $event The normalised event.
	 *
	 * @return JSONResponse
	 */
	private function dispatch(string $gateway, array $event): JSONResponse {
		try {
			$result = $this->reconcile(gateway: $gateway, event: $event);
		} catch (\Throwable $e) {
			// Never leak a stack trace to the gateway (ADR-005).
			$this->logger->error(
				'Shillinq: deposit webhook reconciliation failed',
				['gateway' => $gateway, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['status' => 'error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return PaymentWebhookIntake::resultResponse(result: $result);
	}//end dispatch()

	/**
	 * Reconcile a normalised event against its DepositPayment.
	 *
	 * @param string $gateway The gateway slug.
	 * @param array{paymentIntentId: string, outcome: string, errorCode: ?string, errorMessage: ?string} $event The normalised event.
	 *
	 * @return string The reconciliation result.
	 */
	private function reconcile(string $gateway, array $event): string {
		return $this->reconciliation->reconcile(
			paymentIntentId: $event['paymentIntentId'],
			outcome: $event['outcome'],
			gateway: $gateway,
			errorCode: $event['errorCode'],
			errorMessage: $event['errorMessage'],
		);
	}//end reconcile()

	/**
	 * Read the raw request body. Extracted to a seam so the signature path can be
	 * exercised in unit tests without a real php://input stream.
	 *
	 * @return string The raw request body, or '' when empty/unreadable.
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
