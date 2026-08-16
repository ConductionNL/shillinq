<?php

/**
 * Payroll LIV/LKV Handoff Service
 *
 * Aggregates Werknemer.inkomenniveau + the year's LoonStrook.fiscaalLoon
 * sum into a LIV/LKV eligibility payload that the future
 * bookkeeping-liv-lkv app consumes to claim the Lage Inkomens Voordeel
 * (LIV) and Loonkostenvoordelen (LKV) at UWV. This service does not make
 * the LIV/LKV claim itself; it produces the per-werknemer per-jaar payload
 * with the eligibility input shape stable for the downstream app.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Pure aggregate: per-(werknemer, jaar) LIV/LKV eligibility payload.
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 */
class PayrollLivLkvHandoffService {
	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param PayrollCalculator $calculator Cents arithmetic helper.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly PayrollCalculator $calculator,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Build the LIV/LKV eligibility payload for one werknemer + year.
	 *
	 * Returns: werknemerId, jaar, inkomenniveau (Werknemer master), totaal
	 * fiscaalLoon for the year, contracturen per week (Werknemer master),
	 * any LKV-categorie carried on the Werknemer (banenafspraak,
	 * herplaatsing, doelgroepverklaring), and the administrationId scope.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $employeeId Employee id.
	 * @param int $year Calendar year.
	 *
	 * @return array<string,mixed>|null The eligibility payload or null when werknemer not found.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function toLivLkvEligibilityPayload(string $administrationId, string $employeeId, int $year): ?array {
		$employee = $this->findEmployee(administrationId: $administrationId, employeeId: $employeeId);
		if ($employee === null) {
			return null;
		}

		$totalFiscal = $this->sumFiscalPayYear(
			administrationId: $administrationId,
			employeeId: $employeeId,
			year: $year
		);

		return [
			'employeeId' => $employeeId,
			'year' => $year,
			'inkomenniveau' => (string)($employee['inkomenniveau'] ?? ''),
			'fiscaalLoonJaar' => $totalFiscal,
			'contracturenPerWeek' => (float)($employee['contracturenPerWeek'] ?? 0),
			'lkvCategorie' => (string)($employee['lkvCategorie'] ?? ''),
			'doelgroepverklaring' => (bool)($employee['doelgroepverklaring'] ?? false),
			'administrationId' => $administrationId,
			'source' => 'Werknemer+LoonStrook',
		];

	}//end toLivLkvEligibilityPayload()

	/**
	 * Look up the werknemer (administration-scoped).
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $employeeId Employee id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findEmployee(string $administrationId, string $employeeId): ?array {
		$results = $this->objectService()
			->setRegister($this->register())
			->setSchema('Werknemer')
			->findAll(
				[
					'filters' => [
						'administrationId' => $administrationId,
						'id' => $employeeId,
					],
				]
			);

		foreach ($results as $r) {
			return (array)$r;
		}

		return null;
	}//end findWerknemer()

	/**
	 * Sum LoonStrook.fiscaalLoon for the (werknemer, jaar) tuple.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $employeeId Employee id.
	 * @param int $year Calendar year.
	 *
	 * @return float Sum in euros.
	 */
	private function sumFiscalPayYear(string $administrationId, string $employeeId, int $year): float {
		$results = $this->objectService()
			->setRegister($this->register())
			->setSchema('LoonStrook')
			->findAll(
				[
					'filters' => [
						'administrationId' => $administrationId,
						'employeeId' => $employeeId,
					],
				]
			);

		$totalC = 0;
		foreach ($results as $r) {
			$row = (array)$r;
			$period = (string)($row['periodId'] ?? '');
			if (preg_match('/(?<year>20[0-9]{2})/', $period, $m) === 1 && (int)$m['year'] !== $year) {
				continue;
			}

			$totalC += $this->calculator->toCents(amount: (float)($row['fiscalPay'] ?? 0));
		}

		return $this->calculator->fromCents(cents: $totalC);
	}//end sumFiscaalLoonYear()

	/**
	 * Lazily fetch OpenRegister's ObjectService.
	 *
	 * @return object The ObjectService.
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()

	/**
	 * Resolve the configured OpenRegister register slug.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
