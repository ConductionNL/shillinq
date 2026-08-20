<?php

/**
 * Unit tests for RevenueCutoffService.
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
 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-17
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\RevenueCutoffService;
use OCA\Shillinq\Service\RevenueRecognitionCalculator;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests the on-demand IFRS 15 revenue cut-off service.
 *
 * Covers the contract asset/liability split (REQ-IFRS15-007), the remaining-amount
 * waterfall (REQ-IFRS15-008), period-end scoping of recognition events, the
 * idempotence of the derived cut-off, and administration scoping (ADR-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RevenueCutoffServiceTest extends TestCase {

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
	 * Build the service with an ObjectService stub returning the given data sets.
	 *
	 * @param array<int,array<string,mixed>> $contracts Contract records.
	 * @param array<int,array<string,mixed>> $events RevenueRecognitionEvent records.
	 * @param array<int,array<string,mixed>> $allocations PriceAllocation records.
	 *
	 * @return RevenueCutoffService
	 */
	private function buildService(array $contracts, array $events, array $allocations): RevenueCutoffService {
		$stub = new class($contracts, $events, $allocations) {

			/**
			 * Data sets keyed by schema slug.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Last schema selected via setSchema().
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $contracts Contract records.
			 * @param array<int,array<string,mixed>> $events RevenueRecognitionEvent records.
			 * @param array<int,array<string,mixed>> $allocations PriceAllocation records.
			 */
			public function __construct(array $contracts, array $events, array $allocations) {
				$this->data = [
					'RevenueContract' => $contracts,
					'RevenueRecognitionEvent' => $events,
					'PriceAllocation' => $allocations,
				];
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
		};

		$this->container->method('get')->willReturn($stub);

		return new RevenueCutoffService(
			appConfig: $this->appConfig,
			calculator: new RevenueRecognitionCalculator(),
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * The cut-off derives a contract liability when billed exceeds recognised (REQ-IFRS15-007).
	 *
	 * Spec month-6 scenario: recognised 89143, billed 90000 → liability 857.
	 *
	 * @return void
	 */
	public function testComputeDerivesContractLiability(): void {
		$contracts = [
			['contractNumber' => 'C-2026-001', 'administrationId' => 'adm-1'],
		];
		$events = [
			['contractId' => 'C-2026-001', 'periodEnd' => '2026-05-31', 'recognisedAmount' => 82000.0, 'administrationId' => 'adm-1'],
			['contractId' => 'C-2026-001', 'periodEnd' => '2026-06-30', 'recognisedAmount' => 7143.0, 'administrationId' => 'adm-1'],
		];
		$allocations = [
			['contractId' => 'C-2026-001', 'allocatedAmount' => 360000.0, 'administrationId' => 'adm-1'],
		];

		$service = $this->buildService($contracts, $events, $allocations);
		$result = $service->compute('adm-1', '2026-06-30', ['C-2026-001' => 90000.0]);

		self::assertSame(1, $result['total']);
		$row = $result['data'][0];
		self::assertSame('C-2026-001', $row['contractId']);
		self::assertSame(89143.0, $row['cumulativeRecognised']);
		self::assertSame(7143.0, $row['periodRecognised']);
		self::assertSame(0.0, $row['contractAsset']);
		self::assertSame(857.0, $row['contractLiability']);
		self::assertSame(270857.0, $row['remainingAmount']);

	}//end testComputeDerivesContractLiability()

	/**
	 * The cut-off derives a contract asset when recognised exceeds billed (REQ-IFRS15-007).
	 *
	 * Spec construction scenario: recognised 533000, billed 500000 → asset 33000.
	 *
	 * @return void
	 */
	public function testComputeDerivesContractAsset(): void {
		$contracts = [
			['contractNumber' => 'C-2026-050', 'administrationId' => 'adm-1'],
		];
		$events = [
			['contractId' => 'C-2026-050', 'periodEnd' => '2026-05-31', 'recognisedAmount' => 450000.0, 'administrationId' => 'adm-1'],
			['contractId' => 'C-2026-050', 'periodEnd' => '2026-06-30', 'recognisedAmount' => 83000.0, 'administrationId' => 'adm-1'],
		];
		$allocations = [
			['contractId' => 'C-2026-050', 'allocatedAmount' => 1000000.0, 'administrationId' => 'adm-1'],
		];

		$service = $this->buildService($contracts, $events, $allocations);
		$result = $service->compute('adm-1', '2026-06-30', ['C-2026-050' => 500000.0]);

		$row = $result['data'][0];
		self::assertSame(533000.0, $row['cumulativeRecognised']);
		self::assertSame(33000.0, $row['contractAsset']);
		self::assertSame(0.0, $row['contractLiability']);

	}//end testComputeDerivesContractAsset()

	/**
	 * Recognition events after the cut-off period end are excluded (REQ-IFRS15-008).
	 *
	 * @return void
	 */
	public function testComputeExcludesFutureEvents(): void {
		$contracts = [
			['contractNumber' => 'C-1', 'administrationId' => 'adm-1'],
		];
		$events = [
			['contractId' => 'C-1', 'periodEnd' => '2026-06-30', 'recognisedAmount' => 100.0, 'administrationId' => 'adm-1'],
			// Future event: must NOT count toward the June cut-off.
			['contractId' => 'C-1', 'periodEnd' => '2026-07-31', 'recognisedAmount' => 999.0, 'administrationId' => 'adm-1'],
		];
		$allocations = [
			['contractId' => 'C-1', 'allocatedAmount' => 1000.0, 'administrationId' => 'adm-1'],
		];

		$service = $this->buildService($contracts, $events, $allocations);
		$result = $service->compute('adm-1', '2026-06-30');

		$row = $result['data'][0];
		self::assertSame(100.0, $row['cumulativeRecognised']);

	}//end testComputeExcludesFutureEvents()

	/**
	 * The cut-off is idempotent — re-running on the same snapshot yields identical rows (REQ-IFRS15-007).
	 *
	 * @return void
	 */
	public function testComputeIsIdempotent(): void {
		$contracts = [['contractNumber' => 'C-1', 'administrationId' => 'adm-1']];
		$events = [
			['contractId' => 'C-1', 'periodEnd' => '2026-06-30', 'recognisedAmount' => 500.0, 'administrationId' => 'adm-1'],
		];
		$allocations = [['contractId' => 'C-1', 'allocatedAmount' => 1000.0, 'administrationId' => 'adm-1']];

		$service = $this->buildService($contracts, $events, $allocations);
		$first = $service->compute('adm-1', '2026-06-30', ['C-1' => 300.0]);
		$second = $service->compute('adm-1', '2026-06-30', ['C-1' => 300.0]);

		self::assertEquals($first, $second);

	}//end testComputeIsIdempotent()

	/**
	 * Recognition events from another administration are excluded (ADR-005 IDOR-safety).
	 *
	 * @return void
	 */
	public function testComputeScopesToAdministration(): void {
		$contracts = [
			['contractNumber' => 'C-1', 'administrationId' => 'adm-1'],
		];
		$events = [
			['contractId' => 'C-1', 'periodEnd' => '2026-06-30', 'recognisedAmount' => 200.0, 'administrationId' => 'adm-1'],
			// Belongs to adm-2; must NOT count toward adm-1's cut-off.
			['contractId' => 'C-1', 'periodEnd' => '2026-06-30', 'recognisedAmount' => 9999.0, 'administrationId' => 'adm-2'],
		];
		$allocations = [
			['contractId' => 'C-1', 'allocatedAmount' => 1000.0, 'administrationId' => 'adm-1'],
		];

		$service = $this->buildService($contracts, $events, $allocations);
		$result = $service->compute('adm-1', '2026-06-30');

		$row = $result['data'][0];
		self::assertSame(200.0, $row['cumulativeRecognised']);

	}//end testComputeScopesToAdministration()
}//end class
