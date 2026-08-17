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

use OCA\Shillinq\Service\VATReturnService;
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
	 * Build the inline ObjectService stub seeded with accounts + transactions.
	 *
	 * @param array<int,array<string,mixed>> $accounts Account fixtures.
	 * @param array<int,array<string,mixed>> $transactions GLTransaction fixtures.
	 *
	 * @return object
	 */
	private function fakeObjectService(array $accounts, array $transactions): object {
		return new class($accounts, $transactions) {
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
			 * @param array<int,array<string,mixed>> $accounts Account fixtures.
			 * @param array<int,array<string,mixed>> $transactions GLTransaction fixtures.
			 */
			public function __construct(array $accounts, array $transactions) {
				$this->data = [
					'Account' => $accounts,
					'GLTransaction' => $transactions,
					'BtwAangifte' => [],
					'VATDeclaration' => [],
					'VATLine' => [],
				];
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
	 * createReturn() seeds a draft VATReturn and derives lines from GL.
	 *
	 * @return void
	 */
	public function testCreateReturnDerivesLinesFromGL(): void {
		$accounts = [
			[
				'accountNumber' => '4000',
				'name' => 'Omzet hoog tarief',
				'accountType' => 'revenue',
				'vatApplicable' => true,
				'vatRate' => 21.0,
				'administrationId' => 'adm-1',
			],
			[
				'accountNumber' => '5000',
				'name' => 'Inkopen hoog tarief',
				'accountType' => 'expenses',
				'vatApplicable' => true,
				'vatRate' => 21.0,
				'administrationId' => 'adm-1',
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
			[
				'id' => 'gl-2',
				'administrationId' => 'adm-1',
				'transactionDate' => '2026-02-10',
				'lines' => [
					['accountNumber' => '5000', 'taxableAmount' => 10000.0, 'taxRate' => 21.0],
				],
			],
		];

		$stub = $this->fakeObjectService($accounts, $transactions);
		$service = $this->buildService($stub);

		$created = $service->createReturn(
			administrationId: 'adm-1',
			period: 'quarter',
			periodYear: 2026,
			periodNumber: 1,
			regime: 'standard'
		);

		self::assertSame('draft', $created['statusCode']);
		self::assertSame(3150.0, (float)$created['totalVATCollected']);
		self::assertSame(2100.0, (float)$created['totalVATPaid']);
		self::assertSame(-1050.0, (float)$created['vatBalance']);
		self::assertSame(25000.0, (float)$created['totalTaxableAmount']);
		self::assertCount(2, $stub->dump('VATLine'));
		self::assertCount(2, $stub->dump('VATDeclaration'));

	}//end testCreateReturnDerivesLinesFromGL()

	/**
	 * deriveVATLines() groups by (type, rate) and supports 21% + 9% + 0%.
	 *
	 * @return void
	 */
	public function testDeriveVATLinesGroupsMixedRates(): void {
		$accounts = [
			['accountNumber' => '4000', 'name' => 'Hoog', 'accountType' => 'revenue', 'vatApplicable' => true, 'administrationId' => 'adm-1'],
			['accountNumber' => '4010', 'name' => 'Laag', 'accountType' => 'revenue', 'vatApplicable' => true, 'administrationId' => 'adm-1'],
			['accountNumber' => '4020', 'name' => 'Export', 'accountType' => 'revenue', 'vatApplicable' => true, 'administrationId' => 'adm-1'],
		];
		$transactions = [
			[
				'id' => 'gl-1',
				'administrationId' => 'adm-1',
				'transactionDate' => '2026-04-15',
				'lines' => [
					['accountNumber' => '4000', 'taxableAmount' => 5000.0, 'taxRate' => 21.0],
					['accountNumber' => '4010', 'taxableAmount' => 2000.0, 'taxRate' => 9.0],
					['accountNumber' => '4020', 'taxableAmount' => 3000.0, 'taxRate' => 0.0],
				],
			],
		];

		$stub = $this->fakeObjectService($accounts, $transactions);
		$service = $this->buildService($stub);

		// Seed a return manually so deriveVATLines has a parent.
		$stub->setSchema('BtwAangifte')->saveObject(
			[
				'id' => 'ret-1',
				'returnNumber' => 'NL-2026-Q2',
				'period' => 'quarter',
				'periodYear' => 2026,
				'periodNumber' => 2,
				'startDate' => '2026-04-01',
				'endDate' => '2026-06-30',
				'regime' => 'standard',
				'administrationId' => 'adm-1',
				'statusCode' => 'draft',
			]
		);

		$totals = $service->deriveVATLines(
			returnId: 'ret-1',
			administrationId: 'adm-1',
			startDate: '2026-04-01',
			endDate: '2026-06-30',
			regime: 'standard'
		);

		self::assertSame(3, $totals['lineCount']);
		self::assertSame(1050.0 + 180.0 + 0.0, (float)$totals['totalVATCollected']);
		self::assertSame(0.0, (float)$totals['totalVATPaid']);
		// Three declarations: (collected,21), (collected,9), (collected,0).
		self::assertCount(3, $stub->dump('VATDeclaration'));

	}//end testDeriveVATLinesGroupsMixedRates()

	/**
	 * Reverse-charge lines fold into totalVATPaid (operator self-accounts).
	 *
	 * @return void
	 */
	public function testDeriveVATLinesHandlesReverseCharge(): void {
		$accounts = [
			[
				'accountNumber' => '5010',
				'name' => 'EU inkopen',
				'accountType' => 'expenses',
				'vatApplicable' => true,
				'reverseChargeApplicable' => true,
				'administrationId' => 'adm-1',
			],
		];
		$transactions = [
			[
				'id' => 'gl-eu-1',
				'administrationId' => 'adm-1',
				'transactionDate' => '2026-04-20',
				'lines' => [
					['accountNumber' => '5010', 'taxableAmount' => 9500.0, 'taxRate' => 21.0, 'reverseChargeApplicable' => true],
				],
			],
		];

		$stub = $this->fakeObjectService($accounts, $transactions);
		$service = $this->buildService($stub);
		$stub->setSchema('BtwAangifte')->saveObject(
			[
				'id' => 'ret-rc',
				'administrationId' => 'adm-1',
				'startDate' => '2026-04-01',
				'endDate' => '2026-06-30',
				'regime' => 'reverse-charge',
				'statusCode' => 'draft',
			]
		);

		$totals = $service->deriveVATLines(
			returnId: 'ret-rc',
			administrationId: 'adm-1',
			startDate: '2026-04-01',
			endDate: '2026-06-30',
			regime: 'reverse-charge'
		);

		self::assertSame(0.0, (float)$totals['totalVATCollected']);
		// 9500 * 0.21 = 1995.
		self::assertSame(1995.0, (float)$totals['totalVATPaid']);
		self::assertSame(1, $totals['lineCount']);
		$lines = $stub->dump('VATLine');
		self::assertSame('reverse-charge', $lines[0]['type']);
		self::assertTrue($lines[0]['reverseChargeApplicable']);

	}//end testDeriveVATLinesHandlesReverseCharge()

	/**
	 * KOR regime short-circuits to zero totals (REQ-VAT-004).
	 *
	 * @return void
	 */
	public function testDeriveVATLinesKorRegimeZeroes(): void {
		$stub = $this->fakeObjectService([], []);
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
		$stub = $this->fakeObjectService([], []);
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
		$stub = $this->fakeObjectService([], []);
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
		$stub = $this->fakeObjectService([], []);
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
		$stub = $this->fakeObjectService([], []);
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
