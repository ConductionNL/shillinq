<?php

/**
 * RegisterSchemaRef stub mirroring OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef on openregister main.
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef used by shillinq tests.
 */
final class RegisterSchemaRef {
	/**
	 * Constructor.
	 *
	 * @param string|int $register Register id, uuid or slug.
	 * @param string|int $schema   Schema id, uuid or slug.
	 */
	public function __construct(
		public readonly string|int $register,
		public readonly string|int $schema,
	) {
	}//end __construct()
}//end class
