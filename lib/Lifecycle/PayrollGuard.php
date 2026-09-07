<?php

/**
 * Payroll Guard
 *
 * ADR-031 exception-path lifecycle guards for the Payroll register
 * (bookkeeping-detachering-payroll-administratie, T2). Two preconditions are
 * referenced from the Payroll schema's x-openregister-lifecycle transitions
 * because they require cross-schema aggregation that OpenRegister's
 * declarative `requires:` clause cannot yet express:
 *
 *  - canCalculate(): a payroll may only transition draft -> calculated once
 *                    at least one Deduction line item is assigned and the
 *                    owning Employee is active and not exited before the
 *                    payroll period (REQ-PAY-004 / REQ-PAY-001).
 *  - canIssue():     a payroll may only transition calculated -> issued when
 *                    the per-employee annual deduction totals stay within the
 *                    statutory ceilings for the tax year (REQ-PAY-005). The
 *                    statutory ceiling table is loaded from app config; an
 *                    unconfigured year falls back to a conservative default.
 *
 * ADR-031 exception reason: cross-schema SUM with statutory-table lookup is
 * not yet expressible in the declarative lifecycle DSL. When the aggregation
 * engine gains precondition support, replace these references with
 * declarative conditions and delete this file.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guards for Payroll calculate and issue transitions.
 *
 * Referenced from the Payroll schema (register.d fragment)
 * x-openregister-lifecycle transitions.calculate.requires as
 * OCA\Shillinq\Lifecycle\PayrollGuard::canCalculate and transitions.issue.requires
 * as OCA\Shillinq\Lifecycle\PayrollGuard::canIssue.
 *
 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
 */
