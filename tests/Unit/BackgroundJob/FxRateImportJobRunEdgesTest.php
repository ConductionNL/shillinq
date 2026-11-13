<?php

/**
 * FxRateImportJob run edge tests.
 *
 * One import run over several pairs: a pair whose fetch throws, a deferred
 * answer, a stored rate found as an entity, a failing lookup or save, and
 * the pair list an operator can misconfigure.
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
 * @spec openspec/changes/archive/2026-06-14-add-shillinq-multi-currency/tasks.md#task-11
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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Errors, deferrals and skips are counted per pair; one bad pair never stops the run.
 *
 * @covers \OCA\Shillinq\BackgroundJob\FxRateImportJob
 * @uses   \OCA\Shillinq\BackgroundJob\Support\RunsJobAsSystem
 * @uses   \OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateResult
 */
final class FxRateImportJobRunEdgesTest extends TestCase {

	/**
	 * Log records as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * A rate answer.
	 *
	 * @param string $value  The rate.
	 * @param string $status The status.
	 *
	 * @return TreasuryRateResult
	 */
	private static function rate(string $value = '1.25', string $status = 'OK'): TreasuryRateResult {
		return new TreasuryRateResult(status: $status, rateId: 'r', rateCode: 'X', value: $value, asOf: '2026-06-10', source: 'ECB', dormant: false);
	}//end rate()

	/**
	 * An object service with a fixed lookup answer that records saves.
	 *
	 * @param mixed $lookup What findAll answers, or a Throwable to throw.
	 * @param bool  $saveFails Whether saveObject throws.
	 *
	 * @return object
	 */
	private static function store(mixed $lookup = [], bool $saveFails = false): object {
		return new class($lookup, $saveFails) {
			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			/** @var array<int, array<string, mixed>> */
			public array $lookups = [];

			public function __construct(private mixed $lookup, private bool $saveFails) {
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				return $this;
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $query): array {
				$this->lookups[] = $query;
				if ($this->lookup instanceof \Throwable) {
					throw $this->lookup;
				}

				return $this->lookup;
			}

			/**
			 * @param array<string, mixed> $object The record.
			 */
			public function saveObject(array $object, string $register, string $schema): void {
				if ($this->saveFails === true) {
					throw new RuntimeException('read-only');
				}

				$this->saved[] = $object;
			}
		};
	}//end store()

