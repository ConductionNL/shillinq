<?php

/**
 * BulkBatchDefaults stub mirroring OCA\OpenRegister\Service\Object\BulkBatchDefaults (openregister #9108).
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
 * Stub for OCA\OpenRegister\Service\Object\BulkBatchDefaults used by shillinq tests.
 *
 * OpenRegister types register and schema as its Register / Schema entities;
 * the stub takes any object because the test doubles' entities are not those.
 */
final class BulkBatchDefaults {
	/**
	 * Constructor.
	 *
	 * @param object|string|int|null $register Default register for the batch.
	 * @param object|string|int|null $schema   Default schema for the batch.
	 */
	public function __construct(
		public readonly object|string|int|null $register=null,
		public readonly object|string|int|null $schema=null,
	) {
	}//end __construct()
}//end class
