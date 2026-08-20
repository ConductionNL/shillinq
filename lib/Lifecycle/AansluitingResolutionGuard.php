<?php

/**
 * Aansluiting Resolution Guard
 *
 * ADR-031 exception-path lifecycle guard for the AansluitingResult
 * explained -> resolved transition (REQ-AANS-006). Registered because
 * OpenRegister's x-openregister-lifecycle engine cannot yet express "the
 * explanation fields must be populated" as a declarative guard clause that
 * also needs to distinguish an operator-authored explanation from a blank
 * default. The single method canResolve() performs this check in PHP and is
 * referenced from the AansluitingResult schema's
 * x-openregister-lifecycle.transitions.resolve.guard clause.
 *
 * ADR-031 exception reason: field-presence validation on a lifecycle
 * transition is not yet expressible in the declarative lifecycle DSL. When
 * the engine gains a declarative "required field on transition" primitive,
 * replace this reference with that primitive and delete this file — the
 * same deferral IcpFinalizeGuard and StatementVerifyGuard document for their
 * own ADR-031 exceptions.
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
 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Lifecycle precondition guard for AansluitingResult resolution.
 *
 * Referenced from the AansluitingResult schema's x-openregister-lifecycle
 * transitions.resolve.guard as
 * OCA\Shillinq\Lifecycle\AansluitingResolutionGuard::canResolve. Fail-closed:
 * any missing/blank explanation denies the transition (REQ-AANS-006 /
 * CWE-863 — a resolved-without-explanation record would be indistinguishable
 * from a genuinely reconciled one in the audit trail).
 *
 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
 */
class AansluitingResolutionGuard {
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
	 * Returns true iff the AansluitingResult carries a non-blank explanation
	 * (REQ-AANS-006).
	 *
	 * Fail-closed: returns false when explanationReasonText is missing,
	 * blank, or the record is not in the `explained` status.
	 *
	 * @param array<string,mixed> $result The AansluitingResult record.
	 *
	 * @return bool True when resolution may proceed.
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	public function canResolve(array $result): bool {
		try {
			$status = (string)($result['status'] ?? '');
			if ($status !== 'explained') {
				$this->logger->warning(
					'AansluitingResolutionGuard: result is not explained — denying resolve',
					['resultId' => ($result['id'] ?? null), 'status' => $status]
				);

				return false;
			}

			$reasonText = trim((string)($result['explanationReasonText'] ?? ''));
			if ($reasonText === '') {
				$this->logger->warning(
					'AansluitingResolutionGuard: explanationReasonText is blank — denying resolve',
					['resultId' => ($result['id'] ?? null)]
				);

				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'AansluitingResolutionGuard: check failed — denying resolve (fail-closed)',
				['exception' => $e->getMessage()]
			);

			return false;
		}//end try

	}//end canResolve()
}//end class
