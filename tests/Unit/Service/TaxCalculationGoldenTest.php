<?php

/**
 * Golden record of TaxCalculationService::calculateAllPeriodEnd(): every
 * OpenRegister read (schema and filters, with the number of writes made
 * before it), every write in order, and every warning, over one fixture
 * that reaches each branch of the deferred-tax calculations.
 *
 * The record is tests/Unit/Service/fixtures/tax-calculation.golden.json;
 * SHILLINQ_GOLDEN_RECORD=1 rewrites it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\TaxCalculationHelper;
use OCA\Shillinq\Service\TaxCalculationService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins the deferred-tax period-end run before its methods are split.
 *
 * @covers \OCA\Shillinq\Service\TaxCalculationService
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TaxCalculationGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/tax-calculation.golden.json';

	/**
	 * The fixture every run starts from.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function fixture(): array {
		return [
			'Account' => [
				['id' => 'a-0', 'administrationId' => 'adm-1', 'accountNumber' => '0100', 'commercialCarryingAmount' => 10],
				['id' => 'a-1', 'administrationId' => 'adm-1', 'taxBasisDifferenceCategory' => 'fixed-assets', 'commercialCarryingAmount' => 10],
				['id' => 'a-2', 'administrationId' => 'adm-1', 'accountNumber' => '0200', 'taxBasisDifferenceCategory' => 'fixed-assets', 'jurisdiction' => '', 'commercialCarryingAmount' => 1000.10, 'taxCarryingAmount' => 400],
				['id' => 'a-3', 'administrationId' => 'adm-1', 'accountNumber' => '0300', 'taxBasisDifferenceCategory' => 'provisions', 'jurisdiction' => 'DE', 'commercialCarryingAmount' => 100, 'taxCarryingAmount' => 300.5],
				['id' => 'a-4', 'administrationId' => 'adm-1', 'accountNumber' => '0400', 'taxBasisDifferenceCategory' => 'provisions', 'commercialCarryingAmount' => 50, 'taxCarryingAmount' => 50],
				['id' => 'a-5', 'administrationId' => 'adm-1', 'accountNumber' => '0500', 'taxBasisDifferenceCategory' => 'leases', 'jurisdiction' => 'BE', 'commercialCarryingAmount' => 70],
				['id' => 'a-6', 'administrationId' => 'adm-2', 'accountNumber' => '0600', 'taxBasisDifferenceCategory' => 'leases', 'commercialCarryingAmount' => 70],
			],
			'FiscalPeriod' => [
				[
					'id' => 'fp-1',
					'periodId' => 'P2026',
					'administrationId' => 'adm-1',
					'taxableProfitByJurisdiction' => ['NL' => 500.25],
					'profitBeforeTax' => 2.5,
					'enactedTaxRates' => [
						'NL' => ['rate' => 2500, 'effectiveDate' => '2026-01-01'],
						'DE' => ['rate' => 3000, 'effectiveDate' => ''],
						'BE' => ['rate' => 0, 'effectiveDate' => '2026-01-01'],
					],
				],
			],
			'TaxLossCarryForward' => [
				['id' => 'l-1', 'administrationId' => 'adm-1', 'jurisdiction' => 'NL', 'remainingAmount' => 30000, 'utilisedAmount' => 100, 'originatingYear' => 2021, 'applicableRegime' => '2022-onwards', 'dtaRecognised' => 500],
				['id' => 'l-2', 'administrationId' => 'adm-1', 'jurisdiction' => 'NL', 'remainingAmount' => 40000, 'originatingYear' => 2019, 'dtaRecognised' => 200, 'dtaRecoverabilityRationale' => 'Budget 2027'],
				['administrationId' => 'adm-1', 'jurisdiction' => 'NL', 'remainingAmount' => 5000, 'originatingYear' => 2022, 'dtaRecognised' => 1, 'dtaRecoverabilityRationale' => '  '],
				['id' => 'l-4', 'administrationId' => 'adm-1', 'jurisdiction' => 'NL', 'remainingAmount' => 0, 'originatingYear' => 2015],
				['id' => 'l-5', 'administrationId' => 'adm-1', 'remainingAmount' => 100, 'originatingYear' => 2020, 'applicableRegime' => 'pre-2022'],
				['id' => 'l-6', 'administrationId' => 'adm-1', 'jurisdiction' => 'DE', 'remainingAmount' => 900, 'originatingYear' => 2020],
				['id' => 'l-7', 'administrationId' => 'adm-2', 'jurisdiction' => 'NL', 'remainingAmount' => 900, 'originatingYear' => 2020, 'dtaRecognised' => 9],
			],
			'TemporaryDifference' => [
				['id' => 'td-a', 'slug' => 'td-old-a', 'periodId' => 'P2026', 'administrationId' => 'adm-1', 'jurisdiction' => 'NL', 'category' => 'provisions', 'temporaryDifference' => 100000, 'taxRate' => 2000, 'expectedReversalYear' => 2027, 'deferredTaxBalance' => 20000],
				['id' => 'td-b', 'slug' => 'td-old-b', 'periodId' => 'P2026', 'administrationId' => 'adm-1', 'jurisdiction' => 'NL', 'category' => 'leases', 'temporaryDifference' => 50000, 'taxRate' => 2500, 'expectedReversalYear' => 2030, 'deferredTaxBalance' => 12500],
				['id' => 'td-c', 'slug' => 'td-old-c', 'periodId' => 'P2026', 'administrationId' => 'adm-1', 'jurisdiction' => 'NL', 'category' => 'fixed-assets', 'temporaryDifference' => 70000, 'taxRate' => 2000, 'expectedReversalYear' => 2020, 'deferredTaxBalance' => 14000],
				['slug' => 'td-old-d', 'periodId' => 'P2026', 'administrationId' => 'adm-1', 'jurisdiction' => 'NL', 'temporaryDifference' => -30000, 'taxRate' => 2000, 'expectedReversalYear' => 2028, 'deferredTaxBalance' => -6000],
				['id' => 'td-e', 'slug' => 'td-old-e', 'periodId' => 'P2026', 'administrationId' => 'adm-1', 'jurisdiction' => 'NL', 'temporaryDifference' => -10000, 'taxRate' => 2000, 'expectedReversalYear' => 2029, 'deferredTaxBalance' => -2000],
				['id' => 'td-f', 'slug' => 'td-old-f', 'periodId' => 'P2025', 'administrationId' => 'adm-1', 'jurisdiction' => 'FR', 'temporaryDifference' => 10000],
			],
			'DeferredTaxMovement' => [
				['id' => 'm-1', 'slug' => 'dtm-old-1', 'periodId' => 'P2026', 'administrationId' => 'adm-1', 'jurisdiction' => 'NL', 'category' => 'provisions', 'openingBalance' => 5000, 'reversedInPeriod' => 300, 'rateChangeAdjustment' => 7, 'acquiredViaBusinessCombination' => 11, 'translationAdjustment' => 13, 'recognisedInOCI' => 17, 'linkedJournalEntries' => ['je-1']],
			],
			'TaxProvision' => [
				['id' => 'tp-x', 'slug' => 'tp-P2026-DE', 'periodId' => 'P2026', 'administrationId' => 'adm-1', 'jurisdiction' => 'DE', 'linkedVpbReturn' => 'vpb-1'],
			],
		];

	}//end fixture()

	/**
	 * The run matches the record.
	 *
	 * @return void
	 */
	public function testPeriodEndRunMatchesTheGoldenRecord(): void {
		$saved = [];
		$log = [];
		$store = new class(self::fixture(), $saved, $log) extends InMemoryObjectServiceStub {
			/**
			 * @var array<int,mixed>
			 */
			private array $calls;

			/**
			 * @var array<int,mixed>
			 */
			private array $sink;

			/**
			 * @var string
			 */
			private string $current = '';

			/**
			 * @param array<string,array<int,array<string,mixed>>> $data  Rows.
			 * @param array<int,mixed>                             $saved Save sink.
			 * @param array<int,mixed>                             $calls Read log.
			 */
			public function __construct(array $data, array &$saved, array &$calls) {
				parent::__construct(data: $data, saveSink: $saved);
				$this->sink = &$saved;
				$this->calls = &$calls;
			}

			/**
			 * @param string|int $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string|int $schema): static {
				$this->current = (string)$schema;
				return parent::setSchema($schema);
			}

			/**
			 * @param array<string,mixed> $config        Query.
			 * @param bool                $_rbac         RBAC flag.
			 * @param bool                $_multitenancy Tenancy flag.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = ['writesBefore' => count($this->sink), 'schema' => $this->current, 'query' => $config];
				return parent::findAll($config, $_rbac, $_multitenancy);
			}
		};

		$warnings = [];
		$logger = new class($warnings) extends AbstractLogger {
			/**
			 * @var array<int,mixed>
			 */
			private array $lines;

			/**
			 * @param array<int,mixed> $lines Shared log.
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

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$service = new TaxCalculationService($appConfig, new TaxCalculationHelper(), $logger, $store);
		$service->calculateAllPeriodEnd('P2026', 'adm-1');

		$actual = ['reads' => $log, 'writes' => $saved, 'log' => $warnings];
		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		if (getenv('SHILLINQ_GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		self::assertSame((string)file_get_contents(self::GOLDEN), $json);

	}//end testPeriodEndRunMatchesTheGoldenRecord()

	/**
	 * A new rate-change movement books this run's adjustment in P&L and the
	 * closing balance, not the running total it carries.
	 *
	 * @return void
	 */
	public function testANewRateChangeMovementBooksOnlyThisRunsAdjustment(): void {
		$method = new \ReflectionMethod(TaxCalculationService::class, 'newRateChangeMovementRow');
		$row = $method->invoke(null, 'dtm-x', ['rateChangeAdjustment' => 12], 5);

		$this->assertSame(['dtm-x', 5, 5, 12], [$row['slug'], $row['recognisedInPL'], $row['closingBalance'], $row['rateChangeAdjustment']]);
	}//end testANewRateChangeMovementBooksOnlyThisRunsAdjustment()
}//end class
