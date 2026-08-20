<?php

/**
 * Intercompany Elimination Guard
 *
 * Change revive-gl-tax-capabilities (REQ-GLTAX-002). REQ-MA-004 requires a
 * non-zero afwijking (variance) between the two sides of an intercompany
 * pair to flag the pair for manual review — but nothing computed the
 * variance and nothing checked it, so an unreconciled pair could be booked
 * straight through to `eliminatie_geboekt` and the consolidation
 * elimination would silently be wrong by the difference.
 *
 * This guard is the `requires` precondition on the
 * `IntercompanyJournalEntry` `eliminate` transition. It resolves the
 * counter-side entry through {@see \OCA\Shillinq\Service\IntercompanyLinkService}
 * and delegates the comparison to the existing, unmodified
 * {@see \OCA\Shillinq\Service\IntercompanyJournalService::isBalanced()}.
 *
 * Fail-closed: a pair whose counter-side cannot be resolved is NOT
 * reconciled by definition, so the elimination is denied.
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
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use OCA\Shillinq\Service\IntercompanyJournalService;
use OCA\Shillinq\Service\IntercompanyLinkService;

/**
 * Precondition for `eliminate`: the intercompany pair must reconcile.
 *
 * Registered as the literal DI tag
 * `OCA\Shillinq\Guard\IntercompanyEliminationGuard::requireReconciledPair`
 * in Application.php (via RegisterRequiresGuardAdapter) — OpenRegister's
 * LifecycleGuardRegistry resolves the ENTIRE `requires` string, `::method`
 * suffix included, as one container tag (shillinq#425).
 *
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 */
class IntercompanyEliminationGuard {
	/**
	 * Construct the guard.
	 *
	 * @param IntercompanyLinkService $linkService Resolves the counter-side entry.
	 * @param IntercompanyJournalService $journalService The pure-logic REQ-MA-004 kernel.
	 */
	public function __construct(
		private readonly IntercompanyLinkService $linkService,
		private readonly IntercompanyJournalService $journalService,
	) {

	}//end __construct()

	/**
	 * Precondition for `eliminate`: the two sides of the pair must balance.
	 *
	 * @param array<string,mixed> $entry The IntercompanyJournalEntry being transitioned.
	 *
	 * @return bool True when the pair reconciles to the cent.
	 *
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	public function requireReconciledPair(array $entry): bool {
		$counter = $this->linkService->findCounterSide(entry: $entry);
		if ($counter === null) {
			// No counter-side booked: the pair cannot be reconciled, so the
			// elimination cannot be justified. Fail-closed.
			return false;
		}

		return $this->journalService->isBalanced(
			sourceAmount: ($entry['amount'] ?? 0),
			destinationAmount: ($counter['amount'] ?? 0)
		);

	}//end requireReconciledPair()
}//end class
