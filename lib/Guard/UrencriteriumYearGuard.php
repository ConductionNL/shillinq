<?php

/**
 * Urencriterium Year Guard
 *
 * ADR-031 exception-path lifecycle guard for the UrencriteriumYear save precondition.
 * Before any UrencriteriumYear record is persisted, this guard validates and
 * normalises the fiscal-compliance invariants that the declarative OpenRegister
 * lifecycle DSL cannot yet express:
 *   1. The doel-norm is one of the legally recognised values (1225 / 800 / 525)
 *      and is consistent with the supplied norm-grondslag citation (REQ-URC-000/006).
 *   2. The grotendeels-criterium outcome is consistent with the presence of parallel
 *      loondienst hours (>50% test, art. 3.6 lid 2 Wet IB 2001) (REQ-URC-007).
 *   3. The drempel-status matches the relationship between the prognose-einde-jaar
 *      and the doel-norm (OP_KOERS / RISICO / KRITIEK / BEHAALD) (REQ-URC-003).
 *
 * Referenced from the UrencriteriumYear schema's
 * x-openregister-lifecycle.preconditions.save in
 * lib/Settings/register.d/20-zzp-urencriterium-tracker.json.
 *
 * ADR-031 exception reason: norm-determination, the grotendeels >50% comparison and
 * the prognose-vs-norm drempel classification are arithmetic policy rules that the
 * declarative lifecycle DSL cannot express. They live here as a save precondition so
 * the tally / prognose batch jobs and the UI write the same canonical, validated
 * shape. Replace with declarative conditions when the engine supports arithmetic
 * comparisons over sibling fields.
 *
 * @category Guard
 * @package  OCA\Shillinq\Guard
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

namespace OCA\Shillinq\Guard;

use Psr\Log\LoggerInterface;

/**
 * Save precondition guard for the UrencriteriumYear schema per REQ-URC-000/003/006/007.
 *
 * Fail-closed: any unexpected exception denies the save (CWE-863).
 *
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-14
 */
class UrencriteriumYearGuard {

	/**
	 * The regular urencriterium norm in hours (art. 3.6 lid 1 Wet IB 2001).
	 */
	public const NORM_REGULIER = 1225;

	/**
	 * The reduced norm for entrepreneurs with UWV-assessed incapacity for work
	 * (art. 3.6 lid 5 Wet IB 2001).
	 */
	public const NORM_AO = 800;

	/**
	 * The meewerkaftrek norm in hours.
	 */
	public const NORM_MEEWERK = 525;

