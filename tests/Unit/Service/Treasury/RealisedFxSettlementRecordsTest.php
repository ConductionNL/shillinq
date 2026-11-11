<?php

/**
 * Characterisation of RealisedFxSettlementService::postRealisedFxOnSettlement():
 * the full report of every outcome, the exact GLTransaction and
 * RealisedFxPosting records it saves and in which order, the GL account
 * lookups, and the log lines of a skipped or failed posting.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Treasury;

use OCA\Shillinq\Service\Treasury\RealisedFxSettlementService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Pins postRealisedFxOnSettlement() before it is split into named steps.
 *
 * @covers \OCA\Shillinq\Service\Treasury\RealisedFxSettlementService
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RealisedFxSettlementRecordsTest extends TestCase {

	/**
	 * The fake object store.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * Log lines as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	/**
	 * App-config keys read, in order.
	 *
	 * @var array<int, string>
	 */
	private array $configKeys = [];

	/**
	 * Build the service on a fresh fake store.
	 *
	 * @param array<string, string> $config Configured app values.
	 *
	 * @return RealisedFxSettlementService
	 */
	private function service(array $config = []): RealisedFxSettlementService {
		$this->store = new class {
			/**
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $fixtures = [];

			/**
			 * @var array<int,array{0: string, 1: array<string,mixed>}>
			 */
			public array $saved = [];

			/**
			 * @var array<int,string>
			 */
			public array $failOn = [];

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param string $register Register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * @param string $schema Schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string,mixed> $options Query options.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $options): array {
				$filters = $options['filters'] ?? [];
				return array_values(
					array_filter(
						$this->fixtures[$this->schema] ?? [],
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}

			/**
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				if (in_array($this->schema, $this->failOn, true) === true) {
					throw new RuntimeException('store down');
				}

				$this->saved[] = [$this->schema, $object];
				return $object;
			}
		};
		$this->store->fixtures = [
			'Administration' => [['id' => 'adm-1', 'functionalCurrency' => 'EUR']],
			'FxRate' => [
				['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'date' => '2026-01-10', 'rate' => 0.90],
				['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'date' => '2026-02-15', 'rate' => 0.93],
			],
		];

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->store);

		$this->configKeys = [];
		$keys = &$this->configKeys;
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default) use (&$keys, $config): string {
				$keys[] = $key;
				return ($config[$key] ?? $default);
			}
		);

		$this->logs = [];
		$logs = &$this->logs;
		$logger = new class($logs) extends AbstractLogger {
			/**
			 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
			 */
			private array $lines;

			/**
			 * @param array<int, array{0: string, 1: string, 2: array<string, mixed>}> $lines Shared log.
			 */
			public function __construct(array &$lines) {
				$this->lines = &$lines;
			}

			/**
			 * @param mixed                $level   Level.
			 * @param string|\Stringable   $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [(string)$level, (string)$message, $context];
			}
		};

		return new RealisedFxSettlementService($container, $appConfig, $logger);

	}//end service()

	/**
	 * A settled USD invoice.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string,mixed>
	 */
	private static function invoice(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'arinv-7',
				'invoiceNumber' => 'AR-2026-0007',
				'administrationId' => 'adm-1',
				'currency' => 'usd',
				'grossAmount' => 100000.0,
				'invoiceDate' => '2026-01-10',
				'fxRate' => null,
			],
			$overrides
		);

	}//end invoice()

	/**
	 * A gain: the report, the balanced journal, the audit record, their order
	 * and the account lookups.
	 *
	 * @return void
	 */
	public function testGainSavesJournalThenPosting(): void {
		$report = $this->service(['fx_realised_gain_account' => '8122'])->postRealisedFxOnSettlement(self::invoice(), null, '2026-02-15');

		self::assertSame(
			['posted' => true, 'direction' => 'gain', 'realisedCents' => 300000, 'reason' => null, 'invoiceRate' => 0.90, 'paymentRate' => 0.93],
			$report
		);
		self::assertSame(['GLTransaction', 'RealisedFxPosting'], array_column($this->store->saved, 0));
		self::assertSame(
			[
				'administrationId' => 'adm-1',
				'description' => 'Realised FX gain on settlement of invoice AR-2026-0007',
				'journalDate' => '2026-02-15',
				'isBalanced' => true,
				'invoiceId' => 'arinv-7',
				'journalType' => 'realised-fx',
				'postings' => [
					['accountNumber' => '1130', 'debitCents' => 300000, 'creditCents' => 0, 'description' => 'AR control — realised FX gain on settlement'],
					['accountNumber' => '8122', 'debitCents' => 0, 'creditCents' => 300000, 'description' => 'Realised FX gain'],
				],
			],
			$this->store->saved[0][1]
		);
		$posting = $this->store->saved[1][1];
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $posting['postedAt']);
		unset($posting['postedAt']);
		self::assertSame(
			[
				'administrationId' => 'adm-1',
				'invoiceId' => 'arinv-7',
				'invoiceNumber' => 'AR-2026-0007',
				'foreignCurrency' => 'USD',
				'functionalCurrency' => 'EUR',
				'foreignAmount' => 100000.0,
				'invoiceRate' => 0.90,
				'paymentRate' => 0.93,
				'realisedDeltaCents' => 300000,
				'direction' => 'gain',
				'gainLossGLAccount' => '8122',
				'arControlGLAccount' => '1130',
				'settlementDate' => '2026-02-15',
				'postedBy' => 'SYSTEM:RealisedFxSettlementService',
			],
			$posting
		);
		self::assertSame(['fx_realised_loss_account', 'fx_realised_gain_account', 'fx_ar_control_account'], array_values(array_filter($this->configKeys, static fn (string $k): bool => $k !== 'register')));
		self::assertSame([], $this->logs);

	}//end testGainSavesJournalThenPosting()

	/**
	 * A loss at an explicit payment rate debits the loss account.
	 *
	 * @return void
	 */
	public function testLossAtExplicitRate(): void {
		$report = $this->service()->postRealisedFxOnSettlement(self::invoice(['fxRate' => 0.93, 'invoiceNumber' => null]), 0.9, '2026-03-01');

		self::assertSame(
			['posted' => true, 'direction' => 'loss', 'realisedCents' => -300000, 'reason' => null, 'invoiceRate' => 0.93, 'paymentRate' => 0.9],
			$report
		);
		$journal = $this->store->saved[0][1];
		self::assertSame('Realised FX loss on settlement of invoice arinv-7', $journal['description']);
		self::assertSame(
			[
				['accountNumber' => '8023', 'debitCents' => 300000, 'creditCents' => 0, 'description' => 'Realised FX loss'],
				['accountNumber' => '1130', 'debitCents' => 0, 'creditCents' => 300000, 'description' => 'AR control — realised FX loss on settlement'],
			],
			$journal['postings']
		);
		self::assertSame('', $this->store->saved[1][1]['invoiceNumber']);
		self::assertSame(['fx_realised_loss_account', 'fx_ar_control_account'], array_values(array_filter($this->configKeys, static fn (string $k): bool => $k !== 'register')));

	}//end testLossAtExplicitRate()

	/**
	 * Outcomes that post nothing, with their full reports.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: float|null, 2: array<string, mixed>}>
	 */
	public static function skipped(): array {
		$none = ['posted' => false, 'direction' => null, 'realisedCents' => 0];
		return [
			'same currency'      => [['currency' => 'eur'], null, $none + ['reason' => 'same-currency', 'invoiceRate' => null, 'paymentRate' => null]],
			'blank currency'     => [['currency' => ''], 0.9, $none + ['reason' => 'same-currency', 'invoiceRate' => null, 'paymentRate' => null]],
			'zero amount'        => [['grossAmount' => 0], 0.9, $none + ['reason' => 'zero-amount', 'invoiceRate' => null, 'paymentRate' => null]],
			'no payment rate'    => [['fxRate' => 0.9], null, $none + ['reason' => 'no-rate', 'invoiceRate' => 0.9, 'paymentRate' => null]],
			'negative rate'      => [['fxRate' => 0.9], -1.0, $none + ['reason' => 'no-rate', 'invoiceRate' => 0.9, 'paymentRate' => -1.0]],
			'no invoice rate'    => [['invoiceDate' => '2025-01-01'], 0.9, $none + ['reason' => 'no-rate', 'invoiceRate' => null, 'paymentRate' => 0.9]],
			'no movement'        => [['fxRate' => 0.9], 0.900000001, $none + ['reason' => 'no-fx-movement', 'invoiceRate' => 0.9, 'paymentRate' => 0.900000001]],
		];

	}//end skipped()

	/**
	 * Each skipped outcome returns its report and saves nothing.
	 *
	 * @param array<string, mixed> $overrides      Invoice fields.
	 * @param float|null           $settlementRate The explicit payment rate.
	 * @param array<string, mixed> $expected       The report.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('skipped')]
	public function testSkippedOutcomesSaveNothing(array $overrides, ?float $settlementRate, array $expected): void {
		$report = $this->service()->postRealisedFxOnSettlement(self::invoice($overrides), $settlementRate, '2026-01-01');

		self::assertSame($expected, $report);
		self::assertSame([], $this->store->saved);

	}//end testSkippedOutcomesSaveNothing()

	/**
	 * Without an exact-date row the most recent earlier row prices the
	 * settlement; rows without a positive rate or a date, or dated later,
	 * are skipped.
	 *
	 * @return void
	 */
	public function testMostRecentEarlierRatePricesTheSettlement(): void {
		$service = $this->service();
		$this->store->fixtures['FxRate'] = [
			['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'date' => '2026-01-10', 'rate' => 0.90],
			['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'date' => '2026-02-20', 'rate' => 0.0],
			['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'date' => '', 'rate' => 0.99],
			['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'date' => '2026-02-15', 'rate' => 0.93],
			['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'date' => '2026-02-01', 'rate' => 0.91],
			['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'date' => '2026-03-02', 'rate' => 0.80],
			['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'date' => '2026-02-28', 'rate' => null],
		];

		$report = $service->postRealisedFxOnSettlement(self::invoice(), null, '2026-03-01');

		self::assertSame(0.90, $report['invoiceRate']);
		self::assertSame(0.93, $report['paymentRate']);

	}//end testMostRecentEarlierRatePricesTheSettlement()

	/**
	 * A missing rate is logged with both rates.
	 *
	 * @return void
	 */
	public function testMissingRateIsLogged(): void {
		$this->service()->postRealisedFxOnSettlement(self::invoice(['fxRate' => 0.9]), null, '2026-01-01');

		self::assertSame(
			[['info', 'RealisedFxSettlementService: could not resolve both invoice-date and payment-date rates — skipping', ['foreignCurrency' => 'USD', 'invoiceRate' => 0.9, 'paymentRate' => null]]],
			$this->logs
		);

	}//end testMissingRateIsLogged()

	/**
	 * Failing saves are logged and do not stop the run or change the report.
	 *
	 * @return void
	 */
	public function testFailingSavesAreLoggedAndTheReportStands(): void {
		$service = $this->service();
		$this->store->failOn = ['GLTransaction', 'RealisedFxPosting'];

		$report = $service->postRealisedFxOnSettlement(self::invoice(), null, '2026-02-15');

		self::assertTrue($report['posted']);
		self::assertSame(
			[
				['warning', 'RealisedFxSettlementService: realised-FX GLTransaction save failed (continuing)', ['exception' => 'store down']],
				['warning', 'RealisedFxSettlementService: RealisedFxPosting save failed (continuing)', ['exception' => 'store down']],
			],
			$this->logs
		);

	}//end testFailingSavesAreLoggedAndTheReportStands()
}//end class
