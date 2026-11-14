<?php

/**
 * Contract of GlLineFiscalYearBackfillMigrator
 *
 * The public surface of GlLineFiscalYearBackfillMigrator that other components call, so they depend
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
 * Contract of GlLineFiscalYearBackfillMigrator.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GlLineFiscalYearBackfillMigratorInterface {

	/**
	 * Schema slug of the line rows this migrator writes.
	 *
	 * @var string
	 */
	public const SCHEMA_GL_LINE = 'GLLine';

	/**
	 * Schema slug of the parent rows this migrator reads.
	 *
	 * @var string
	 */
	public const SCHEMA_GL_TRANSACTION = 'GLTransaction';

	/**
	 * Backfill a batch of `GLLine` rows from their parent `GLTransaction` rows.
	 *
	 * Every row is classified before anything is returned, and the three class
	 * counts are asserted to sum to the number of rows seen — a row that fell
	 * through the classifier silently would otherwise make the report describe
	 * a smaller set than was actually processed.
	 *
	 * Unlike the administration backfill, an unresolvable row does NOT abort
	 * the batch: it is counted and reported. See the class docblock.
	 *
	 * The returned `lines` are keyed by the SAME integer offsets as `$glLines`,
	 * so a caller can pair each migrated row with the source row it came from
	 * without re-deriving anything. Only changed offsets are present.
	 *
	 * @param array<int, array<string, mixed>> $glLines        The line rows.
	 * @param array<int, array<string, mixed>> $glTransactions The parent rows.
	 *
	 * @return array{lines: array<int, array<string, mixed>>, seen: int, stamped: int,
	 *               alreadyStamped: int, unresolvable: int,
	 *               disagreements: array<int, string>}
	 *
	 * @throws RuntimeException When the class counts do not sum to the rows seen.
	 *
	 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md#req-cc-005
	 */
	public function backfillBatch(array $glLines, array $glTransactions): array;

	/**
	 * Count the rows still carrying no fiscal year.
	 *
	 * A TOTAL over the set handed in, never a sample — the point of this method
	 * is to describe the whole store after the fact, so a caller can report
	 * what the backfill actually left behind rather than what it intended.
	 *
	 * @param array<int, array<string, mixed>> $glLines The line rows.
	 *
	 * @return int How many rows lack a fiscal year.
	 *
	 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md#req-cc-005
	 */
	public function countMissingFiscalYearId(array $glLines): int;
}//end interface
