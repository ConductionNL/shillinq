<?php

/**
 * Characterisation tests for PayrollService::bouwLoonjournaalpost().
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
use OCA\Shillinq\Service\PayrollChartOfAccountsMapping as Accounts;
use OCA\Shillinq\Service\PayrollService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * Pins every journal rule (account, name, debit, credit, order), the period
 * scoping, the administration scoping and the balanced flag.
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
final class PayrollJournalTest extends TestCase {

	/**
	 * Build the service on seeded payslips.
	 *
	 * @param array<int,array<string,mixed>> $stroken LoonStrook rows.
	 *
	 * @return PayrollService
	 */
	private function service(array $stroken): PayrollService {
		$os = new InMemoryObjectService();
		$os->seed(schema: 'LoonStrook', rows: $stroken);
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new PayrollService(
			appConfig: $appConfig,
			calculator: new PayrollCalculator(),
			logger: $this->createStub(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($os),
		);

	}//end service()

	/**
	 * A payslip row.
	 *
	 * @param string $periodId         The period.
	 * @param string $administrationId The administration.
	 * @param float  $net              The net paid.
	 *
	 * @return array<string,mixed>
	 */
	private function strook(string $periodId, string $administrationId, float $net): array {
		return [
			'periodId' => $periodId,
			'administrationId' => $administrationId,
			'grossComponents' => ['totaal_bruto' => 1000.10, 'thuiswerkvergoeding' => 20.05],
			'employerSocialInsurancePremiums' => ['totaal_werkgever' => 100.0],
			'zvw' => ['afgedragen_wg' => 60.0],
			'pension' => ['premie_wg_aandeel' => 50.0, 'premie_wn_aandeel' => 25.0],
			'payrollTax' => 200.0,
			'netPaid' => $net,
		];

	}//end strook()

	/**
	 * Every rule of a two-payslip journal, only the period's and
	 * administration's payslips counted.
	 *
	 * @return void
	 */
	public function testJournalRules(): void {
		$journal = $this->service(
			[
				$this->strook('lp-1', 'adm-1', 750.10),
				$this->strook('lp-1', 'adm-1', 750.10),
				$this->strook('lp-2', 'adm-1', 1.0),
				$this->strook('lp-1', 'adm-2', 1.0),
			]
		)->bouwLoonjournaalpost('adm-1', 'lp-1');

		self::assertSame(['periodId', 'date', 'rules', 'balanced', 'administrationId'], array_keys($journal));
		self::assertSame(['lp-1', 'adm-1'], [$journal['periodId'], $journal['administrationId']]);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $journal['date']);
		self::assertSame(
			[
				['rekening' => Accounts::ACC_BRUTOLONEN, 'name' => 'Brutolonen', 'debet' => 1960.1, 'credit' => 0.0],
				['rekening' => Accounts::ACC_BELASTINGVRIJE_VERGOEDINGEN, 'name' => 'Belastingvrije vergoedingen', 'debet' => 40.1, 'credit' => 0.0],
				['rekening' => Accounts::ACC_SOCIALE_LASTEN_WG, 'name' => 'Sociale lasten WG', 'debet' => 200.0, 'credit' => 0.0],
				['rekening' => Accounts::ACC_ZVW_WG, 'name' => 'ZVW-bijdrage WG', 'debet' => 120.0, 'credit' => 0.0],
				['rekening' => Accounts::ACC_PENSIOEN_WG, 'name' => 'Pensioenpremie WG', 'debet' => 100.0, 'credit' => 0.0],
				['rekening' => Accounts::ACC_TE_BETALEN_NETTO_LOON, 'name' => 'Te betalen netto loon', 'debet' => 0.0, 'credit' => 1500.2],
				['rekening' => Accounts::ACC_AF_TE_DRAGEN_LH, 'name' => 'Af te dragen loonheffing', 'debet' => 0.0, 'credit' => 400.0],
				['rekening' => Accounts::ACC_AF_TE_DRAGEN_PREMIES_SV_ZVW, 'name' => 'Af te dragen premies SV+ZVW', 'debet' => 0.0, 'credit' => 320.0],
				['rekening' => Accounts::ACC_AF_TE_DRAGEN_PENSIOEN, 'name' => 'Af te dragen pensioenpremie', 'debet' => 0.0, 'credit' => 150.0],
			],
			$journal['rules']
		);
		self::assertFalse($journal['balanced']);

	}//end testJournalRules()

	/**
	 * A journal whose debits equal its credits is balanced; one without
	 * payslips is too.
	 *
	 * @return void
	 */
	public function testBalancedFlag(): void {
		self::assertTrue($this->service([$this->strook('lp-1', 'adm-1', 775.1)])->bouwLoonjournaalpost('adm-1', 'lp-1')['balanced']);
		self::assertTrue($this->service([])->bouwLoonjournaalpost('adm-1', 'lp-1')['balanced']);

	}//end testBalancedFlag()
}//end class
