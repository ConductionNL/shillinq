<?php

/**
 * DBA risk-score calculator — ADR-031 exception-path guard.
 *
 * Invoked by the x-openregister-calculations engine (via the `guard:` clause on
 * DBAIntake.totaalScore) when the declarative weighted-sum with conditional
 * boosters cannot be expressed natively. Single deterministic method, no
 * persistence, pure arithmetic per ADR-031 §"PHP guards remain a legitimate
 * seam". Computes the Wet DBA three-pillar score (max 60) + Deliveroo-criteria
 * (max 40) into a 0-100 totaal, with conditional boosters for exclusiviteit +
 * langjarigheid (REQ-DBA-003).
 *
 * Exception documented in
 * openspec/changes/dba-compliance-marker/design.md §D2.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use Psr\Log\LoggerInterface;

/**
 * ADR-031 exception guard for the DBA risk-score (REQ-DBA-003).
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */
class DBAScoreCalculator {
	/**
	 * Deliveroo-criteria duur-relatie point weights.
	 */
	private const DUUR_POINTS = [
		'MINDER_DAN_3_MONTHS' => 2,
		'3_TO_6_MONTHS' => 4,
		'6_TO_12_MONTHS' => 6,
		'1_TO_2_YEAR' => 8,
		'MEER_DAN_2_YEAR' => 10,
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Nextcloud logger for computation diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute the full DBA risk-score (REQ-DBA-003).
	 *
	 * Sums the three pillar subtotals (max 60), adds Deliveroo-criteria (max 40),
	 * and applies conditional boosters: +5 when exclusief AND duurRelatie >= 1y;
	 * +5 when vervangbaarheid is theoretisch (vervangbaarScore < 5 AND
	 * vervangingFeitelijkScore = 10). The result is clamped to [0, 100].
	 *
	 * @param array<string,mixed> $intake A DBAIntake object as stored in OpenRegister.
	 *
	 * @return int The total score in [0, 100].
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function computeTotal(array $intake): int {
		$gezag = $this->subtotalGezag(intake: $intake);
		$arbeid = $this->subtotalArbeid(intake: $intake);
		$financieel = $this->subtotalFinancieel(intake: $intake);
		$deliveroo = $this->subtotalDeliveroo(intake: $intake);

		$base = $gezag + $arbeid + $financieel + $deliveroo;

		$booster = 0;
		$deliverooBlock = $this->arrayOrEmpty(value: ($intake['deliverooCriteria'] ?? []));
		$arbeidBlock = $this->arrayOrEmpty(value: ($intake['personalLabour'] ?? []));

		$excluding = (bool)($deliverooBlock['excluding'] ?? false);
		$duration = (string)($deliverooBlock['durationRelationship'] ?? '');
		if ($excluding === true && in_array($duration, ['1_TO_2_YEAR', 'MEER_DAN_2_YEAR'], true) === true) {
			$booster += 5;
		}

		$vervBaar = (int)($arbeidBlock['replaceableScore'] ?? 0);
		$vervActual = (int)($arbeidBlock['replacementActualScore'] ?? 0);
		if ($vervBaar < 5 && $vervActual >= 10) {
			$booster += 5;
		}

		$total = $base + $booster;
		if ($total < 0) {
			$total = 0;
		}

		if ($total > 100) {
			$total = 100;
		}

		$this->logger->debug(
			'DBAScoreCalculator: computed total',
			[
				'gezag' => $gezag,
				'arbeid' => $arbeid,
				'financieel' => $financieel,
				'deliveroo' => $deliveroo,
				'booster' => $booster,
				'total' => $total,
			]
		);

		return $total;
	}//end computeTotal()

	/**
	 * Compute the gezagsverhouding subtotal (max 20).
	 *
	 * @param array<string,mixed> $intake The DBAIntake object.
	 *
	 * @return int The subtotal in [0, 20].
	 */
	public function subtotalGezag(array $intake): int {
		$block = $this->arrayOrEmpty(value: ($intake['authorityRelationship'] ?? []));
		$instructies = (int)($block['kwaInstructiesScore'] ?? 0);
		$result = (int)($block['kwaResultFreeScore'] ?? 0);
		$teamMeeting = (int)($block['participatesInTeamMeetingScore'] ?? 0);
		$value = ($instructies + $result + $teamMeeting);
		return $this->clamp(value: $value, min: 0, max: 20);
	}//end subtotalGezag()

	/**
	 * Compute the persoonlijke arbeid subtotal (max 20).
	 *
	 * @param array<string,mixed> $intake The DBAIntake object.
	 *
	 * @return int The subtotal in [0, 20].
	 */
	public function subtotalArbeid(array $intake): int {
		$block = $this->arrayOrEmpty(value: ($intake['personalLabour'] ?? []));
		$value = (int)($block['replaceableScore'] ?? 0) + (int)($block['replacementActualScore'] ?? 0);
		return $this->clamp(value: $value, min: 0, max: 20);
	}//end subtotalArbeid()

	/**
	 * Compute the financieel risico subtotal (max 20).
	 *
	 * @param array<string,mixed> $intake The DBAIntake object.
	 *
	 * @return int The subtotal in [0, 20].
	 */
	public function subtotalFinancieel(array $intake): int {
		$block = $this->arrayOrEmpty(value: ($intake['financialRisk'] ?? []));
		$frequency = (int)($block['invoiceFrequencyScore'] ?? 0);
		$risk = (int)($block['paymentRiskScore'] ?? 0);
		$investment = (int)($block['investmentOwnResourcesScore'] ?? 0);
		$value = ($frequency + $risk + $investment);
		return $this->clamp(value: $value, min: 0, max: 20);
	}//end subtotalFinancieel()

	/**
	 * Compute the Deliveroo-criteria subtotal (max 40).
	 *
	 * Per HR 24-3-2023, ECLI:NL:HR:2023:443: weights each criterion to surface
	 * werknemerschap-signalen. Specialistisch werk + eigen klanten + eigen
	 * reclame reduce risk; exclusiviteit + lange duur + ontbrekend model raise it.
	 *
	 * @param array<string,mixed> $intake The DBAIntake object.
	 *
	 * @return int The subtotal in [0, 40].
	 */
	public function subtotalDeliveroo(array $intake): int {
		$block = $this->arrayOrEmpty(value: ($intake['deliverooCriteria'] ?? []));

		$score = 0;

		$duration = (string)($block['durationRelationship'] ?? '');
		$score += self::DUUR_POINTS[$duration] ?? 0;

		if ((bool)($block['excluding'] ?? false) === true) {
			$score += 8;
		}

		if ((bool)($block['natureActivitiesSpecialist'] ?? false) === false) {
			$score += 6;
		}

		if ((bool)($block['ownCustomers'] ?? false) === false) {
			$score += 6;
		}

		if ((bool)($block['ownReclame'] ?? false) === false) {
			$score += 4;
		}

		if ((bool)($block['modelAgreementPresent'] ?? false) === false) {
			$score += 4;
		}

		if ((bool)($block['actualExecutionFollowsContract'] ?? true) === false) {
			$score += 2;
		}

		return $this->clamp(value: $score, min: 0, max: 40);
	}//end subtotalDeliveroo()

	/**
	 * Coerce a value to an array, returning an empty array when not array-shaped.
	 *
	 * @param mixed $value Any value (typically an OR sub-object).
	 *
	 * @return array<string,mixed> An array shape.
	 */
	private function arrayOrEmpty(mixed $value): array {
		if (is_array($value) === true) {
			/*
			 * @var array<string,mixed> $value
			 */

			return $value;
		}

		if (is_object($value) === true) {
			$arr = (array)$value;

			/*
			 * @var array<string,mixed> $arr
			 */

			return $arr;
		}

		return [];
	}//end arrayOrEmpty()

	/**
	 * Clamp a value to a [min, max] range.
	 *
	 * @param int $value Input value.
	 * @param int $min Lower bound.
	 * @param int $max Upper bound.
	 *
	 * @return int The clamped value.
	 */
	private function clamp(int $value, int $min, int $max): int {
		if ($value < $min) {
			return $min;
		}

		if ($value > $max) {
			return $max;
		}

		return $value;
	}//end clamp()
}//end class
