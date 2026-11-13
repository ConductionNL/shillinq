<?php

/**
 * Unit tests for what LogMolliePaymentAdapter logs and returns.
 *
 * A deferred payment carries no checkout URL, a fresh payment id and the
 * connector to bind; the redirect and webhook URLs never reach the log.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\External\Mollie;

use OCA\Shillinq\Service\External\Mollie\LogMolliePaymentAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for LogMolliePaymentAdapter's deferral.
 *
 * @covers \OCA\Shillinq\Service\External\Mollie\LogMolliePaymentAdapter
 * @uses \OCA\Shillinq\Service\External\Mollie\MolliePaymentResult
 */
class LogMolliePaymentAdapterDeferralTest extends TestCase {

	/**
	 * Context of every info line the adapter logged.
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $logged = [];

	/**
	 * The adapter, over a logger that records every info line and expects no warnings or errors.
	 *
	 * @return LogMolliePaymentAdapter The adapter.
	 */
	private function adapter(): LogMolliePaymentAdapter {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->never())->method('error');

		return new LogMolliePaymentAdapter(logger: $logger);
	}//end adapter()

	/**
	 * The redirect and webhook URLs are left out of the log; the rest of the payload is kept.
	 *
	 * @return void
	 */
	public function testTheCallbackUrlsAreNotLogged(): void {
		$result = $this->adapter()->createPayment(
			[
				'amount'      => ['currency' => 'EUR', 'value' => '10.00'],
				'description' => 'Factuur 2026-001',
				'redirectUrl' => 'https://shop.example/return?token=abc',
				'webhookUrl'  => 'https://cloud.example/webhook?sig=def',
			]
		);

		$this->assertSame('Shillinq Mollie createPayment deferred (no outbound connector bound)', $this->logged[0][0]);
		$this->assertSame(
			[
				'molliePaymentId' => $result->molliePaymentId,
				'payload'         => ['amount' => ['currency' => 'EUR', 'value' => '10.00'], 'description' => 'Factuur 2026-001'],
			],
			$this->logged[0][1]
		);
		$this->assertStringNotContainsString('token=abc', json_encode($this->logged));
		$this->assertStringNotContainsString('sig=def', json_encode($this->logged));
	}//end testTheCallbackUrlsAreNotLogged()

	/**
	 * A deferred payment has no checkout URL, a fresh id and names the connector to bind.
	 *
	 * @return void
	 */
	public function testADeferredPaymentExplainsItself(): void {
		$adapter = $this->adapter();
		$first   = $adapter->createPayment([]);
		$second  = $adapter->createPayment([]);

		$this->assertSame('', $first->checkoutUrl);
		$this->assertMatchesRegularExpression('/^tr_log_[0-9a-f]{14}$/', $first->molliePaymentId);
		$this->assertNotSame($first->molliePaymentId, $second->molliePaymentId);
		$this->assertSame('no-outbound-connector-bound', $first->extras['reason']);
		$this->assertStringContainsString('`mollie-payments`', $first->extras['note']);
		$this->assertSame(['molliePaymentId' => $first->molliePaymentId, 'payload' => []], $this->logged[0][1]);
	}//end testADeferredPaymentExplainsItself()
}//end class
