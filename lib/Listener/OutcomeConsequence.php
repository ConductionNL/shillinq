<?php

/**
 * Outcome Consequence
 *
 * The local accounting consequence of a completing outcome (REQ-SIGN-006),
 * handed to the signing or sign-off service as its consequence callback. It
 * stamps the timestamp field, opens the gate field, logs it, and keeps the
 * written fields so they can be persisted with the mirror in one OR write.
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
 * The consequence callback of one concluded outcome, with the delta it wrote.
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 */
final class OutcomeConsequence {

	/**
	 * The consequence fields written by the last call, empty until then and
	 * on a non-completing outcome.
	 *
	 * @var array<string,mixed>
	 */
	public array $delta = [];

	/**
	 * Hold the settings, the logger and the outcome.
	 *
	 * @param ConcludedOutcomeSpec $spec     The listener's settings.
	 * @param LoggerInterface      $logger   The logger.
	 * @param ConcludedOutcome     $outcome  The concluded outcome.
	 */
	public function __construct(
		private readonly ConcludedOutcomeSpec $spec,
		private readonly LoggerInterface $logger,
		private readonly ConcludedOutcome $outcome,
	) {
	}//end __construct()

	/**
	 * Apply the consequence to the finance object and capture its delta.
	 *
	 * @param array<string,mixed> $object The finance object (already carrying the mirror).
	 *
	 * @return array<string,mixed> The (possibly mutated) finance object.
	 */
	public function apply(array $object): array {
		$object      = $this->applyAccountingConsequence(object: $object);
		$this->delta = $this->consequenceDelta(object: $object);
		return $object;
	}//end apply()

	/**
	 * Stamp the timestamp field and open the gate field on a completing
	 * outcome; any other outcome leaves the object as it is.
	 *
	 * @param array<string,mixed> $object The finance object.
	 *
	 * @return array<string,mixed> The (possibly mutated) finance object.
	 */
	private function applyAccountingConsequence(array $object): array {
		if ($this->completes() === false) {
			return $object;
		}

		[$stampField, $gateField] = $this->spec->consequenceFields;
		$object[$stampField]      = gmdate('Y-m-d\TH:i:s\Z');
		$object[$gateField]       = true;

		$this->logger->info(
			$this->spec->listener.': accounting consequence applied',
			[
				'schema' => $this->outcome->schema(),
				'outcome' => $this->outcome->outcome(),
			]
		);

		return $object;
	}//end applyAccountingConsequence()

	/**
	 * The consequence fields present on the object, empty on a
	 * non-completing outcome.
	 *
	 * @param array<string,mixed> $object The finance object after the consequence.
	 *
	 * @return array<string,mixed> The consequence delta.
	 */
	private function consequenceDelta(array $object): array {
		if ($this->completes() === false) {
			return [];
		}

		$delta = [];
		foreach ($this->spec->consequenceFields as $key) {
			if (array_key_exists($key, $object) === true) {
				$delta[$key] = $object[$key];
			}
		}

		return $delta;
	}//end consequenceDelta()

	/**
	 * Whether the outcome is the one that fires the consequence.
	 *
	 * @return bool True on the completing outcome.
	 */
	private function completes(): bool {
		return $this->outcome->outcome() === $this->spec->completedOutcome;
	}//end completes()
}//end class
