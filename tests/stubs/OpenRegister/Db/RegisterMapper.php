<?php

/**
 * Minimal RegisterMapper stub mirroring OCA\OpenRegister\Db\RegisterMapper::find() on openregister main.
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
 * Stub for OCA\OpenRegister\Db\RegisterMapper used by shillinq tests.
 */
class RegisterMapper {
	/**
	 * Find a register by id, uuid or slug (real signature).
	 *
	 * @param string|int $id            The identifier.
	 * @param bool       $_rbac         Whether RBAC applies.
	 * @param bool       $_multitenancy Whether multitenancy applies.
	 *
	 * @return Register
	 *
	 * @throws RuntimeException Always in the stub: a test overrides this method.
	 */
	public function find(string|int $id, bool $_rbac=true, bool $_multitenancy=true): Register {
		throw new RuntimeException('RegisterMapper stub: override find() in the test.');
	}//end find()
}//end class
