<?php

/**
 * OrderFulfilment Guard
 *
 * ADR-031 exception-path lifecycle guard for the OrderFulfilment completion
 * transition (in-progress → completed). Enforces the bewijsstuk gate of REQ-004:
 * a delivery milestone may only be marked completed when at least one proof-of-
 * delivery (bewijsstuk) is attached. The bewijsstukken live as docudesk file
 * references on the OrderFulfilment record (ADR-022 / design D4).
 *
 * Referenced from the OrderFulfilment schema's
 * x-openregister-lifecycle.transitions.voltooien.requires in
 * lib/Settings/register.d/20-bookkeeping-tenderned-integratie.json.
 *
 * ADR-031 exception reason: the "at least one non-empty attachment" existence
 * check on an embedded array is a per-record completeness rule the declarative
 * lifecycle DSL cannot yet express inside a `requires:` clause. Replace with a
 * declarative condition when the engine supports array-length predicates.
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
 * Completion precondition guard for the OrderFulfilment schema per REQ-004.
 *
 * Fail-closed: any unexpected exception denies the completion (CWE-863).
 *
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-8
 */
class OrderFulfilmentGuard {
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
	 * Precondition for the voltooien (in-progress → completed) transition.
	 *
	 * REQ-004: the delivery can only be completed when at least one bewijsstuk
	 * (proof of delivery) is attached. A bewijsstuk is considered valid when it
	 * carries a non-empty documentId, so an empty placeholder object cannot
	 * satisfy the gate.
	 *
	 * Fail-closed: returns false on any exception (denies completion) per CWE-863.
	 *
	 * @param array<string, mixed> $assignment OrderFulfilment object array supplied by OR.
	 *
	 * @return bool True when the delivery may be marked completed.
	 *
	 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-8
	 */
	public function canVoltooien(array $assignment): bool {
		try {
			if ($this->hasValidBewijsstuk(assignment: $assignment) === false) {
				$this->logger->info(
					'OrderFulfilmentGuard: no bewijsstuk attached — denying completion (REQ-004)',
					[
						'commitmentId' => ($assignment['commitmentId'] ?? 'unknown'),
						'milestoneId' => ($assignment['milestoneId'] ?? 'unknown'),
					]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'OrderFulfilmentGuard: canVoltooien failed — denying completion (fail-closed)',
				[
					'commitmentId' => ($assignment['commitmentId'] ?? 'unknown'),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end canVoltooien()

	/**
	 * Determine whether the delivery carries at least one valid bewijsstuk.
	 *
	 * A bewijsstuk is valid when it is an array carrying a non-empty documentId.
	 * Scalar entries or entries without a documentId do not satisfy REQ-004.
	 *
	 * @param array<string, mixed> $assignment OrderFulfilment object array.
	 *
	 * @return bool True when at least one valid bewijsstuk exists.
	 */
	private function hasValidBewijsstuk(array $assignment): bool {
		$supportingDocuments = ($assignment['supportingDocuments'] ?? []);
		if (is_array($supportingDocuments) === false) {
			return false;
		}

		foreach ($supportingDocuments as $bewijsstuk) {
			if (is_array($bewijsstuk) === false) {
				continue;
			}

			if (trim((string)($bewijsstuk['documentId'] ?? '')) !== '') {
				return true;
			}
		}

		return false;
	}//end hasValidBewijsstuk()
}//end class
