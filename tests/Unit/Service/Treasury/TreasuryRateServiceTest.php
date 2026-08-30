<?php

/**
 * Unit tests for `TreasuryRateService`.
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
 * @spec openspec/changes/bookkeeping-treasury-ihb/tasks.md#external-adapter
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the consumer-side facade over `TreasuryRateAdapterInterface`.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TreasuryRateServiceTest extends TestCase {
	/**
	 * Mock adapter.
	 *
	 * @var TreasuryRateAdapterInterface&MockObject
	 */
	private TreasuryRateAdapterInterface&MockObject $adapter;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Subject under test.
	 *
	 * @var TreasuryRateService
	 */
	private TreasuryRateService $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->adapter = $this->createMock(TreasuryRateAdapterInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new TreasuryRateService($this->adapter, $this->logger);
	}//end setUp()

	/**
	 * Build a live adapter result.
	 *
	 * @param string $value Decimal rate.
	 *
	 * @return TreasuryRateResult
	 */
	private function liveResult(string $value): TreasuryRateResult {
		return new TreasuryRateResult(
			status: 'OK',
			rateId: 'tr_live',
			rateCode: 'X',
			value: $value,
			asOf: '2026-06-10',
			source: 'ECB',
			dormant: false,
		);
	}//end liveResult()

	/**
	 * Build a dormant adapter result.
	 *
	 * @return TreasuryRateResult
	 */
	private function deferredResult(): TreasuryRateResult {
		return new TreasuryRateResult(
			status: 'SNAPSHOT_DEFERRED',
			rateId: 'tr_log',
			rateCode: 'X',
			value: '0',
			asOf: '2026-06-10',
			source: 'LOG_DEFERRED',
			dormant: true,
		);
	}//end deferredResult()

	/**
	 * Live snapshot — service returns a non-dormant snapshot carrying the value.
	 *
	 * @return void
	 */
	public function testGetReferenceRateReturnsLiveSnapshot(): void {
		$this->adapter->expects(self::once())
			->method('fetchReferenceRate')
			->with('EURIBOR-3M', '2026-06-10')
			->willReturn($this->liveResult('0.0392'));

		$snapshot = $this->service->getReferenceRate('EURIBOR-3M', '2026-06-10');

		self::assertInstanceOf(TreasuryRateSnapshot::class, $snapshot);
		self::assertTrue($snapshot->isLive());
		self::assertFalse($snapshot->isDormant());
		self::assertSame('0.0392', $snapshot->value);
		self::assertEqualsWithDelta(0.0392, $snapshot->asFloat(), 0.0001);
		self::assertSame('EURIBOR-3M', $snapshot->rateCode);
	}//end testGetReferenceRateReturnsLiveSnapshot()

	/**
	 * Dormant adapter → dormant snapshot. The synthetic `'0'` value is
	 * preserved BUT the dormant flag is set so callers branch.
	 *
	 * @return void
	 */
	public function testGetReferenceRateConvertsDeferredToDormant(): void {
		$this->adapter->expects(self::once())
			->method('fetchReferenceRate')
			->willReturn($this->deferredResult());

		$snapshot = $this->service->getReferenceRate('EURIBOR-3M', '2026-06-10');

		self::assertTrue($snapshot->isDormant());
		self::assertFalse($snapshot->isLive());
		self::assertSame('0', $snapshot->value);
		self::assertSame('LOG_DEFERRED', $snapshot->source);
	}//end testGetReferenceRateConvertsDeferredToDormant()

	/**
	 * Adapter throws → service catches and emits a dormant synthetic snapshot.
	 * Callers never see the exception.
	 *
	 * @return void
	 */
	public function testGetReferenceRateAbsorbsAdapterException(): void {
		$this->adapter->expects(self::once())
			->method('fetchReferenceRate')
			->willThrowException(new RuntimeException('transport down'));

		$snapshot = $this->service->getReferenceRate('EURIBOR-3M', '2026-06-10');

		self::assertTrue($snapshot->isDormant());
		self::assertSame('LOG_DEFERRED', $snapshot->source);
		self::assertSame('tr_synth_deferred', $snapshot->rateId);
	}//end testGetReferenceRateAbsorbsAdapterException()

	/**
	 * Per-request memoisation: two reads of the same tuple hit the adapter once.
	 *
	 * @return void
	 */
	public function testGetReferenceRateIsMemoisedPerRequest(): void {
		$this->adapter->expects(self::once()) // critical: once, not twice
			->method('fetchReferenceRate')
			->willReturn($this->liveResult('0.0421'));

		$a = $this->service->getReferenceRate('SOFR', '2026-06-10');
		$b = $this->service->getReferenceRate('SOFR', '2026-06-10');

		self::assertSame($a->value, $b->value);
		self::assertSame('0.0421', $a->value);
	}//end testGetReferenceRateIsMemoisedPerRequest()

	/**
	 * The FX-spot variant funnels through the same shape.
	 *
	 * @return void
	 */
	public function testGetFxSpotReturnsLiveSnapshot(): void {
		$this->adapter->expects(self::once())
			->method('fetchFxSpot')
			->with('EUR', 'USD', '2026-06-10')
			->willReturn($this->liveResult('1.0823'));

		$snapshot = $this->service->getFxSpot('EUR', 'USD', '2026-06-10');

		self::assertTrue($snapshot->isLive());
		self::assertSame('1.0823', $snapshot->value);
		self::assertSame('EUR/USD', $snapshot->rateCode);
	}//end testGetFxSpotReturnsLiveSnapshot()

	/**
	 * Reference and FX caches are independent — caching FX does not poison
	 * a reference-rate lookup with the same code.
	 *
	 * @return void
	 */
	public function testReferenceAndFxCachesAreIndependent(): void {
		$this->adapter->expects(self::once())
			->method('fetchFxSpot')
			->willReturn($this->liveResult('1.10'));
		$this->adapter->expects(self::once())
			->method('fetchReferenceRate')
			->willReturn($this->liveResult('0.05'));

		$fx = $this->service->getFxSpot('EUR', 'USD', '2026-06-10');
		$ref = $this->service->getReferenceRate('EUR/USD', '2026-06-10');

		self::assertSame('1.10', $fx->value);
		self::assertSame('0.05', $ref->value);
	}//end testReferenceAndFxCachesAreIndependent()

	/**
	 * `hasLiveSnapshotSource()` proxies the adapter's dormancy flag.
	 *
	 * @return void
	 */
	public function testHasLiveSnapshotSourceReflectsAdapter(): void {
		$this->adapter->method('isDormant')->willReturn(true);
		self::assertFalse($this->service->hasLiveSnapshotSource());

		$adapter = $this->createMock(TreasuryRateAdapterInterface::class);
		$adapter->method('isDormant')->willReturn(false);
		$service = new TreasuryRateService($adapter, $this->logger);
		self::assertTrue($service->hasLiveSnapshotSource());
	}//end testHasLiveSnapshotSourceReflectsAdapter()

	/**
	 * `resetCache()` clears the per-request memoisation so a second read
	 * goes back to the adapter.
	 *
	 * @return void
	 */
	public function testResetCacheReissuesAdapterCall(): void {
		$this->adapter->expects(self::exactly(2))
			->method('fetchReferenceRate')
			->willReturn($this->liveResult('0.0392'));

		$this->service->getReferenceRate('EURIBOR-3M', '2026-06-10');
		$this->service->resetCache();
		$this->service->getReferenceRate('EURIBOR-3M', '2026-06-10');

		$this->addToAssertionCount(1);
	}//end testResetCacheReissuesAdapterCall()
}//end class
