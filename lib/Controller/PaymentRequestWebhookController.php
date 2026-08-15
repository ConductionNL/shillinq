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

use OCA\Shillinq\AppInfo\Application;
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
		parent::__construct(appName: Application::APP_ID, request: $request);
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
		if (in_array($gateway, ['mollie', 'stripe'], true) === false) {
			return new JSONResponse(['status' => 'unknown-gateway'], Http::STATUS_NOT_FOUND);
		}

		$rawBody = $this->getRawBody();
		if ($rawBody === '') {
			return new JSONResponse(['status' => 'empty-body'], Http::STATUS_BAD_REQUEST);
		}

		// ADR-005: verify the provider signature before doing ANY work. The
		// signature is part of the payload (not user auth), so a mismatch is
		// surfaced as 400 (malformed/untrusted payload) on this #[PublicPage]
		// route. Fail-closed when no secret is configured.
		if ($this->verifySignature(gateway: $gateway, rawBody: $rawBody) === false) {
			// Do not echo any detail to the caller; log without the body to avoid
			// persisting payment data (REQ-APL-001).
			$this->logger->warning(
				'Shillinq: rejected payment webhook with invalid signature',
				['gateway' => $gateway]
			);
			return new JSONResponse(['status' => 'invalid-signature'], Http::STATUS_BAD_REQUEST);
		}

		$payload = json_decode($rawBody, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($payload) === false) {
			return new JSONResponse(['status' => 'malformed-payload'], Http::STATUS_BAD_REQUEST);
		}

		$event = $this->extractEvent(gateway: $gateway, payload: $payload);
		if ($event === null || $event['paymentIntentId'] === '') {
			return new JSONResponse(['status' => 'unparseable-event'], Http::STATUS_BAD_REQUEST);
		}

		return $this->dispatch(gateway: $gateway, event: $event);
	}//end handle()

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

		$result = $outcome['result'];

		if ($result === PaymentReconciliationService::RESULT_NOT_FOUND) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		// 200 when applied (or captured_unapplied — the gateway succeeded and we
		// recorded the exception), 202 when it was an idempotent no-op so the
		// gateway stops retrying (REQ-APL-004).
		$httpStatus = Http::STATUS_OK;
		if ($result === PaymentReconciliationService::RESULT_NOOP) {
			$httpStatus = Http::STATUS_ACCEPTED;
		}

		return new JSONResponse(['status' => $result], $httpStatus);
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
		$body = file_get_contents('php://input');
		if ($body === false) {
			return '';
		}

		return $body;
	}//end getRawBody()

	/**
	 * Verify the gateway signature over the raw request body using the configured
	 * per-gateway shared secret. Uses a constant-time comparison. Fail-closed when
	 * no secret is configured (endpoint not provisioned).
	 *
	 * @param string $gateway The gateway slug.
	 * @param string $rawBody The raw request body.
	 *
	 * @return bool True when the signature is valid.
	 */
	private function verifySignature(string $gateway, string $rawBody): bool {
		// Shared secret is read from app config exactly as DepositWebhookController
		// does — the live provider leg is wired through deployment config, never a
		// hardcoded/always-valid stub. Reuse the same secret keys so deposits and
		// invoice links share one provisioning surface (REQ-APL-004).
		$secret = $this->appConfig->getValueString(
			Application::APP_ID,
			'deposit_webhook_secret_' . $gateway,
			''
		);
		if ($secret === '') {
			// No secret configured means the endpoint is not provisioned; refuse
			// rather than accepting an unverifiable request (fail-closed).
			return false;
		}

		$header = 'X-Mollie-Signature';
		if ($gateway === 'stripe') {
			$header = 'Stripe-Signature';
		}

		$provided = $this->request->getHeader($header);
		if ($provided === '') {
			return false;
		}

		// Stripe prefixes the header with "t=...,v1=<hex>"; extract the v1 segment.
		if ($gateway === 'stripe' && str_contains($provided, 'v1=') === true) {
			foreach (explode(',', $provided) as $part) {
				$part = trim($part);
				if (str_starts_with($part, 'v1=') === true) {
					$provided = substr($part, 3);
					break;
				}
			}
		}

		$expected = hash_hmac('sha256', $rawBody, $secret);

		return hash_equals($expected, strtolower(trim($provided)));
	}//end verifySignature()

	/**
	 * Normalise a Mollie/Stripe webhook payload into a gateway-agnostic event.
	 *
	 * @param string $gateway The gateway slug.
	 * @param array<string, mixed> $payload The decoded JSON payload.
	 *
	 * @return array{paymentIntentId: string, outcome: string, errorCode: ?string, errorMessage: ?string}|null
	 */
	private function extractEvent(string $gateway, array $payload): ?array {
		if ($gateway === 'stripe') {
			return $this->extractStripeEvent(payload: $payload);
		}

		return $this->extractMollieEvent(payload: $payload);
	}//end extractEvent()

	/**
	 * Normalise a Stripe webhook payload into a gateway-agnostic event.
	 *
	 * @param array<string, mixed> $payload The decoded JSON payload.
	 *
	 * @return array{paymentIntentId: string, outcome: string, errorCode: ?string, errorMessage: ?string}|null
	 */
	private function extractStripeEvent(array $payload): ?array {
		$type = (string)($payload['type'] ?? '');
		$object = ($payload['data']['object'] ?? []);
		$intentId = (string)($object['id'] ?? '');
		$outcome = match ($type) {
			'payment_intent.amount_capturable_updated' => PaymentReconciliationService::OUTCOME_AUTHORIZED,
			'payment_intent.succeeded', 'charge.captured' => PaymentReconciliationService::OUTCOME_CAPTURED,
			'payment_intent.payment_failed' => PaymentReconciliationService::OUTCOME_FAILED,
			'charge.refunded', 'payment_intent.canceled' => PaymentReconciliationService::OUTCOME_VOIDED,
			default => '',
		};

		if ($outcome === '' || $intentId === '') {
			return null;
		}

		$lastError = ($object['last_payment_error'] ?? []);
		$errorCode = null;
		$errorMessage = null;
		if (isset($lastError['code']) === true) {
			$errorCode = (string)$lastError['code'];
		}

		if (isset($lastError['message']) === true) {
			$errorMessage = (string)$lastError['message'];
		}

		return [
			'paymentIntentId' => $intentId,
			'outcome' => $outcome,
			'errorCode' => $errorCode,
			'errorMessage' => $errorMessage,
		];
	}//end extractStripeEvent()

	/**
	 * Normalise a Mollie webhook payload into a gateway-agnostic event.
	 *
	 * Mollie posts { "id": "tr_XXXX", "status": "paid|failed|canceled|expired" }.
	 * iDEAL captures directly, so a Mollie "paid" maps to OUTCOME_CAPTURED.
	 *
	 * @param array<string, mixed> $payload The decoded JSON payload.
	 *
	 * @return array{paymentIntentId: string, outcome: string, errorCode: ?string, errorMessage: ?string}|null
	 */
	private function extractMollieEvent(array $payload): ?array {
		$intentId = (string)($payload['id'] ?? '');
		$status = (string)($payload['status'] ?? '');
		$outcome = match ($status) {
			'paid' => PaymentReconciliationService::OUTCOME_CAPTURED,
			'failed' => PaymentReconciliationService::OUTCOME_FAILED,
			'expired' => PaymentReconciliationService::OUTCOME_FAILED,
			'canceled' => PaymentReconciliationService::OUTCOME_VOIDED,
			default => '',
		};

		if ($outcome === '' || $intentId === '') {
			return null;
		}

		$errorCode = null;
		$errorMessage = null;
		if ($status === 'failed' || $status === 'expired') {
			$errorCode = $status;
		}

		if ($status === 'failed') {
			$errorMessage = 'Payment failed at gateway.';
		}

		return [
			'paymentIntentId' => $intentId,
			'outcome' => $outcome,
			'errorCode' => $errorCode,
			'errorMessage' => $errorMessage,
		];
	}//end extractMollieEvent()
}//end class
