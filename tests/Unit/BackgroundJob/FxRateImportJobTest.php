<?php

/**
 * Unit tests for FxRateImportJob.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-shillinq-multi-currency/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\FxRateImportJob;
use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateAdapterInterface;
use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateResult;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for FxRateImportJob per REQ-MC-003.
 *
 * Covers: dormant short-circuit (no writes when adapter is a log-only stub),
 * pure helpers (previousBusinessDay, configuredPairs, isDeferredResult,
 * buildRecord), idempotent upsert (no second write on duplicate composite key),
 * non-positive rate guard, and the register-slug fallback.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class FxRateImportJobTest extends TestCase {
	/**
	 * Mock ITimeFactory.
	 *
	 * @var ITimeFactory&MockObject
	 */
	private ITimeFactory&MockObject $timeFactory;

	/**
	 * Mock DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock FX adapter.
	 *
	 * @var TreasuryRateAdapterInterface&MockObject
	 */
	private TreasuryRateAdapterInterface&MockObject $adapter;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getTime')->willReturn(time());
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->adapter = $this->createMock(TreasuryRateAdapterInterface::class);
	}//end setUp()

	/**
	 * Build a job under test with the current mock set.
	 *
	 * @return FxRateImportJob
	 */
	private function makeJob(): FxRateImportJob {
		return new FxRateImportJob(
			$this->timeFactory,
			$this->container,
			$this->appConfig,
			$this->logger,
			$this->adapter,
		);
	}//end makeJob()

	/**
	 * Invoke a private method on the job under test.
	 *
	 * @param FxRateImportJob $job The job instance.
	 * @param string $name The method name.
	 * @param array<mixed> $args The arguments.
	 *
	 * @return mixed The method result.
	 */
	private function invoke(FxRateImportJob $job, string $name, array $args): mixed {
		$m = new ReflectionMethod(FxRateImportJob::class, $name);
		$m->setAccessible(true);
		return $m->invokeArgs($job, $args);
	}//end invoke()

	/**
	 * previousBusinessDay returns yesterday's ISO date Tue–Fri.
	 *
	 * @return void
	 */
	public function testPreviousBusinessDayReturnsIsoDateAndSkipsWeekends(): void {
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$job = $this->makeJob();
		$iso = (string)$this->invoke($job, 'previousBusinessDay', []);

		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $iso);
		$dow = (int)(new \DateTimeImmutable($iso))->format('N');
		self::assertLessThanOrEqual(5, $dow, 'previousBusinessDay must skip weekends (ISO day 1..5)');
		self::assertGreaterThanOrEqual(1, $dow);
	}//end testPreviousBusinessDayReturnsIsoDateAndSkipsWeekends()

	/**
	 * configuredPairs falls back to defaults when the app-config value is empty.
	 *
	 * @return void
	 */
	public function testConfiguredPairsFallsBackToDefaults(): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(static function (string $app, string $key, string $default) {
				return ($key === 'multi_currency_pairs' ? '' : 'shillinq');
			});

		$job = $this->makeJob();
		$list = (array)$this->invoke($job, 'configuredPairs', []);

		self::assertNotEmpty($list);
		self::assertSame('USD', $list[0]['transaction']);
		self::assertSame('EUR', $list[0]['base']);
		self::assertContainsEquals(['transaction' => 'GBP', 'base' => 'EUR'], $list);
	}//end testConfiguredPairsFallsBackToDefaults()

	/**
	 * configuredPairs parses operator-supplied tokens, skipping malformed ones.
	 *
	 * @return void
	 */
	public function testConfiguredPairsParsesOperatorOverride(): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(static function (string $app, string $key, string $default) {
				if ($key === 'multi_currency_pairs') {
					return 'usd/eur, GBP/EUR,bad-token, JPY/EUR';
				}
				return 'shillinq';
			});

		$job = $this->makeJob();
		$list = (array)$this->invoke($job, 'configuredPairs', []);

		self::assertSame([
			['transaction' => 'USD', 'base' => 'EUR'],
			['transaction' => 'GBP', 'base' => 'EUR'],
			['transaction' => 'JPY', 'base' => 'EUR'],
		], $list);
	}//end testConfiguredPairsParsesOperatorOverride()

	/**
	 * isDeferredResult flags dormant + SNAPSHOT_DEFERRED results so the upsert is skipped.
	 *
	 * @return void
	 */
	public function testIsDeferredResultRecognisesDormantAndSentinel(): void {
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$job = $this->makeJob();

		$dormant = new TreasuryRateResult(
			status: 'SNAPSHOT_DEFERRED',
			rateId: 'tr_fx_log_test',
			rateCode: 'EUR/USD',
			value: '0',
			asOf: '2026-06-10',
			source: 'LOG_DEFERRED',
			dormant: true,
		);
		$live = new TreasuryRateResult(
			status: 'OK',
			rateId: 'tr_fx_live',
			rateCode: 'EUR/USD',
			value: '1.0823',
			asOf: '2026-06-10',
			source: 'ECB',
			dormant: false,
		);

		self::assertTrue((bool)$this->invoke($job, 'isDeferredResult', [$dormant]));
		self::assertFalse((bool)$this->invoke($job, 'isDeferredResult', [$live]));
	}//end testIsDeferredResultRecognisesDormantAndSentinel()

	/**
	 * buildRecord shapes the FxRate payload to the register schema with inverseRate.
	 *
	 * @return void
	 */
	public function testBuildRecordShapesFxRatePayload(): void {
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$job = $this->makeJob();

		/** @var array<string,mixed> $record */
		$record = (array)$this->invoke(
			$job,
			'buildRecord',
			['USD', 'EUR', '2026-06-10', 0.9259],
		);

		self::assertSame('USD', $record['transactionCurrency']);
		self::assertSame('EUR', $record['baseCurrency']);
		self::assertSame('2026-06-10', $record['date']);
		self::assertSame('ecb', $record['source']);
		self::assertEqualsWithDelta(0.9259, (float)$record['rate'], 0.0001);
		self::assertEqualsWithDelta((1 / 0.9259), (float)$record['inverseRate'], 0.0001);
		self::assertArrayHasKey('ingestedAt', $record);
		self::assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\+|\-|Z)/',
			(string)$record['ingestedAt']
		);
	}//end testBuildRecordShapesFxRatePayload()

	/**
	 * getRegisterSlug falls back to 'shillinq' when the app-config value is empty.
	 *
	 * @return void
	 */
	public function testRegisterSlugFallback(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$job = $this->makeJob();
		self::assertSame('shillinq', (string)$this->invoke($job, 'getRegisterSlug', []));
	}//end testRegisterSlugFallback()

	/**
	 * run() is a no-op when the TreasuryRate adapter is dormant — no FxRate
	 * record is written and the ObjectService is never resolved.
	 *
	 * @return void
	 */
	public function testRunSkipsWhenAdapterIsDormant(): void {
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->adapter->method('isDormant')->willReturn(true);
		$this->adapter->expects(self::never())->method('fetchFxSpot');
		$this->container->expects(self::never())->method('get');

		$job = $this->makeJob();
		$m = new ReflectionMethod(FxRateImportJob::class, 'run');
		$m->setAccessible(true);
		$m->invoke($job, null);

		$this->addToAssertionCount(1);
	}//end testRunSkipsWhenAdapterIsDormant()

	/**
	 * run() exits gracefully when OpenRegister is absent — adapter live but
	 * the OR container resolution fails (e.g. peer app disabled).
	 *
	 * @return void
	 */
	public function testRunSkipsWhenOpenRegisterUnavailable(): void {
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->adapter->method('isDormant')->willReturn(false);
		$this->container->method('get')->willThrowException(new \RuntimeException('no OR'));

		$job = $this->makeJob();
		$m = new ReflectionMethod(FxRateImportJob::class, 'run');
		$m->setAccessible(true);
		$m->invoke($job, null);

		$this->addToAssertionCount(1);
	}//end testRunSkipsWhenOpenRegisterUnavailable()

	/**
	 * run() writes one FxRate per pair when the adapter is live and no
	 * existing record matches the composite key.
	 *
	 * @return void
	 */
	public function testRunWritesOneFxRatePerPair(): void {
		// Use a single-pair override so the test is deterministic.
		$this->appConfig->method('getValueString')
			->willReturnCallback(static function (string $app, string $key, string $default) {
				return ($key === 'multi_currency_pairs' ? 'USD/EUR' : 'shillinq');
			});
		$this->adapter->method('isDormant')->willReturn(false);
		$this->adapter->method('fetchFxSpot')->willReturn(new TreasuryRateResult(
			status: 'OK',
			rateId: 'tr_fx_test',
			rateCode: 'EUR/USD',
			value: '0.9259',
			asOf: '2026-06-10',
			source: 'ECB',
			dormant: false,
		));

		$objectService = new class {
			public int $saved = 0;
			public ?array $lastObject = null;

			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				return [];
			}
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): void {
				$this->saved++;
				$this->lastObject = $object;
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$job = $this->makeJob();
		$m = new ReflectionMethod(FxRateImportJob::class, 'run');
		$m->setAccessible(true);
		$m->invoke($job, null);

		self::assertSame(1, $objectService->saved);
		self::assertSame('USD', $objectService->lastObject['transactionCurrency']);
		self::assertSame('EUR', $objectService->lastObject['baseCurrency']);
		self::assertSame('ecb', $objectService->lastObject['source']);
	}//end testRunWritesOneFxRatePerPair()

	/**
	 * run() is idempotent — duplicate composite-key match means no write.
	 *
	 * @return void
	 */
	public function testRunIsIdempotentOnDuplicate(): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(static function (string $app, string $key, string $default) {
				return ($key === 'multi_currency_pairs' ? 'USD/EUR' : 'shillinq');
			});
		$this->adapter->method('isDormant')->willReturn(false);
		$this->adapter->method('fetchFxSpot')->willReturn(new TreasuryRateResult(
			status: 'OK',
			rateId: 'tr_fx_test',
			rateCode: 'EUR/USD',
			value: '0.9259',
			asOf: '2026-06-10',
			source: 'ECB',
			dormant: false,
		));

		$objectService = new class {
			public int $saved = 0;

			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				// Pretend a duplicate exists.
				return [['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR']];
			}
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): void {
				$this->saved++;
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$job = $this->makeJob();
		$m = new ReflectionMethod(FxRateImportJob::class, 'run');
		$m->setAccessible(true);
		$m->invoke($job, null);

		self::assertSame(0, $objectService->saved, 'Duplicate composite-key match must skip the upsert');
	}//end testRunIsIdempotentOnDuplicate()

	/**
	 * Non-positive rate returned by the adapter is guarded — no write.
	 *
	 * @return void
	 */
	public function testNonPositiveRateIsGuarded(): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(static function (string $app, string $key, string $default) {
				return ($key === 'multi_currency_pairs' ? 'USD/EUR' : 'shillinq');
			});
		$this->adapter->method('isDormant')->willReturn(false);
		$this->adapter->method('fetchFxSpot')->willReturn(new TreasuryRateResult(
			status: 'OK',
			rateId: 'tr_fx_test',
			rateCode: 'EUR/USD',
			value: '0',
			asOf: '2026-06-10',
			source: 'ECB',
			dormant: false,
		));

		$objectService = new class {
			public int $saved = 0;
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				return [];
			}
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): void {
				$this->saved++;
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$job = $this->makeJob();
		$m = new ReflectionMethod(FxRateImportJob::class, 'run');
		$m->setAccessible(true);
		$m->invoke($job, null);

		self::assertSame(0, $objectService->saved, 'Non-positive rate must be guarded');
	}//end testNonPositiveRateIsGuarded()
}//end class
