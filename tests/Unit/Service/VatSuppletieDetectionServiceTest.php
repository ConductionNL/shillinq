<?php

/**
 * Unit tests for VatSuppletieDetectionService.
 *
 * Exercises the GL-drift detection, per-rubriek delta compilation, €1.000
 * suppletie-grens decision, and draft GL correction posting for the
 * btw-suppletie-detection change (REQ-VBTW-013, REQ-VBTW-014). Uses an
 * inline fake ObjectService stub so the real OR-API call shape (find /
 * findAll / saveObject) stays honest, matching VATReturnServiceTest's
 * pattern.
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
 * @spec openspec/changes/btw-suppletie-detection/specs/bookkeeping-vat-btw-filing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\VATReturnService;
use OCA\Shillinq\Service\VatSuppletieDetectionService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests VatSuppletieDetectionService against an inline ObjectService fake.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VatSuppletieDetectionServiceTest extends TestCase {

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
	 * Build the VATReturnService + VatSuppletieDetectionService pair wired
	 * against the same fake ObjectService.
	 *
	 * @param object $stub The ObjectService fake.
	 *
	 * @return array{0:VATReturnService,1:VatSuppletieDetectionService}
	 */
	private function buildServices(object $stub): array {
		$this->container->method('get')->willReturn($stub);

		$vatReturnService = new VATReturnService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

		$detectionService = new VatSuppletieDetectionService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			vatReturnService: $vatReturnService,
			objectService: new DuckObjectServiceAdapter($stub),
		);

		return [$vatReturnService, $detectionService];
	}//end buildServices()

	/**
	 * Build the inline ObjectService stub, pre-seeded with the given rows
	 * per schema.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $seed Rows keyed by schema slug.
	 *
	 * @return object
	 */
	private function fakeObjectService(array $seed): object {
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
			 * @var integer
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
				$defaults = [
					'Account' => [],
					'GLTransaction' => [],
					'GLLine' => [],
					'BtwAangifte' => [],
					'VATDeclaration' => [],
					'VATLine' => [],
					'VatCorrection' => [],
				];
				$this->data = array_merge($defaults, $seed);
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
	 * Posted ledger rows in the shape the posting mapper writes them: one
	 * GLTransaction per entry and its GLLine rows, stamped with tariff, box
	 * and amount kind.
	 *
	 * Per transaction id: its posting date and its lines as
	 * [account, side, amount, tariff, box, kind].
	 *
	 * @param array<string,array{0:string,1:list<array{0:string,1:string,2:float,3:string,4:string,5:string}>}> $entries The entries.
	 *
	 * @return array{GLTransaction:list<array<string,mixed>>,GLLine:list<array<string,mixed>>}
	 */
	private function ledger(array $entries): array {
		$rows = ['GLTransaction' => [], 'GLLine' => []];
		foreach ($entries as $transactionId => [$postingDate, $lines]) {
			$rows['GLTransaction'][] = [
				'id' => $transactionId, 'transactionNumber' => strtoupper($transactionId), 'postingDate' => $postingDate,
				'periodId' => substr($postingDate, 0, 7), 'currency' => 'EUR', 'description' => 'Verkoop', 'state' => 'posted',
				'administrationId' => 'adm-1',
			];
			foreach ($lines as $index => [$account, $side, $amount, $tariff, $box, $kind]) {
				$rows['GLLine'][] = [
					'id' => $transactionId . '-' . ($index + 1), 'transactionId' => $transactionId, 'lineNumber' => ($index + 1),
					'accountNumber' => $account, 'side' => $side, 'amount' => $amount, 'currency' => 'EUR', 'periodId' => substr($postingDate, 0, 7),
					'administrationId' => 'adm-1', 'vatTariffCode' => $tariff, 'vatReturnBox' => $box, 'vatAmountKind' => $kind,
				];
			}
		}

		return $rows;
	}//end ledger()

	/**
	 * The Q1 sale every drift test starts from: EUR 15,000 at the high tariff with EUR 3,150 VAT.
	 *
	 * @return array<string,array{0:string,1:list<array{0:string,1:string,2:float,3:string,4:string,5:string}>}>
	 */
	private function q1Sale(): array {
		return [
			'gl-1' => [
				'2026-01-15',
				[
					['8000', 'credit', 15000.0, 'high', '1a', 'base'],
					['2110', 'credit', 3150.0, 'high', '1a', 'vat'],
				],
			],
		];
	}//end q1Sale()

	/**
	 * The as-filed Q1 return of q1Sale(), as VATReturnService wrote it.
	 *
	 * @return array<string,list<array<string,mixed>>>
	 */
	private function filedQ1(): array {
		return [
			'BtwAangifte' => [
				[
					'id' => 'vat-1', 'returnNumber' => 'NL-2026-Q1', 'period' => 'quarter', 'periodYear' => 2026, 'periodNumber' => 1,
					'startDate' => '2026-01-01', 'endDate' => '2026-03-31', 'regime' => 'standard', 'administrationId' => 'adm-1',
					'statusCode' => 'submitted', 'totalVATCollected' => 3150.0, 'totalVATPaid' => 0.0, 'vatBalance' => -3150.0,
					'totalTaxableAmount' => 15000.0,
				],
			],
			'VATDeclaration' => [
				[
					'id' => 'decl-1', 'returnId' => 'vat-1', 'returnBox' => '1a', 'type' => 'collected', 'taxRate' => 21.0,
					'totalVATAmount' => 3150.0, 'totalTaxableAmount' => 15000.0, 'lineCount' => 2,
				],
			],
			'VATLine' => [
				[
					'id' => 'line-1', 'returnId' => 'vat-1', 'returnBox' => '1a', 'type' => 'collected', 'taxRate' => 21.0,
					'glAccountNumber' => '8000', 'taxableAmount' => 15000.0, 'vatAmount' => 0.0,
				],
				[
					'id' => 'line-2', 'returnId' => 'vat-1', 'returnBox' => '1a', 'type' => 'collected', 'taxRate' => 21.0,
					'glAccountNumber' => '2110', 'taxableAmount' => 0.0, 'vatAmount' => 3150.0,
				],
			],
		];
	}//end filedQ1()

	/**
	 * Verifies computeCurrentDeclarations() groups the booked lines per box
	 * without persisting anything (REQ-VBTW-013 on REQ-VBTW-004's derivation).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.1
	 */
	public function testComputeCurrentDeclarationsDoesNotPersist(): void {
		$stub = $this->fakeObjectService($this->ledger($this->q1Sale()));
		[$vatReturnService] = $this->buildServices($stub);

		$result = $vatReturnService->computeCurrentDeclarations(
			administrationId: 'adm-1',
			startDate: '2026-01-01',
			endDate: '2026-03-31'
		);

		self::assertCount(1, $result);
		self::assertSame('1a', $result[0]['returnBox']);
		self::assertSame('collected', $result[0]['type']);
		self::assertSame(3150.0, $result[0]['totalVATAmount']);
		self::assertSame(15000.0, $result[0]['totalTaxableAmount']);

		// Nothing was written — VATLine/VATDeclaration/VATReturn all remain empty.
		self::assertSame([], $stub->dump('VATLine'));
		self::assertSame([], $stub->dump('VATDeclaration'));
		self::assertSame([], $stub->dump('BtwAangifte'));

	}//end testComputeCurrentDeclarationsDoesNotPersist()

	/**
	 * REQ-VBTW-004 scenario "A correction return references the prior
	 * period": a EUR 500 sale at the high tariff posted into Q1 after filing
	 * shows a delta of EUR 500 base and EUR 105 VAT in box 1a, the correction
	 * raises what is owed by EUR 105, and its posting books the VAT delta on
	 * the box's VAT account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.1
	 */
	public function testALateSaleShowsItsDeltaInItsBox(): void {
		$entries = $this->q1Sale();
		$entries['gl-2'] = ['2026-03-01', [['8000', 'credit', 500.0, 'high', '1a', 'base'], ['2110', 'credit', 105.0, 'high', '1a', 'vat']]];
		$stub = $this->fakeObjectService($this->ledger($entries) + $this->filedQ1());
		[, $detectionService] = $this->buildServices($stub);

		$correction = $detectionService->detect(vatReturnId: 'vat-1');

		self::assertIsArray($correction);
		self::assertSame('draft', $correction['state']);
		self::assertSame('vat-1', $correction['originalVatReturnId']);
		self::assertSame(3150.0, $correction['filedSnapshot'][0]['totalVATAmount']);
		self::assertSame(3255.0, $correction['currentSnapshot'][0]['totalVATAmount']);

		$prepared = $detectionService->prepare(vatCorrectionId: (string)$correction['id']);

		self::assertSame(
			[
				[
					'type' => 'collected', 'taxRate' => 21.0, 'returnBox' => '1a', 'deltaVATAmount' => 105.0,
					'deltaTaxableAmount' => 500.0, 'glAccountNumber' => '2110',
				],
			],
			$prepared['categoryDeltas']
		);
		self::assertSame(105.0, $prepared['correctionAmount']);
		self::assertFalse($prepared['thresholdExceeded']);
		$correctionId = $prepared['glCorrectionTransactionId'];
		$vatAccountLine = array_values(
			array_filter(
				$stub->dump('GLLine'),
				static fn (array $l): bool => ($l['transactionId'] ?? '') === $correctionId && $l['accountNumber'] === '2110'
			)
		);
		self::assertSame('credit', $vatAccountLine[0]['side']);
		self::assertEquals(105.0, $vatAccountLine[0]['amount']);

	}//end testALateSaleShowsItsDeltaInItsBox()

	/**
	 * More input VAT in box 5b after filing lowers what is owed: the
	 * correction amount is negative, as it was for paid VAT before boxes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.1
	 */
	public function testLateInputVatLowersTheCorrection(): void {
		$entries = $this->q1Sale();
		$entries['gl-3'] = ['2026-02-01', [['7000', 'debit', 1000.0, 'high', '', 'base'], ['1230', 'debit', 210.0, 'high', '5b', 'vat']]];
		$stub = $this->fakeObjectService($this->ledger($entries) + $this->filedQ1());
		[, $detectionService] = $this->buildServices($stub);

		$correction = $detectionService->detect(vatReturnId: 'vat-1');
		self::assertIsArray($correction);
		$prepared = $detectionService->prepare(vatCorrectionId: (string)$correction['id']);

		self::assertSame(-210.0, $prepared['correctionAmount']);
		self::assertSame('5b', $prepared['categoryDeltas'][0]['returnBox']);
		// The filed return had no 5b line, so the posting falls back to the clearing account, as before boxes.
		self::assertNull($prepared['categoryDeltas'][0]['glAccountNumber']);

	}//end testLateInputVatLowersTheCorrection()

	/**
	 * Verifies detect() returns null and creates nothing when the ledger
	 * has not changed since filing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.1
	 */
	public function testDetectReturnsNullWhenNoDrift(): void {
		$stub = $this->fakeObjectService($this->ledger($this->q1Sale()) + $this->filedQ1());
		[, $detectionService] = $this->buildServices($stub);

		$correction = $detectionService->detect(vatReturnId: 'vat-1');

		self::assertNull($correction);
		self::assertSame([], $stub->dump('VatCorrection'));

	}//end testDetectReturnsNullWhenNoDrift()

	/**
	 * Verifies detect() refuses to run against a draft (not-yet-filed) return.
	 *
	 * @return void
	 */
	public function testDetectThrowsOnDraftReturn(): void {
		$vatReturn = [
			'id' => 'vat-1',
			'administrationId' => 'adm-1',
			'startDate' => '2026-01-01',
			'endDate' => '2026-03-31',
			'statusCode' => 'draft',
		];

		$stub = $this->fakeObjectService(['BtwAangifte' => [$vatReturn]]);
		[, $detectionService] = $this->buildServices($stub);

		$this->expectException(RuntimeException::class);
		$detectionService->detect(vatReturnId: 'vat-1');

	}//end testDetectThrowsOnDraftReturn()

	/**
	 * Verifies prepare() flags an above-grens correction as
	 * thresholdExceeded with an 8-week filing deadline and a balanced
	 * draft GL correction posting (Task 4, REQ-VBTW-014).
	 *
	 * @return void
	 */
	public function testPrepareFlagsAboveGrensWithDeadlineAndPosting(): void {
		$correction = [
			'id' => 'corr-1',
			'administrationId' => 'adm-1',
			'originalVatReturnId' => 'vat-1',
			'originalReturnId' => 'vat-1',
			'state' => 'draft',
			'preparedAt' => null,
			'filedSnapshot' => [
				['type' => 'collected', 'taxRate' => 21.0, 'totalVATAmount' => 3150.0, 'totalTaxableAmount' => 15000.0],
			],
			'currentSnapshot' => [
				['type' => 'collected', 'taxRate' => 21.0, 'totalVATAmount' => 4200.0, 'totalTaxableAmount' => 20000.0],
			],
		];

		$lines = [
			['id' => 'line-1', 'returnId' => 'vat-1', 'type' => 'collected', 'taxRate' => 21.0, 'glAccountNumber' => '4000'],
		];

		$stub = $this->fakeObjectService(
			[
				'VatCorrection' => [$correction],
				'VATLine' => $lines,
			]
		);
		[, $detectionService] = $this->buildServices($stub);

		$prepared = $detectionService->prepare(vatCorrectionId: 'corr-1');

		self::assertSame(1050.0, $prepared['correctionAmount']);
		self::assertSame(1050.0, $prepared['adjustmentAmount']);
		self::assertTrue($prepared['thresholdExceeded']);
		self::assertNotNull($prepared['preparedAt']);
		self::assertNotNull($prepared['filingDeadline']);
		self::assertNotNull($prepared['glCorrectionTransactionId']);

		$transactions = $stub->dump('GLTransaction');
		self::assertCount(1, $transactions);
		self::assertSame('draft', $transactions[0]['state']);

		$glLines = $stub->dump('GLLine');
		$debitTotal = 0.0;
		$creditTotal = 0.0;
		foreach ($glLines as $line) {
			if ($line['side'] === 'debit') {
				$debitTotal += (float)$line['amount'];
			} else {
				$creditTotal += (float)$line['amount'];
			}
		}

		self::assertEqualsWithDelta($debitTotal, $creditTotal, 0.001, 'GL correction posting must balance');

		// REQ-GLS-001: the delta lines and the clearing line carry the parent's administration.
		self::assertNotEmpty($glLines);
		foreach ($glLines as $line) {
			self::assertSame('adm-1', $line['administrationId'] ?? null);
		}

	}//end testPrepareFlagsAboveGrensWithDeadlineAndPosting()

	/**
	 * Verifies prepare() flags a below-grens correction (abs < €1.000) as
	 * not threshold-exceeding with no filing deadline, but still fully
	 * compiles the deltas + posting so the operator can decide.
	 *
	 * @return void
	 */
	public function testPrepareFlagsBelowGrensWithoutDeadline(): void {
		$correction = [
			'id' => 'corr-2',
			'administrationId' => 'adm-1',
			'originalVatReturnId' => 'vat-2',
			'originalReturnId' => 'vat-2',
			'state' => 'draft',
			'preparedAt' => null,
			'filedSnapshot' => [
				['type' => 'collected', 'taxRate' => 9.0, 'totalVATAmount' => 180.0, 'totalTaxableAmount' => 2000.0],
			],
			'currentSnapshot' => [
				['type' => 'collected', 'taxRate' => 9.0, 'totalVATAmount' => 450.0, 'totalTaxableAmount' => 5000.0],
			],
		];

		$lines = [
			['id' => 'line-2', 'returnId' => 'vat-2', 'type' => 'collected', 'taxRate' => 9.0, 'glAccountNumber' => '4010'],
		];

		$stub = $this->fakeObjectService(
			[
				'VatCorrection' => [$correction],
				'VATLine' => $lines,
			]
		);
		[, $detectionService] = $this->buildServices($stub);

		$prepared = $detectionService->prepare(vatCorrectionId: 'corr-2');

		self::assertSame(270.0, $prepared['correctionAmount']);
		self::assertFalse($prepared['thresholdExceeded']);
		self::assertNull($prepared['filingDeadline']);
		// Still fully compiled — deltas + posting exist despite being below grens.
		self::assertNotEmpty($prepared['categoryDeltas']);
		self::assertNotNull($prepared['glCorrectionTransactionId']);

	}//end testPrepareFlagsBelowGrensWithoutDeadline()

	/**
	 * Verifies prepare() refuses to run twice against an already-prepared
	 * correction.
	 *
	 * @return void
	 */
	public function testPrepareRefusesAlreadyPreparedCorrection(): void {
		$correction = [
			'id' => 'corr-3',
			'administrationId' => 'adm-1',
			'originalVatReturnId' => 'vat-3',
			'state' => 'draft',
			'preparedAt' => '2026-07-01T00:00:00+00:00',
			'filedSnapshot' => [],
			'currentSnapshot' => [],
		];

		$stub = $this->fakeObjectService(['VatCorrection' => [$correction]]);
		[, $detectionService] = $this->buildServices($stub);

		$this->expectException(RuntimeException::class);
		$detectionService->prepare(vatCorrectionId: 'corr-3');

	}//end testPrepareRefusesAlreadyPreparedCorrection()
}//end class
