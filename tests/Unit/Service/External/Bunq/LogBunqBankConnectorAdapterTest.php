<?php

/**
 * Unit tests for LogBunqBankConnectorAdapter.
 *
 * Without an outbound connector a bunq transaction pull and a consent renewal
 * are deferred under the connection they were asked for, with no transactions.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\Bunq
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

namespace OCA\Shillinq\Tests\Unit\Service\External\Bunq;

use OCA\Shillinq\Service\External\Bunq\LogBunqBankConnectorAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Shillinq\Service\External\Bunq\LogBunqBankConnectorAdapter
 * @uses \OCA\Shillinq\Service\External\Bunq\BunqSyncResult
 */
final class LogBunqBankConnectorAdapterTest extends TestCase {

	/**
	 * The info lines the adapter logged, as [message, context] pairs.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The adapter, over a logger that records every info line.
	 *
	 * @return LogBunqBankConnectorAdapter The adapter.
	 */
	private function adapter(): LogBunqBankConnectorAdapter {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);

		return new LogBunqBankConnectorAdapter(logger: $logger);
	}//end adapter()

	/**
	 * A pull is deferred with no transactions.
	 *
	 * @return void
	 */
	public function testAPullIsDeferred(): void {
		$result = $this->adapter()->pullTransactions('conn-1', ['since' => '2026-11-01']);

		$this->assertSame('SYNC_DEFERRED', $result->syncStatus);
		$this->assertSame('conn-1', $result->connectionReference);
		$this->assertSame(0, $result->transactionCount);
		$this->assertTrue($result->dormant);
		$this->assertArrayNotHasKey('scaUrl', $result->extras);
		$this->assertSame(
			[['Shillinq Bunq pullTransactions deferred (no outbound connector bound)', ['connectionReference' => 'conn-1', 'context' => ['since' => '2026-11-01']]]],
			$this->logged
		);
	}//end testAPullIsDeferred()

	/**
	 * A consent renewal is deferred with an empty SCA URL.
	 *
	 * @return void
	 */
	public function testAConsentRenewalIsDeferredWithoutAnScaUrl(): void {
		$result = $this->adapter()->renewConsent('conn-2');

		$this->assertSame('SYNC_DEFERRED', $result->syncStatus);
		$this->assertSame('conn-2', $result->connectionReference);
		$this->assertSame('', $result->extras['scaUrl']);
		$this->assertSame('Shillinq Bunq renewConsent deferred (no outbound connector bound)', $this->logged[0][0]);
		$this->assertSame(['connectionReference' => 'conn-2', 'context' => []], $this->logged[0][1]);
	}//end testAConsentRenewalIsDeferredWithoutAnScaUrl()


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
