<?php

/**
 * Commitment Guard
 *
 * ADR-031 exception-path lifecycle guard for the Commitment activation
 * transition (concept → active). Enforces the contractmanager-enrichment gate
 * of design D2: a concept obligation may only be activated once it carries a
 * kostenplaats (cost centre) and a grootboekrekening (GL account). It also
 * validates that any planned milestone date falls within the obligation term
 * (design validation rules), so an activation cannot lock budget against an
 * out-of-range milestone plan.
 *
 * Referenced from the Commitment schema's
 * x-openregister-lifecycle.transitions.activeren.requires in
 * lib/Settings/register.d/20-bookkeeping-tenderned-integratie.json.
 *
 * ADR-031 exception reason: the conditional completeness check (both enrichment
 * fields present) combined with the per-milestone date-range bound on an
 * embedded array is not yet expressible in the declarative lifecycle DSL.
 * Replace with declarative conditions when the engine gains conditional-required
 * + array-element range predicates.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Activation precondition guard for the Commitment schema per design D2 and
 * the milestone date-range validation rule.
 *
 * Fail-closed: any unexpected exception denies the activation (CWE-863).
 *
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-8
 */
class CommitmentGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Precondition for the activeren (concept → active) transition.
	 *
	 * Validates:
	 * 1. kostenplaats (cost centre) is set (design D2).
	 * 2. grootboekrekening (GL account) is set (design D2).
	 * 3. Every planned milestone date falls within looptijdStart..looptijdEind
	 *    when the obligation declares a term (design validation rules).
	 *
	 * Fail-closed: returns false on any exception (denies activation) per CWE-863.
	 *
	 * @param array<string, mixed> $commitment Commitment object array supplied by OR.
	 *
	 * @return bool True when the obligation may be activated.
	 *
	 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-8
	 */
	public function canActiveren(array $commitment): bool {
		try {
			if (trim((string)($commitment['costCentre'] ?? '')) === ''
				|| trim((string)($commitment['generalLedgerAccount'] ?? '')) === ''
			) {
				$this->logger->info(
					'CommitmentGuard: missing kostenplaats or grootboekrekening — denying activation (design D2)',
					['commitmentNumber' => ($commitment['commitmentNumber'] ?? 'unknown')]
				);
				return false;
			}

			if ($this->milestonesWithinTerm(commitment: $commitment) === false) {
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'CommitmentGuard: canActiveren failed — denying activation (fail-closed)',
				[
					'commitmentNumber' => ($commitment['commitmentNumber'] ?? 'unknown'),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end canActiveren()

	/**
	 * Verify every planned milestone date falls within the obligation term.
	 *
	 * When the obligation declares no looptijdStart/looptijdEind the check is
	 * skipped (no bound to enforce). Milestones whose dates are unparseable are
	 * rejected so a malformed plan cannot slip through.
	 *
	 * @param array<string, mixed> $commitment Commitment object array.
	 *
	 * @return bool True when all milestone dates are in range (or no term/plan).
	 */
	private function milestonesWithinTerm(array $commitment): bool {
		$start = $this->parseDate(value: (string)($commitment['termStart'] ?? ''));
		$end = $this->parseDate(value: (string)($commitment['termEnd'] ?? ''));
		if ($start === null || $end === null) {
			// No declared term — nothing to bound.
			return true;
		}

		$milestones = ($commitment['milestones'] ?? []);
		if (is_array($milestones) === false) {
			return true;
		}

		foreach ($milestones as $milestone) {
			if (is_array($milestone) === false) {
				continue;
			}

			$date = $this->parseDate(value: (string)($milestone['date'] ?? ''));
			if ($date === null || $date < $start || $date > $end) {
				$this->logger->info(
					'CommitmentGuard: milestone date out of contract term — denying activation',
					[
						'commitmentNumber' => ($commitment['commitmentNumber'] ?? 'unknown'),
						'milestoneId' => ($milestone['milestoneId'] ?? 'unknown'),
						'date' => ($milestone['date'] ?? 'unknown'),
					]
				);
				return false;
			}
		}//end foreach

		return true;
	}//end milestonesWithinTerm()

	/**
	 * Parse an ISO 8601 date string into a Unix epoch, or null on failure.
	 *
	 * @param string $value Date string (e.g. "2026-05-01").
	 *
	 * @return int|null Epoch seconds at midnight UTC, or null when unparseable.
	 */
	private function parseDate(string $value): ?int {
		if ($value === '') {
			return null;
		}

		$epoch = strtotime($value);
		if ($epoch === false) {
			return null;
		}

		return $epoch;
	}//end parseDate()
}//end class
