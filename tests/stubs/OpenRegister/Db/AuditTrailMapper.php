<?php

/**
 * Minimal AuditTrailMapper stub for unit tests that write audit rows through
 * OpenRegister without the OpenRegister app being autoloaded. Mirrors the
 * signature of the real `OCA\OpenRegister\Db\AuditTrailMapper::createAuditTrailEntry()`
 * on openregister development (lib/Db/AuditTrailMapper.php): the one entry
 * point for a non-CRUD action recorded on an object's hash-chained trail.
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use RuntimeException;

/**
 * Stub for OCA\OpenRegister\Db\AuditTrailMapper used by shillinq tests.
 */
class AuditTrailMapper {
	/**
	 * Create a custom audit trail entry on an object (real signature).
	 *
	 * @param ObjectEntity $object The object the entry relates to.
	 * @param string $action The action, e.g. `NexusCalculation.calculated`.
	 * @param array<string,mixed> $context Additional context data, stored as the row's `changed`.
	 * @param string|null $actorId Explicit actor id; null uses the session.
	 * @param string|null $actorName Explicit actor display name.
	 * @param string|null $ipAddress The calling address.
	 *
	 * @return object The created audit trail entry (an AuditTrail entity in OpenRegister).
	 *
	 * @throws RuntimeException Always in the stub: a test overrides this method.
	 */
	public function createAuditTrailEntry(
		ObjectEntity $object,
		string $action,
		array $context = [],
		?string $actorId = null,
		?string $actorName = null,
		?string $ipAddress = null,
	): object {
		throw new RuntimeException('AuditTrailMapper stub: override createAuditTrailEntry() in the test.');
	}//end createAuditTrailEntry()
}//end class