	/**
	 * Run the job.
	 *
	 * @param object                       $store   The object service.
	 * @param string                       $pairs   The configured pairs.
	 * @param callable(string): mixed      $answers Answer per transaction currency (a result or a Throwable).
	 *
	 * @return void
	 */
	private function runJob(object $store, string $pairs, callable $answers): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'multi_currency_pairs' ? $pairs : 'shillinq')
		);
		$adapter = $this->createMock(TreasuryRateAdapterInterface::class);
		$adapter->method('isDormant')->willReturn(false);
		$adapter->method('fetchFxSpot')->willReturnCallback(static function (string $baseCurrency, string $quoteCurrency) use ($answers): TreasuryRateResult {
			$answer = $answers($quoteCurrency);
			if ($answer instanceof \Throwable) {
				throw $answer;
			}

			return $answer;
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				$this->logged[] = [$level, $message, $context];
			});
		}

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(time());

		$job = new FxRateImportJob($time, $container, $appConfig, $logger, $adapter);
		(new ReflectionMethod(FxRateImportJob::class, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * The run's closing summary.
	 *
	 * @return string
	 */
	private function summary(): string {
		$infos = array_values(array_filter($this->logged, static fn (array $r): bool => $r[0] === 'info'));
		return (string)preg_replace('/ for asOf=\S+$/', '', end($infos)[1]);
	}//end summary()

	/**
	 * The messages logged at a level.
	 *
	 * @param string $level The level.
	 *
	 * @return array<int, string>
	 */
	private function messages(string $level): array {
		return array_values(array_map(
			static fn (array $r): string => $r[1],
			array_filter($this->logged, static fn (array $r): bool => $r[0] === $level)
		));
	}//end messages()

	/**
	 * A throwing fetch is an error, a deferred answer a skip, and the other pairs still import.
	 *
	 * @return void
	 */
	public function testEachPairIsCountedOnItsOwn(): void {
		$store = self::store();

		$this->runJob($store, 'USD/EUR,GBP/EUR,CHF/EUR', static fn (string $tx): mixed => match ($tx) {
			'USD' => new RuntimeException('ECB timeout'),
			'GBP' => self::rate(status: 'SNAPSHOT_DEFERRED'),
			default => self::rate(),
		});

		self::assertSame(['CHF'], array_column($store->saved, 'transactionCurrency'));
		self::assertSame(0.8, $store->saved[0]['inverseRate']);
		self::assertSame(['Shillinq FxRateImportJob: fetchFxSpot threw'], $this->messages('warning'));
		self::assertSame('USD/EUR', $this->logged[array_search('Shillinq FxRateImportJob: fetchFxSpot threw', array_column($this->logged, 1), true)][2]['pair']);
		self::assertSame('Shillinq FxRateImportJob: imported 1 rate(s), skipped 1, 1 error(s)', $this->summary());
	}//end testEachPairIsCountedOnItsOwn()

	/**
	 * A stored rate answered as an entity is found, so nothing is written.
	 *
	 * @return void
	 */
	public function testAStoredEntityIsAnIdempotentSkip(): void {
		$entity = new class {
			/**
			 * @return array<string, string>
			 */
			public function getObject(): array {
				return ['transactionCurrency' => 'USD'];
			}
		};
		$store = self::store([$entity]);

		$this->runJob($store, 'USD/EUR', static fn (): TreasuryRateResult => self::rate());

		self::assertSame([], $store->saved);
		self::assertSame(['Shillinq FxRateImportJob: FxRate already present, idempotent skip'], $this->messages('debug'));
		self::assertSame(
			['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'source' => 'ecb'],
			array_diff_key($store->lookups[0]['filters'], ['date' => true])
		);
		self::assertSame(1, $store->lookups[0]['limit']);
		self::assertSame('Shillinq FxRateImportJob: imported 0 rate(s), skipped 1, 0 error(s)', $this->summary());
	}//end testAStoredEntityIsAnIdempotentSkip()

	/**
	 * A failing lookup, or one answering nothing readable, counts as no stored rate.
	 *
	 * @return void
	 */
	public function testAnUnreadableLookupStillImports(): void {
		$failing = self::store(new RuntimeException('index missing'));
		$this->runJob($failing, 'USD/EUR', static fn (): TreasuryRateResult => self::rate());
		self::assertCount(1, $failing->saved);
		self::assertSame(['Shillinq FxRateImportJob: findExisting lookup failed'], $this->messages('warning'));

		$opaque = self::store([new \stdClass()]);
		$this->runJob($opaque, 'USD/EUR', static fn (): TreasuryRateResult => self::rate());
		self::assertCount(1, $opaque->saved);
	}//end testAnUnreadableLookupStillImports()

	/**
	 * A failing save is logged and counted as a skip.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsASkip(): void {
		$this->runJob(self::store([], true), 'USD/EUR', static fn (): TreasuryRateResult => self::rate());

		self::assertSame(['Shillinq FxRateImportJob: failed to save FxRate'], $this->messages('error'));
		self::assertSame('Shillinq FxRateImportJob: imported 0 rate(s), skipped 1, 0 error(s)', $this->summary());
	}//end testAFailingSaveIsASkip()

	/**
	 * Pairs are upper-cased; malformed ones are dropped, and a list with none left falls back to the defaults.
	 *
	 * @return void
	 */
	public function testMalformedPairsAreDroppedAndAnEmptyListFallsBack(): void {
		$asked = [];
		$answers = static function (string $tx) use (&$asked): TreasuryRateResult {
			$asked[] = $tx;
			return self::rate();
		};

		$this->runJob(self::store(), ' usd / eur ,US/EUR,EURO/USD, ,GBPEUR', $answers);
		self::assertSame(['USD'], $asked);

		$asked = [];
		$this->runJob(self::store(), 'US/EUR,nonsense', $answers);
		self::assertSame(['USD', 'GBP', 'CHF', 'JPY'], $asked);
	}//end testMalformedPairsAreDroppedAndAnEmptyListFallsBack()
}//end class
