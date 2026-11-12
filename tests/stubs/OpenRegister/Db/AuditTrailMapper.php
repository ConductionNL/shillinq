<?php

/**
 * Minimal AuditTrailMapper stub for unit tests that write audit rows through
 * OpenRegister without the OpenRegister app being autoloaded. Mirrors the
 * signatures of the real `OCA\OpenRegister\Db\AuditTrailMapper` on openregister
 * main (lib/Db/AuditTrailMapper.php): `recordObjectEvent()` is the one entry
 * point for a non-CRUD action recorded on an object's hash-chained trail, and
 * `recordRegisterEvent()` (openregister #9714) the one for an event about a
 * register as a whole.
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

use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrail\RegisterAuditEvent;
use RuntimeException;

/**
 * Stub for OCA\OpenRegister\Db\AuditTrailMapper used by shillinq tests.
 */
class AuditTrailMapper {
	/**
	 * Record a non-CRUD action on one object (real signature).
	 *
	 * @param ObjectAuditEvent $event The object, action, context, and the explicit actor and address.
	 *
	 * @return object The created audit trail entry (an AuditTrail entity in OpenRegister).
	 *
	 * @throws RuntimeException Always in the stub: a test overrides this method.
	 */
	public function recordObjectEvent(ObjectAuditEvent $event): object {
		throw new RuntimeException('AuditTrailMapper stub: override recordObjectEvent() in the test.');
	}//end recordObjectEvent()

	/**
	 * Record an event about a register as a whole (signature from openregister #9714).
	 *
	 * @param RegisterAuditEvent $event The register id, the namespaced action, the details and the actor.
	 *
	 * @return object The created audit trail entry (an AuditTrail entity in OpenRegister).
	 *
	 * @throws RuntimeException Always in the stub: a test overrides this method.
	 */
	public function recordRegisterEvent(RegisterAuditEvent $event): object {
		throw new RuntimeException('AuditTrailMapper stub: override recordRegisterEvent() in the test.');
	}//end recordRegisterEvent()

	/**
	 * Every audit trail matching a listing's filters and sort (real signature).
	 *
	 * @param ListQuery $query The offset, filters, sort and search.
	 *
	 * @return array<int, object> The matching entries (AuditTrail entities in OpenRegister).
	 *
	 * @throws RuntimeException Always in the stub: a test overrides this method.
	 */
	public function findEvery(ListQuery $query): array {
		throw new RuntimeException('AuditTrailMapper stub: override findEvery() in the test.');
	}//end findEvery()
}//end class
