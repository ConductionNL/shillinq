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
	 * Shared fixture: an Account chart with a 21% revenue account and a
	 * 9% revenue account.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function accounts(): array {
		return [
			[
				'accountNumber' => '4000',
				'name' => 'Omzet hoog tarief',
				'accountType' => 'revenue',
				'vatApplicable' => true,
				'vatRate' => 21.0,
				'administrationId' => 'adm-1',
			],
			[
				'accountNumber' => '4010',
				'name' => 'Omzet laag tarief',
				'accountType' => 'revenue',
				'vatApplicable' => true,
				'vatRate' => 9.0,
				'administrationId' => 'adm-1',
			],
		];
	}//end accounts()

	/**
	 * Verifies computeCurrentDeclarations() recomputes the GL grouping
	 * without persisting anything (Task 2, REQ-VBTW-013).
	 *
	 * @return void
	 */
	public function testComputeCurrentDeclarationsDoesNotPersist(): void {
		$transactions = [
			[
				'id' => 'gl-1',
				'administrationId' => 'adm-1',
				'transactionDate' => '2026-01-15',
				'lines' => [
					['accountNumber' => '4000', 'taxableAmount' => 15000.0, 'taxRate' => 21.0],
				],
			],
		];

		$stub = $this->fakeObjectService(['Account' => $this->accounts(), 'GLTransaction' => $transactions]);
		[$vatReturnService] = $this->buildServices($stub);

		$result = $vatReturnService->computeCurrentDeclarations(
			administrationId: 'adm-1',
			startDate: '2026-01-01',
			endDate: '2026-03-31'
		);

		self::assertCount(1, $result);
		self::assertSame('collected', $result[0]['type']);
		self::assertSame(21.0, $result[0]['taxRate']);
		self::assertSame(3150.0, $result[0]['totalVATAmount']);
		self::assertSame(15000.0, $result[0]['totalTaxableAmount']);

		// Nothing was written — VATLine/VATDeclaration/VATReturn all remain empty.
		self::assertSame([], $stub->dump('VATLine'));
		self::assertSame([], $stub->dump('VATDeclaration'));
		self::assertSame([], $stub->dump('BtwAangifte'));

	}//end testComputeCurrentDeclarationsDoesNotPersist()

	/**
	 * Verifies detect() creates a draft VatCorrection with filed + current
	 * snapshots when a late-posted GL transaction drifts the return (Task 3,
	 * REQ-VBTW-013).
	 *
	 * @return void
	 */
	public function testDetectCreatesCorrectionOnDrift(): void {
		$vatReturn = [
			'id' => 'vat-1',
			'returnNumber' => 'NL-2026-Q1',
			'period' => 'quarter',
			'periodYear' => 2026,
			'periodNumber' => 1,
			'startDate' => '2026-01-01',
			'endDate' => '2026-03-31',
			'regime' => 'standard',
			'administrationId' => 'adm-1',
			'statusCode' => 'submitted',
			'totalVATCollected' => 3150.0,
			'totalVATPaid' => 0.0,
			'vatBalance' => -3150.0,
			'totalTaxableAmount' => 15000.0,
		];

		$declarations = [
			[
				'id' => 'decl-1',
				'returnId' => 'vat-1',
				'type' => 'collected',
				'taxRate' => 21.0,
				'totalVATAmount' => 3150.0,
				'totalTaxableAmount' => 15000.0,
				'lineCount' => 1,
			],
		];

		$lines = [
			[
				'id' => 'line-1',
				'returnId' => 'vat-1',
				'type' => 'collected',
				'taxRate' => 21.0,
				'glAccountNumber' => '4000',
			],
		];

		// Ledger has drifted: an extra €5.000 taxable @ 21% posted after filing.
		$transactions = [
			[
				'id' => 'gl-1',
				'administrationId' => 'adm-1',
				'transactionDate' => '2026-01-15',
				'lines' => [
					['accountNumber' => '4000', 'taxableAmount' => 15000.0, 'taxRate' => 21.0],
				],
			],
			[
				'id' => 'gl-2',
				'administrationId' => 'adm-1',
				'transactionDate' => '2026-03-01',
				'lines' => [
					['accountNumber' => '4000', 'taxableAmount' => 5000.0, 'taxRate' => 21.0],
				],
			],
		];

		$stub = $this->fakeObjectService(
			[
				'Account' => $this->accounts(),
				'GLTransaction' => $transactions,
				'BtwAangifte' => [$vatReturn],
				'VATDeclaration' => $declarations,
				'VATLine' => $lines,
			]
		);
		[, $detectionService] = $this->buildServices($stub);

		$correction = $detectionService->detect(vatReturnId: 'vat-1');

		self::assertIsArray($correction);
		self::assertSame('draft', $correction['state']);
		self::assertSame('vat-1', $correction['originalVatReturnId']);
		self::assertSame('vat-1', $correction['originalReturnId']);
		self::assertNull($correction['preparedAt']);
		self::assertSame(3150.0, $correction['filedSnapshot'][0]['totalVATAmount']);
		self::assertSame(4200.0, $correction['currentSnapshot'][0]['totalVATAmount']);

	}//end testDetectCreatesCorrectionOnDrift()

	/**
	 * Verifies detect() returns null and creates nothing when the ledger
	 * has not changed since filing.
	 *
	 * @return void
	 */
	public function testDetectReturnsNullWhenNoDrift(): void {
		$vatReturn = [
			'id' => 'vat-1',
			'period' => 'quarter',
			'periodYear' => 2026,
			'periodNumber' => 1,
			'startDate' => '2026-01-01',
			'endDate' => '2026-03-31',
			'administrationId' => 'adm-1',
			'statusCode' => 'submitted',
		];
		$declarations = [
			[
				'id' => 'decl-1',
				'returnId' => 'vat-1',
				'type' => 'collected',
				'taxRate' => 21.0,
				'totalVATAmount' => 3150.0,
				'totalTaxableAmount' => 15000.0,
				'lineCount' => 1,
			],
		];
		$transactions = [
			[
				'id' => 'gl-1',
				'administrationId' => 'adm-1',
				'transactionDate' => '2026-01-15',
				'lines' => [
					['accountNumber' => '4000', 'taxableAmount' => 15000.0, 'taxRate' => 21.0],
				],
			],
		];

		$stub = $this->fakeObjectService(
			[
				'Account' => $this->accounts(),
				'GLTransaction' => $transactions,
				'BtwAangifte' => [$vatReturn],
				'VATDeclaration' => $declarations,
			]
		);
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
