<?php

/**
 * Resolution Run
 *
 * One resolve or bulk-resolve request: the reconciliation, the decision, the
 * acting user and, for a bulk request, the tally so far.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

/**
 * The state of one resolution request.
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */
final class ResolutionRun {
	/**
	 * The reconciliation.
	 *
	 * @var string
	 */
	public readonly string $reconId;

	/**
	 * The validated decision.
	 *
	 * @var ResolutionDecision
	 */
	public readonly ResolutionDecision $decision;

	/**
	 * The acting user.
	 *
	 * @var string
	 */
	public readonly string $actor;

	/**
	 * Matches resolved so far.
	 *
	 * @var integer
	 */
	public int $applied = 0;

	/**
	 * Safe failure message per match that could not be resolved.
	 *
	 * @var array<string, string>
	 */
	public array $failed = [];

	/**
	 * Start a run.
	 *
	 * @param string             $reconId  The reconciliation.
	 * @param ResolutionDecision $decision The validated decision.
	 * @param string             $actor    The acting user.
	 */
	public function __construct(string $reconId, ResolutionDecision $decision, string $actor) {
		$this->reconId = $reconId;
		$this->decision = $decision;
		$this->actor = $actor;

	}//end __construct()

	/**
	 * The reconciliation and match as log context, with the failure.
	 *
	 * @param string     $matchId The match.
	 * @param \Throwable $e       The failure.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function logContext(string $matchId, \Throwable $e): array {
		return ['reconId' => $this->reconId, 'matchId' => $matchId, 'exception' => $e->getMessage()];
	}//end logContext()
}//end class
