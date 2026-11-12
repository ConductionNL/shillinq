<?php

/**
 * ObjectFindTarget stub mirroring OCA\OpenRegister\Service\Object\ObjectFindTarget (openregister #9096 and #9099).
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Stub for OCA\OpenRegister\Service\Object\ObjectFindTarget used by shillinq tests.
 *
 * OpenRegister types register and schema as its Register / Schema entities;
 * the stub takes any object because the test doubles' entities are not those.
 */
final class ObjectFindTarget {
	/**
	 * Constructor.
	 *
	 * @param object|string|int|null $register The register or its id, uuid or slug.
	 * @param object|string|int|null $schema   The schema or its id, uuid or slug.
	 */
	public function __construct(
		public readonly object|string|int|null $register=null,
		public readonly object|string|int|null $schema=null,
	) {
	}//end __construct()
}//end class
