<?php

/**
 * Unit tests for the PayrollLivLkvHandoffService.
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
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/specs/req-pay-001-bruto-netto-berekening.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PayrollCalculator;
use OCA\Shillinq\Service\PayrollLivLkvHandoffService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Asserts the LIV/LKV eligibility payload contract.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PayrollLivLkvHandoffServiceTest extends TestCase {

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
	 * Set up shared mocks.
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
	 * Build the service over an in-memory ObjectService stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 *
	 * @return PayrollLivLkvHandoffService
	 */
	private function buildService(array $data): PayrollLivLkvHandoffService {
		$stub = new class($data) {

			/**
			 * Schema => rows.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $data Data.
			 */
			public function __construct(array $data) {
				$this->data = $data;
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
			 * Fluent schema setter.
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
			 * Filtered findAll.
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

		return new PayrollLivLkvHandoffService(
			appConfig: $this->appConfig,
			calculator: new PayrollCalculator(),
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Emits a complete eligibility payload for a known werknemer + year.
	 *
	 * @return void
	 */
	public function testEligibilityPayloadAggregatesYearlyFiscaalLoon(): void {
		$svc = $this->buildService(
			data: [
				'Werknemer' => [
					[
						'id' => 'wn-1',
						'administrationId' => 'adm-1',
						'inkomenniveau' => 'LIV',
						'contracturenPerWeek' => 36,
						'lkvCategorie' => 'BANENAFSPRAAK',
						'doelgroepverklaring' => true,
					],
				],
				'LoonStrook' => [
					['employeeId' => 'wn-1', 'administrationId' => 'adm-1', 'periodId' => 'lp-2026-01', 'fiscalPay' => 2000.00],
					['employeeId' => 'wn-1', 'administrationId' => 'adm-1', 'periodId' => 'lp-2026-02', 'fiscalPay' => 2050.00],
					['employeeId' => 'wn-1', 'administrationId' => 'adm-1', 'periodId' => 'lp-2025-12', 'fiscalPay' => 9999.00], // wrong year
					['employeeId' => 'wn-2', 'administrationId' => 'adm-1', 'periodId' => 'lp-2026-01', 'fiscalPay' => 9999.00], // wrong werknemer
				],
			]
		);

		$payload = $svc->toLivLkvEligibilityPayload(
			administrationId: 'adm-1',
			employeeId: 'wn-1',
			year: 2026
		);

		$this->assertNotNull($payload);
		$this->assertSame('wn-1', $payload['employeeId']);
		$this->assertSame(2026, $payload['year']);
		$this->assertSame('LIV', $payload['inkomenniveau']);
		$this->assertEqualsWithDelta(4050.00, $payload['fiscaalLoonJaar'], 0.005);
		$this->assertSame(36.0, $payload['contracturenPerWeek']);
		$this->assertSame('BANENAFSPRAAK', $payload['lkvCategorie']);
		$this->assertTrue($payload['doelgroepverklaring']);
		$this->assertSame('adm-1', $payload['administrationId']);
		$this->assertSame('Werknemer+LoonStrook', $payload['source']);

	}//end testEligibilityPayloadAggregatesYearlyFiscaalLoon()

	/**
	 * Returns null when the werknemer does not exist in scope.
	 *
	 * @return void
	 */
	public function testReturnsNullWhenWerknemerNotFound(): void {
		$svc = $this->buildService(
			data: [
				'Werknemer' => [['id' => 'wn-1', 'administrationId' => 'adm-1']],
				'LoonStrook' => [],
			]
		);

		$payload = $svc->toLivLkvEligibilityPayload(
			administrationId: 'adm-1',
			employeeId: 'wn-x',
			year: 2026
		);

		$this->assertNull($payload);

	}//end testReturnsNullWhenWerknemerNotFound()

	/**
	 * Respects administration scope for the werknemer lookup.
	 *
	 * @return void
	 */
	public function testCrossAdministrationLookupIsBlocked(): void {
		$svc = $this->buildService(
			data: [
				'Werknemer' => [['id' => 'wn-1', 'administrationId' => 'adm-1', 'inkomenniveau' => 'LIV']],
				'LoonStrook' => [],
			]
		);

		$payload = $svc->toLivLkvEligibilityPayload(
			administrationId: 'adm-2',
			employeeId: 'wn-1',
			year: 2026
		);

		$this->assertNull($payload);

	}//end testCrossAdministrationLookupIsBlocked()
}//end class
