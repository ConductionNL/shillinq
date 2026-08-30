<?php

/**
 * Unit tests for MolliePaymentProvider (portal-payment-initiation, REQ-SPPI-001).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Payment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-001)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Payment;

use OCA\Shillinq\Service\External\Mollie\MolliePaymentAdapterInterface;
use OCA\Shillinq\Service\External\Mollie\MolliePaymentResult;
use OCA\Shillinq\Service\Payment\MolliePaymentProvider;
use OCA\Shillinq\Service\Payment\PaymentSessionRequest;
use PHPUnit\Framework\TestCase;

/**
 * Pins that the port ALWAYS requests iDEAL, delegates to the existing
 * verified Mollie adapter (no fork), and honestly relays dormancy.
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-001)
 */
final class MolliePaymentProviderTest extends TestCase {
	/**
	 * A live (non-dormant) adapter call always requests method: 'ideal',
	 * regardless of the caller — this is the required Dutch MKB rail.
	 *
	 * @return void
	 */
	public function testCreateSessionAlwaysRequestsIdeal(): void {
		$mollie = $this->createMock(MolliePaymentAdapterInterface::class);
		$mollie->expects($this->once())
			->method('createPayment')
			->with(
				$this->callback(
					static function (array $payload): bool {
						return ($payload['method'] ?? null) === 'ideal'
							&& $payload['amount']['value'] === '125.50'
							&& $payload['amount']['currency'] === 'EUR';
					}
				)
			)
			->willReturn(
				new MolliePaymentResult(
					paymentStatus: 'open',
					molliePaymentId: 'tr_live_1',
					checkoutUrl: 'https://mollie.example/checkout/tr_live_1',
					dormant: false,
				)
			);
		$mollie->method('isDormant')->willReturn(false);

		$provider = new MolliePaymentProvider($mollie);
		$result = $provider->createSession(
			new PaymentSessionRequest(
				amount: 125.5,
				currency: 'EUR',
				description: 'Invoice INV-1',
				redirectUrl: 'https://instance.example/',
				webhookUrl: 'https://instance.example/webhook',
				method: 'bancontact', // caller's hint is ignored — always ideal.
			)
		);

		self::assertFalse($result->dormant);
		self::assertSame('https://mollie.example/checkout/tr_live_1', $result->checkoutUrl);
		self::assertSame('tr_live_1', $result->paymentIntentId);
		self::assertFalse($provider->isDormant());
	}//end testCreateSessionAlwaysRequestsIdeal()

	/**
	 * A dormant bound adapter yields no checkout URL — honest degradation,
	 * never a fabricated URL (REQ-SPPI-001).
	 *
	 * @return void
	 */
	public function testDormantAdapterYieldsNoCheckoutUrl(): void {
		$mollie = $this->createMock(MolliePaymentAdapterInterface::class);
		$mollie->method('createPayment')->willReturn(
			new MolliePaymentResult(
				paymentStatus: 'PAYMENT_DEFERRED',
				molliePaymentId: 'tr_log_deferred',
				checkoutUrl: '',
				dormant: true,
			)
		);
		$mollie->method('isDormant')->willReturn(true);

		$provider = new MolliePaymentProvider($mollie);
		$result = $provider->createSession(
			new PaymentSessionRequest(
				amount: 10.0,
				currency: 'EUR',
				description: 'Invoice INV-2',
				redirectUrl: 'https://instance.example/',
				webhookUrl: 'https://instance.example/webhook',
				method: 'ideal',
			)
		);

		self::assertTrue($result->dormant);
		self::assertSame('', $result->checkoutUrl);
		self::assertSame('tr_log_deferred', $result->paymentIntentId);
		self::assertTrue($provider->isDormant());
	}//end testDormantAdapterYieldsNoCheckoutUrl()
}//end class
