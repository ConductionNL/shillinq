<?php

/**
 * ICP Finalize Guard
 *
 * ADR-031 exception-path lifecycle guard for the IcpOpgaaf draft → finalized
 * transition (REQ-ICP-004, REQ-ICP-005). This guard is registered because
 * OpenRegister's x-openregister-lifecycle engine cannot yet express the
 * cross-schema reconciliation join (compare the period's ICP ledger total against
 * the BTW-aangifte rubriek 3b) inside the declarative `guard:` clause. The single
 * method canFinalize() performs the reconciliation in PHP and is referenced from
 * the IcpOpgaaf schema's x-openregister-lifecycle.states.draft.transitions.finalize
 * guard clause.
 *
 * ADR-031 exception reason: cross-schema aggregation + cross-register comparison
 * (ICP ledger SUM vs VatReturn rubriek 3b) is not yet expressible in the
 * declarative lifecycle DSL. When the engine gains that capability, replace this
 * reference with a declarative condition and delete this file.
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
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\Service\IcpService;
use Psr\Log\LoggerInterface;

/**
 * Lifecycle precondition guard for IcpOpgaaf finalization.
 *
 * Referenced from the IcpOpgaaf schema's x-openregister-lifecycle
 * states.draft.transitions.finalize.guard as
 * OCA\Shillinq\Lifecycle\IcpFinalizeGuard::canFinalize. Fail-closed: any error
 * denies finalization (REQ-ICP-004 / CWE-863).
 *
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 */
class IcpFinalizeGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IcpService $icpService The ICP reconciliation service.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly IcpService $icpService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Returns true iff the period's ICP ledger reconciles with rubriek 3b (REQ-ICP-004).
	 *
	 * Compares the computed ICP total against the BTW-aangifte rubriek 3b within the
	 * EUR 1 tolerance. A missing BTW-aangifte (icp.btw.missing) and any divergence
	 * beyond tolerance (icp.reconciliation.mismatch) both deny finalization.
	 *
	 * Fail-closed: returns false on any exception.
	 *
	 * @param string $administrationId The administration the opgaaf belongs to.
	 * @param string $period The filing period (YYYY-Qn or YYYY-MM).
	 *
	 * @return bool True when finalization may proceed.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function canFinalize(string $administrationId, string $period): bool {
		try {
			$outcome = $this->icpService->reconcile(administrationId: $administrationId, period: $period);
			if ($outcome['missing'] === true) {
				$this->logger->warning(
					'IcpFinalizeGuard: no BTW-aangifte for period — denying finalize (icp.btw.missing)',
					['administrationId' => $administrationId, 'period' => $period]
				);

				return false;
			}

			return $outcome['matches'];
		} catch (\Throwable $e) {
			$this->logger->error(
				'IcpFinalizeGuard: reconciliation failed — denying finalize (fail-closed)',
				['administrationId' => $administrationId, 'period' => $period, 'exception' => $e->getMessage()]
			);

			return false;
		}//end try

	}//end canFinalize()
}//end class
