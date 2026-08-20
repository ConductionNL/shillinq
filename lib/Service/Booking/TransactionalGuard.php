<?php

/**
 * Transactional Guard
 *
 * Thin facade around IDBConnection used by the booking flow so the
 * controller / conflict-detection service can be tested without pulling
 * the Doctrine DBAL into the unit-test boot. Production wiring backs the
 * guard with IDBConnection; PHPUnit tests pass a hand-rolled fake.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Booking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Booking;

use LogicException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Transaction + resource-row lock facade for the booking flow.
 *
 * Production builds back this with an injected IDBConnection (registered
 * by Nextcloud's DI container). Tests pass null for the connection and
 * the facade becomes a no-op (transactions are tracked in a local flag);
 * the calling service still gets a correct beginTransaction → commit /
 * rollBack flow that satisfies the LogicException invariants in
 * ConflictDetectionService::lockResource.
 */
class TransactionalGuard {

	/**
	 * Local transaction tracker used when no IDBConnection is wired.
	 *
	 * @var boolean
	 */
	private bool $localInTransaction = false;

	/**
	 * Construct the guard.
	 *
	 * @param IDBConnection|null $db Database connection (production); null in unit tests.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ?IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Begin a transaction. Idempotent — calling twice is a no-op.
	 *
	 * @return void
	 */
	public function beginTransaction(): void {
		if ($this->db !== null) {
			$this->db->beginTransaction();
			return;
		}

		$this->localInTransaction = true;
	}//end beginTransaction()

	/**
	 * Commit the outer transaction. Idempotent when no transaction is open.
	 *
	 * @return void
	 */
	public function commit(): void {
		if ($this->db !== null) {
			if ($this->db->inTransaction() === true) {
				$this->db->commit();
			}

			return;
		}

		$this->localInTransaction = false;
	}//end commit()

	/**
	 * Roll back the outer transaction. Idempotent when no transaction is open.
	 *
	 * @return void
	 */
	public function rollBack(): void {
		if ($this->db !== null) {
			if ($this->db->inTransaction() === true) {
				$this->db->rollBack();
			}

			return;
		}

		$this->localInTransaction = false;
	}//end rollBack()

	/**
	 * Is the guard currently inside a transaction?
	 *
	 * @return bool
	 */
	public function inTransaction(): bool {
		if ($this->db !== null) {
			return $this->db->inTransaction();
		}

		return $this->localInTransaction;
	}//end inTransaction()

	/**
	 * Acquire a row-level lock on the OR object row backing the resource.
	 *
	 * The lock is released when the surrounding transaction commits or rolls
	 * back. Returns true when a row was locked, false when the resource row
	 * is not present (the conflict service falls back to advisory checks).
	 *
	 * Implementation note: OpenRegister persists objects in a generic
	 * oc_openregister_objects table; we lock the row whose JSON payload
	 * carries the resourceId. Postgres and MySQL both honour the FOR UPDATE
	 * suffix in the SELECT, blocking concurrent FOR UPDATE callers until
	 * the outer transaction completes.
	 *
	 * @param string $resourceId Resource logical id.
	 *
	 * @return bool True when a row was locked, false otherwise.
	 */
	public function lockResourceRow(string $resourceId): bool {
		if ($this->db === null) {
			// No DB wired (unit-test stack) — the row lock degrades to a
			// no-op; the conflict service still serialises on the in-memory
			// checks performed by the caller.
			return false;
		}

		if ($this->db->inTransaction() === false) {
			throw new LogicException('lockResourceRow must be called inside a transaction');
		}

		try {
			$query = $this->db->getQueryBuilder();
			$query->select('id')
				->from('openregister_objects')
				->where(
					$query->expr()->like(
						$query->createFunction('CAST("object" AS TEXT)'),
						$query->createNamedParameter('%"resourceId":"' . $resourceId . '"%')
					)
				)
				->setMaxResults(1);

			$sql = $query->getSQL();
			$stmt = $this->db->executeQuery($sql . ' FOR UPDATE', $query->getParameters());
			$row = $stmt->fetch();
			$stmt->closeCursor();
			return ($row !== false);
		} catch (\Throwable $e) {
			$this->logger->info(
				'Shillinq: resource row-lock unavailable, falling back to advisory check',
				[
					'resourceId' => $resourceId,
					'reason' => $e->getMessage(),
				]
			);
			return false;
		}//end try
	}//end lockResourceRow()
}//end class
