<?php

/**
 * Shillinq Payment Webhook Receiver
 *
 * The request flow the deposit and payment-request webhooks share: refuse an
 * unknown gateway, verify the provider signature before doing any work,
 * normalise the gateway payload into an event, reconcile it and map the result
 * to HTTP. A concrete webhook supplies its outcome maps, its log wording and
 * its reconciliation call.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;

/**
 * Shared flow of the signature-verified payment webhooks.
 *
 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-006)
 */
abstract class PaymentWebhookReceiver extends Controller {
	/**
	 * Reconcile result for an unknown payment; both reconciliation services use it.
	 */
	private const RESULT_NOT_FOUND = 'not-found';

	/**
	 * Reconcile result for an idempotent no-op; both reconciliation services use it.
	 */
	private const RESULT_NOOP = 'noop';

	/**
	 * What the webhook reconciles, as written in its log messages.
	 */
	protected const WEBHOOK_KIND = '';

	/**
	 * Outcome per Stripe event type; other types are not handled.
	 *
	 * @var array<string,string>
	 */
	protected const STRIPE_OUTCOMES = [];

	/**
	 * Outcome per Mollie payment status; other statuses are not handled.
	 *
	 * @var array<string,string>
	 */
	protected const MOLLIE_OUTCOMES = [];

	/**
	 * Handle a Mollie or Stripe webhook call.
	 *
	 * ADR-005: the provider signature is verified before doing ANY work. The
	 * signature is part of the payload (not user auth), so a mismatch is
	 * surfaced as 400 (malformed/untrusted payload) on the #[PublicPage] route.
	 *
	 * @param PaymentWebhookIntake $intake The signature and payload intake.
	 * @param string $gateway The gateway slug from the route.
	 *
	 * @return JSONResponse
	 */
	protected function receive(PaymentWebhookIntake $intake, string $gateway): JSONResponse {
		$gateway = strtolower($gateway);
		if (in_array($gateway, ['mollie', 'stripe'], true) === false) {
			return new JSONResponse(['status' => 'unknown-gateway'], Http::STATUS_NOT_FOUND);
		}

		$payload = $intake->verifiedPayload(gateway: $gateway, rawBody: $this->getRawBody());
		if (is_string($payload) === true) {
			return $this->refuse(gateway: $gateway, status: $payload);
		}

		$event = $this->extractEvent(gateway: $gateway, payload: $payload);
		if ($event === null) {
			return new JSONResponse(['status' => 'unparseable-event'], Http::STATUS_BAD_REQUEST);
		}

		return $this->dispatch(gateway: $gateway, event: $event);

	}//end receive()

	/**
	 * Read the raw request body. A seam so the signature path can be exercised
	 * in unit tests without a real php://input stream.
	 *
	 * @return string The raw request body, or '' when empty/unreadable.
	 */
	protected function getRawBody(): string {
		$body = file_get_contents('php://input');
		if ($body === false) {
			return '';
		}

		return $body;

	}//end getRawBody()

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
			$this->webhookLogger()->warning(
				'Shillinq: rejected ' . static::WEBHOOK_KIND . ' webhook with invalid signature',
				['gateway' => $gateway]
			);
		}

		return new JSONResponse(['status' => $status], Http::STATUS_BAD_REQUEST);

	}//end refuse()

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
			return self::stripeEvent(payload: $payload, outcomes: static::STRIPE_OUTCOMES);
		}

		return self::mollieEvent(payload: $payload, outcomes: static::MOLLIE_OUTCOMES);

	}//end extractEvent()

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
			$result = $this->reconcileEvent(gateway: $gateway, event: $event);
		} catch (\Throwable $e) {
			// Never leak a stack trace to the gateway (ADR-005).
			$this->webhookLogger()->error(
				'Shillinq: ' . static::WEBHOOK_KIND . ' webhook reconciliation failed',
				['gateway' => $gateway, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['status' => 'error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return self::resultResponse(result: $result);

	}//end dispatch()

	/**
	 * Normalise a Stripe payload with the receiving webhook's outcome map.
	 *
	 * @param array<mixed> $payload The decoded JSON payload.
	 * @param array<string,string> $outcomes Outcome per Stripe event type.
	 *
	 * @return array{paymentIntentId: string, outcome: string, errorCode: ?string, errorMessage: ?string}|null
	 */
	private static function stripeEvent(array $payload, array $outcomes): ?array {
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
	 */
	private static function mollieEvent(array $payload, array $outcomes): ?array {
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
	 */
	private static function resultResponse(string $result): JSONResponse {
		if ($result === self::RESULT_NOT_FOUND) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		$httpStatus = Http::STATUS_OK;
		if ($result === self::RESULT_NOOP) {
			$httpStatus = Http::STATUS_ACCEPTED;
		}

		return new JSONResponse(['status' => $result], $httpStatus);

	}//end resultResponse()

	/**
	 * The logger of the concrete webhook (never receives raw payment data).
	 *
	 * @return LoggerInterface
	 */
	abstract protected function webhookLogger(): LoggerInterface;

	/**
	 * Reconcile a normalised event and return the reconciliation result.
	 *
	 * @param string $gateway The gateway slug.
	 * @param array{paymentIntentId: string, outcome: string, errorCode: ?string, errorMessage: ?string} $event The normalised event.
	 *
	 * @return string
	 */
	abstract protected function reconcileEvent(string $gateway, array $event): string;
}//end class
