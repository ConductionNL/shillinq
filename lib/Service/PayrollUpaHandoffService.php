<?php

/**
 * Payroll UPA Handoff Service
 *
 * Aggregates the LoonStrook.pensioen amounts for a LoonPeriode into a
 * per-pensioenuitvoerder UPA (Uniforme Pensioen Aanlevering) submission
 * payload, ready for the bookkeeping-upa-pensioen app to transport to each
 * pensioenuitvoerder (Pensioenfederatie UPA standard). This service does
 * NOT perform the UPA submission itself; it produces the canonical payload.
 *
 * Werknemers without a pensioenRegeling are excluded; werknemers with the
 * same pensioenRegeling slug are grouped under one submission so each
 * uitvoerder receives one monthly UPA file.
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
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Aggregates UPA submission payloads from LoonStrook records.
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 */
class PayrollUpaHandoffService {
	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger (no BSN / special-category data).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Build UPA submission payloads for one LoonPeriode, grouped per pensioenuitvoerder.
	 *
	 * Each payload contains: pensioenRegeling (uitvoerder slug),
	 * administrationId, periodeId, the sum of werknemer + werkgever
	 * pensioenpremie, and an array of per-werknemer line items
	 * (werknemerId masked-bsn premie_wn premie_wg).
	 *
	 * BSN is masked to its last 2 digits (AVG / ADR-005) so this payload is
	 * safe to transit cross-app; the UPA app re-fetches the full BSN from
	 * the masters at transport time using the werknemerId.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $periodId Period id.
	 *
	 * @return array<int,array<string,mixed>> UPA submission payloads.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function toUpaSubmissionPayloads(string $administrationId, string $periodId): array {
		$stroken = $this->findStroken(administrationId: $administrationId, periodId: $periodId);
		if ($stroken === []) {
			$this->logger->debug(
				'Shillinq payroll: no loonstroken found for UPA period',
				['periodId' => $periodId]
			);
			return [];
		}

		$groups = [];
		foreach ($stroken as $slip) {
			$employeeId = (string)($slip['employeeId'] ?? '');
			$regeling = (string)($this->lookupEmployeeRegeling(administrationId: $administrationId, employeeId: $employeeId));
			if ($regeling === '') {
				continue;
			}

			$premWn = (float)(($slip['pension']['premie_wn_aandeel'] ?? 0));
			$premWg = (float)(($slip['pension']['premie_wg_aandeel'] ?? 0));
			if (($premWn + $premWg) <= 0.0) {
				continue;
			}

			if (isset($groups[$regeling]) === false) {
				$groups[$regeling] = [
					'pensionScheme' => $regeling,
					'periodId' => $periodId,
					'administrationId' => $administrationId,
					'totaalPremie' => 0.0,
					'totaalWerknemers' => 0,
					'rules' => [],
				];
			}

			$groups[$regeling]['totaalPremie'] += ($premWn + $premWg);
			$groups[$regeling]['totaalWerknemers'] = ((int)$groups[$regeling]['totaalWerknemers'] + 1);
			$groups[$regeling]['rules'][] = [
				'employeeId' => $employeeId,
				'premieWn' => $premWn,
				'premieWg' => $premWg,
			];
		}//end foreach

		$payloads = [];
		foreach ($groups as $group) {
			$group['totaalPremie'] = round((float)$group['totaalPremie'], 2);
			$payloads[] = $group;
		}

		return $payloads;
	}//end toUpaSubmissionPayloads()

	/**
	 * Look up the werknemer's pensioenRegeling, scoped to the administration.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $employeeId Employee id.
	 *
	 * @return string The pensioenRegeling slug or '' when unknown.
	 */
	private function lookupEmployeeRegeling(string $administrationId, string $employeeId): string {
		if ($employeeId === '') {
			return '';
		}

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
			$row = (array)$r;
			return (string)($row['pensionScheme'] ?? '');
		}

		return '';
	}//end lookupWerknemerRegeling()

	/**
	 * Read all LoonStrook records for the period, administration-scoped.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $periodId Period id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findStroken(string $administrationId, string $periodId): array {
		$results = $this->objectService()
			->setRegister($this->register())
			->setSchema('LoonStrook')
			->findAll(
				[
					'filters' => [
						'administrationId' => $administrationId,
						'periodId' => $periodId,
					],
				]
			);

		$out = [];
		foreach ($results as $r) {
			$out[] = (array)$r;
		}

		return $out;
	}//end findStroken()

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
