<?php

/**
 * Contract of RevenueContractRenameMigrator
 *
 * The public surface of RevenueContractRenameMigrator that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

use RuntimeException;

/**
 * Contract of RevenueContractRenameMigrator.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RevenueContractRenameMigratorInterface {

	/**
	 * Migrate a batch of source objects, guarded by count.
	 *
	 * Every source object is inspected; IFRS-15-shaped ones are re-pointed to
	 * `$to`, CLM-shaped ones (and anything not under `$from`) are returned
	 * unchanged — no object is ever dropped. The output count MUST equal the
	 * source count or the migration ABORTS (this method never deletes
	 * anything, so an abort leaves the source data intact).
	 *
	 * @param array<int, array<string, mixed>> $sourceObjects The objects under the source slug.
	 * @param string $from The source schema slug.
	 * @param string $to The target schema slug.
	 *
	 * @return array<int, array<string, mixed>> The migrated (and unchanged) objects.
	 *
	 * @throws RuntimeException When the migrated count does not match the source count.
	 *
	 * @spec openspec/changes/contracts-single-home/specs/contracts-single-home/spec.md
	 */
	public function migrateBatch(array $sourceObjects, string $from, string $to): array;

	/**
	 * The from/to slug pair for the RevenueContract rename.
	 *
	 * @return array{from: string, to: string}
	 *
	 * @spec openspec/changes/contracts-single-home/specs/contracts-single-home/spec.md
	 */
	public function revenueContractRename(): array;
}//end interface
