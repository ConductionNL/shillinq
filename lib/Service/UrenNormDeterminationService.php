<?php

/**
 * Uren Norm Determination Service
 *
 * Deterministic norm-determination service for the urencriterium per REQ-URC-000/006.
 * Builds the canonical {doelNorm, normGrondslag, grotendeelsCriterium} triple for a
 * new UrencriteriumYear record from an entrepreneur profile (rechtsvorm, AO-status,
 * meewerkende-partner-status, parallel loondienst hours). The deterministic policy
 * itself is owned by UrencriteriumYearGuard; this service is the init wiring around
 * it: profile → policy → fully-populated UrencriteriumYear seed.
 *
 * The hrmq cross-app profile query (AO-status, loondienst hours) is intentionally
 * abstracted behind a profile array parameter so the orchestrator can be unit-tested
 * without hrmq + can be wired to the live hrmq surface when it lands.
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
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-14
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Guard\UrencriteriumYearGuard;
use Psr\Log\LoggerInterface;

/**
 * Builds a fully-populated UrencriteriumYear seed from an entrepreneur profile.
 *
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-14
 */
final class UrenNormDeterminationService {
	/**
	 * Construct the service.
	 *
	 * @param UrencriteriumYearGuard $guard The deterministic norm/grondslag policy.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 */
	public function __construct(
		private readonly UrencriteriumYearGuard $guard,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build a fully-populated UrencriteriumYear seed for a new tracker.
	 *
	 * Profile shape (REQ-URC-000):
	 *  - 'administrationId' string   — tenant-isolation FK.
	 *  - 'enterpriseId'    string   — onderneming FK.
	 *  - 'calendarYear'     int      — year.
	 *  - 'arbeidsongeschikt'  bool   — UWV AO-status (drives 800 norm).
	 *  - 'meewerkendePartner' bool   — drives 525 norm.
	 *  - 'ondernemingsUrenJTD' float — onderneming hours year-to-date (>0 → grotendeels test).
	 *  - 'loondienstUrenJTD'  float  — paid-employment hours year-to-date.
	 *
	 * Returns a record that — by construction — passes
	 * UrencriteriumYearGuard::validateOnSave.
	 *
	 * @param array<string, mixed> $profiel Entrepreneur profile.
	 *
	 * @return array<string, mixed> UrencriteriumYear seed shape.
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-14
	 */
	public function bouwSeedRecord(array $profiel): array {
		$norm = $this->guard->bepaalDoelNorm(profiel: $profiel);
		$basis = $this->guard->bepaalNormGrondslag(purposeNorm: $norm);
		$grotendeels = $this->guard->bepaalGrotendeelsCriterium(
			enterpriseHours: (float)($profiel['ondernemingsUrenJTD'] ?? 0.0),
			employmentHours: (float)($profiel['loondienstUrenJTD'] ?? 0.0)
		);

		$seed = [
			'administrationId' => (string)($profiel['administrationId'] ?? ''),
			'enterpriseId' => (string)($profiel['enterpriseId'] ?? ''),
			'calendarYear' => (int)($profiel['calendarYear'] ?? (int)gmdate('Y')),
			'purposeNorm' => $norm,
			'normBasis' => $basis,
			'currentHours' => 0.0,
			'thresholdStatus' => 'ON_RATE',
			'largelyCriterium' => $grotendeels,
		];

		$this->logger->info(
			'UrenNormDeterminationService: built seed for new urencriterium-jaar',
			[
				'enterpriseId' => $seed['enterpriseId'],
				'calendarYear' => $seed['calendarYear'],
				'purposeNorm' => $norm,
				'grotendeels' => $grotendeels,
			]
		);

		return $seed;
	}//end bouwSeedRecord()
}//end class