	/**
	 * Construct the guard.
	 *
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Save precondition for the UrencriteriumYear schema.
	 *
	 * Validates the norm / grondslag / grotendeels / drempel invariants. Returns
	 * true only when every check passes. Fail-closed: returns false on any
	 * exception (denies the save) per CWE-863.
	 *
	 * @param array<string, mixed> $year UrencriteriumYear object array supplied by OR.
	 *
	 * @return bool True when the record may be saved.
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-14
	 */
	public function validateOnSave(array $year): bool {
		try {
			if ($this->hasValidNorm(year: $year) === false) {
				return false;
			}

			if ($this->normMatchesBasis(year: $year) === false) {
				return false;
			}

			if ($this->grotendeelsIsConsistent(year: $year) === false) {
				return false;
			}

			return $this->thresholdStatusMatchesPrognose(year: $year);
		} catch (\Throwable $e) {
			$this->logger->error(
				'UrencriteriumYearGuard: validateOnSave failed — denying save (fail-closed)',
				[
					'enterpriseId' => ($year['enterpriseId'] ?? 'unknown'),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end validateOnSave()

	/**
	 * Determine the applicable doel-norm from an entrepreneur profile.
	 *
	 * Pure, deterministic norm-determination per REQ-URC-000/006:
	 *   - AO (arbeidsongeschiktheid) → 800 (art. 3.6 lid 5).
	 *   - meewerkende partner without own onderneming → 525 (meewerkaftrek).
	 *   - otherwise → 1225 (art. 3.6 lid 1).
	 *
	 * @param array<string, mixed> $profiel Profile flags: 'arbeidsongeschikt' (bool),
	 *                                      'meewerkendePartner' (bool).
	 *
	 * @return int One of NORM_REGULIER / NORM_AO / NORM_MEEWERK.
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-14
	 */
	public function bepaalDoelNorm(array $profiel): int {
		if (($profiel['arbeidsongeschikt'] ?? false) === true) {
			return self::NORM_AO;
		}

		if (($profiel['meewerkendePartner'] ?? false) === true) {
			return self::NORM_MEEWERK;
		}

		return self::NORM_REGULIER;
	}//end bepaalDoelNorm()

	/**
	 * Determine the fiscal grondslag citation for a doel-norm.
	 *
	 * @param int $purposeNorm One of the recognised norm values.
	 *
	 * @return string The legal grondslag citation.
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-14
	 */
	public function bepaalNormGrondslag(int $purposeNorm): string {
		return match ($purposeNorm) {
			self::NORM_AO => 'art. 3.6 lid 5 Wet IB 2001',
			self::NORM_MEEWERK => 'art. 3.6 lid 1 Wet IB 2001 (meewerkaftrek)',
			default => 'art. 3.6 lid 1 Wet IB 2001',
		};

	}//end bepaalNormGrondslag()

	/**
	 * Classify the drempel-status from lopende uren, prognose and norm.
	 *
	 * Deterministic classification per REQ-URC-003:
	 *   - lopendeUren >= norm                       → BEHAALD.
	 *   - prognose >= norm                          → OP_KOERS.
	 *   - prognose >= 80% of norm                   → RISICO.
	 *   - prognose <  80% of norm                   → KRITIEK.
	 *
	 * @param float $currentHours Cumulative realised hours.
	 * @param float $prognose Forecast year-end hours.
	 * @param int $norm Applicable doel-norm.
	 *
	 * @return string One of BEHAALD / OP_KOERS / RISICO / KRITIEK.
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-14
	 */
	public function bepaalDrempelStatus(float $currentHours, float $prognose, int $norm): string {
		if ($currentHours >= $norm) {
			return 'ACHIEVED';
		}

		if ($prognose >= $norm) {
			return 'ON_RATE';
		}

		if ($prognose >= ($norm * 0.8)) {
			return 'RISK';
		}

		return 'CRITICAL';
	}//end bepaalDrempelStatus()

	/**
	 * Evaluate the grotendeels-criterium (>50% onderneming vs loondienst).
	 *
	 * Per art. 3.6 lid 2 Wet IB 2001 (REQ-URC-007): when the entrepreneur also
	 * works in paid employment, more than 50% of total working time must be spent
	 * on the onderneming.
	 *
	 * @param float $enterpriseHours Hours spent on the onderneming.
	 * @param float $employmentHours Hours spent in paid employment (0 = none).
	 *
	 * @return string NIET_TOEPASSELIJK when no loondienst, otherwise
	 *                GROTENDEELS_ONDERNEMING / NIET_GROTENDEELS_ONDERNEMING.
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-15
	 */
	public function bepaalGrotendeelsCriterium(float $enterpriseHours, float $employmentHours): string {
		if ($employmentHours <= 0.0) {
			return 'NON_APPLICABLE';
		}

		$total = ($enterpriseHours + $employmentHours);
		if ($total <= 0.0) {
			return 'NON_APPLICABLE';
		}

		if (($enterpriseHours / $total) > 0.5) {
			return 'LARGELY_ENTERPRISE';
		}

		return 'NON_LARGELY_ENTERPRISE';
	}//end bepaalGrotendeelsCriterium()

	/**
	 * Verify the doel-norm is one of the recognised values.
	 *
	 * @param array<string, mixed> $year UrencriteriumYear object array.
	 *
	 * @return bool True when doelNorm is 1225, 800 or 525.
	 */
	private function hasValidNorm(array $year): bool {
		$norm = (int)($year['purposeNorm'] ?? 0);
		if (in_array($norm, [self::NORM_REGULIER, self::NORM_AO, self::NORM_MEEWERK], true) === false) {
			$this->logger->info(
				'UrencriteriumYearGuard: doelNorm not a recognised value — denying save',
				['purposeNorm' => $norm]
			);
			return false;
		}

		return true;
	}//end hasValidNorm()

	/**
	 * Verify the supplied norm-grondslag matches the doel-norm.
	 *
	 * The 800-uren norm MUST cite art. 3.6 lid 5; any other norm MUST NOT.
	 *
	 * @param array<string, mixed> $year UrencriteriumYear object array.
	 *
	 * @return bool True when the citation is consistent with the norm.
	 */
	private function normMatchesBasis(array $year): bool {
		$norm = (int)($year['purposeNorm'] ?? 0);
		$basis = (string)($year['normBasis'] ?? '');
		$citeertLid5 = (str_contains($basis, 'lid 5') === true);

		if ($norm === self::NORM_AO && $citeertLid5 === false) {
			$this->logger->info(
				'UrencriteriumYearGuard: 800-uren norm must cite art. 3.6 lid 5 — denying save',
				['normBasis' => $basis]
			);
			return false;
		}

		if ($norm !== self::NORM_AO && $citeertLid5 === true) {
			$this->logger->info(
				'UrencriteriumYearGuard: only the 800-uren norm may cite art. 3.6 lid 5 — denying save',
				['purposeNorm' => $norm, 'normBasis' => $basis]
			);
			return false;
		}

		return true;
	}//end normMatchesGrondslag()

	/**
	 * Verify the grotendeels-criterium value is internally consistent.
	 *
	 * Only a value within the recognised enum is accepted. A
	 * NIET_GROTENDEELS_ONDERNEMING marking implies the zelfstandigenaftrek is
	 * blocked, which the IB-aangifte consumer reads downstream (REQ-URC-007).
	 *
	 * @param array<string, mixed> $year UrencriteriumYear object array.
	 *
	 * @return bool True when the value is recognised.
	 */
	private function grotendeelsIsConsistent(array $year): bool {
		$value = (string)($year['largelyCriterium'] ?? 'NON_APPLICABLE');
		$allowed = [
			'NON_APPLICABLE',
			'LARGELY_ENTERPRISE',
			'NON_LARGELY_ENTERPRISE',
		];

		if (in_array($value, $allowed, true) === false) {
			$this->logger->info(
				'UrencriteriumYearGuard: grotendeelsCriterium has an unrecognised value — denying save',
				['largelyCriterium' => $value]
			);
			return false;
		}

		return true;
	}//end grotendeelsIsConsistent()

	/**
	 * Verify the drempel-status matches the prognose / norm relationship.
	 *
	 * When a prognose is present the stored drempelStatus must equal the
	 * deterministically-derived status; this keeps batch-written and UI-written
	 * records canonical. When no prognose is present yet, the status is accepted
	 * as-is (initial OP_KOERS).
	 *
	 * @param array<string, mixed> $year UrencriteriumYear object array.
	 *
	 * @return bool True when consistent (or no prognose yet).
	 */
	private function thresholdStatusMatchesPrognose(array $year): bool {
		if (isset($year['forecastYearEnd']) === false) {
			return true;
		}

		$expected = $this->bepaalDrempelStatus(
			currentHours: (float)($year['currentHours'] ?? 0),
			prognose: (float)$year['forecastYearEnd'],
			norm: (int)($year['purposeNorm'] ?? self::NORM_REGULIER)
		);

		$actual = (string)($year['thresholdStatus'] ?? '');
		if ($actual !== $expected) {
			$this->logger->info(
				'UrencriteriumYearGuard: drempelStatus does not match prognose/norm — denying save',
				[
					'actual' => $actual,
					'expected' => $expected,
				]
			);
			return false;
		}

		return true;
	}//end drempelStatusMatchesPrognose()
}//end class
