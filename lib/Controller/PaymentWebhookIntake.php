<?php

/**
 * Shillinq Payment Webhook Intake
 *
 * The gateway-facing half that the deposit and payment-request webhooks share:
 * which gateways are served, provider signature verification over the raw body,
 * payload decoding, normalising a Mollie or Stripe payload into a
 * gateway-agnostic event, and mapping a reconciliation result to HTTP.
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
 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-006)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Signature verification and event normalisation for payment webhooks.
 *
 * Built by the webhook controller that uses it; holds no state of its own.
 *
 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-006)
 */
final class PaymentWebhookIntake {
	/**
	 * Refusal status: the request had no body.
	 */
	public const EMPTY_BODY = 'empty-body';

	/**
	 * Refusal status: the signature is missing, wrong or cannot be verified.
	 */
	public const INVALID_SIGNATURE = 'invalid-signature';

	/**
	 * Refusal status: the body is not a JSON object or array.
	 */
	public const MALFORMED_PAYLOAD = 'malformed-payload';

	/**
	 * Reconcile result for an unknown payment; both reconciliation services use it.
	 */
	private const RESULT_NOT_FOUND = 'not-found';

	/**
	 * Reconcile result for an idempotent no-op; both reconciliation services use it.
	 */
	private const RESULT_NOOP = 'noop';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The webhook request (for the signature header).
	 * @param IAppConfig $appConfig App config holding the per-gateway shared secrets.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Whether the gateway slug is one the webhooks serve.
	 *
	 * @param string $gateway The lower-cased gateway slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-006)
	 */
	public static function isKnownGateway(string $gateway): bool {
		return in_array($gateway, ['mollie', 'stripe'], true);

	}//end isKnownGateway()

	/**
	 * Read the raw request body, '' when empty or unreadable.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-006)
	 */
	public static function readRawBody(): string {
		$body = file_get_contents('php://input');
		if ($body === false) {
			return '';
		}

		return $body;

	}//end readRawBody()

	/**
	 * Verify the signature, then decode the body.
	 *
	 * The signature is checked before ANY work (ADR-005).
	 *
	 * @param string $gateway The gateway slug.
	 * @param string $rawBody The raw request body.
	 *
	 * @return array<mixed>|string The decoded payload, or the refusal status.
	 *
	 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-006)
	 */
	public function verifiedPayload(string $gateway, string $rawBody): array|string {
		if ($rawBody === '') {
			return self::EMPTY_BODY;
		}

		if ($this->verifySignature(gateway: $gateway, rawBody: $rawBody) === false) {
			return self::INVALID_SIGNATURE;
		}

		$payload = json_decode($rawBody, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($payload) === false) {
			return self::MALFORMED_PAYLOAD;
		}

		return $payload;

	}//end verifiedPayload()

	/**
	 * Verify the gateway signature over the raw body with the configured shared
	 * secret, in constant time. Fail-closed when no secret is configured (the
	 * endpoint is not provisioned) or no signature is sent.
	 *
	 * @param string $gateway The gateway slug.
	 * @param string $rawBody The raw request body.
	 *
	 * @return bool
	 */
	private function verifySignature(string $gateway, string $rawBody): bool {
		$secret = $this->sharedSecret(gateway: $gateway);
		if ($secret === '') {
			return false;
		}

		$provided = $this->request->getHeader(self::signatureHeader(gateway: $gateway));
		if ($provided === '') {
			return false;
		}

		if ($gateway === 'stripe') {
			$provided = self::stripeV1Signature(provided: $provided);
		}

		$expected = hash_hmac('sha256', $rawBody, $secret);

		return hash_equals($expected, strtolower(trim($provided)));

	}//end verifySignature()

	/**
	 * The configured shared secret of a gateway, '' when not provisioned.
	 *
	 * Deposits and invoice payment links share one provisioning surface.
	 *
	 * @param string $gateway The gateway slug.
	 *
	 * @return string
	 */
	private function sharedSecret(string $gateway): string {
		return $this->appConfig->getValueString(
			AppIdentity::APP_ID,
			'deposit_webhook_secret_' . $gateway,
			''
		);

	}//end sharedSecret()

	/**
	 * The header a gateway sends its signature in.
	 *
	 * @param string $gateway The gateway slug.
	 *
	 * @return string
	 */
	private static function signatureHeader(string $gateway): string {
		if ($gateway === 'stripe') {
			return 'Stripe-Signature';
		}

		return 'X-Mollie-Signature';

	}//end signatureHeader()

	/**
	 * Take the v1 segment of a Stripe "t=...,v1=<hex>" header; any other
	 * header value is returned as sent.
	 *
	 * @param string $provided The Stripe-Signature header.
	 *
	 * @return string
	 */
	private static function stripeV1Signature(string $provided): string {
		if (str_contains($provided, 'v1=') === false) {
			return $provided;
		}

		foreach (explode(',', $provided) as $part) {
			$part = trim($part);
			if (str_starts_with($part, 'v1=') === true) {
				return substr($part, 3);
			}
		}

		return $provided;

	}//end stripeV1Signature()

	/**
	 * Normalise a Stripe payload with the receiving webhook's outcome map.
	 *
	 * @param array<mixed> $payload The decoded JSON payload.
	 * @param array<string,string> $outcomes Outcome per Stripe event type.
	 *
	 * @return array{paymentIntentId: string, outcome: string, errorCode: ?string, errorMessage: ?string}|null
	 *
	 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-006)
	 */
	public static function stripeEvent(array $payload, array $outcomes): ?array {
		$object = ($payload['data']['object'] ?? []);
		$event = self::event(
			intentId: (string)($object['id'] ?? ''),
			outcome: ($outcomes[(string)($payload['type'] ?? '')] ?? ''),
		);
		if ($event === null) {
			return null;
		}

		return array_merge($event, self::stripeErrors(lastError: ($object['last_payment_error'] ?? [])));

	}//end stripeEvent()

	/**
	 * Normalise a Mollie payload with the receiving webhook's outcome map.
	 *
	 * Mollie posts { "id": "tr_XXXX", "status": "paid|failed|canceled|expired" }.
	 *
	 * @param array<mixed> $payload The decoded JSON payload.
	 * @param array<string,string> $outcomes Outcome per Mollie status.
	 *
	 * @return array{paymentIntentId: string, outcome: string, errorCode: ?string, errorMessage: ?string}|null
	 *
	 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-006)
	 */
	public static function mollieEvent(array $payload, array $outcomes): ?array {
		$status = (string)($payload['status'] ?? '');
		$event = self::event(intentId: (string)($payload['id'] ?? ''), outcome: ($outcomes[$status] ?? ''));
		if ($event === null) {
			return null;
		}

		return array_merge($event, self::mollieErrors(status: $status));

	}//end mollieEvent()

	/**
	 * The identifying part of an event; null when the outcome or id is unknown.
	 *
	 * @param string $intentId The gateway payment id.
	 * @param string $outcome The mapped outcome, '' when unmapped.
	 *
	 * @return array{paymentIntentId: string, outcome: string}|null
	 */
	private static function event(string $intentId, string $outcome): ?array {
		if ($outcome === '' || $intentId === '') {
			return null;
		}

		return [
			'paymentIntentId' => $intentId,
			'outcome' => $outcome,
		];

	}//end event()

	/**
	 * Error code and message of a Stripe last_payment_error, null when absent.
	 *
	 * @param mixed $lastError The last_payment_error object.
	 *
	 * @return array{errorCode: ?string, errorMessage: ?string}
	 */
	private static function stripeErrors(mixed $lastError): array {
		if (is_array($lastError) === false) {
			$lastError = [];
		}

		return [
			'errorCode' => self::stringOrNull(value: ($lastError['code'] ?? null)),
			'errorMessage' => self::stringOrNull(value: ($lastError['message'] ?? null)),
		];

	}//end stripeErrors()

	/**
	 * A set value as string, null when it is not set.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function stringOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		return (string)$value;

	}//end stringOrNull()

	/**
	 * Error code and message derived from a Mollie status.
	 *
	 * @param string $status The Mollie status.
	 *
	 * @return array{errorCode: ?string, errorMessage: ?string}
	 */
	private static function mollieErrors(string $status): array {
		$errorCode = null;
		$errorMessage = null;
		if ($status === 'failed' || $status === 'expired') {
			$errorCode = $status;
		}

		if ($status === 'failed') {
			$errorMessage = 'Payment failed at gateway.';
		}

		return [
			'errorCode' => $errorCode,
			'errorMessage' => $errorMessage,
		];

	}//end mollieErrors()

	/**
	 * Map a reconciliation result to the response the gateway gets: 404 when no
	 * payment matches, 202 for an idempotent no-op so the gateway stops
	 * retrying, 200 otherwise.
	 *
	 * @param string $result The reconciliation result.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-006)
	 */
	public static function resultResponse(string $result): JSONResponse {
		if ($result === self::RESULT_NOT_FOUND) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		$httpStatus = Http::STATUS_OK;
		if ($result === self::RESULT_NOOP) {
			$httpStatus = Http::STATUS_ACCEPTED;
		}

		return new JSONResponse(['status' => $result], $httpStatus);

	}//end resultResponse()
}//end class
