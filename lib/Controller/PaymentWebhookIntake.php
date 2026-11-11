<?php

/**
 * Shillinq Payment Webhook Intake
 *
 * Provider signature verification over the raw body and payload decoding, as
 * the deposit and payment-request webhooks share them.
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
 * Signature verification and payload decoding for payment webhooks.
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
	 * How far, in seconds, a Stripe signature timestamp may lie from now
	 * (Stripe's documented default tolerance).
	 */
	public const STRIPE_TOLERANCE_SECONDS = 300;

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
			return $this->verifyStripeSignature(provided: $provided, rawBody: $rawBody, secret: $secret);
		}

		$expected = hash_hmac('sha256', $rawBody, $secret);

		return hash_equals($expected, strtolower(trim($provided)));

	}//end verifySignature()

	/**
	 * Verify a Stripe "t=<unix>,v1=<hex>[,v1=<hex>]" header the way Stripe
	 * signs it: HMAC-SHA256 over "<t>.<raw body>", accepted when any v1 value
	 * matches and t lies within the tolerance of now.
	 *
	 * @param string $provided The Stripe-Signature header.
	 * @param string $rawBody The raw request body.
	 * @param string $secret The endpoint secret.
	 *
	 * @return bool
	 */
	private function verifyStripeSignature(string $provided, string $rawBody, string $secret): bool {
		$timestamp = null;
		$signatures = [];
		foreach (explode(',', $provided) as $part) {
			[$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
			if ($key === 't' && ctype_digit($value) === true) {
				$timestamp = $value;
			} else if ($key === 'v1' && $value !== '') {
				$signatures[] = strtolower($value);
			}
		}

		if ($timestamp === null || $signatures === []) {
			return false;
		}

		if (abs(time() - (int)$timestamp) > self::STRIPE_TOLERANCE_SECONDS) {
			return false;
		}

		$expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
		foreach ($signatures as $signature) {
			if (hash_equals($expected, $signature) === true) {
				return true;
			}
		}

		return false;

	}//end verifyStripeSignature()

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

}//end class
