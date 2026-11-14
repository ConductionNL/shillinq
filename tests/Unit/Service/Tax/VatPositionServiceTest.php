<?php

/**
 * Unit tests for VatPositionService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Tax
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Tax;

use InvalidArgumentException;
use OCA\Shillinq\Lifecycle\Action\MaterialiseGlTransactionAction;
use OCA\Shillinq\Service\Tax\VatPositionService;
use OCA\Shillinq\Service\VATReturnService;
use OCA\Shillinq\Tests\Unit\Lifecycle\Action\InMemoryObjectStore;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The position is read through the real VATReturnService over lines posted by
 * the real posting mapper, so it is the derivation a return is prepared with.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VatPositionServiceTest extends TestCase {

	/**
	 * The store the mapper posted into and the services read from.
	 *
	 * @var InMemoryObjectStore
	 */
	private InMemoryObjectStore $store;

	/**
	 * Post documents through the real posting mapper into a fresh store.
	 *
	 * @param list<array{0: array<string,mixed>, 1: string}> $documents Each document and its source schema.
	 *
	 * @return VATReturnService A VAT return service reading that store.
	 */
	private function postedByTheMapper(array $documents): VATReturnService {
		$this->store = new InMemoryObjectStore();
		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/seeds/btw-tariffs-2026.json'), true);
		$this->store->rows['VatTariff'] = $seed['tariffs'];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$objectService = $this->store->mock($this);
		$action = new MaterialiseGlTransactionAction($objectService, $appConfig, $this->createMock(LoggerInterface::class));
		foreach ($documents as [$document, $sourceSchema]) {
			$action->execute($document, [], ['sourceSchema' => $sourceSchema], MaterialiseGlTransactionAction::class);
		}

		$this->store->rows['GLTransaction'] = $this->store->savedOf('GLTransaction');
		$this->store->rows['GLLine'] = $this->store->savedOf('GLLine');

		return new VATReturnService(appConfig: $appConfig, logger: $this->createMock(LoggerInterface::class), objectService: $objectService);
	}//end postedByTheMapper()

	/**
	 * The spec's Q4 so far: sales of EUR 20,000 at 21% and a purchase with EUR 1,500 input VAT,
	 * plus a sale in Q3 that is not in the Q4 position.
	 *
	 * @return list<array{0: array<string,mixed>, 1: string}>
	 */
	private function q4SoFar(): array {
		return [
			[
				[
					'id' => 'ar-q4-1', 'invoiceNumber' => '2026-1001', 'invoiceDate' => '2026-10-15',
					'administrationId' => 'adm-1', 'currency' => 'EUR', 'grossAmount' => 24200.0, 'netAmount' => 20000.0, 'vatAmount' => 4200.0,
					'invoiceLines' => [['itemName' => 'Advies', 'netAmount' => 20000.0, 'vatCategory' => 'S', 'vatRate' => 21]],
					'lifecycleState' => 'issued',
				],
				'ARInvoice',
			],
			[
				[
					'id' => 'ap-q4-1', 'invoiceNumber' => 'INK-1001', 'invoiceDate' => '2026-11-02', 'administrationId' => 'adm-1',
					'totalAmount' => 8642.86, 'taxAmount' => 1500.0,
					'lines' => [['accountNumber' => '7000', 'amount' => 7142.86, 'description' => 'Laptops', 'taxCode' => 'high']],
					'state' => 'posted',
				],
				'APInvoice',
			],
			[
				[
					'id' => 'ar-q3-1', 'invoiceNumber' => '2026-0901', 'invoiceDate' => '2026-09-30',
					'administrationId' => 'adm-1', 'currency' => 'EUR', 'grossAmount' => 1210.0, 'netAmount' => 1000.0, 'vatAmount' => 210.0,
					'invoiceLines' => [['itemName' => 'September', 'netAmount' => 1000.0, 'vatCategory' => 'S', 'vatRate' => 21]],
					'lifecycleState' => 'issued',
				],
				'ARInvoice',
			],
		];
	}//end q4SoFar()

	/**
	 * REQ-VPS-001 "A bookkeeper checks the position mid-quarter": box 1a base EUR 20,000 and
	 * VAT EUR 4,200, box 5b EUR 1,500, payable EUR 2,700; the September sale is not in Q4.
	 *
	 * @return void
	 */
	public function testTheQuarterPositionShowsEachBoxAndThePayableAmount(): void {
		$service = new VatPositionService(vatReturnService: $this->postedByTheMapper($this->q4SoFar()));

		$position = $service->positionFor(administrationId: 'adm-1', period: '2026-Q4');

		self::assertSame(['2026-10-01', '2026-12-31'], [$position['startDate'], $position['endDate']]);
		$byBox = array_column($position['boxes'], null, 'returnBox');
		self::assertSame(['1a', '5b'], array_keys($byBox));
		self::assertSame(20000.0, $byBox['1a']['base']);
		self::assertSame(4200.0, $byBox['1a']['vat']);
		self::assertSame('collected', $byBox['1a']['type']);
		self::assertSame(0.0, $byBox['5b']['base']);
		self::assertSame(1500.0, $byBox['5b']['vat']);
		self::assertSame(4200.0, $position['totalVATCollected']);
		self::assertSame(1500.0, $position['totalVATPaid']);
		self::assertSame(2700.0, $position['payable']);
		self::assertSame('payable', $position['direction']);
	}//end testTheQuarterPositionShowsEachBoxAndThePayableAmount()

	/**
	 * REQ-VPS-001 "The lines behind a box": the lines of box 1a add up to its figures and name their entries.
	 *
	 * @return void
	 */
	public function testTheLinesBehindABoxAddUpToIt(): void {
		$service = new VatPositionService(vatReturnService: $this->postedByTheMapper($this->q4SoFar()));

		$box = array_column($service->positionFor(administrationId: 'adm-1', period: '2026-Q4')['boxes'], null, 'returnBox')['1a'];

		self::assertSame(2, $box['lineCount']);
		self::assertCount(2, $box['lines']);
		self::assertSame(20000.0, round(array_sum(array_column($box['lines'], 'taxableAmount')), 2));
		self::assertSame(4200.0, round(array_sum(array_column($box['lines'], 'vatAmount')), 2));
		$transactions = array_unique(array_column($box['lines'], 'glTransactionId'));
		self::assertCount(1, $transactions);
		self::assertNotSame('', (string)current($transactions));
	}//end testTheLinesBehindABoxAddUpToIt()

	/**
	 * The position of a period equals the return prepared for it, box for box.
	 *
	 * @return void
	 */
	public function testThePositionOfAPeriodEqualsItsPreparedReturn(): void {
		$this->postedByTheMapper($this->q4SoFar());
		$records = new class($this->store->rows) {
			/**
			 * Rows by schema.
			 *
			 * @var array<string,list<array<string,mixed>>>
			 */
			public array $rows;

			/**
			 * The active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,list<array<string,mixed>>> $rows Rows by schema.
			 */
			public function __construct(array $rows) {
				$this->rows = $rows;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register The register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema The schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Rows of the active schema matching every filter.
			 *
			 * @param array<string,mixed> $params The query.
			 *
			 * @return list<array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$filters = ($params['filters'] ?? []);
				return array_values(
					array_filter(
						($this->rows[$this->schema] ?? []),
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
			}//end findAll()

			/**
			 * One row of the active schema.
			 *
			 * @param string $id The id.
			 *
			 * @return array<string,mixed>|null
			 */
			public function find(string $id): ?array {
				foreach (($this->rows[$this->schema] ?? []) as $row) {
					if ((string)($row['id'] ?? '') === $id) {
						return $row;
					}
				}

				return null;
			}//end find()

			/**
			 * Insert or replace a row of the active schema.
			 *
			 * @param array<string,mixed> $data The row.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $data): array {
				$data['id'] = ($data['id'] ?? ($this->schema . '-' . count($this->rows[$this->schema] ?? [])));
				foreach (($this->rows[$this->schema] ?? []) as $index => $row) {
					if ((string)($row['id'] ?? '') === (string)$data['id']) {
						$this->rows[$this->schema][$index] = $data;
						return $data;
					}
				}

				$this->rows[$this->schema][] = $data;
				return $data;
			}//end saveObject()

			/**
			 * Delete a row of the active schema.
			 *
			 * @param string $id The id.
			 *
			 * @return void
			 */
			public function deleteObject(string $id): void {
				$this->rows[$this->schema] = array_values(
					array_filter(($this->rows[$this->schema] ?? []), static fn (array $row): bool => (string)($row['id'] ?? '') !== $id)
				);
			}//end deleteObject()
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$vatReturns = new VATReturnService(
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($records)
		);

		$position = (new VatPositionService(vatReturnService: $vatReturns))->positionFor(administrationId: 'adm-1', period: '2026-Q4');
		$created = $vatReturns->createReturn(administrationId: 'adm-1', period: 'quarter', periodYear: 2026, periodNumber: 4, regime: 'standard');

		$declared = [];
		foreach ($records->rows['VATDeclaration'] as $declaration) {
			$declared[$declaration['returnBox']] = [(float)$declaration['totalTaxableAmount'], (float)$declaration['totalVATAmount']];
		}

		ksort($declared);
		$shown = [];
		foreach ($position['boxes'] as $box) {
			$shown[$box['returnBox']] = [$box['base'], $box['vat']];
		}

		self::assertSame(['1a', '5b'], array_keys($declared));
		self::assertSame($declared, $shown);
		self::assertSame((float)$created['totalVATCollected'], $position['totalVATCollected']);
		self::assertSame((float)$created['totalVATPaid'], $position['totalVATPaid']);
		self::assertSame(round((float)$created['totalVATCollected'] - (float)$created['totalVATPaid'], 2), $position['payable']);
	}//end testThePositionOfAPeriodEqualsItsPreparedReturn()

	/**
	 * A month, a year and a September with only the Q3 sale; more VAT paid than collected is refundable.
	 *
	 * @return void
	 */
	public function testAMonthOrAYearIsReadAndARefundIsNamed(): void {
		$service = new VatPositionService(vatReturnService: $this->postedByTheMapper($this->q4SoFar()));

		$september = $service->positionFor(administrationId: 'adm-1', period: '2026-09');
		self::assertSame(['2026-09-01', '2026-09-30'], [$september['startDate'], $september['endDate']]);
		self::assertSame(210.0, $september['payable']);

		$november = $service->positionFor(administrationId: 'adm-1', period: '2026-11');
		self::assertSame(-1500.0, $november['payable']);
		self::assertSame('refundable', $november['direction']);

		$year = $service->positionFor(administrationId: 'adm-1', period: '2026');
		self::assertSame(['2026-01-01', '2026-12-31'], [$year['startDate'], $year['endDate']]);
		self::assertSame(2910.0, $year['payable']);

		$empty = $service->positionFor(administrationId: 'adm-other', period: '2026-Q4');
		self::assertSame([], $empty['boxes']);
		self::assertSame('nil', $empty['direction']);
	}//end testAMonthOrAYearIsReadAndARefundIsNamed()

	/**
	 * Period bounds for each form, including February and the last quarter.
	 *
	 * @return void
	 */
	public function testPeriodBounds(): void {
		$service = new VatPositionService(vatReturnService: $this->createMock(VATReturnService::class));

		self::assertSame(['2026-01-01', '2026-03-31'], $service->periodBounds(period: '2026-Q1'));
		self::assertSame(['2026-04-01', '2026-06-30'], $service->periodBounds(period: ' 2026-Q2 '));
		self::assertSame(['2028-02-01', '2028-02-29'], $service->periodBounds(period: '2028-02'));
		self::assertSame(['2026-12-01', '2026-12-31'], $service->periodBounds(period: '2026-12'));
	}//end testPeriodBounds()

	/**
	 * A period in none of the three forms is refused, naming what it got, before anything is read.
	 *
	 * @return void
	 */
	public function testAMalformedPeriodIsRefused(): void {
		$vatReturns = $this->createMock(VATReturnService::class);
		$vatReturns->expects(self::never())->method('positionByBox');
		$service = new VatPositionService(vatReturnService: $vatReturns);

		foreach (['', '2026-Q5', '2026-13', '26-Q1', '2026-Q4; drop'] as $period) {
			try {
				$service->positionFor(administrationId: 'adm-1', period: $period);
				self::fail('Accepted period "' . $period . '"');
			} catch (InvalidArgumentException $e) {
				self::assertStringContainsString('YYYY-Qn, YYYY-MM or YYYY', $e->getMessage());
			}
		}
	}//end testAMalformedPeriodIsRefused()
}//end class
