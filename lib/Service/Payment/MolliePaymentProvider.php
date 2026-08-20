<?php

/**
 * Mollie-backed implementation of PaymentProviderInterface (portal-payment-initiation).
 *
 * The shipped binding for the portal pay-now flow (REQ-SPPI-001). Delegates
 * EVERY call to the existing, verified `MolliePaymentAdapterInterface` — no
 * fork, no second Mollie client — and forces `method: 'ideal'` regardless of
 * what the caller requests, because iDEAL is the required Dutch MKB rail for
 * this flow. The Mollie API key / test-mode flag are sourced from app config
 * by whichever `MolliePaymentAdapterInterface` binding is active
 * (`Application::register()`); this class never reads config directly.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Payment
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Payment;

use OCA\Shillinq\Service\External\Mollie\MolliePaymentAdapterInterface;

/**
 * Delegates to MolliePaymentAdapterInterface, projecting its result onto
 * PaymentSessionResult.
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-001)
 */
class MolliePaymentProvider implements PaymentProviderInterface {
	/**
	 * The only method this port ever requests (REQ-SPPI-001 — iDEAL is the
	 * required Dutch MKB rail for the portal pay-now flow).
	 *
	 * @var string
	 */
	private const METHOD_IDEAL = 'ideal';

	/**
	 * Construct the provider.
	 *
	 * @param MolliePaymentAdapterInterface $mollie The existing, verified Mollie adapter port.
	 */
	public function __construct(
		private readonly MolliePaymentAdapterInterface $mollie,
	) {
	}//end __construct()

	/**
	 * Request an iDEAL payment session through the bound Mollie adapter.
	 *
	 * @param PaymentSessionRequest $request The session request.
	 *
	 * @return PaymentSessionResult The session outcome.
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-001)
	 */
	public function createSession(PaymentSessionRequest $request): PaymentSessionResult {
		$result = $this->mollie->createPayment(
			[
				'amount' => [
					'value' => number_format($request->amount, 2, '.', ''),
					'currency' => $request->currency,
				],
				'description' => $request->description,
				'redirectUrl' => $request->redirectUrl,
				'webhookUrl' => $request->webhookUrl,
				'method' => self::METHOD_IDEAL,
				'metadata' => $request->metadata,
			]
		);

		return new PaymentSessionResult(
			dormant: $result->dormant,
			checkoutUrl: $result->checkoutUrl,
			paymentIntentId: $result->molliePaymentId,
			extras: $result->extras,
		);

	}//end createSession()

	/**
	 * Whether the bound Mollie adapter is dormant.
	 *
	 * @return bool True when the bound adapter is a log-only stub.
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-001)
	 */
	public function isDormant(): bool {
		return $this->mollie->isDormant();
	}//end isDormant()
}//end class
