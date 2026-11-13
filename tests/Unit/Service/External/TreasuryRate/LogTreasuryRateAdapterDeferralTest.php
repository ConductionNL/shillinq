<?php

/**
 * Unit tests for what LogTreasuryRateAdapter tells the caller about a deferral.
 *
 * Every deferred snapshot says why it was deferred and which manual-entry
 * path carries the value meanwhile, and logs exactly one info line.
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
use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateAdapterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for LogTreasuryRateAdapter's deferral notes.
 *
 * @covers \OCA\Shillinq\Service\External\TreasuryRate\LogTreasuryRateAdapter
 * @uses \OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateResult
 */
class LogTreasuryRateAdapterDeferralTest extends TestCase {

	/**
	 * The adapter over a logger that expects one info line with the given message.
	 *
	 * @param string $message The expected log message.
	 *
	 * @return LogTreasuryRateAdapter The adapter.
	 */
	private function adapterLogging(string $message): LogTreasuryRateAdapter {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($message, $this->isType('array'));
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->never())->method('error');

		return new LogTreasuryRateAdapter(logger: $logger);
	}//end adapterLogging()

	/**
	 * The adapter is the treasury rate adapter the app binds by default.
	 *
	 * @return void
	 */
	public function testItIsATreasuryRateAdapter(): void {
		$adapter = new LogTreasuryRateAdapter(logger: $this->createMock(LoggerInterface::class));

		$this->assertInstanceOf(TreasuryRateAdapterInterface::class, $adapter);
	}//end testItIsATreasuryRateAdapter()

	/**
	 * A deferred reference rate names the connector to bind and the manual interest-rate path.
	 *
	 * @return void
	 */
	public function testAReferenceRateExplainsItsDeferral(): void {
		$result = $this->adapterLogging('Shillinq Treasury fetchReferenceRate deferred (no outbound connector bound)')
			->fetchReferenceRate('ESTR', '2026-10-31');

		$this->assertSame(['reason', 'note'], array_keys($result->extras));
		$this->assertSame('no-outbound-connector-bound', $result->extras['reason']);
		$this->assertStringContainsString('`treasury-rates`', $result->extras['note']);
		$this->assertStringContainsString('IntercompanyLoan.interestRate', $result->extras['note']);
		$this->assertStringContainsString('REQ-IHB-004', $result->extras['note']);
	}//end testAReferenceRateExplainsItsDeferral()

	/**
	 * A deferred FX spot is a dormant, zero-valued, log-sourced snapshot that names the manual spot-rate path.
	 *
	 * @return void
	 */
	public function testAnFxSpotExplainsItsDeferral(): void {
		$result = $this->adapterLogging('Shillinq Treasury fetchFxSpot deferred (no outbound connector bound)')
			->fetchFxSpot('GBP', 'EUR', '2026-10-31');

		$this->assertSame('SNAPSHOT_DEFERRED', $result->status);
		$this->assertSame('GBP/EUR', $result->rateCode);
		$this->assertSame('0', $result->value);
		$this->assertSame('2026-10-31', $result->asOf);
		$this->assertSame('LOG_DEFERRED', $result->source);
		$this->assertTrue($result->dormant);
		$this->assertSame('no-outbound-connector-bound', $result->extras['reason']);
		$this->assertStringContainsString('`treasury-rates`', $result->extras['note']);
		$this->assertStringContainsString('FXPosition.spotRate', $result->extras['note']);
	}//end testAnFxSpotExplainsItsDeferral()

	/**
	 * Reference-rate and FX ids are drawn fresh per call and never collide across the two kinds.
	 *
	 * @return void
	 */
	public function testIdsAreFreshPerSnapshot(): void {
		$adapter = new LogTreasuryRateAdapter(logger: $this->createMock(LoggerInterface::class));
		$ids     = [];
		for ($i = 0; $i < 5; $i++) {
			$ids[] = $adapter->fetchReferenceRate('ESTR', '2026-10-31')->rateId;
			$ids[] = $adapter->fetchFxSpot('EUR', 'USD', '2026-10-31')->rateId;
		}

		$this->assertCount(10, array_unique($ids));
		$this->assertCount(5, preg_grep('/^tr_ref_log_/', $ids));
		$this->assertCount(5, preg_grep('/^tr_fx_log_/', $ids));
	}//end testIdsAreFreshPerSnapshot()
}//end class
