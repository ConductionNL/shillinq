<?php

/**
 * Unit tests for the PayrollJaaropgaveService.
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
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/specs/req-pay-013-jaaropgave.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PayrollCalculator;
use OCA\Shillinq\Service\PayrollJaaropgaveService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Asserts the yearly aggregate contract.
 *
 * REQ-PAY-013: a Jaaropgave is the SUM of the period loonstroken for one
 * (administration, werknemer, year) tuple, with cumulatieven-consistency
 * verified before persistence; an inconsistent statement is refused.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PayrollJaaropgaveServiceTest extends TestCase {

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
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

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
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build a PayrollJaaropgaveService over the in-memory ObjectService stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Saved capture.
	 *
	 * @return PayrollJaaropgaveService
	 */
	private function buildService(array $data, array &$saved): PayrollJaaropgaveService {
		$stub = new class($data, $saved) {

			/**
			 * Schema => rows.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Captured saves.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $saved;

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
			 * @param array<int,array<string,mixed>> $saved Saved capture ref.
			 */
			public function __construct(array $data, array &$saved) {
				$this->data = $data;
				$this->saved = &$saved;
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

			/**
			 * Capture saved object.
			 *
			 * @param array<string,mixed> $object Payload.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$object['@self'] = ['register' => $register, 'schema' => $schema];
				$this->saved[] = $object;
				return $object;
			}//end saveObject()
		};

		$this->container->method('get')->willReturn($stub);

		return new PayrollJaaropgaveService(
			appConfig: $this->appConfig,
			calculator: new PayrollCalculator(),
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Build three months of identical loonstroken for one employee.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function dataset(): array {
		$slip = static function (string $id, string $periodId, float $ytdFiscal, float $ytdVak): array {
			return [
				'id' => $id,
				'periodId' => $periodId,
				'employeeId' => 'wn-1',
				'administrationId' => 'adm-1',
				'fiscalPay' => 4959.20,
				'payrollTax' => 1083.40,
				'employerSocialInsurancePremiums' => ['totaal_werkgever' => 500.86],
				'zvw' => ['afgedragen_wg' => 262.80],
				'pension' => ['premie_wn_aandeel' => 355.68, 'premie_wg_aandeel' => 898.88],
				'grossComponents' => ['totaal_bruto' => 4959.20, 'vakantietoeslag_uitbetaling' => 0.0],
				'netPaid' => 3520.12,
				'cumulatieven' => ['fiscaalloon_ytd' => $ytdFiscal, 'vakantiegeld_reservering_ytd' => $ytdVak],
				'holidayDaysAccrual' => ['opgebouwdEuro' => 395.20],
			];
		};

		return [
			'LoonStrook' => [
				$slip('ls-1', 'lp-2026-01', 4959.20, 395.20),
				$slip('ls-2', 'lp-2026-02', 9918.40, 790.40),
				$slip('ls-3', 'lp-2026-03', 14877.60, 1185.60),
				// Different werknemer in same admin (must be filtered out).
				[
					'id' => 'ls-x',
					'periodId' => 'lp-2026-01',
					'employeeId' => 'wn-9',
					'administrationId' => 'adm-1',
					'fiscalPay' => 99999.00,
				],
				// Different year (must be filtered out).
				$slip('ls-y', 'lp-2025-12', 60000.00, 4800.00) + ['employeeId' => 'wn-1', 'administrationId' => 'adm-1'],
			],
		];

	}//end dataset()

	/**
	 * The bouwJaaropgave sums all monthly stroken for the year.
	 *
	 * @return void
	 */
	public function testBouwJaaropgaveSumsAllPerioden(): void {
		$saved = [];
		$service = $this->buildService(data: $this->dataset(), saved: $saved);

		$statement = $service->bouwJaaropgave(
			administrationId: 'adm-1',
			employeeId: 'wn-1',
			year: 2026
		);

		$this->assertSame(2026, $statement['year']);
		$this->assertSame(3, $statement['aantalPerioden']);
		$this->assertEqualsWithDelta(14877.60, $statement['fiscaalLoonJTD'], 0.005);
		$this->assertEqualsWithDelta(3250.20, $statement['loonheffingJTD'], 0.005);
		$this->assertEqualsWithDelta(1502.58, $statement['premiesSVWgJTD'], 0.005);
		$this->assertEqualsWithDelta(788.40, $statement['zvwWgJTD'], 0.005);
		$this->assertEqualsWithDelta(1067.04, $statement['pensioenWnJTD'], 0.005);
		$this->assertEqualsWithDelta(2696.64, $statement['pensioenWgJTD'], 0.005);
		$this->assertEqualsWithDelta(10560.36, $statement['nettoUitbetaaldJTD'], 0.005);
		$this->assertSame('CONCEPT', $statement['status']);
		$this->assertSame('adm-1', $statement['administrationId']);
		$this->assertTrue($statement['cumulatievenConsistent']);

	}//end testBouwJaaropgaveSumsAllPerioden()

	/**
	 * The cumulatievenConsistent flag flips false when the ytd snapshot diverges.
	 *
	 * @return void
	 */
	public function testBouwJaaropgaveFlagsCumulatievenMismatch(): void {
		$data = $this->dataset();
		// Tamper with the last cumulatieven snapshot.
		$data['LoonStrook'][2]['cumulatieven']['fiscaalloon_ytd'] = 12345.67;

		$saved = [];
		$service = $this->buildService(data: $data, saved: $saved);

		$statement = $service->bouwJaaropgave(
			administrationId: 'adm-1',
			employeeId: 'wn-1',
			year: 2026
		);

		$this->assertFalse($statement['cumulatievenConsistent']);

	}//end testBouwJaaropgaveFlagsCumulatievenMismatch()

	// The two testPersistJaaropgave* cases were removed with
	// PayrollJaaropgaveService::persistJaaropgave(), which had no production
	// caller. The cumulatieven invariant itself is still asserted, on the
	// payload the live path actually produces, by
	// testBouwJaaropgaveFlagsCumulatievenMismatch above.
}//end class
