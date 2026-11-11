<?php

/**
 * Concluded Outcome Flow
 *
 * The projection the concluded-outcome listeners share: keep a
 * shillinq-originated, terminal outcome, find the finance object it belongs
 * to (logging when there is none), and persist the mirror plus consequence
 * delta with the "outcome consumed" log line. The listener keeps what is its
 * own: reading the event, the service callback and the mirror fields.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use Psr\Log\LoggerInterface;

/**
 * The shared steps of projecting a concluded outcome onto its finance object.
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 */
final class ConcludedOutcomeFlow {

	/**
	 * Hold the settings, the register access and the logger.
	 *
	 * @param ConcludedOutcomeSpec  $spec     The listener's settings.
	 * @param ConcludedSubjectStore $subjects The register access.
	 * @param LoggerInterface       $logger   The logger.
	 */
	public function __construct(
		private readonly ConcludedOutcomeSpec $spec,
		private readonly ConcludedSubjectStore $subjects,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The shillinq-originated, terminal outcome with its finance object, or
	 * null when the outcome is foreign, non-terminal or has no object.
	 *
	 * @param array<string,string> $payload The normalised payload.
	 *
	 * @return ConcludedOutcome|null The concluded outcome, or null.
	 */
	public function conclude(array $payload): ConcludedOutcome|null {
		// Filter to shillinq-originated requests only.
		if ($payload['sourceApp'] !== 'shillinq') {
			return null;
		}

		$outcome = $this->spec->statusMap[$payload['status']] ?? null;
		if ($outcome === null) {
			// Unknown / non-terminal status — no projection.
			return null;
		}

		$found = $this->locateSubject(payload: $payload);
		if ($found === null) {
			return null;
		}

		return new ConcludedOutcome(found: $found, conclusion: ($payload + ['outcome' => $outcome]));
	}//end conclude()

	/**
	 * The consequence callback for the outcome.
	 *
	 * @param ConcludedOutcome $concluded The concluded outcome.
	 *
	 * @return OutcomeConsequence The callback with its captured delta.
	 */
	public function consequence(ConcludedOutcome $concluded): OutcomeConsequence {
		return new OutcomeConsequence(spec: $this->spec, logger: $this->logger, outcome: $concluded);
	}//end consequence()

	/**
	 * Persist the field updates onto the finance object and log the consumed
	 * outcome.
	 *
	 * @param ConcludedOutcome    $concluded The concluded outcome.
	 * @param array<string,mixed> $updates   The mirror plus consequence fields.
	 *
	 * @return void
	 */
	public function finish(ConcludedOutcome $concluded, array $updates): void {
		$this->subjects->persist(ref: $concluded->found->ref, updates: $updates);

		$this->logger->info(
			$this->spec->listener.': outcome consumed',
			[
				'schema' => $concluded->found->ref->schema,
				'subjectId' => $concluded->found->ref->id,
				'outcome' => $concluded->outcome(),
				$this->spec->requestRefKey => $concluded->conclusion[$this->spec->requestIdKey],
			]
		);
	}//end finish()

	/**
	 * Find the finance object the request belongs to, logging and answering
	 * null when there is no subject id or no matching object.
	 *
	 * @param array<string,string> $payload The normalised payload.
	 *
	 * @return FoundSubject|null The finance object, or null.
	 */
	private function locateSubject(array $payload): FoundSubject|null {
		$subjectId = $this->subjects->subjectId(payload: $payload);
		if ($subjectId === '') {
			$this->logger->info(
				$this->spec->listener.': no subject id on concluded '.$this->spec->requestNoun.' (skipping)',
				[$this->spec->requestRefKey => $payload[$this->spec->requestIdKey]]
			);
			return null;
		}

		return $this->locateOrLog(payload: $payload, subjectId: $subjectId);
	}//end locateSubject()

	/**
	 * Search the subject schemas, logging when nothing matches.
	 *
	 * @param array<string,string> $payload   The normalised payload.
	 * @param string               $subjectId The resolved subject id.
	 *
	 * @return FoundSubject|null The finance object, or null.
	 */
	private function locateOrLog(array $payload, string $subjectId): FoundSubject|null {
		$found = $this->subjects->locate(payload: $payload, knownSchemas: $this->spec->subjectSchemas);
		if ($found === null) {
			$this->logger->info(
				$this->spec->listener.': no matching finance object (skipping)',
				[$this->spec->requestRefKey => $payload[$this->spec->requestIdKey], 'subjectId' => $subjectId]
			);
		}

		return $found;
	}//end locateOrLog()
}//end class
