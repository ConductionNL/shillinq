<?php

/**
 * Concluded Outcome Spec
 *
 * What tells one concluded-outcome listener apart from the other: its log
 * name, the payload key and mirror key of the request reference, the subject
 * schemas, the status map and the consequence the completing outcome fires.
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
 * The per-listener settings of a concluded-outcome projection.
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 */
final class ConcludedOutcomeSpec {

	/**
	 * Hold the settings.
	 *
	 * @param string               $listener         The listener name that prefixes every log line.
	 * @param string               $requestNoun      What the concluded request is called in the log ('signing request', 'decision').
	 * @param string               $requestIdKey     The payload key of the request reference.
	 * @param string               $requestRefKey    The log context key of the request reference.
	 * @param list<string>         $subjectSchemas   The finance schemas that may hold the subject.
	 * @param array<string,string> $statusMap        Event status to terminal shillinq outcome.
	 * @param string               $completedOutcome The outcome that fires the accounting consequence.
	 * @param array{0: string, 1: string} $consequenceFields The timestamp field and the gate field the consequence sets.
	 */
	public function __construct(
		public readonly string $listener,
		public readonly string $requestNoun,
		public readonly string $requestIdKey,
		public readonly string $requestRefKey,
		public readonly array $subjectSchemas,
		public readonly array $statusMap,
		public readonly string $completedOutcome,
		public readonly array $consequenceFields,
	) {
	}//end __construct()
}//end class
