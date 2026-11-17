<?php

/**
 * Concluded Subject Lookup
 *
 * Finds the finance object a concluded cross-app outcome belongs to and logs
 * why when there is none: no subject id on the event, or no object under it.
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
 * The subject lookup shared by the concluded-outcome listeners.
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 */
final class ConcludedSubjectLookup {

	/**
	 * Hold the store, the log and what the listener looks in.
	 *
	 * @param ConcludedSubjectStore $store        The register access.
	 * @param ConcludedOutcomeLog   $log          The listener's log lines.
	 * @param list<string>          $knownSchemas The listener's subject schemas.
	 * @param string                $request      What the concluded request is called in the log.
	 */
	public function __construct(
		private readonly ConcludedSubjectStore $store,
		private readonly ConcludedOutcomeLog $log,
		private readonly array $knownSchemas,
		private readonly string $request,
	) {
	}//end __construct()

	/**
	 * The finance object the concluded event belongs to, or null (logged) when
	 * the event has no subject id or no object matches it.
	 *
	 * @param array{externalReference: string, subjectId: string, subjectSchema: string} $payload The normalised event payload.
	 *
	 * @return FoundSubject|null The finance object, or null.
	 *
	 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function find(array $payload): FoundSubject|null {
		$subjectId = $this->store->subjectId(payload: $payload);
		if ($subjectId === '') {
			$this->log->noSubjectId(payload: $payload, request: $this->request);
			return null;
		}

		$found = $this->store->locate(payload: $payload, knownSchemas: $this->knownSchemas);
		if ($found === null) {
			$this->log->noMatch(payload: $payload, subjectId: $subjectId);
		}

		return $found;
	}//end find()
}//end class
