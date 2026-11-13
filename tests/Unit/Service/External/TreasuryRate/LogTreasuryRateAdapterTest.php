<?php

/**
 * Unit tests for LogTreasuryRateAdapter.
 *
 * Without an outbound connector every rate request is deferred: a dormant
 * snapshot with a zero value and a fresh id, and one info line naming it.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\TreasuryRate
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

namespace OCA\Shillinq\Tests\Unit\Service\External\TreasuryRate;

use OCA\Shillinq\Service\External\TreasuryRate\LogTreasuryRateAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Shillinq\Service\External\TreasuryRate\LogTreasuryRateAdapter
 * @uses \OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateResult
 */
final class LogTreasuryRateAdapterTest extends TestCase {

	/**
	 * The info lines the adapter logged, as [message, context] pairs.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The adapter, over a logger that records every info line.
	 *
	 * @return LogTreasuryRateAdapter The adapter.
	 */
	private function adapter(): LogTreasuryRateAdapter {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);

		return new LogTreasuryRateAdapter(logger: $logger);
	}//end adapter()

	/**
	 * A reference rate is deferred with a zero value under its code and date.
	 *
	 * @return void
	 */
	public function testAReferenceRateIsDeferred(): void {
		$result = $this->adapter()->fetchReferenceRate('EURIBOR-3M', '2026-11-01');

		$this->assertSame('SNAPSHOT_DEFERRED', $result->status);
		$this->assertMatchesRegularExpression('/^tr_ref_log_[0-9a-f]{14}$/', $result->rateId);
		$this->assertSame('EURIBOR-3M', $result->rateCode);
		$this->assertSame('0', $result->value);
		$this->assertSame('2026-11-01', $result->asOf);
		$this->assertSame('LOG_DEFERRED', $result->source);
		$this->assertTrue($result->dormant);
		$this->assertSame('no-outbound-connector-bound', $result->extras['reason']);
		$this->assertSame(
			[['Shillinq Treasury fetchReferenceRate deferred (no outbound connector bound)', ['rateId' => $result->rateId, 'rateCode' => 'EURIBOR-3M', 'asOf' => '2026-11-01']]],
			$this->logged
		);
	}//end testAReferenceRateIsDeferred()

	/**
	 * An FX spot is deferred under the base/quote pair.
	 *
	 * @return void
	 */
	public function testAnFxSpotIsDeferredUnderThePair(): void {
		$adapter = $this->adapter();
		$first   = $adapter->fetchFxSpot('EUR', 'USD', '2026-11-01');
		$second  = $adapter->fetchFxSpot('EUR', 'USD', '2026-11-01');

		$this->assertSame('SNAPSHOT_DEFERRED', $first->status);
		$this->assertSame('EUR/USD', $first->rateCode);
		$this->assertSame('0', $first->value);
		$this->assertMatchesRegularExpression('/^tr_fx_log_[0-9a-f]{14}$/', $first->rateId);
		$this->assertNotSame($first->rateId, $second->rateId, 'each snapshot gets its own id');
		$this->assertSame(['rateId' => $first->rateId, 'baseCurrency' => 'EUR', 'quoteCurrency' => 'USD', 'asOf' => '2026-11-01'], $this->logged[0][1]);
	}//end testAnFxSpotIsDeferredUnderThePair()


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
