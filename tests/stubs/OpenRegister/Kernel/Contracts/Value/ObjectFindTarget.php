<?php

/**
 * Test stub for OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget.
 *
 * Mirrors the constructor of the real value object on OpenRegister main
 * (lib/Kernel/Contracts/Value/ObjectFindTarget.php).
 *
 * @category Test
 * @package  OCA\OpenRegister
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * The register and schema one object call names.
 */
final class ObjectFindTarget {

	/**
	 * Constructor.
	 *
	 * @param object|string|int|null $register The register or its id, UUID or slug.
	 * @param object|string|int|null $schema   The schema or its id, UUID or slug.
	 */
	public function __construct(
		public readonly object|string|int|null $register=null,
		public readonly object|string|int|null $schema=null,
	) {
	}//end __construct()
}//end class
