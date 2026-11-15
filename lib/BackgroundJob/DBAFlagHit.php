<?php

/**
 * Shillinq DBA flag hit.
 *
 * A risk flag a detection rule raised for one assignment.
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

/**
 * The type and details of a raised DBA risk flag.
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */
final class DBAFlagHit {
	/**
	 * Construct the hit.
	 *
	 * @param string $type The flag type.
	 * @param array<string,string> $details What the rule saw.
	 */
	public function __construct(
		public readonly string $type,
		public readonly array $details,
	) {
	}//end __construct()
}//end class
