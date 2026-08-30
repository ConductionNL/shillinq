<?php

/**
 * Unit tests for BcfClaimService.
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
 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BcfClaimService;
use OCA\Shillinq\Service\BcfCompensationCalculator;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests the OR-backed BCF claim computation service.
 *
 * Covers REQ-BCF-002 (GLLine -> BbvAccountMapping weighted join), REQ-BCF-012
 * (administration scoping) and the debit-side / elimination filtering.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BcfClaimServiceTest extends TestCase {

	/**
	 * Mock ContainerInterface.
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
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build the service over a seeded ObjectService double.
	 *
	 * ADR-084 injects the ObjectService through the constructor, so the
	 * subject has to be built AFTER the per-test store exists — it can no
	 * longer be assembled once in setUp() and re-pointed through a container.
	 *
	 * @param object $stub The seeded in-memory double.
	 *
	 * @return BcfClaimService
	 */
	private function serviceWith(object $stub): BcfClaimService {
		return new BcfClaimService(
			appConfig: $this->appConfig,
			calculator: new BcfCompensationCalculator(),
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end serviceWith()

	/**
	 * The service joins GLLines to mappings, filters and weights to the quarter total (REQ-BCF-002).
	 *
	 * @return void
	 */
	public function testComputeClaimWorkedExample(): void {
		$transactions = [
			['id' => 'tx-1', 'administrationId' => 'adm-1', 'periodId' => '2026-Q1'],
		];
		$lines = [
			// Compensable, debit, in scope: 100,000 @ 100%.
			['transactionId' => 'tx-1', 'accountNumber' => '3610', 'side' => 'debit', 'amount' => 100000.0, 'periodId' => '2026-Q1'],
			// Compensable, debit, in scope, mixed-use: 40,000 @ 50% -> 20,000.
			['transactionId' => 'tx-1', 'accountNumber' => '4100', 'side' => 'debit', 'amount' => 40000.0, 'periodId' => '2026-Q1'],
			// Not compensable (mapping flag false): excluded.
			['transactionId' => 'tx-1', 'accountNumber' => '3650', 'side' => 'debit', 'amount' => 50000.0, 'periodId' => '2026-Q1'],
			// Credit side: excluded (BCF claims the debit-side VAT).
			['transactionId' => 'tx-1', 'accountNumber' => '3610', 'side' => 'credit', 'amount' => 99999.0, 'periodId' => '2026-Q1'],
			// Eliminated: excluded.
			[
				'transactionId' => 'tx-1',
				'accountNumber' => '3610',
				'side' => 'debit',
				'amount' => 7000.0,
				'eliminationFlag' => true,
				'periodId' => '2026-Q1',
			],
			// Out-of-scope transaction: excluded.
			['transactionId' => 'tx-OTHER', 'accountNumber' => '3610', 'side' => 'debit', 'amount' => 5000.0, 'periodId' => '2026-Q1'],
		];
		$mappings = [
			['administrationId' => 'adm-1', 'accountNumber' => '3610', 'bcfCompensable' => true, 'compensablePercentage' => 100],
			['administrationId' => 'adm-1', 'accountNumber' => '4100', 'bcfCompensable' => true, 'compensablePercentage' => 50],
			['administrationId' => 'adm-1', 'accountNumber' => '3650', 'bcfCompensable' => false, 'compensablePercentage' => 0],
		];

		$service = $this->serviceWith(
			$this->buildObjectServiceStub(
				[
					'GLTransaction' => $transactions,
					'GLLine' => $lines,
					'BbvAccountMapping' => $mappings,
				]
			)
		);

		$result = $service->computeClaim(administrationId: 'adm-1', claimQuarter: '2026-Q1');

		self::assertSame('adm-1', $result['administrationId']);
		self::assertSame('2026-Q1', $result['claimQuarter']);
		self::assertSame(120000.0, $result['totalCompensableAmount']);
		self::assertCount(2, $result['breakdown']);
		self::assertSame('3610', $result['breakdown'][0]['accountNumber']);
		self::assertSame(100000.0, $result['breakdown'][0]['compensableAmount']);
		self::assertSame('4100', $result['breakdown'][1]['accountNumber']);
		self::assertSame(20000.0, $result['breakdown'][1]['compensableAmount']);

	}//end testComputeClaimWorkedExample()

	/**
	 * A quarter with no compensable postings yields a zero claim (REQ-BCF-003 empty-claim path).
	 *
	 * @return void
	 */
	public function testComputeClaimZeroWhenNoCompensablePostings(): void {
		$service = $this->serviceWith(
			$this->buildObjectServiceStub(
				[
					'GLTransaction' => [['id' => 'tx-1', 'administrationId' => 'adm-1', 'periodId' => '2026-Q2']],
					'GLLine' => [
						['transactionId' => 'tx-1', 'accountNumber' => '3650', 'side' => 'debit', 'amount' => 50000.0, 'periodId' => '2026-Q2'],
					],
					'BbvAccountMapping' => [
						['administrationId' => 'adm-1', 'accountNumber' => '3650', 'bcfCompensable' => false, 'compensablePercentage' => 0],
					],
				]
			)
		);

		$result = $service->computeClaim(administrationId: 'adm-1', claimQuarter: '2026-Q2');

		self::assertSame(0.0, $result['totalCompensableAmount']);
		self::assertSame([], $result['breakdown']);

	}//end testComputeClaimZeroWhenNoCompensablePostings()

	/**
	 * Build an ObjectService stub that returns per-schema records based on setSchema().
	 *
	 * @param array<string,array<mixed>> $recordsBySchema Schema slug => records to return from findAll().
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $recordsBySchema): object {
		return new class($recordsBySchema) {
			/**
			 * Records keyed by schema slug.
			 *
			 * @var array<string,array<mixed>>
			 */
			private array $recordsBySchema;

			/**
			 * Currently selected schema slug.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<mixed>> $recordsBySchema Records keyed by schema slug.
			 */
			public function __construct(array $recordsBySchema) {
				$this->recordsBySchema = $recordsBySchema;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter — records the active schema.
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
			 * Return the records for the active schema.
			 *
			 * @param array<string,mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return ($this->recordsBySchema[$this->schema] ?? []);
			}//end findAll()
		};

	}//end buildObjectServiceStub()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
