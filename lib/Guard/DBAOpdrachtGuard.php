<?php

/**
 * DBA Opdracht save-precondition guard.
 *
 * Validates that an opdracht transitioning into ACTIEF or onto first factuur
 * holds a voltooide intake (REQ-DBA-001), that hard-mode HOOG-risico requires
 * an explicit override (REQ-DBA-000), and that beeindigde opdrachten carry
 * a retention deadline (REQ-DBA-018).
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

use DateTimeImmutable;
use OCA\Shillinq\Enums\DBAConstants;
use Psr\Log\LoggerInterface;

/**
 * ADR-031 save-time guard for DBAOpdracht state-transitions.
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */
class DBAOpdrachtGuard {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Nextcloud logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Validate an incoming DBAOpdracht save (REQ-DBA-001/000/018).
	 *
	 * Returns a list of validation errors; an empty list means the save MAY proceed.
	 * OR's lifecycle engine treats a non-empty return as a save-block.
	 *
	 * @param array<string,mixed> $assignment The incoming object.
	 * @param array<string,mixed> $previous The persisted previous version (empty on create).
	 *
	 * @return array<int,string> Validation error messages; empty when valid.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function validateOnSave(array $assignment, array $previous = []): array {
		$status = (string)($assignment['intakeStatus'] ?? 'DRAFT');
		$previousStatus = (string)($previous['intakeStatus'] ?? 'DRAFT');

		$errors = array_merge(
			$this->activationErrors(status: $status, previousStatus: $previousStatus),
			$this->endedErrors(status: $status, assignment: $assignment),
			$this->riskLevelErrors(assignment: $assignment)
		);

		$this->logBlockedSave(errors: $errors, statuses: ['status' => $status, 'previousStatus' => $previousStatus]);

		return $errors;
	}//end validateOnSave()

	/**
	 * Log a blocked save with its errors and the incoming and previous status.
	 *
	 * @param array<int,string>    $errors   The validation errors (nothing is logged when empty).
	 * @param array<string,string> $statuses The incoming and previous intake status, as log context.
	 *
	 * @return void
	 */
	private function logBlockedSave(array $errors, array $statuses): void {
		if (count($errors) > 0) {
			$this->logger->info('DBAOpdrachtGuard: save blocked', ['errors' => $errors] + $statuses);
		}
	}//end logBlockedSave()

	/**
	 * REQ-DBA-001 — transition to ACTIEF requires INTAKE_VOLTOOID first.
	 *
	 * @param string $status         The incoming intake status.
	 * @param string $previousStatus The persisted intake status.
	 *
	 * @return array<int,string> The refusal, or none.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	private function activationErrors(string $status, string $previousStatus): array {
		if ($status !== 'ACTIEF' || in_array($previousStatus, ['INTAKE_COMPLETED', 'ACTIEF'], true) === true) {
			return [];
		}

		return ['REQ-DBA-001: opdracht mag pas ACTIEF worden na voltooide DBA-intake.'];
	}//end activationErrors()

	/**
	 * REQ-DBA-018 — BEEINDIGD requires feitelijkeEindDatum + retentieDeadline.
	 *
	 * @param string              $status     The incoming intake status.
	 * @param array<string,mixed> $assignment The incoming object.
	 *
	 * @return array<int,string> The refusal, or none.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	private function endedErrors(string $status, array $assignment): array {
		if ($status !== 'ENDED') {
			return [];
		}

		$end = (string)($assignment['actualEndDate'] ?? '');
		if ($end === '') {
			return ['REQ-DBA-018: BEEINDIGD vereist feitelijkeEindDatum.'];
		}

		$retention = (string)($assignment['retentionDeadline'] ?? '');
		if ($retention === '') {
			return ['REQ-DBA-018: BEEINDIGD vereist retentieDeadline (7 jaar na einddatum).'];
		}

		return $this->retentionMismatchErrors(end: $end, retention: $retention);
	}//end endedErrors()

	/**
	 * REQ-DBA-018 — the retention deadline must be the end date plus seven years
	 * (not checked when the end date cannot be parsed).
	 *
	 * @param string $end       The actual end date.
	 * @param string $retention The given retention deadline.
	 *
	 * @return array<int,string> The refusal, or none.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	private function retentionMismatchErrors(string $end, string $retention): array {
		$expected = $this->computeRetentieDeadline(feitelijkeEindDatum: $end);
		if ($expected === null || $retention === $expected) {
			return [];
		}

		return [
			sprintf(
				'REQ-DBA-018: retentieDeadline %s wijkt af van verwachte %s (einddatum + 7 jaar).',
				$retention,
				$expected
			),
		];
	}//end retentionMismatchErrors()

	/**
	 * REQ-DBA-000 — risico-niveau HOOG vereist actueleRisicoscore >= 75.
	 *
	 * @param array<string,mixed> $assignment The incoming object.
	 *
	 * @return array<int,string> The refusal, or none.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	private function riskLevelErrors(array $assignment): array {
		$level = (string)($assignment['riskLevel'] ?? 'LOW');
		$score = $assignment['actueleRisicoscore'] ?? null;
		if ($level === 'HIGH' && is_int($score) === true && $score < 75) {
			return ['REQ-DBA-000/003: risicoNiveau HOOG vereist actueleRisicoscore >= 75.'];
		}

		return [];
	}//end riskLevelErrors()

	/**
	 * Compute the retention deadline (einddatum + 7 jaar) per AWR art. 52.
	 *
	 * @param string $feitelijkeEindDatum Y-m-d datestring.
	 *
	 * @return string|null The deadline Y-m-d, or null when input cannot be parsed.
	 */
	public function computeRetentieDeadline(string $feitelijkeEindDatum): ?string {
		try {
			$date = new DateTimeImmutable($feitelijkeEindDatum);
		} catch (\Throwable) {
			return null;
		}

		return $date->modify('+' . DBAConstants::RETENTIE_TERMIJN_JAREN . ' years')->format('Y-m-d');
	}//end computeRetentieDeadline()
}//end class
