<?php

/**
 * Unit tests for KorMonitorService.
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
 * @spec openspec/changes/bookkeeping-kor-kleine-ondernemersregeling/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\KorMonitorService;
use OCA\Shillinq\Service\KorThresholdCalculator;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests the on-demand KOR drempel-bewaking service against a stub ObjectService.
 *
 * Covers REQ-KOR-002 (running omzet + benutting + monthly breakdown + prognose,
 * scoped to administration) and REQ-KOR-003 (highest reached alert-schijf).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class KorMonitorServiceTest extends TestCase {

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
	 * @param array<int,array<string,mixed>> $invoices ARInvoice records.
	 * @param array<int,array<string,mixed>> $registrations KORRegistration records.
	 *
	 * @return KorMonitorService
	 */
	private function buildService(array $invoices, array $registrations): KorMonitorService {
		$stub = new class($invoices, $registrations) {

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
			 * @param array<int,array<string,mixed>> $invoices ARInvoice records.
			 * @param array<int,array<string,mixed>> $registrations KORRegistration records.
			 */
			public function __construct(array $invoices, array $registrations) {
				$this->data = [
					'ARInvoice' => $invoices,
					'KORRegistration' => $registrations,
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

		return new KorMonitorService(
			appConfig: $this->appConfig,
			calculator: new KorThresholdCalculator(),
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Build an ARInvoice fixture row.
	 *
	 * @param string $admin Administration id.
	 * @param float $amount Gross amount.
	 * @param string $date Delivery date (YYYY-MM-DD).
	 * @param string $grond vrijstellingsGrondslag.
	 * @param string $status Invoice status.
	 *
	 * @return array<string,mixed>
	 */
	private function invoice(string $admin, float $amount, string $date, string $grond = 'KOR_ART25_OB', string $status = 'issued'): array {
		return [
			'administrationId' => $admin,
			'amount' => $amount,
			'leveringsDatum' => $date,
			'vrijstellingsGrondslag' => $grond,
			'status' => $status,
		];

	}//end invoice()

	/**
	 * The running omzet sums only this administration's KOR-eligible invoices (REQ-KOR-002).
	 *
	 * @return void
	 */
	public function testStatusScopesToAdministration(): void {
		$invoices = [
			$this->invoice('adm-1', 16420.00, '2026-08-12'),
			$this->invoice('adm-1', 200.00, '2026-08-20'),
			// Other administration must not leak in.
			$this->invoice('adm-2', 99999.00, '2026-08-15'),
			// Non-KOR omzet excluded.
			$this->invoice('adm-1', 5000.00, '2026-08-21', 'REGULIER_21PCT_VAT'),
		];

		$service = $this->buildService($invoices, []);
		$result = $service->status(administrationId: 'adm-1', year: 2026);

		self::assertSame('adm-1', $result['administrationId']);
		self::assertSame(16620.00, $result['currentRevenue']);
		self::assertSame(20000.00, $result['threshold']);
		self::assertEqualsWithDelta(0.831, $result['thresholdUtilisation'], 0.001);

	}//end testStatusScopesToAdministration()

	/**
	 * Crossing 80% surfaces the VROEG schijf (REQ-KOR-003).
	 *
	 * @return void
	 */
	public function testStatusReportsAlertSchijf(): void {
		$invoices = [$this->invoice('adm-1', 16620.00, '2026-08-12')];

		$service = $this->buildService($invoices, []);
		$result = $service->status(administrationId: 'adm-1', year: 2026);

		self::assertSame('THRESHOLD_80_PCT', $result['trigger']);
		self::assertSame('EARLY', $result['severity']);

	}//end testStatusReportsAlertSchijf()

	/**
	 * The monthly breakdown and prognose drive the prognose-status (REQ-KOR-002).
	 *
	 * @return void
	 */
	public function testStatusBuildsMonthlyPrognose(): void {
		$invoices = [
			$this->invoice('adm-1', 2000.00, '2026-01-10'),
			$this->invoice('adm-1', 2000.00, '2026-02-10'),
			$this->invoice('adm-1', 2000.00, '2026-08-10'),
		];

		$service = $this->buildService($invoices, []);
		$result = $service->status(administrationId: 'adm-1', year: 2026);

		self::assertArrayHasKey('2026-01', $result['perMonth']);
		self::assertArrayHasKey('2026-08', $result['perMonth']);
		self::assertSame(6000.00, $result['currentRevenue']);
		// Month 8, avg 750/mo, +4 months => 6000 + 3000 = 9000 prognose, well under drempel.
		self::assertSame(9000.00, $result['forecastYearEnd']);
		self::assertSame('UNDER_THRESHOLD', $result['prognoseStatus']);

	}//end testStatusBuildsMonthlyPrognose()

	/**
	 * An ACTIEF registration's drempelJaar overrides the default EUR 20.000 (REQ-KOR-002).
	 *
	 * @return void
	 */
	public function testRegistrationDrempelOverride(): void {
		$invoices = [$this->invoice('adm-1', 4000.00, '2026-03-01')];
		$registrations = [
			['administrationId' => 'adm-1', 'status' => 'ACTIEF', 'thresholdYear' => 10000],
		];

		$service = $this->buildService($invoices, $registrations);
		$result = $service->status(administrationId: 'adm-1', year: 2026);

		self::assertSame(10000.00, $result['threshold']);
		self::assertEqualsWithDelta(0.4, $result['thresholdUtilisation'], 0.0001);

	}//end testRegistrationDrempelOverride()

	/**
	 * Status exposes optOutPermitted, false outside the lock-in opt-out window (REQ-KOR-007).
	 *
	 * @return void
	 */
	public function testOptOutPermittedFalseWithoutWindow(): void {
		$service = $this->buildService([], []);
		$result = $service->status(administrationId: 'adm-1', year: 2026);

		self::assertArrayHasKey('optOutPermitted', $result);
		self::assertFalse($result['optOutPermitted']);

	}//end testOptOutPermittedFalseWithoutWindow()

	/**
	 * Status reports optOutPermitted=true when ACTIEF registratie's window covers today (REQ-KOR-007).
	 *
	 * @return void
	 */
	public function testOptOutPermittedTrueInsideWindow(): void {
		$today = date('Y-m-d');
		$registrations = [
			[
				'administrationId' => 'adm-1',
				'status' => 'ACTIEF',
				'earliestTerminationDate' => '1900-01-01',
				'lockInEndDate' => '9999-12-31',
			],
		];

		$service = $this->buildService([], $registrations);
		$result = $service->status(administrationId: 'adm-1', year: (int)substr($today, 0, 4));

		self::assertTrue($result['optOutPermitted']);

	}//end testOptOutPermittedTrueInsideWindow()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
