<?php

/**
 * Unit tests for VATReturnService.
 *
 * Exercises the GL-derivation, lifecycle, and totals roll-up paths for
 * the bookkeeping-vat-btw-filing change (issue #127). Uses an inline
 * fake ObjectService stub so the real OR-API call shape (find /
 * findAll / saveObject / deleteObject) stays honest.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Lifecycle\Action\MaterialiseGlTransactionAction;
use OCA\Shillinq\Service\VATReturnService;
use OCA\Shillinq\Tests\Unit\Lifecycle\Action\InMemoryObjectStore;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests VATReturnService against an inline ObjectService fake.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VATReturnServiceTest extends TestCase {

	/**
	 * Mock container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock app config.
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
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build the service wired against the given fake ObjectService.
	 *
	 * @param object $stub The ObjectService fake.
	 *
	 * @return VATReturnService
	 */
	private function buildService(object $stub): VATReturnService {
		$this->container->method('get')->willReturn($stub);

		return new VATReturnService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Build the inline ObjectService stub seeded with rows per schema.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $seed Rows keyed by schema slug.
	 *
	 * @return object
	 */
	private function fakeObjectService(array $seed = []): object {
		return new class($seed) {
			/**
			 * Records keyed by schema slug.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Auto-increment counter for synthetic ids.
			 *
			 * @var int
			 */
			private int $idCounter = 0;

			/**
			 * Active schema (set via setSchema()).
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $seed Rows keyed by schema slug.
			 */
			public function __construct(array $seed) {
				$this->data = array_merge(
					[
						'GLTransaction' => [],
						'GLLine' => [],
						'VatTariff' => [],
						'BtwAangifte' => [],
						'VATDeclaration' => [],
						'VATLine' => [],
					],
					$seed
				);
			}//end __construct()

			/**
			 * Fluent register setter (no-op).
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter; records the active schema.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return the data set for the active schema, applying simple equality filters.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->data[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
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
			 * Find a single record by id.
			 *
			 * @param string $id Record id.
			 *
			 * @return array<string,mixed>|null
			 */
			public function find(string $id): ?array {
				foreach (($this->data[$this->schema] ?? []) as $row) {
					if (((string)($row['id'] ?? '')) === $id) {
						return $row;
					}
				}

				return null;
			}//end find()

			/**
			 * Save a record (insert or update) and return the persisted shape.
			 *
			 * @param array<string,mixed> $data Record body.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $data): array {
				if (isset($data['id']) === false || $data['id'] === '') {
					$this->idCounter++;
					$data['id'] = $this->schema . '-' . $this->idCounter;
				}

				foreach (($this->data[$this->schema] ?? []) as $idx => $row) {
					if (((string)($row['id'] ?? '')) === ((string)$data['id'])) {
						$this->data[$this->schema][$idx] = $data;
						return $data;
					}
				}

				$this->data[$this->schema][] = $data;
				return $data;
			}//end saveObject()

			/**
			 * Delete a record by id.
			 *
			 * @param string $id Record id.
			 *
			 * @return void
			 */
			public function deleteObject(string $id): void {
				foreach (($this->data[$this->schema] ?? []) as $idx => $row) {
					if (((string)($row['id'] ?? '')) === $id) {
						unset($this->data[$this->schema][$idx]);
					}
				}

				$this->data[$this->schema] = array_values($this->data[$this->schema] ?? []);
			}//end deleteObject()

			/**
			 * Expose the live data set (for assertions).
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function dump(string $schema): array {
				return ($this->data[$schema] ?? []);
			}//end dump()
		};

	}//end fakeObjectService()

	/**
	 * Post documents through the real posting mapper and return the rows it
	 * wrote, so the return is prepared from ledger lines as they are booked.
	 *
	 * @param list<array{0: array<string,mixed>, 1: string}> $documents Each document and its source schema.
	 *
	 * @return array<string,list<array<string,mixed>>> GLTransaction, GLLine and VatTariff rows.
	 */
	private function postedByTheMapper(array $documents): array {
		$store = new InMemoryObjectStore();
		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/seeds/btw-tariffs-2026.json'), true);
		$store->rows['VatTariff'] = $seed['tariffs'];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$action = new MaterialiseGlTransactionAction($store->mock($this), $appConfig, $this->createMock(LoggerInterface::class));
		foreach ($documents as [$document, $sourceSchema]) {
			$action->execute($document, [], ['sourceSchema' => $sourceSchema], MaterialiseGlTransactionAction::class);
		}

		return [
			'GLTransaction' => $store->savedOf('GLTransaction'),
			'GLLine' => $store->savedOf('GLLine'),
			'VatTariff' => $seed['tariffs'],
		];
	}//end postedByTheMapper()

	/**
	 * The design's Korenbloem quarter: a sale of EUR 10,000 at the low tariff
	 * with EUR 900 VAT, and purchases of EUR 4,000 at the high tariff and
	 * EUR 2,000 at the low tariff with EUR 1,020 input VAT.
	 *
	 * @return list<array{0: array<string,mixed>, 1: string}>
	 */
	private function korenbloemQ3(): array {
		return [
			[
				[
					'id' => 'ar-kb-1', 'invoiceNumber' => '2026-0301', 'invoiceDate' => '2026-08-14',
					'administrationId' => 'adm-kb', 'currency' => 'EUR', 'grossAmount' => 10900.0, 'netAmount' => 10000.0, 'vatAmount' => 900.0,
					'invoiceLines' => [['itemName' => 'Brood en banket', 'netAmount' => 10000.0, 'vatCategory' => 'S', 'vatRate' => 9]],
					'lifecycleState' => 'issued',
				],
				'ARInvoice',
			],
			[
				[
					'id' => 'ap-kb-1', 'invoiceNumber' => 'INK-301', 'invoiceDate' => '2026-08-02', 'administrationId' => 'adm-kb',
					'totalAmount' => 7020.0, 'taxAmount' => 1020.0,
					'lines' => [
						['accountNumber' => '7000', 'amount' => 4000.0, 'description' => 'Oven', 'taxCode' => 'high'],
						['accountNumber' => '7010', 'amount' => 2000.0, 'description' => 'Meel', 'taxCode' => 'low'],
					],
					'state' => 'posted',
				],
				'APInvoice',
			],
		];
	}//end korenbloemQ3()

	/**
	 * Declarations of a prepared return keyed by box.
	 *
	 * @param object $stub The ObjectService fake.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function declarationsByBox(object $stub): array {
		$byBox = [];
		foreach ($stub->dump('VATDeclaration') as $declaration) {
			$byBox[(string)($declaration['returnBox'] ?? '')] = $declaration;
		}

		ksort($byBox);
		return $byBox;
	}//end declarationsByBox()

	/**
	 * REQ-VBTW-004 scenario "A quarterly return aggregates the period's
	 * postings": box 1b shows EUR 10,000 and EUR 900, box 5b EUR 1,020, the
	 * amount payable is EUR -120. Lines outside the period, on a draft
	 * transaction or without a box are not in the return, and what is
	 * written validates against the merged register.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.1
	 */
	public function testTheKorenbloemQuarterSumsTheBookedLinesPerBox(): void {
		$documents = $this->korenbloemQ3();
		$documents[] = [
			[
				'id' => 'ar-kb-0', 'invoiceNumber' => '2026-0201', 'invoiceDate' => '2026-06-30',
				'administrationId' => 'adm-kb', 'currency' => 'EUR', 'grossAmount' => 1210.0, 'netAmount' => 1000.0, 'vatAmount' => 210.0,
				'invoiceLines' => [['itemName' => 'Juni', 'netAmount' => 1000.0, 'vatCategory' => 'S', 'vatRate' => 21]],
				'lifecycleState' => 'issued',
			],
			'ARInvoice',
		];
		$rows = $this->postedByTheMapper($documents);

		// A draft transaction in the period, with a stamped line: not booked, so not declared.
		$rows['GLTransaction'][] = [
			'id' => 'gl-draft', 'transactionNumber' => 'D-1', 'postingDate' => '2026-09-01', 'periodId' => '2026-09',
			'currency' => 'EUR', 'description' => 'Concept', 'state' => 'draft', 'administrationId' => 'adm-kb',
		];
		$rows['GLLine'][] = [
			'id' => 'gl-draft-1', 'transactionId' => 'gl-draft', 'lineNumber' => 1, 'accountNumber' => '8000', 'side' => 'credit',
			'amount' => 500.0, 'currency' => 'EUR', 'administrationId' => 'adm-kb', 'vatTariffCode' => 'high', 'vatReturnBox' => '1a',
			'vatAmountKind' => 'base',
		];

		$stub = $this->fakeObjectService($rows);
		$created = $this->buildService($stub)->createReturn(
			administrationId: 'adm-kb',
			period: 'quarter',
			periodYear: 2026,
			periodNumber: 3,
			regime: 'standard'
		);

		$byBox = $this->declarationsByBox($stub);
		self::assertSame(['1b', '5b'], array_keys($byBox));
		self::assertSame(10000.0, $byBox['1b']['totalTaxableAmount']);
		self::assertSame(900.0, $byBox['1b']['totalVATAmount']);
		self::assertSame('collected', $byBox['1b']['type']);
		self::assertSame(0.0, $byBox['5b']['totalTaxableAmount']);
		self::assertSame(1020.0, $byBox['5b']['totalVATAmount']);
		self::assertSame('paid', $byBox['5b']['type']);

		self::assertSame(900.0, (float)$created['totalVATCollected']);
		self::assertSame(1020.0, (float)$created['totalVATPaid']);
		self::assertSame(-120.0, round((float)$created['totalVATCollected'] - (float)$created['totalVATPaid'], 2));
		self::assertSame(10000.0, (float)$created['totalTaxableAmount']);

		// One VAT line per contributing ledger line: the revenue line, the output VAT line and two input VAT lines.
		$lines = $stub->dump('VATLine');
		self::assertSame(['1b', '1b', '5b', '5b'], array_map(static fn (array $l): string => $l['returnBox'], $lines));
		self::assertSame([10000.0, 0.0, 0.0, 0.0], array_map(static fn (array $l): float => $l['taxableAmount'], $lines));
		self::assertSame([0.0, 900.0, 840.0, 180.0], array_map(static fn (array $l): float => $l['vatAmount'], $lines));

		foreach ($stub->dump('VATDeclaration') as $declaration) {
			self::assertSame([], RegisterSchema::errors(slug: 'VATDeclaration', object: $declaration));
		}

		foreach ($lines as $line) {
			self::assertSame([], RegisterSchema::errors(slug: 'VATLine', object: $line));
		}
	}//end testTheKorenbloemQuarterSumsTheBookedLinesPerBox()

	/**
	 * The VAT is taken as booked, not recalculated: an invoice that charged
	 * EUR 7.01 on EUR 33.33 at 21 percent (the stray cent of its breakdown)
	 * declares EUR 7.01 in box 1a, where base times rate gives EUR 7.00.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.1
	 */
	public function testTheVatIsTakenAsBookedAndNotRecalculatedFromARate(): void {
		$rows = $this->postedByTheMapper(
			[
				[
					[
						'id' => 'ar-10', 'invoiceNumber' => '2026-0091', 'invoiceDate' => '2026-08-12', 'periodId' => '2026-08',
						'administrationId' => 'adm-1', 'currency' => 'EUR', 'grossAmount' => 76.67, 'netAmount' => 66.66, 'vatAmount' => 10.01,
						'invoiceLines' => [
							['itemName' => 'A', 'netAmount' => 33.33, 'vatCategory' => 'S', 'vatRate' => 21],
							['itemName' => 'B', 'netAmount' => 33.33, 'vatCategory' => 'S', 'vatRate' => 9],
						],
						'vatBreakdown' => [['category' => 'S', 'rate' => 21, 'taxableAmount' => 33.33, 'taxAmount' => 7.0]],
						'lifecycleState' => 'issued',
					],
					'ARInvoice',
				],
			]
		);
		$stub = $this->fakeObjectService($rows);
		$stub->setSchema('BtwAangifte')->saveObject(
			['id' => 'ret-q3', 'administrationId' => 'adm-1', 'startDate' => '2026-07-01', 'endDate' => '2026-09-30', 'statusCode' => 'draft']
		);

		$totals = $this->buildService($stub)->deriveVATLines(
			returnId: 'ret-q3',
			administrationId: 'adm-1',
			startDate: '2026-07-01',
			endDate: '2026-09-30',
			regime: 'standard'
		);

		$byBox = $this->declarationsByBox($stub);
		self::assertSame(7.01, $byBox['1a']['totalVATAmount']);
		self::assertSame(21.0, $byBox['1a']['taxRate']);
		self::assertSame(3.0, $byBox['1b']['totalVATAmount']);
		self::assertSame(10.01, $totals['totalVATCollected']);
		self::assertSame(4, $totals['lineCount']);
	}//end testTheVatIsTakenAsBookedAndNotRecalculatedFromARate()

	/**
	 * A reverse-charged purchase declares its base and its VAT in the box of
	 * its tariff (2a domestic, 4b from the EU) and the same VAT in 5b, and its
	 * VAT lines say reverse charge.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.1
	 */
	public function testAReverseChargedPurchaseDeclaresItsBaseInTheBoxOfItsTariff(): void {
		$rows = $this->postedByTheMapper(
			[
				[
					[
						'id' => 'ap-10', 'invoiceNumber' => 'INK-91', 'invoiceDate' => '2026-08-03', 'administrationId' => 'adm-1',
						'totalAmount' => 3000.0, 'taxAmount' => 0,
						'lines' => [
							['accountNumber' => '7100', 'amount' => 2000.0, 'description' => 'Onderaanneming', 'taxCode' => 'reverse-charge'],
							['accountNumber' => '7110', 'amount' => 1000.0, 'description' => 'Machine uit Duitsland', 'taxCode' => 'intra-eu-acquisition'],
						],
						'state' => 'posted',
					],
					'APInvoice',
				],
			]
		);
		$stub = $this->fakeObjectService($rows);
		$stub->setSchema('BtwAangifte')->saveObject(
			['id' => 'ret-rc', 'administrationId' => 'adm-1', 'startDate' => '2026-07-01', 'endDate' => '2026-09-30', 'statusCode' => 'draft']
		);

		$totals = $this->buildService($stub)->deriveVATLines(
			returnId: 'ret-rc',
			administrationId: 'adm-1',
			startDate: '2026-07-01',
			endDate: '2026-09-30',
			regime: 'standard'
		);

		$byBox = $this->declarationsByBox($stub);
		ksort($byBox);
		self::assertSame(['2a', '4b', '5b'], array_keys($byBox));
		self::assertSame(2000.0, $byBox['2a']['totalTaxableAmount']);
		self::assertSame(1000.0, $byBox['4b']['totalTaxableAmount']);
		self::assertSame('reverse-charge', $byBox['2a']['type']);
		// Task 1.2: the VAT is owed next to the base and deducted in 5b, so the net is nil.
		self::assertSame(420.0, $byBox['2a']['totalVATAmount']);
		self::assertSame(210.0, $byBox['4b']['totalVATAmount']);
		self::assertSame(630.0, $byBox['5b']['totalVATAmount']);
		self::assertSame(630.0, $totals['totalVATCollected']);
		self::assertSame(630.0, $totals['totalVATPaid']);
		self::assertSame(3000.0, $totals['totalTaxableAmount']);
		foreach ($stub->dump('VATLine') as $line) {
			self::assertTrue($line['reverseChargeApplicable']);
		}
	}//end testAReverseChargedPurchaseDeclaresItsBaseInTheBoxOfItsTariff()

	/**
	 * A credit note books its sale lines on the opposite side, so it lowers
	 * the box instead of adding to it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.1
	 */
	public function testALineBookedOnTheOppositeSideLowersItsBox(): void {
		$rows = $this->postedByTheMapper($this->korenbloemQ3());
		$rows['GLTransaction'][] = [
			'id' => 'gl-cn', 'transactionNumber' => 'CN-1', 'postingDate' => '2026-09-10', 'periodId' => '2026-09',
			'currency' => 'EUR', 'description' => 'Creditnota', 'state' => 'posted', 'administrationId' => 'adm-kb',
		];
		foreach ([['8000', 1000.0, 'base'], ['2110', 90.0, 'vat']] as $index => [$account, $amount, $kind]) {
			$rows['GLLine'][] = [
				'id' => 'gl-cn-' . $index, 'transactionId' => 'gl-cn', 'lineNumber' => ($index + 1), 'accountNumber' => $account,
				'side' => 'debit', 'amount' => $amount, 'currency' => 'EUR', 'administrationId' => 'adm-kb',
				'vatTariffCode' => 'low', 'vatReturnBox' => '1b', 'vatAmountKind' => $kind,
			];
		}

		$stub = $this->fakeObjectService($rows);
		$this->buildService($stub)->createReturn(
			administrationId: 'adm-kb',
			period: 'quarter',
			periodYear: 2026,
			periodNumber: 3,
			regime: 'standard'
		);

		$byBox = $this->declarationsByBox($stub);
		self::assertSame(9000.0, $byBox['1b']['totalTaxableAmount']);
		self::assertSame(810.0, $byBox['1b']['totalVATAmount']);
	}//end testALineBookedOnTheOppositeSideLowersItsBox()

	/**
	 * KOR regime short-circuits to zero totals (REQ-VAT-004).
	 *
	 * @return void
	 */
	public function testDeriveVATLinesKorRegimeZeroes(): void {
		$stub = $this->fakeObjectService();
		$service = $this->buildService($stub);
		$stub->setSchema('BtwAangifte')->saveObject(['id' => 'ret-kor', 'statusCode' => 'draft']);

		$totals = $service->deriveVATLines(
			returnId: 'ret-kor',
			administrationId: 'adm-1',
			startDate: '2026-01-01',
			endDate: '2026-03-31',
			regime: 'kor'
		);

		self::assertSame(0, $totals['lineCount']);
		self::assertSame(0.0, $totals['totalVATCollected']);
		self::assertSame(0.0, $totals['totalVATPaid']);
		self::assertSame([], $stub->dump('VATLine'));

	}//end testDeriveVATLinesKorRegimeZeroes()

	/**
	 * Empty GL produces zero totals and no lines.
	 *
	 * @return void
	 */
	public function testDeriveVATLinesEmptyGL(): void {
		$stub = $this->fakeObjectService();
		$service = $this->buildService($stub);
		$stub->setSchema('BtwAangifte')->saveObject(['id' => 'ret-empty', 'statusCode' => 'draft']);

		$totals = $service->deriveVATLines(
			returnId: 'ret-empty',
			administrationId: 'adm-1',
			startDate: '2026-01-01',
			endDate: '2026-03-31',
			regime: 'standard'
		);

		self::assertSame(0, $totals['lineCount']);
		self::assertSame(0.0, $totals['totalVATCollected']);
		self::assertSame(0.0, $totals['totalVATPaid']);

	}//end testDeriveVATLinesEmptyGL()

	/**
	 * submitReturn() transitions draft → submitted and stamps the submissionDate.
	 *
	 * @return void
	 */
	public function testSubmitReturnTransitionsToSubmitted(): void {
		$stub = $this->fakeObjectService();
		$service = $this->buildService($stub);
		$stub->setSchema('BtwAangifte')->saveObject(
			[
				'id' => 'ret-sub',
				'statusCode' => 'draft',
				'totalVATCollected' => 100.0,
				'totalVATPaid' => 50.0,
			]
		);

		$result = $service->submitReturn(returnId: 'ret-sub', userId: 'alice');

		self::assertSame('submitted', $result['statusCode']);
		self::assertNotNull($result['submissionDate']);

	}//end testSubmitReturnTransitionsToSubmitted()

	/**
	 * submitReturn() rejects non-draft returns with a RuntimeException.
	 *
	 * @return void
	 */
	public function testSubmitReturnRejectsNonDraft(): void {
		$stub = $this->fakeObjectService();
		$service = $this->buildService($stub);
		$stub->setSchema('BtwAangifte')->saveObject(['id' => 'ret-sub-2', 'statusCode' => 'submitted']);

		$this->expectException(\RuntimeException::class);
		$service->submitReturn(returnId: 'ret-sub-2', userId: 'alice');

	}//end testSubmitReturnRejectsNonDraft()

	/**
	 * rebaseReturn() transitions submitted → draft, clears stamps, re-derives.
	 *
	 * @return void
	 */
	public function testRebaseReturnClearsAndRederives(): void {
		$stub = $this->fakeObjectService();
		$service = $this->buildService($stub);
		$stub->setSchema('BtwAangifte')->saveObject(
			[
				'id' => 'ret-reb',
				'administrationId' => 'adm-1',
				'startDate' => '2026-01-01',
				'endDate' => '2026-03-31',
				'regime' => 'standard',
				'statusCode' => 'submitted',
				'submissionDate' => '2026-04-25T10:00:00Z',
				'filingReference' => 'TBD-12345',
			]
		);
		$stub->setSchema('VATLine')->saveObject(['id' => 'line-old', 'returnId' => 'ret-reb', 'type' => 'collected', 'vatAmount' => 100.0]);

		$result = $service->rebaseReturn(returnId: 'ret-reb', userId: 'bob');

		self::assertSame('draft', $result['statusCode']);
		self::assertNull($result['submissionDate']);
		self::assertNull($result['filingReference']);
		self::assertCount(0, $stub->dump('VATLine'));

	}//end testRebaseReturnClearsAndRederives()
}//end class
