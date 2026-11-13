<?php

/**
 * Unit tests for what LogIb47Adapter returns and logs at the edges.
 *
 * A deferred IB47 submission names the connector to bind; an empty recipient
 * list is still redacted to an (empty) aggregate.
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

use OCA\Shillinq\Service\External\Ib47\Ib47AdapterInterface;
use OCA\Shillinq\Service\External\Ib47\LogIb47Adapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for LogIb47Adapter's deferral.
 *
 * @covers \OCA\Shillinq\Service\External\Ib47\LogIb47Adapter
 * @uses \OCA\Shillinq\Service\External\Ib47\Ib47SubmissionResult
 */
class LogIb47AdapterDeferralTest extends TestCase {

	/**
	 * Context of every info line the adapter logged.
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $logged = [];

	/**
	 * The adapter, over a logger that records every info line and expects no warnings or errors.
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
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->never())->method('error');

		return new LogIb47Adapter(logger: $logger);
	}//end adapter()

	/**
	 * A deferred submission names the connector to bind and logs one line.
	 *
	 * @return void
	 */
	public function testASubmissionExplainsItsDeferral(): void {
		$adapter = $this->adapter();
		$result  = $adapter->submit(['year' => 2026]);

		$this->assertInstanceOf(Ib47AdapterInterface::class, $adapter);
		$this->assertSame('no-outbound-connector-bound', $result->extras['reason']);
		$this->assertStringContainsString('`belastingdienst-ib47`', $result->extras['note']);
		$this->assertSame('Shillinq Belastingdienst IB47 submission deferred (no outbound connector bound)', $this->logged[0][0]);
		$this->assertSame(['reference' => $result->reference, 'payload' => ['year' => 2026]], $this->logged[0][1]);
	}//end testASubmissionExplainsItsDeferral()

	/**
	 * An empty recipient list is redacted to an empty aggregate, not logged as given.
	 *
	 * @return void
	 */
	public function testAnEmptyRecipientListIsStillRedacted(): void {
		$this->adapter()->submit(['recipients' => []]);

		$this->assertSame(
			['_redacted' => true, 'rowCount' => 0, 'totalPaidAmount' => 0.0, 'natureHistogram' => []],
			$this->logged[0][1]['payload']['recipients']
		);
	}//end testAnEmptyRecipientListIsStillRedacted()

	/**
	 * A nature code is counted as text, and a row without an amount adds nothing.
	 *
	 * @return void
	 */
	public function testNatureCodesAreCountedAsText(): void {
		$this->adapter()->submit(['recipients' => [['natureCode' => 1, 'paidAmount' => 5], ['natureCode' => '1']]]);

		$this->assertSame(
			['_redacted' => true, 'rowCount' => 2, 'totalPaidAmount' => 5.0, 'natureHistogram' => ['1' => 2]],
			$this->logged[0][1]['payload']['recipients']
		);
	}//end testNatureCodesAreCountedAsText()
}//end class
