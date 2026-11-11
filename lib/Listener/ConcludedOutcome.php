<?php

/**
 * Concluded Outcome
 *
 * A terminal outcome matched to the finance object it belongs to: the located
 * object and the event payload plus the mapped outcome.
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
 * A located finance object and its conclusion.
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 */
final class ConcludedOutcome {

	/**
	 * Hold the object and the conclusion.
	 *
	 * @param FoundSubject          $found      The finance object.
	 * @param array<string,string> $conclusion The normalised payload plus the mapped 'outcome'.
	 */
	public function __construct(
		public readonly FoundSubject $found,
		public readonly array $conclusion,
	) {
	}//end __construct()

	/**
	 * The mapped terminal outcome.
	 *
	 * @return string The outcome.
	 */
	public function outcome(): string {
		return $this->conclusion['outcome'];
	}//end outcome()

	/**
	 * The schema of the finance object.
	 *
	 * @return string The schema.
	 */
	public function schema(): string {
		return $this->found->ref->schema;
	}//end schema()
}//end class
