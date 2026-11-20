<?php

/**
 * Unit tests for the FX-spot fallback of TreasuryRateService: an adapter that
 * throws yields a dormant synthetic snapshot for the pair, logged once and
 * served from the cache afterwards.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Treasury
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Treasury;

use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateAdapterInterface;
use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateResult;
use OCA\Shillinq\Service\Treasury\TreasuryRateService;
use OCA\Shillinq\Service\Treasury\TreasuryRateSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

// phpcs:disable CustomSniffs.Functions.NamedParameters

#[CoversClass(TreasuryRateService::class)]
#[UsesClass(TreasuryRateResult::class)]
#[UsesClass(TreasuryRateSnapshot::class)]
final class TreasuryRateServiceFxFallbackTest extends TestCase {
	/**
	 * A throwing FX-spot adapter gives a dormant snapshot for the pair and
	 * date, logs the failure once, and the cached snapshot answers again.
	 *
	 * @return void
	 */
	public function testAThrowingFxAdapterYieldsACachedDormantSnapshot(): void {
		$adapter = $this->createMock(TreasuryRateAdapterInterface::class);
		$adapter->expects(self::once())->method('fetchFxSpot')->with('USD', 'EUR', '2026-03-31')
			->willThrowException(new RuntimeException('transport down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Shillinq TreasuryRateService: FX-spot fetch threw — emitting dormant snapshot',
			['pair' => 'USD/EUR', 'asOf' => '2026-03-31', 'exception' => 'transport down']
		);
		$service = new TreasuryRateService($adapter, $logger);

		$snapshot = $service->getFxSpot('USD', 'EUR', '2026-03-31');

		self::assertTrue($snapshot->isDormant());
		self::assertFalse($snapshot->isLive());
		self::assertSame('0', $snapshot->value);
		self::assertSame('LOG_DEFERRED', $snapshot->source);
		self::assertSame('USD/EUR', $snapshot->rateCode);
		self::assertSame('2026-03-31', $snapshot->asOf);
		self::assertSame('tr_synth_deferred', $snapshot->rateId);
		self::assertSame($snapshot, $service->getFxSpot('USD', 'EUR', '2026-03-31'));

	}//end testAThrowingFxAdapterYieldsACachedDormantSnapshot()
}//end class
