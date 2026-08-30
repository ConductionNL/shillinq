<?php

/**
 * Unit tests for LogMolliePaymentAdapter (narrowed dormant port).
 *
 * Guards the orphan-auth-remediation deletion: the adapter port no longer
 * carries an inbound `verifyWebhook()` method. Inbound Mollie webhook
 * signature verification is owned exclusively by the fail-closed HMAC gate
 * on the `#[PublicPage]` webhook controllers (PaymentRequestWebhookController
 * + DepositWebhookController — REQ-APL-004 "ONE shared surface, never a
 * fork"). These tests pin the surviving dormant contract (createPayment
 * returns PAYMENT_DEFERRED, isDormant()) and assert the removed method is not
 * silently re-introduced.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\Mollie
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/orphan-auth-remediation/specs/orphan-auth-remediation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\External\Mollie;

use OCA\Shillinq\Service\External\Mollie\LogMolliePaymentAdapter;
use OCA\Shillinq\Service\External\Mollie\MolliePaymentAdapterInterface;
use OCA\Shillinq\Service\External\Mollie\MolliePaymentResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Verifies the narrowed dormant Mollie port and the removal of verifyWebhook().
 */
final class LogMolliePaymentAdapterTest extends TestCase {
	/**
	 * The dormant default's createPayment returns a deferred, dormant result
	 * without contacting Mollie.
	 *
	 * @return void
	 */
	public function testCreatePaymentIsDormantAndDeferred(): void {
		$adapter = new LogMolliePaymentAdapter(logger: new NullLogger());

		$result = $adapter->createPayment(
			payload: [
				'amount' => ['value' => '10.00', 'currency' => 'EUR'],
				'description' => 'Invoice 2026-0001',
				'redirectUrl' => 'https://example.test/return',
				'webhookUrl' => 'https://example.test/webhook',
			]
		);

		$this->assertInstanceOf(expected: MolliePaymentResult::class, actual: $result);
		$this->assertSame(expected: 'PAYMENT_DEFERRED', actual: $result->paymentStatus);
		$this->assertTrue(condition: $result->dormant);
		$this->assertTrue(condition: $adapter->isDormant());
	}//end testCreatePaymentIsDormantAndDeferred()

	/**
	 * The port is inbound-verification-free: neither the interface nor the
	 * dormant default may re-declare verifyWebhook(). Inbound HMAC verification
	 * lives solely on the webhook controllers (REQ-APL-004).
	 *
	 * @return void
	 */
	public function testWebhookVerificationIsNotOnThePort(): void {
		$this->assertFalse(
			condition: method_exists(MolliePaymentAdapterInterface::class, 'verifyWebhook'),
			message: 'verifyWebhook() must not be re-introduced on the Mollie port; '
				. 'the controller HMAC gate is the sole inbound verifier.'
		);
		$this->assertFalse(
			condition: method_exists(LogMolliePaymentAdapter::class, 'verifyWebhook'),
			message: 'LogMolliePaymentAdapter must not re-declare verifyWebhook().'
		);
	}//end testWebhookVerificationIsNotOnThePort()
}//end class
