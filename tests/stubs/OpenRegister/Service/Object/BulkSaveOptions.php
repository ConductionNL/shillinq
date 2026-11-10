<?php

/**
 * BulkSaveOptions stub mirroring OCA\OpenRegister\Service\Object\BulkSaveOptions (openregister #9108).
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
 * Stub for OCA\OpenRegister\Service\Object\BulkSaveOptions used by shillinq tests.
 */
final class BulkSaveOptions {
	/**
	 * Constructor.
	 *
	 * @param BulkBatchDefaults $defaults   The register and schema defaults.
	 * @param bool              $validation Validate every object.
	 * @param bool              $events     Dispatch object events.
	 * @param bool              $audit      Write audit trail entries.
	 */
	public function __construct(
		public readonly BulkBatchDefaults $defaults=new BulkBatchDefaults(),
		public readonly bool $validation=false,
		public readonly bool $events=false,
		public readonly bool $audit=true,
	) {
	}//end __construct()
}//end class