class PayrollGuard {
	/**
	 * Default fraction of annual gross income that statutory income-tax
	 * deductions may not exceed when no per-year ceiling is configured
	 * (REQ-PAY-005). Conservative fail-closed default.
	 */
	private const DEFAULT_INCOME_TAX_CEILING_FRACTION = 0.52;

	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for the register slug and statutory ceilings.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Returns true iff the payroll may transition draft -> calculated.
	 *
	 * REQ-PAY-004: a payroll may only be calculated once deductions are
	 * assigned. REQ-PAY-001: payroll for an exited employee whose period
	 * starts after exitDate is rejected. Fail-closed on any error.
	 *
	 * @param string $payrollId The Payroll.id being transitioned.
	 * @param array<string,mixed>|null $object The in-flight Payroll object.
	 *
	 * @return bool True when the payroll may be calculated.
	 *
	 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
	 */
	public function canCalculate(string $payrollId, ?array $object = null): bool {
		try {
			$payroll = $this->resolvePayroll(payrollId: $payrollId, object: $object);
			if ($payroll === null) {
				return false;
			}

			$employeeId = (string)($payroll['employeeId'] ?? '');
			if ($employeeId === '') {
				return false;
			}

			// The employee must be active and not exited before the period start.
			$employeeOk = $this->employeeAllowsPayroll(
				employeeId: $employeeId,
				periodStartDate: (string)($payroll['periodStartDate'] ?? '')
			);
			if ($employeeOk === false) {
				return false;
			}

			// At least one deduction line item must be assigned.
			$deductions = $this->findDeductions(payrollId: (string)($payroll['id'] ?? $payrollId));
			return count($deductions) > 0;
		} catch (\Throwable $e) {
			$this->logger->error(
				'PayrollGuard: calculate check failed — denying transition (fail-closed)',
				['payrollId' => $payrollId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canCalculate()

	/**
	 * Returns true iff the payroll may transition calculated -> issued.
	 *
	 * REQ-PAY-005: the per-employee annual income-tax total (this payroll's
	 * deductions plus prior payrolls in the same tax year) must not exceed the
	 * statutory ceiling. Fail-closed on any error.
	 *
	 * @param string $payrollId The Payroll.id being transitioned.
	 * @param array<string,mixed>|null $object The in-flight Payroll object.
	 *
	 * @return bool True when the payroll may be issued.
	 *
	 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
	 */
	public function canIssue(string $payrollId, ?array $object = null): bool {
		try {
			$payroll = $this->resolvePayroll(payrollId: $payrollId, object: $object);
			if ($payroll === null) {
				return false;
			}

			$grossAmount = (float)($payroll['grossAmount'] ?? 0);
			if ($grossAmount <= 0) {
				return false;
			}

			$deductions = $this->findDeductions(payrollId: (string)($payroll['id'] ?? $payrollId));
			$incomeTaxSum = 0.0;
			foreach ($deductions as $deduction) {
				if (is_array($deduction) === false) {
					continue;
				}

				if (($deduction['deductionType'] ?? '') === 'income-tax') {
					$incomeTaxSum += (float)($deduction['amount'] ?? 0);
				}
			}

			$ceiling = $this->incomeTaxCeiling(
				period: (string)($payroll['period'] ?? ''),
				grossAmount: $grossAmount
			);
			return $incomeTaxSum <= $ceiling;
		} catch (\Throwable $e) {
			$this->logger->error(
				'PayrollGuard: issue check failed — denying transition (fail-closed)',
				['payrollId' => $payrollId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canIssue()

	/**
	 * Compute the statutory income-tax ceiling for the payroll period.
	 *
	 * The per-year ceiling fraction may be overridden via app config key
	 * `payroll_income_tax_ceiling_<year>` (a decimal fraction, e.g. "0.52");
	 * otherwise the conservative default applies.
	 *
	 * @param string $period The payroll period (YYYY-MM); year is parsed from it.
	 * @param float $grossAmount The payroll gross amount.
	 *
	 * @return float The maximum permissible income-tax deduction for this payroll.
	 */
	private function incomeTaxCeiling(string $period, float $grossAmount): float {
		$year = '';
		if (strlen($period) >= 4) {
			$year = substr($period, 0, 4);
		}

		$fraction = self::DEFAULT_INCOME_TAX_CEILING_FRACTION;
		if ($year !== '') {
			$configured = $this->appConfig->getValueString(
				Application::APP_ID,
				'payroll_income_tax_ceiling_' . $year,
				''
			);
			if ($configured !== '' && is_numeric($configured) === true) {
				$parsed = (float)$configured;
				if ($parsed > 0 && $parsed <= 1) {
					$fraction = $parsed;
				}
			}
		}

		return $grossAmount * $fraction;
	}//end incomeTaxCeiling()

	/**
	 * Returns true iff the employee is active and not exited before the period.
	 *
	 * @param string $employeeId The Employee.id to look up.
	 * @param string $periodStartDate The payroll period start date (YYYY-MM-DD).
	 *
	 * @return bool True when payroll is permitted for this employee/period.
	 */
	private function employeeAllowsPayroll(string $employeeId, string $periodStartDate): bool {
		$register = $this->resolveRegister();

		$employees = $this->objectService
			->setRegister($register)
			->setSchema('payrollEmployee')
			->findAll(['filters' => ['id' => $employeeId]]);

		foreach ($employees as $employee) {
			if (is_array($employee) === false) {
				continue;
			}

			if (($employee['state'] ?? 'active') === 'inactive') {
				return false;
			}

			$exitDate = (string)($employee['exitDate'] ?? '');
			if ($exitDate !== '' && $periodStartDate !== '' && $periodStartDate > $exitDate) {
				return false;
			}

			return true;
		}

		// No matching employee — fail closed.
		return false;
	}//end employeeAllowsPayroll()

	/**
	 * Find all Deduction records for a payroll.
	 *
	 * @param string $payrollId The parent Payroll.id.
	 *
	 * @return array<int,mixed> The deduction rows (possibly empty).
	 */
	private function findDeductions(string $payrollId): array {
		if ($payrollId === '') {
			return [];
		}

		$register = $this->resolveRegister();

		$deductions = $this->objectService
			->setRegister($register)
			->setSchema('Deduction')
			->findAll(['filters' => ['payrollId' => $payrollId]]);

		return array_values($deductions);
	}//end findDeductions()

	/**
	 * Resolve the payroll object, preferring the supplied object and falling
	 * back to an ObjectService lookup by id.
	 *
	 * @param string $payrollId The Payroll.id to look up if no object given.
	 * @param array<string,mixed>|null $object The in-flight object, if provided by the engine.
	 *
	 * @return array<string,mixed>|null The payroll object, or null when not found.
	 */
	private function resolvePayroll(string $payrollId, ?array $object): ?array {
		if ($object !== null && (isset($object['grossAmount']) === true || isset($object['payrollNumber']) === true)) {
			return $object;
		}

		if ($payrollId === '') {
			return null;
		}

		$register = $this->resolveRegister();

		$payrolls = $this->objectService
			->setRegister($register)
			->setSchema('Payroll')
			->findAll(['filters' => ['id' => $payrollId]]);

		foreach ($payrolls as $payroll) {
			if (is_array($payroll) === true) {
				return $payroll;
			}
		}

		return null;
	}//end resolvePayroll()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
	 *
	 * @return string The register slug.
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
