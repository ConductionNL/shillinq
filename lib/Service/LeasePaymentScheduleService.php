<?php

/**
 * Lease Payment Schedule Service
 *
 * Generates and persists the IFRS 16 amortization schedule for a lease
 * (REQ-LA-002). When a LeaseContract transitions draft→active (or a reassessment
 * regenerates future periods), this service reads the contract via the real
 * OpenRegister ObjectService API (find / findAll), delegates the period-by-period
 * arithmetic to the side-effect-free LeaseAmortizationCalculator, and writes one
 * LeasePaymentSchedule row per period via saveObject. The rows are read-only once
 * written; a reassessment supersedes future periods rather than mutating them.
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
 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Materialises the LeasePaymentSchedule rows for an active lease (REQ-LA-002).
 *
 * Reads are scoped to a single lease + administration (ADR-005 IDOR safety):
 * callers pass the administrationId resolved from the authenticated user's
 * context, never a client-supplied trust boundary; only schedule rows whose
 * parent lease belongs to that administration are written or returned.
 *
 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
 */
class LeasePaymentScheduleService {
	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LeaseAmortizationCalculator $calculator Pure-logic IFRS 16 arithmetic helper.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LeaseAmortizationCalculator $calculator,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Build the amortization schedule for a lease without persisting it (REQ-LA-002).
	 *
	 * Pure read + compute: fetches the lease, verifies the administration scope,
	 * and returns the calculator's per-period rows. Used by the detail view's
	 * "next 12 months" preview and by the disclosure aggregation.
	 *
	 * @param string $leaseContractId The LeaseContract id or slug.
	 * @param string $administrationId Administration scope (server-resolved).
	 *
	 * @return array<int,array<string,float|int>> Amortization rows (empty if out of scope / not found).
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	public function buildSchedule(string $leaseContractId, string $administrationId): array {
		$lease = $this->fetchLease(leaseContractId: $leaseContractId, administrationId: $administrationId);
		if ($lease === null) {
			return [];
		}

		return $this->calculator->buildSchedule(lease: $lease);
	}//end buildSchedule()

	/**
	 * Generate and persist the LeasePaymentSchedule rows for an active lease (REQ-LA-002).
	 *
	 * Idempotent within a generation: each row is keyed by leaseContract +
	 * periodSequence, so re-running after a reassessment overwrites the matching
	 * future rows. Returns the number of rows written.
	 *
	 * @param string $leaseContractId The LeaseContract id or slug.
	 * @param string $administrationId Administration scope (server-resolved, ADR-005).
	 * @param int $fromSequence First period sequence to (re)generate (1 = full schedule).
	 *
	 * @return int Count of rows written.
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	public function generateSchedule(string $leaseContractId, string $administrationId, int $fromSequence = 1): int {
		$lease = $this->fetchLease(leaseContractId: $leaseContractId, administrationId: $administrationId);
		if ($lease === null) {
			$this->logger->warning(
				'LeasePaymentScheduleService: lease not found or out of scope',
				['leaseContractId' => $leaseContractId, 'administrationId' => $administrationId]
			);

			return 0;
		}

		if (($lease['classification'] ?? '') !== 'IFRS16-capitalised') {
			// Exempt leases post straight-line expense and carry no schedule
			// (REQ-LE-003); nothing to generate.
			return 0;
		}

		$rows = $this->calculator->buildSchedule(lease: $lease);
		if ($rows === []) {
			return 0;
		}

		$objectService = $this->objectService();
		$register = $this->register();
		$written = 0;

		foreach ($rows as $row) {
			if ((int)$row['periodSequence'] < $fromSequence) {
				continue;
			}

			$object = array_merge(
				$row,
				[
					'leaseContract' => $leaseContractId,
					'administrationId' => $administrationId,
					'postedToGl' => null,
				]
			);

			$objectService->saveObject(
				object: $object,
				register: $register,
				schema: 'LeasePaymentSchedule',
			);
			$written++;
		}//end foreach

		return $written;
	}//end generateSchedule()

	/**
	 * Fetch a lease and verify it belongs to the given administration (ADR-005).
	 *
	 * @param string $leaseContractId The LeaseContract id or slug.
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string,mixed>|null The lease, or null when not found / out of scope.
	 */
	private function fetchLease(string $leaseContractId, string $administrationId): ?array {
		$matches = $this->objectService()
			->setRegister($this->register())
			->setSchema('LeaseContract')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		foreach ($matches as $match) {
			// OpenRegister's findAll() returns ObjectEntity instances, not plain
			// arrays; normalise to the array shape this service reads.
			$lease = $this->asArray(row: $match);
			if ($lease === []) {
				continue;
			}

			$id = (string)($lease['id'] ?? ($lease['@self']['id'] ?? ''));
			$slug = (string)($lease['@self']['slug'] ?? '');
			if ($id === $leaseContractId || $slug === $leaseContractId) {
				return $lease;
			}
		}

		return null;
	}//end fetchLease()

	/**
	 * Normalise an OpenRegister ObjectService row (ObjectEntity or array) to a
	 * plain array<string,mixed>.
	 *
	 * @param mixed $row Raw row from ObjectService::findAll()/find().
	 *
	 * @return array<string,mixed> The object as an array (empty array when unusable).
	 */
	private function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$out = $row->jsonSerialize();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$out = $row->getObject();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		return [];
	}//end asArray()

	/**
	 * Resolve OpenRegister's ObjectService lazily.
	 *
	 * @return object The ObjectService instance.
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
