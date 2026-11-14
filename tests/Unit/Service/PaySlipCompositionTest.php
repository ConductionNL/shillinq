<?php

/**
 * Characterisation tests for the payslip PayrollService::berekenLoonStrook()
 * composes.
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * Pins the complete payslip (every component, in key order) for an employee
 * with a home-office allowance, the 30% expat scheme, a pension and prior
 * payslips feeding the year-to-date totals.
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
final class PaySlipCompositionTest extends TestCase {

	/**
	 * The full payslip.
	 *
	 * @return void
	 */
	public function testComposedPaySlip(): void {
		$os = new InMemoryObjectService();
		$os->seed(schema: 'Werkgever', rows: [['id' => 'wg-1', 'awfRate' => 'HIGH', 'zvwRate' => 'LOW', 'administrationId' => 'adm-1']]);
		$os->seed(
			schema: 'Werknemer',
			rows: [
				[
					'id' => 'wn-1',
					'employerId' => 'wg-1',
					'periodeBruto' => 4200.0,
					'thuiswerkdagenPerWeek' => 2,
					'expat30PctScheme' => true,
					'pensionPremiumPctEmployer' => 0.15,
					'pensionPremiumPctEmployee' => 0.05,
					'holidayAllowancePct' => 0.08,
					'whkTarief2026' => 0.0152,
					'wkoTarief2026' => 0.005,
					'payrollTaxTable' => 'GREEN',
					'administrationId' => 'adm-1',
				],
			]
		);
		$os->seed(schema: 'LoonPeriode', rows: [['id' => 'lp-2', 'periodType' => 'MONTH', 'periodEnd' => '2026-02-28', 'administrationId' => 'adm-1']]);
		$os->seed(
			schema: 'LoonheffingTabel2026',
			rows: [
				[
					'colour' => 'GREEN',
					'period' => 'MONTH',
					'withDiscount' => true,
					'tableRules' => [['from' => 0, 'tot' => 99999, 'percentage' => 0.37, 'vasteHeffing' => 0, 'korting' => 100.0]],
				],
			]
		);
		$os->seed(
			schema: 'LoonStrook',
			rows: [['employeeId' => 'wn-1', 'fiscalPay' => 3000.5, 'holidayDaysAccrual' => ['opgebouwdEuro' => 336.0], 'administrationId' => 'adm-1']]
		);
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$service = new PayrollService(
			appConfig: $appConfig,
			calculator: new PayrollCalculator(),
			logger: $this->createStub(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($os),
		);

		self::assertSame(
			[
				'employeeId' => 'wn-1',
				'periodId' => 'lp-2',
				'grossComponents' => [
					'basissalaris' => 4200.0,
					'thuiswerkvergoeding' => 20.8,
					'totaal_bruto' => 4220.8,
				],
				'fiscalPay' => 2940.0,
				'contribution_pay_sv' => 4200.0,
				'payrollTax' => 987.8,
				'inhoudingenSV' => [
					'totaal_sv_wn' => 0,
				],
				'employerSocialInsurancePremiums' => [
					'premieloon_gemaximeerd' => 4200.0,
					'awf' => 149.1,
					'aof_basis' => 225.96,
					'whk' => 63.84,
					'wko' => 21.0,
					'totaal_werkgever' => 459.9,
				],
				'zvw' => [
					'afgedragen_wg' => 223.44,
					'rate' => 0.0532,
				],
				'pension' => [
					'premie_wg_aandeel' => 630.0,
					'premie_wn_aandeel' => 210.0,
				],
				'netPaid' => 3023.0,
				'cumulatieven' => [
					'fiscaalloon_ytd' => 5940.5,
					'vakantiegeld_reservering_ytd' => 672.0,
				],
				'holidayDaysAccrual' => [
					'opgebouwdEuro' => 336.0,
				],
				'administrationId' => 'adm-1',
			],
			$service->berekenLoonStrook('adm-1', 'wn-1', 'lp-2')
		);

	}//end testComposedPaySlip()
}//end class
