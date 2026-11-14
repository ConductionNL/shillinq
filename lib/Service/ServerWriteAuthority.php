<?php

/**
 * Server Write Authority
 *
 * A request-scoped grant that lets a lifecycle guard or a pre-save listener tell
 * a write made by one of shillinq's own services from the same write sent to
 * the OpenRegister object API. The service grants a purpose for one object,
 * saves, and the grant is withdrawn when the save returns; a browser PATCH runs
 * in a request where nothing was granted, so the guard refuses it.
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
 * @spec openspec/changes/enforced-schema-authorization/specs/schema-authorization/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Holds the purposes granted for the duration of one save.
 *
 * The grants live in a static property because the guard is constructed by
 * OpenRegister's container and the granting service by shillinq's, so the two
 * never share an instance.
 *
 * @spec openspec/changes/enforced-schema-authorization/specs/schema-authorization/spec.md
 */
class ServerWriteAuthority {

	/**
	 * Purpose => object id => grant depth.
	 *
	 * @var array<string, array<string, int>>
	 */
	private static array $grants = [];

	/**
	 * Run a save with a purpose granted for one object.
	 *
	 * @param string   $purpose  What the write is, such as `purchase-order-approval`.
	 * @param string   $objectId The object the write is for.
	 * @param callable $work     The save.
	 *
	 * @return mixed What the save returned.
	 *
	 * @spec openspec/changes/enforced-schema-authorization/specs/schema-authorization/spec.md
	 */
	public function during(string $purpose, string $objectId, callable $work): mixed {
		$objectId = trim($objectId);
		self::$grants[$purpose][$objectId] = ((self::$grants[$purpose][$objectId] ?? 0) + 1);
		try {
			return $work();
		} finally {
			self::$grants[$purpose][$objectId]--;
			if (self::$grants[$purpose][$objectId] <= 0) {
				unset(self::$grants[$purpose][$objectId]);
			}
		}
	}//end during()

	/**
	 * Whether a purpose is granted for an object right now.
	 *
	 * @param string $purpose  The purpose.
	 * @param string $objectId The object id; an empty id is never granted.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/enforced-schema-authorization/specs/schema-authorization/spec.md
	 */
	public function holds(string $purpose, string $objectId): bool {
		$objectId = trim($objectId);
		return $objectId !== '' && isset(self::$grants[$purpose][$objectId]) === true;
	}//end holds()
}//end class
