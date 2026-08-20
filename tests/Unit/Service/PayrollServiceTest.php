<?php

/**
 * Unit tests for PayrollService.
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
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PayrollCalculator;
use OCA\Shillinq\Service\PayrollService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the OpenRegister-backed payroll service.
 *
 * Covers REQ-PAY-001 (bruto->netto payslip), REQ-PAY-011 (LH-afdracht aggregate),
 * REQ-PAY-012 (balanced GL journal + refusal of an unbalanced one), the
 * administrationId scoping (IDOR) and BSN masking (AVG). The ObjectService is
 * stubbed with an in-memory schema-keyed store that honours equality filters
 * (including the forced administrationId filter), so cross-administration data
 * never leaks.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PayrollServiceTest extends TestCase {

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
	 * Build a PayrollService over an in-memory schema-keyed ObjectService stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Captures saved objects.
	 *
	 * @return PayrollService
	 */
	private function buildService(array $data, array &$saved): PayrollService {
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
			 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
			 * @param array<int,array<string,mixed>> $saved Capture ref.
			 */
			public function __construct(array $data, array &$saved) {
				$this->data = $data;
				$this->saved = &$saved;
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
			 * Return rows for the active schema, applying equality filters.
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
			 * Capture a saved object.
			 *
			 * @param array<string,mixed> $object Object payload.
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

		return new PayrollService(
			appConfig: $this->appConfig,
			calculator: new PayrollCalculator(),
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * A worked May-2026 employee dataset for one administration.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function dataset(): array {
		return [
			'Werkgever' => [
				[
					'id' => 'wg-1',
					'awfRate' => 'LOW',
					'zvwRate' => 'LOW',
					'administrationId' => 'adm-1',
				],
			],
			'Werknemer' => [
				[
					'id' => 'wn-1',
					'employerId' => 'wg-1',
					'personId' => 'person-1',
					'bsn' => '123456789',
					'periodeBruto' => 4940.0,
					'thuiswerkdagenPerWeek' => 0,
					'expat30PctScheme' => false,
					'pensionPremiumPctEmployer' => 0.182,
					'pensionPremiumPctEmployee' => 0.072,
					'holidayAllowancePct' => 0.08,
					'payrollTaxTable' => 'WIT_REGULAR',
					'payrollTaxTableDiscount' => true,
					'administrationId' => 'adm-1',
				],
			],
			'LoonPeriode' => [
				[
					'id' => 'lp-1',
					'employerId' => 'wg-1',
					'periodType' => 'MONTH',
					'periodEnd' => '2026-05-31',
					'payrollTaxTableId' => 'lht-1',
					'administrationId' => 'adm-1',
				],
			],
			'LoonheffingTabel2026' => [
				[
					'id' => 'lht-1',
					'colour' => 'WHITE',
					'period' => 'MONTH',
					'withDiscount' => true,
					'tableRules' => [
						['from' => 3300, 'tot' => 6400, 'percentage' => 0.3697, 'vasteHeffing' => 888.6, 'korting' => 295.0],
					],
				],
			],
			'LoonStrook' => [],
		];
	}//end dataset()

	/**
	 * The berekenLoonStrook method computes a full bruto->netto payslip (REQ-PAY-001).
	 *
	 * @return void
	 */
	public function testBerekenLoonStrookComputesNetto(): void {
		$saved = [];
		$service = $this->buildService($this->dataset(), $saved);

		$slip = $service->berekenLoonStrook('adm-1', 'wn-1', 'lp-1');

		self::assertSame(4940.0, $slip['fiscalPay']);
		// LH from the bracket: 888.60 + 0.3697*(4940-3300) - 295 = 1199.91.
		self::assertSame(1199.91, $slip['payrollTax']);
		// Net = 4940 - 1199.91 - 0 - pensioen-wn 355.68 + 0 = 3384.41.
		self::assertSame(3384.41, $slip['netPaid']);
		self::assertSame('adm-1', $slip['administrationId']);
		self::assertSame(899.08, $slip['pension']['premie_wg_aandeel']);

	}//end testBerekenLoonStrookComputesNetto()

	/**
	 * A record in another administration is invisible (IDOR scoping).
	 *
	 * @return void
	 */
	public function testCrossAdministrationIsScopedOut(): void {
		$saved = [];
		$service = $this->buildService($this->dataset(), $saved);

		$this->expectException(\RuntimeException::class);
		// Same ids, wrong administration -> the forced administrationId filter hides them.
		$service->berekenLoonStrook('adm-OTHER', 'wn-1', 'lp-1');

	}//end testCrossAdministrationIsScopedOut()

	/**
	 * The berekenLHAfdracht method aggregates payslip components and dates the remittance (REQ-PAY-011).
	 *
	 * @return void
	 */
	public function testBerekenLHAfdrachtAggregates(): void {
		$data = $this->dataset();
		$data['LoonStrook'] = [
			[
				'periodId' => 'lp-1',
				'payrollTax' => 1000.0,
				'employerSocialInsurancePremiums' => ['totaal_werkgever' => 400.0],
				'zvw' => ['afgedragen_wg' => 200.0],
				'administrationId' => 'adm-1',
			],
			[
				'periodId' => 'lp-1',
				'payrollTax' => 500.0,
				'employerSocialInsurancePremiums' => ['totaal_werkgever' => 100.0],
				'zvw' => ['afgedragen_wg' => 50.0],
				'administrationId' => 'adm-1',
			],
		];

		$saved = [];
		$service = $this->buildService($data, $saved);

		$remittance = $service->berekenLHAfdracht('adm-1', 'lp-1', 0.0);

		self::assertSame(1500.0, $remittance['totalPayrollTax']);
		self::assertSame(500.0, $remittance['totalSocialInsuranceContributions']);
		self::assertSame(250.0, $remittance['totalHealthInsurance']);
		self::assertSame(2250.0, $remittance['totalRemittance']);
		self::assertSame('VOORBEREID', $remittance['status']);
		self::assertSame('2026-06-30', $remittance['dueDateRemittance']);

	}//end testBerekenLHAfdrachtAggregates()

	/**
	 * The bouwLoonjournaalpost method produces a balanced journal (REQ-PAY-012).
	 *
	 * @return void
	 */
	public function testBouwLoonjournaalpostBalances(): void {
		$data = $this->dataset();
		$data['LoonStrook'] = [
			[
				'periodId' => 'lp-1',
				'grossComponents' => ['totaal_bruto' => 4940.0, 'thuiswerkvergoeding' => 0.0],
				'employerSocialInsurancePremiums' => ['totaal_werkgever' => 400.0],
				'zvw' => ['afgedragen_wg' => 262.81],
				'pension' => ['premie_wg_aandeel' => 899.08, 'premie_wn_aandeel' => 355.68],
				'payrollTax' => 1199.91,
				'netPaid' => 3384.41,
				'administrationId' => 'adm-1',
			],
		];

		$saved = [];
		$service = $this->buildService($data, $saved);

		$journaal = $service->bouwLoonjournaalpost('adm-1', 'lp-1');

		self::assertTrue($journaal['balanced']);
		$debet = 0;
		$credit = 0;
		foreach ($journaal['rules'] as $rule) {
			$debet += (int)round(((float)$rule['debet']) * 100);
			$credit += (int)round(((float)$rule['credit']) * 100);
		}

		self::assertSame($debet, $credit);

	}//end testBouwLoonjournaalpostBalances()

	// testPersistRefusesUnbalancedJournal and testPersistLoonStrookSaves were
	// removed with PayrollService::persistLoonjournaalpost() and
	// ::persistLoonStrook(). Both methods had zero production callers, so
	// neither the save nor the balance guard these cases asserted ever ran
	// outside this file. testBouwLoonjournaalpostBalances above still asserts
	// the balance invariant on the payload the live controller path returns.

	/**
	 * BSN masking exposes only the last two digits (AVG, REQ-PAY-000).
	 *
	 * @return void
	 */
	public function testMaskBsn(): void {
		$saved = [];
		$service = $this->buildService($this->dataset(), $saved);

		self::assertSame('*******89', $service->maskBsn('123456789'));
		self::assertNull($service->maskBsn(null));
		self::assertSame('**', $service->maskBsn('12'));

	}//end testMaskBsn()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
