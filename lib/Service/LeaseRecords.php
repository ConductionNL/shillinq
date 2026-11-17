<?php

/**
 * Lease records
 *
 * Reads LeaseContract and LeaseReassessmentEvent records of one administration
 * from the app's register for the lease services. A lease is matched on its id
 * or slug among the administration's leases (ADR-005); a failing lease query is
 * logged as a warning under the owner's message and reads as no lease.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Log\LoggerInterface;

/**
 * Administration-scoped reads of leases and their reassessment events.
 *
 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
 */
final class LeaseRecords {
	/**
	 * Build the reader on the register the owner resolved.
	 *
	 * @param object          $objectService The OpenRegister object service.
	 * @param string          $register      The register slug.
	 * @param LoggerInterface $logger        The owner's logger.
	 */
	public function __construct(
		private readonly object $objectService,
		private readonly string $register,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The administration's lease with this id or slug, or null when it is
	 * not found, out of scope, or the query fails (logged as $failure).
	 *
	 * @param string $leaseContractId  The LeaseContract id or slug.
	 * @param string $administrationId Administration scope.
	 * @param string $failure          The warning logged when the query fails.
	 *
	 * @return array<string,mixed>|null The lease, or null.
	 *
	 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
	 */
	public function lease(string $leaseContractId, string $administrationId, string $failure): ?array {
		try {
			$matches = $this->objectService
				->setRegister($this->register)
				->setSchema('LeaseContract')
				->findAll(['filters' => ['administrationId' => $administrationId]]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				$failure,
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);

			return null;
		}

		foreach ($matches as $lease) {
			if (is_array($lease) === false) {
				continue;
			}

			$id = (string)($lease['id'] ?? ($lease['@self']['id'] ?? ''));
			$slug = (string)($lease['@self']['slug'] ?? '');
			if ($id === $leaseContractId || $slug === $leaseContractId) {
				return $lease;
			}
		}

		return null;
	}//end lease()

	/**
	 * The reassessment events of a lease in the administration, as the
	 * object service answers them; a failing query throws.
	 *
	 * @param string $sourceLease      The source-lease FK.
	 * @param string $administrationId Administration scope.
	 *
	 * @return mixed The query result.
	 *
	 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
	 */
	public function events(string $sourceLease, string $administrationId): mixed {
		return $this->objectService
			->setRegister($this->register)
			->setSchema('LeaseReassessmentEvent')
			->findAll(
				[
					'filters' => [
						'administrationId' => $administrationId,
						'sourceLease' => $sourceLease,
					],
				]
			);
	}//end events()
}//end class
