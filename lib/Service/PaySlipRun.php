<?php

/**
 * Pay slip run
 *
 * The state of composing one LoonStrook: the administration and employee, the
 * employer, period, period type and wage-tax table it is computed with, and the
 * gross, fiscal, tax and premium figures each step adds.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Progress of composing one employee's payslip for a period.
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 */
final class PaySlipRun {
	/**
	 * Employer record.
	 *
	 * @var array<string,mixed>
	 */
	public array $werkgever = [];

	/**
	 * Period record.
	 *
	 * @var array<string,mixed>
	 */
	public array $period = [];

	/**
	 * WEEK|4WEKEN|MAAND.
	 *
	 * @var string
	 */
	public string $periodType = '';

	/**
	 * Wage-tax bracket rows.
	 *
	 * @var array<int,mixed>
	 */
	public array $tableRules = [];

	/**
	 * The base salary for the period.
	 *
	 * @var float
	 */
	public float $basissalaris = 0.0;

	/**
	 * The tax-free home-office allowance.
	 *
	 * @var float
	 */
	public float $thuiswerkvergoeding = 0.0;

	/**
	 * The gross components.
	 *
	 * @var array<string,float>
	 */
	public array $grossComponents = [];

	/**
	 * The tax-free total (allowance + expat exemption).
	 *
	 * @var float
	 */
	public float $belastingvrijTotaal = 0.0;

	/**
	 * The fiscal pay.
	 *
	 * @var float
	 */
	public float $fiscalPay = 0.0;

	/**
	 * The wage tax.
	 *
	 * @var float
	 */
	public float $payrollTax = 0.0;

	/**
	 * Start composing a payslip.
	 *
	 * @param string              $administrationId Administration scope.
	 * @param array<string,mixed> $employee         Employee record.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly array $employee,
	) {
	}//end __construct()
}//end class
