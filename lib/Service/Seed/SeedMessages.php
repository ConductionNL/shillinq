<?php

/**
 * Shillinq seed messages
 *
 * The log lines and result message of one seed import.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Seed
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

namespace OCA\Shillinq\Service\Seed;

/**
 * What SeedImporter::import() logs and returns for one seed.
 *
 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
 */
final class SeedMessages {

	/**
	 * Constructor for the SeedMessages.
	 *
	 * @param string $seededLog The info line logged after the import
	 * @param string $seededMessage The result message after the import
	 * @param string $failedLog The error line logged when the import throws
	 * @param array<string, mixed> $logContext Context logged before the counts
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $seededLog,
		public readonly string $seededMessage,
		public readonly string $failedLog,
		public readonly array $logContext=[],
	) {
	}//end __construct()
}//end class
