<?php

/**
 * Unit tests for `FxRevaluationService` — period-end FX revaluation.
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
 * @spec openspec/changes/fx-period-end-revaluation/specs/bookkeeping-multi-currency/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Treasury;

use OCA\Shillinq\Service\Treasury\FxRevaluationService;
use OCA\Shillinq\Service\Treasury\TreasuryRateService;
use OCA\Shillinq\Service\Treasury\TreasuryRateSnapshot;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for `FxRevaluationService::reval()` — the delegate
 * `SoftCloseExecutor::delegateFxRevaluation()` has always probed for but,
 * before this change, could never find (the class did not exist), so
 * `SoftCloseExecutor::execute()['fxPostings']` was unconditionally 0.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class FxRevaluationServiceTest extends TestCase {

	/**
	 * In-memory fake ObjectService — supports the fluent
	 * setRegister/setSchema/findAll/saveObject shape every service in this
	 * codebase already consumes (mirrors `FxRateImportJobTest`'s anonymous
	 * class pattern), keyed per schema so FXPosition, Administration, and
	 * FxRevaluationPosting can be queried/persisted independently within
	 * one test.
	 *
	 * Exposes public `$fixtures`/`$saved` array properties (see the
	 * anonymous class in `setUp()`) — plain `object` here so PHPStan does
	 * not treat the shape as read-only.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Mock rate service.
	 *
	 * @var TreasuryRateService&MockObject
	 */
	private TreasuryRateService&MockObject $treasuryRateService;

	/**
	 * Subject under test.
	 *
	 * @var FxRevaluationService
	 */
	private FxRevaluationService $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = new class {

			/**
			 * Per-schema fixture rows the fake responds to `findAll()` with.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $fixtures = [];

			/**
			 * Objects saved during the run, keyed by schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $saved = [];

			/**
			 * Schema selected by the most recent `setSchema()` call.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Fluent register selector (no-op fake).
			 *
			 * @param string $register Register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema selector — remembers the schema for `findAll()`.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return the fixture rows for the selected schema, applying equality filters.
			 *
			 * @param array<string,mixed> $options Query options (`filters` supported).
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $options): array {
				$rows = $this->fixtures[$this->schema] ?? [];
				$filters = $options['filters'] ?? [];
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()

			/**
			 * Record the saved object under its schema.
			 *
			 * The register/schema arguments are optional so the fake also answers
			 * the single-argument call the contract adapter makes; the schema
			 * then falls back to the one `setSchema()` last selected, which the
			 * adapter applies from the caller's named `schema:` argument.
			 *
			 * @param array<string,mixed> $object Object payload.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$target = $schema;
				if ($target === '') {
					$target = $this->schema;
				}

				$this->saved[$target][] = $object;
				return $object;
			}//end saveObject()
		};

		$this->objectService->fixtures = [
			'Administration' => [['id' => 'adm-holding-nl', 'functionalCurrency' => 'EUR']],
			'FXPosition' => [],
		];

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $default
		);

		$this->treasuryRateService = $this->createMock(TreasuryRateService::class);

		$this->service = new FxRevaluationService(
			$appConfig,
			$this->treasuryRateService,
			new NullLogger(),
			objectService: new DuckObjectServiceAdapter($this->objectService),
		);
	}//end setUp()

	/**
	 * Build a live snapshot.
	 *
	 * @param string $value Decimal rate.
	 *
	 * @return TreasuryRateSnapshot
	 */
	private function liveSnapshot(string $value): TreasuryRateSnapshot {
		return new TreasuryRateSnapshot(
			value: $value,
			source: 'ECB',
			asOf: '2026-03-31',
			rateCode: 'USD/EUR',
			dormant: false,
			rateId: 'tr_live'
		);
	}//end liveSnapshot()

	/**
	 * Build a dormant snapshot (SNAPSHOT_DEFERRED).
	 *
	 * @return TreasuryRateSnapshot
	 */
	private function dormantSnapshot(): TreasuryRateSnapshot {
		return new TreasuryRateSnapshot(
			value: '0',
			source: 'LOG_DEFERRED',
			asOf: '2026-03-31',
			rateCode: 'USD/EUR',
			dormant: true,
			rateId: 'tr_synth_deferred'
		);
	}//end dormantSnapshot()

	/**
	 * REQ-MC-006 scenario 1: an open USD position with a prior mark
	 * revalues at period-end and posts a gain. This is the correctness
	 * proof — before this change no FxRevaluationService existed at all,
	 * so `SoftCloseExecutor::delegateFxRevaluation()` unconditionally
	 * returned 0.
	 *
	 * @return void
	 */
	public function testOpenPositionRevaluesAndPostsGain(): void {
		$this->objectService->fixtures['FXPosition'] = [
			[
				'id' => 'fxpos-usd-1',
				'administrationId' => 'adm-holding-nl',
				'foreignCurrency' => 'USD',
				'position' => 100000.0,
				'spotRate' => 0.90,
				'fairValue' => 90000.0,
				'unrealisedPL' => 0.0,
			],
		];

		$this->treasuryRateService->method('getFxSpot')
			->with('USD', 'EUR', '2026-03-31')
			->willReturn($this->liveSnapshot('0.93'));

		$result = $this->service->reval('adm-holding-nl', '2026-03');

		self::assertSame(1, $result['postingCount']);
		self::assertSame(1, $result['positionsEvaluated']);
		self::assertSame('EUR', $result['functionalCurrency']);

		self::assertCount(1, $this->objectService->saved['FxRevaluationPosting'] ?? []);
		$posting = $this->objectService->saved['FxRevaluationPosting'][0];
		self::assertSame(300000, $posting['unrealisedDeltaCents']);
		self::assertSame('gain', $posting['direction']);
		self::assertSame('live', $posting['rateSource']);
		self::assertSame(0.93, $posting['closingRate']);
		self::assertSame('SYSTEM:FxRevaluationService', $posting['postedBy']);
		self::assertSame('8020', $posting['targetGLAccount']);

		self::assertCount(1, $this->objectService->saved['FXPosition'] ?? []);
		$updatedPosition = $this->objectService->saved['FXPosition'][0];
		self::assertEqualsWithDelta(93000.0, $updatedPosition['fairValue'], 0.001);
		self::assertEqualsWithDelta(3000.0, $updatedPosition['unrealisedPL'], 0.001);
		self::assertSame(0.93, $updatedPosition['spotRate']);
	}//end testOpenPositionRevaluesAndPostsGain()

	/**
	 * REQ-MC-006 scenario 1 (loss variant): a weakening foreign currency
	 * posts a loss to the loss GL account.
	 *
	 * @return void
	 */
	public function testOpenPositionRevaluesAndPostsLoss(): void {
		$this->objectService->fixtures['FXPosition'] = [
			[
				'id' => 'fxpos-usd-1',
				'administrationId' => 'adm-holding-nl',
				'foreignCurrency' => 'USD',
				'position' => 100000.0,
				'spotRate' => 0.93,
				'fairValue' => 93000.0,
				'unrealisedPL' => 3000.0,
			],
		];

		$this->treasuryRateService->method('getFxSpot')->willReturn($this->liveSnapshot('0.90'));

		$result = $this->service->reval('adm-holding-nl', '2026-04');

		self::assertSame(1, $result['postingCount']);
		$posting = $this->objectService->saved['FxRevaluationPosting'][0];
		self::assertSame(-300000, $posting['unrealisedDeltaCents']);
		self::assertSame('loss', $posting['direction']);
		self::assertSame('8021', $posting['targetGLAccount']);
		self::assertSame('1699', $posting['contraGLAccount']);
	}//end testOpenPositionRevaluesAndPostsLoss()

	/**
	 * REQ-MC-006 scenario 2: a position with no prior mark establishes a
	 * baseline and posts nothing.
	 *
	 * @return void
	 */
	public function testNewPositionWithNoPriorMarkEstablishesBaselineOnly(): void {
		$this->objectService->fixtures['FXPosition'] = [
			[
				'id' => 'fxpos-gbp-1',
				'administrationId' => 'adm-holding-nl',
				'foreignCurrency' => 'GBP',
				'position' => 50000.0,
				'spotRate' => null,
				'fairValue' => null,
				'unrealisedPL' => null,
			],
		];

		$this->treasuryRateService->method('getFxSpot')->willReturn($this->liveSnapshot('1.15'));

		$result = $this->service->reval('adm-holding-nl', '2026-03');

		self::assertSame(0, $result['postingCount']);
		self::assertArrayNotHasKey('FxRevaluationPosting', $this->objectService->saved);
		self::assertCount(1, $this->objectService->saved['FXPosition']);
		self::assertEqualsWithDelta(57500.0, $this->objectService->saved['FXPosition'][0]['fairValue'], 0.001);
		self::assertSame(0.0, $this->objectService->saved['FXPosition'][0]['unrealisedPL']);
	}//end testNewPositionWithNoPriorMarkEstablishesBaselineOnly()

	/**
	 * REQ-MC-006 scenario 3: an immaterial movement refreshes the mark but
	 * does not post.
	 *
	 * @return void
	 */
	public function testImmaterialMovementRefreshesMarkWithoutPosting(): void {
		$this->objectService->fixtures['FXPosition'] = [
			[
				'id' => 'fxpos-usd-1',
				'administrationId' => 'adm-holding-nl',
				'foreignCurrency' => 'USD',
				'position' => 1.0,
				'spotRate' => 0.900000,
				'fairValue' => 0.9,
				'unrealisedPL' => 0.0,
			],
		];

		// Delta = 1.0 * (0.900004 - 0.900000) = 0.000004 → 0 cents rounded.
		$this->treasuryRateService->method('getFxSpot')->willReturn($this->liveSnapshot('0.900004'));

		$result = $this->service->reval('adm-holding-nl', '2026-03');

		self::assertSame(0, $result['postingCount']);
		self::assertArrayNotHasKey('FxRevaluationPosting', $this->objectService->saved);
		self::assertCount(1, $this->objectService->saved['FXPosition']);
	}//end testImmaterialMovementRefreshesMarkWithoutPosting()

	/**
	 * REQ-MC-007 scenario 1: a dormant rate adapter falls back to the
	 * position's manually-maintained spotRate, and the resulting posting
	 * is attributed `rateSource: "manual-fallback"`.
	 *
	 * @return void
	 */
	public function testDormantAdapterFallsBackToManualSpotRate(): void {
		$this->objectService->fixtures['FXPosition'] = [
			[
				'id' => 'fxpos-gbp-1',
				'administrationId' => 'adm-holding-nl',
				'foreignCurrency' => 'GBP',
				'position' => 50000.0,
				'spotRate' => 0.80,
				'fairValue' => 40000.0,
				'unrealisedPL' => 0.0,
			],
		];

		$this->treasuryRateService->method('getFxSpot')->willReturn($this->dormantSnapshot());

		$result = $this->service->reval('adm-holding-nl', '2026-05');

		// The dormant adapter carries no usable value; the only rate
		// available is the position's OWN prior spotRate (0.80), so the
		// fallback resolves to the same value as the prior mark — no
		// delta, but the rateSource on the (non-posted) resolution path
		// is still exercised. Force an actual movement by seeding a
		// higher manual spotRate on a second position.
		$this->objectService->fixtures['FXPosition'][] = [
			'id' => 'fxpos-gbp-2',
			'administrationId' => 'adm-holding-nl',
			'foreignCurrency' => 'GBP',
			'position' => 50000.0,
			'spotRate' => 0.86,
			'fairValue' => 40000.0,
			'unrealisedPL' => 0.0,
		];

		$result = $this->service->reval('adm-holding-nl', '2026-05');

		self::assertSame(1, $result['postingCount']);
		$postings = $this->objectService->saved['FxRevaluationPosting'];
		self::assertCount(1, $postings);
		self::assertSame('manual-fallback', $postings[0]['rateSource']);
		self::assertSame('fxpos-gbp-2', $postings[0]['positionId']);
	}//end testDormantAdapterFallsBackToManualSpotRate()

	/**
	 * REQ-MC-007 scenario 2: neither a live rate nor a manual spotRate is
	 * available — the position is skipped without failing the run, and
	 * other positions are still processed and counted normally.
	 *
	 * @return void
	 */
	public function testUnresolvablePositionIsSkippedWithoutFailingOtherPositions(): void {
		$this->objectService->fixtures['FXPosition'] = [
			[
				'id' => 'fxpos-chf-unresolvable',
				'administrationId' => 'adm-holding-nl',
				'foreignCurrency' => 'CHF',
				'position' => 20000.0,
				'spotRate' => null,
				'fairValue' => null,
				'unrealisedPL' => null,
			],
			[
				'id' => 'fxpos-usd-resolvable',
				'administrationId' => 'adm-holding-nl',
				'foreignCurrency' => 'USD',
				'position' => 100000.0,
				'spotRate' => 0.90,
				'fairValue' => 90000.0,
				'unrealisedPL' => 0.0,
			],
		];

		$this->treasuryRateService->method('getFxSpot')->willReturn($this->dormantSnapshot());

		$result = $this->service->reval('adm-holding-nl', '2026-03');

		self::assertSame(2, $result['positionsEvaluated']);
		// CHF has no live snapshot and no manual spotRate → skipped
		// entirely (not mutated). USD falls back to its own prior
		// spotRate (0.90 == 0.90) → refreshed, no material delta.
		self::assertSame(0, $result['postingCount']);
		self::assertCount(1, $this->objectService->saved['FXPosition'] ?? []);
		self::assertSame('fxpos-usd-resolvable', $this->objectService->saved['FXPosition'][0]['id']);
	}//end testUnresolvablePositionIsSkippedWithoutFailingOtherPositions()

	/**
	 * Same-currency and zero-balance positions are not revalued at all —
	 * no FX exposure.
	 *
	 * @return void
	 */
	public function testSameCurrencyAndZeroPositionsAreIgnored(): void {
		$this->objectService->fixtures['FXPosition'] = [
			[
				'id' => 'fxpos-eur-same',
				'administrationId' => 'adm-holding-nl',
				'foreignCurrency' => 'EUR',
				'position' => 5000.0,
				'spotRate' => 1.0,
			],
			[
				'id' => 'fxpos-usd-zero',
				'administrationId' => 'adm-holding-nl',
				'foreignCurrency' => 'USD',
				'position' => 0.0,
				'spotRate' => 0.9,
			],
		];

		$result = $this->service->reval('adm-holding-nl', '2026-03');

		self::assertSame(0, $result['postingCount']);
		self::assertSame(2, $result['positionsEvaluated']);
		self::assertArrayNotHasKey('FXPosition', $this->objectService->saved);
	}//end testSameCurrencyAndZeroPositionsAreIgnored()

	/**
	 * Invalid periodId short-circuits with a zero-value report instead of
	 * throwing.
	 *
	 * @return void
	 */
	public function testInvalidPeriodIdReturnsZeroReportWithoutThrowing(): void {
		$result = $this->service->reval('adm-holding-nl', 'not-a-period');

		self::assertSame(0, $result['postingCount']);
		self::assertSame(0, $result['positionsEvaluated']);
	}//end testInvalidPeriodIdReturnsZeroReportWithoutThrowing()

	/**
	 * Missing Administration record defaults functionalCurrency to EUR
	 * rather than throwing.
	 *
	 * @return void
	 */
	public function testMissingAdministrationDefaultsToEur(): void {
		$this->objectService->fixtures['Administration'] = [];
		$this->objectService->fixtures['FXPosition'] = [
			[
				'id' => 'fxpos-usd-1',
				'administrationId' => 'adm-unknown',
				'foreignCurrency' => 'USD',
				'position' => 1000.0,
				'spotRate' => 0.9,
				'fairValue' => 900.0,
				'unrealisedPL' => 0.0,
			],
		];

		$this->treasuryRateService->method('getFxSpot')->willReturn($this->liveSnapshot('0.95'));

		$result = $this->service->reval('adm-unknown', '2026-03');

		self::assertSame('EUR', $result['functionalCurrency']);
		self::assertSame(1, $result['postingCount']);
	}//end testMissingAdministrationDefaultsToEur()
}//end class
