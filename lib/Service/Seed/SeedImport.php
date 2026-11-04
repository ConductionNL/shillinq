<?php

/**
 * Shillinq seed import
 *
 * One schema's rows to import from a seed file, and how each row is matched.
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

use Closure;

/**
 * The rows SeedImporter::import() writes into one schema.
 *
 * The match closure returns the findAll() filters that find an existing copy
 * of a row, or null to leave the row out. A non-null administration id is
 * stamped on every row that is saved.
 *
 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
 */
final class SeedImport {

	/**
	 * Constructor for the SeedImport.
	 *
	 * @param string $schema The OpenRegister schema slug the rows are saved in
	 * @param mixed $rows The rows from the seed file, as decoded
	 * @param Closure(mixed): (array<string, mixed>|null) $match The deduplication filters per row
	 * @param string|null $administrationId The administration stamped on saved rows, or null for none
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $schema,
		public readonly mixed $rows,
		public readonly Closure $match,
		public readonly ?string $administrationId=null,
	) {
	}//end __construct()
}//end class
