<?php

/**
 * Grotendeels Criterium Service
 *
 * Daily grotendeels-criterium (>50% onderneming vs loondienst) evaluation per
 * REQ-URC-007 / art. 3.6 lid 2 Wet IB 2001. Sums year-to-date hours from the
 * UrenDagregistratie feed (onderneming-side) and from a supplied hrmq snapshot
 * (loondienst-side) and emits the canonical grotendeelsCriterium marking that
 * gets stored on UrencriteriumYear.
 *
 * The hrmq loondienst-hours surface is intentionally abstracted: callers pass an
 * already-resolved float for the year-to-date payroll hours. When the cross-app
 * hrmq surface lands, the loondienst-hours adapter wires here without touching
 * the policy.
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
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-15
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Guard\UrencriteriumYearGuard;
use Psr\Log\LoggerInterface;

/**
 * Aggregates onderneming + loondienst hours and emits the grotendeels-criterium marking.
 *
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-15
 */
final class GrotendeelsCriteriumService {
	/**
	 * Construct the service.
	 *
	 * @param UrencriteriumYearGuard $guard Owns the >50% policy.
	 * @param LoggerInterface $logger Diagnostics logger.
	 */
	public function __construct(
		private readonly UrencriteriumYearGuard $guard,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Sum the onderneming hours from a UrenDagregistratie collection.
	 *
	 * Entries that do not count toward the urencriterium (telTMee=false on the
	 * category-table) are excluded by the caller; this method counts every
	 * supplied entry's `getoldeUren` (preferring it over `uren` so the
	 * reistijd-cap is honoured).
	 *
	 * @param array<int, array<string, mixed>> $dagregistraties Year-to-date entries.
	 *
	 * @return float Total onderneming hours (post-cap).
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-15
	 */
	public function telOndernemingsUren(array $dagregistraties): float {
		$total = 0.0;
		foreach ($dagregistraties as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$geteld = $entry['countedHours'] ?? $entry['hours'] ?? 0;
			$total += (float)$geteld;
		}

		return $total;
	}//end telOndernemingsUren()

	/**
	 * Classify the grotendeels-criterium from the YTD totals.
	 *
	 * Thin wrapper around UrencriteriumYearGuard::bepaalGrotendeelsCriterium so a
	 * single canonical policy is used by both the year-init service and the
	 * daily batch.
	 *
	 * @param float $enterpriseHours YTD onderneming hours.
	 * @param float $employmentHours YTD payroll hours.
	 *
	 * @return string NIET_TOEPASSELIJK / GROTENDEELS_ONDERNEMING / NIET_GROTENDEELS_ONDERNEMING.
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-15
	 */
	public function classifeer(float $enterpriseHours, float $employmentHours): string {
		return $this->guard->bepaalGrotendeelsCriterium(
			enterpriseHours: $enterpriseHours,
			employmentHours: $employmentHours
		);

	}//end classifeer()

	/**
	 * Build the grotendeels patch to apply to a UrencriteriumYear record.
	 *
	 * Returns the canonical {grotendeelsCriterium, blokkeertZelfstandigenaftrek}
	 * shape: NIET_GROTENDEELS_ONDERNEMING blocks the zelfstandigenaftrek
	 * (REQ-URC-007), the other markings do not.
	 *
	 * @param array<int, array<string, mixed>> $dagregistraties YTD onderneming entries.
	 * @param float $employmentHours YTD payroll hours.
	 *
	 * @return array{largelyCriterium: string, blokkeertZelfstandigenaftrek: bool}
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-15
	 */
	public function bouwPatch(array $dagregistraties, float $employmentHours): array {
		$enterpriseHours = $this->telOndernemingsUren(dagregistraties: $dagregistraties);
		$marking = $this->classifeer(
			enterpriseHours: $enterpriseHours,
			employmentHours: $employmentHours
		);

		$blokkeert = ($marking === 'NON_LARGELY_ENTERPRISE');
		if ($blokkeert === true) {
			$this->logger->warning(
				'GrotendeelsCriteriumService: grotendeels-criterium niet behaald — zelfstandigenaftrek geblokkeerd',
				[
					'ondernemingsUren' => $enterpriseHours,
					'loondienstUren' => $employmentHours,
				]
			);
		}

		return [
			'largelyCriterium' => $marking,
			'blokkeertZelfstandigenaftrek' => $blokkeert,
		];

	}//end bouwPatch()
}//end class
