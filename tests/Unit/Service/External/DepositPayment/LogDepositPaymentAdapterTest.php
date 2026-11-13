<?php

/**
 * Unit tests for LogDepositPaymentAdapter (dormant DepositPayment port).
 *
 * Pins the dormant contract every lifecycle operation reports: the
 * synthetic lifecycle state, the PAYMENT_DEFERRED gateway status, the
 * LOG_DEFERRED gateway slug and dormant=true, plus the structured log
 * entry and the redaction of redirect / webhook URLs on requestPayment.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\DepositPayment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookings-deposits/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\External\DepositPayment;

use OCA\Shillinq\Service\External\DepositPayment\DepositPaymentAdapterInterface;
use OCA\Shillinq\Service\External\DepositPayment\DepositPaymentResult;
use OCA\Shillinq\Service\External\DepositPayment\LogDepositPaymentAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the dormant DepositPayment adapter's results and log entries.
 */
#[CoversClass(LogDepositPaymentAdapter::class)]
#[UsesClass(DepositPaymentResult::class)]
final class LogDepositPaymentAdapterTest extends TestCase {

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Adapter under test.
	 *
	 * @var LogDepositPaymentAdapter
	 */
	private LogDepositPaymentAdapter $adapter;

	/**
	 * Build the adapter with a mocked logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->adapter = new LogDepositPaymentAdapter(logger: $this->logger);
	}//end setUp()

	/**
	 * The adapter implements the port and reports itself dormant.
	 *
	 * @return void
	 */
	public function testImplementsThePortAndIsDormant(): void {
		$this->assertInstanceOf(expected: DepositPaymentAdapterInterface::class, actual: $this->adapter);
		$this->assertTrue(condition: $this->adapter->isDormant());
	}//end testImplementsThePortAndIsDormant()

	/**
	 * requestPayment reports pending with a synthetic intent id and logs the
	 * payload without the redirect and webhook URLs.
	 *
	 * @return void
	 */
	public function testRequestPaymentIsPendingAndRedactsUrls(): void {
		$logged = [];
		$this->logger->expects($this->once())
			->method('info')
			->willReturnCallback(
				function (string $message, array $context) use (&$logged): void {
					$logged = ['message' => $message, 'context' => $context];
				}
			);

		$result = $this->adapter->requestPayment(
			payload: [
				'amount' => ['value' => '25.00', 'currency' => 'EUR'],
				'description' => 'Deposit booking 42',
				'redirectUrl' => 'https://example.test/return?token=abc',
				'webhookUrl' => 'https://example.test/hook?token=abc',
			]
		);

		$this->assertSame(expected: 'pending', actual: $result->lifecycleState);
		$this->assertSame(expected: 'PAYMENT_DEFERRED', actual: $result->gatewayStatus);
		$this->assertSame(expected: 'LOG_DEFERRED', actual: $result->gateway);
		$this->assertSame(expected: '', actual: $result->paymentLink);
		$this->assertTrue(condition: $result->dormant);
		$this->assertMatchesRegularExpression(pattern: '/^dep_log_[0-9a-f]{14}$/', string: $result->paymentIntentId);
		$this->assertSame(expected: 'no-outbound-psp-bound', actual: $result->extras['reason']);
		$this->assertArrayHasKey(key: 'note', array: $result->extras);

		$this->assertStringContainsString(needle: 'requestPayment deferred', haystack: $logged['message']);
		$this->assertSame(expected: $result->paymentIntentId, actual: $logged['context']['paymentIntentId']);
		$this->assertSame(
			expected: [
				'amount' => ['value' => '25.00', 'currency' => 'EUR'],
				'description' => 'Deposit booking 42',
			],
			actual: $logged['context']['payload']
		);
	}//end testRequestPaymentIsPendingAndRedactsUrls()

	/**
	 * Two requests mint two different intent ids.
	 *
	 * @return void
	 */
	public function testRequestPaymentMintsAFreshIntentIdEachCall(): void {
		$first = $this->adapter->requestPayment(payload: []);
		$second = $this->adapter->requestPayment(payload: []);

		$this->assertNotSame(expected: $first->paymentIntentId, actual: $second->paymentIntentId);
	}//end testRequestPaymentMintsAFreshIntentIdEachCall()

	/**
	 * capturePayment reports a synthetic captured state for the given intent
	 * and logs the full payload.
	 *
	 * @return void
	 */
	public function testCapturePaymentIsCapturedAndLogsPayload(): void {
		$payload = ['amount' => ['value' => '15.00', 'currency' => 'EUR']];
		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains(string: 'capturePayment deferred'),
				['paymentIntentId' => 'tr_123', 'payload' => $payload]
			);

		$result = $this->adapter->capturePayment(paymentIntentId: 'tr_123', payload: $payload);

		$this->assertSame(expected: 'captured', actual: $result->lifecycleState);
		$this->assertSame(expected: 'PAYMENT_DEFERRED', actual: $result->gatewayStatus);
		$this->assertSame(expected: 'tr_123', actual: $result->paymentIntentId);
		$this->assertSame(expected: '', actual: $result->paymentLink);
		$this->assertSame(expected: 'LOG_DEFERRED', actual: $result->gateway);
		$this->assertTrue(condition: $result->dormant);
		$this->assertSame(expected: 'no-outbound-psp-bound', actual: $result->extras['reason']);
	}//end testCapturePaymentIsCapturedAndLogsPayload()

	/**
	 * fetchStatus keeps the record pending and logs both ids.
	 *
	 * @return void
	 */
	public function testFetchStatusStaysPendingAndLogsBothIds(): void {
		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains(string: 'fetchStatus deferred'),
				['paymentIntentId' => 'tr_456', 'depositPaymentId' => 'dp-1']
			);

		$result = $this->adapter->fetchStatus(paymentIntentId: 'tr_456', depositPaymentId: 'dp-1');

		$this->assertSame(expected: 'pending', actual: $result->lifecycleState);
		$this->assertSame(expected: 'PAYMENT_DEFERRED', actual: $result->gatewayStatus);
		$this->assertSame(expected: 'tr_456', actual: $result->paymentIntentId);
		$this->assertSame(expected: 'LOG_DEFERRED', actual: $result->gateway);
		$this->assertTrue(condition: $result->dormant);
		$this->assertSame(expected: 'no-outbound-psp-bound', actual: $result->extras['reason']);
	}//end testFetchStatusStaysPendingAndLogsBothIds()

	/**
	 * initiateRefund reports a synthetic voided state and logs the payload.
	 *
	 * @return void
	 */
	public function testInitiateRefundIsVoidedAndLogsPayload(): void {
		$payload = ['amount' => ['value' => '5.00', 'currency' => 'EUR']];
		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains(string: 'initiateRefund deferred'),
				['paymentIntentId' => 'tr_789', 'payload' => $payload]
			);

		$result = $this->adapter->initiateRefund(paymentIntentId: 'tr_789', payload: $payload);

		$this->assertSame(expected: 'voided', actual: $result->lifecycleState);
		$this->assertSame(expected: 'PAYMENT_DEFERRED', actual: $result->gatewayStatus);
		$this->assertSame(expected: 'tr_789', actual: $result->paymentIntentId);
		$this->assertSame(expected: '', actual: $result->paymentLink);
		$this->assertSame(expected: 'LOG_DEFERRED', actual: $result->gateway);
		$this->assertTrue(condition: $result->dormant);
		$this->assertSame(expected: 'no-outbound-psp-bound', actual: $result->extras['reason']);
	}//end testInitiateRefundIsVoidedAndLogsPayload()
}//end class
