<?php

/**
 * Unit tests for LogIb47Adapter.
 *
 * Without an outbound connector an IB47 submission is deferred, and the
 * recipient rows never reach the log: only their count, total and a
 * histogram of nature codes do.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\Ib47
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

namespace OCA\Shillinq\Tests\Unit\Service\External\Ib47;

use OCA\Shillinq\Service\External\Ib47\LogIb47Adapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Shillinq\Service\External\Ib47\LogIb47Adapter
 * @uses \OCA\Shillinq\Service\External\Ib47\Ib47SubmissionResult
 */
final class LogIb47AdapterTest extends TestCase {

	/**
	 * The info lines the adapter logged, as [message, context] pairs.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The adapter, over a logger that records every info line.
	 *
	 * @return LogIb47Adapter The adapter.
	 */
	private function adapter(): LogIb47Adapter {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);

		return new LogIb47Adapter(logger: $logger);
	}//end adapter()

	/**
	 * Recipients are replaced by an aggregate before logging.
	 *
	 * @return void
	 */
	public function testRecipientsAreRedactedToAnAggregate(): void {
		$result = $this->adapter()->submit(
			[
				'year' => 2026,
				'recipients' => [
					['bsn' => '123456782', 'natureCode' => 'A', 'paidAmount' => 100.5],
					['bsn' => '987654321', 'natureCode' => 'A', 'paidAmount' => '50'],
					['bsn' => '111222333', 'paidAmount' => 10],
					'not a row',
				],
			]
		);

		$this->assertSame('DEFERRED', $result->deliveryStatus);
		$this->assertMatchesRegularExpression('/^ib47-log-[0-9a-f]{16}$/', $result->reference);
		$this->assertTrue($result->dormant);
		$this->assertSame(
			[
				'reference' => $result->reference,
				'payload' => [
					'year' => 2026,
					'recipients' => [
						'_redacted' => true,
						'rowCount' => 3,
						'totalPaidAmount' => 160.5,
						'natureHistogram' => ['A' => 2, 'unknown' => 1],
					],
				],
			],
			$this->logged[0][1]
		);
		$this->assertStringNotContainsString('123456782', json_encode($this->logged));
	}//end testRecipientsAreRedactedToAnAggregate()

	/**
	 * A payload without a recipient list is logged as given.
	 *
	 * @return void
	 */
	public function testAPayloadWithoutRecipientsIsLoggedAsGiven(): void {
		$this->adapter()->submit(['year' => 2026, 'recipients' => 'none']);

		$this->assertSame(['year' => 2026, 'recipients' => 'none'], $this->logged[0][1]['payload']);
	}//end testAPayloadWithoutRecipientsIsLoggedAsGiven()


	/**
	 * The adapter reports itself dormant.
	 *
	 * @return void
	 */
	public function testTheAdapterIsDormant(): void {
		$this->assertTrue($this->adapter()->isDormant());
		$this->assertSame([], $this->logged);
	}//end testTheAdapterIsDormant()
}//end class
