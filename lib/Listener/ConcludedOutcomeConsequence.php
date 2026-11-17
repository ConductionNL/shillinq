<?php

/**
 * Concluded Outcome Consequence
 *
 * The local accounting consequence of a completing cross-app outcome
 * (REQ-SIGN-006): the finance object is stamped with the completion time and
 * its downstream gate opens. Any other outcome leaves the object as it is.
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

/**
 * Stamp and gate fields written when a concluded outcome completes.
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 */
final class ConcludedOutcomeConsequence {

	/**
	 * Constructor.
	 *
	 * @param string $completing The outcome that completes the request.
	 * @param string $stampField The field that records when it completed.
	 * @param string $gateField  The gate field that opens on completion.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $completing,
		private readonly string $stampField,
		private readonly string $gateField,
	) {
	}//end __construct()

	/**
	 * Whether the outcome completes the request.
	 *
	 * @param string $outcome The mapped outcome.
	 *
	 * @return bool True when the consequence applies.
	 *
	 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function appliesTo(string $outcome): bool {
		return $outcome === $this->completing;

	}//end appliesTo()

	/**
	 * Stamp the completion time and open the gate on a completing outcome.
	 *
	 * @param array<string,mixed> $object  The finance object.
	 * @param string              $outcome The mapped outcome.
	 *
	 * @return array<string,mixed> The (possibly mutated) finance object.
	 *
	 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function apply(array $object, string $outcome): array {
		if ($this->appliesTo(outcome: $outcome) === false) {
			return $object;
		}

		$object[$this->stampField] = gmdate('Y-m-d\TH:i:s\Z');
		$object[$this->gateField]  = true;

		return $object;

	}//end apply()

	/**
	 * The consequence fields of the object, so they persist with the mirror
	 * in one write; empty for any other outcome.
	 *
	 * @param array<string,mixed> $object  The finance object after the consequence.
	 * @param string              $outcome The mapped outcome.
	 *
	 * @return array<string,mixed> The consequence delta.
	 *
	 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function delta(array $object, string $outcome): array {
		if ($this->appliesTo(outcome: $outcome) === false) {
			return [];
		}

		$delta = [];
		foreach ([$this->stampField, $this->gateField] as $key) {
			if (array_key_exists($key, $object) === true) {
				$delta[$key] = $object[$key];
			}
		}

		return $delta;

	}//end delta()
}//end class
