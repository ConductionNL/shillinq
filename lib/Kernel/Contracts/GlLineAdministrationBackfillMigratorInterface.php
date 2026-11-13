<?php

/**
 * Contract of GlLineAdministrationBackfillMigrator
 *
 * The public surface of GlLineAdministrationBackfillMigrator that other components call, so they depend
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
 * Contract of GlLineAdministrationBackfillMigrator.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GlLineAdministrationBackfillMigratorInterface {

	/**
	 * The schema whose rows are backfilled.
	 */
	public const SCHEMA_GL_LINE = 'GLLine';

	/**
	 * The parent schema that owns the administration (source of truth).
	 */
	public const SCHEMA_GL_TRANSACTION = 'GLTransaction';

	/**
	 * App-config key holding the backfill-completeness gate.
	 *
	 * Written ONLY by {@see \OCA\Shillinq\Repair\BackfillGlLineAdministration}
	 * after it has RE-READ every `GLLine` row from the store and counted zero
	 * without a scope; read by {@see \OCA\Shillinq\Service\SpendAnalyticsService}
	 * before it will filter a GL-backed aggregation on `administrationId`.
	 */
	public const GATE_CONFIG_KEY = 'glline_administration_backfill_complete';

	/**
	 * The value the gate must hold to count as green.
	 *
	 * A VERSION, not a boolean, on purpose. The gate's real claim is "every
	 * GLLine row carries a scope, AND every code path that writes one stamps
	 * it". The first half is measured at repair time; the second half is a
	 * property of the CODE, which a stored boolean cannot notice changing. So
	 * whenever a new `GLLine` writer is added (or an existing one is reworked),
	 * bump this constant: every deployment's stored value instantly stops
	 * matching, `SpendAnalyticsService` fails closed again, and the repair step
	 * must re-prove completeness against the new writer set before the views
	 * come back. A boolean would have kept answering "yes" on evidence gathered
	 * before that writer existed.
	 *
	 * Contract v1 covers the writers stamped by `glline-administration-scope`:
	 * CogsPosterService, InventoryGlAdjustmentPoster, RuleTestDataSeeder and
	 * VatSuppletieDetectionService.
	 */
	public const GATE_CONTRACT_VERSION = 'v1';

	/**
	 * Backfill a batch of `GLLine` rows from their parent `GLTransaction` rows.
	 *
	 * Classifies EVERY row first, then count-verifies before returning
	 * anything: `already-scoped + resolved` MUST equal the number of rows seen,
	 * or {@see assertCountsMatch()} throws and the caller MUST NOT write any of
	 * the returned rows. One unclassifiable line aborts the whole batch,
	 * including the lines that resolved cleanly.
	 *
	 * The returned `lines` are keyed by the SAME integer offsets as
	 * `$glLines`, so a caller can pair each migrated row with the source row it
	 * came from (and hence with its object id) without re-deriving anything.
	 * Only offsets that were actually changed are present.
	 *
	 * @param array<int, array<string, mixed>> $glLines The rows under `GLLine`.
	 * @param array<int, array<string, mixed>> $glTransactions The parent rows.
	 *
	 * @return array{lines: array<int, array<string, mixed>>, total: int, backfilled: int, unchanged: int, unclassifiable: int, conflicting: int}
	 *         The changed rows (by source offset) plus the report counts.
	 *
	 * @throws RuntimeException When any row is unclassifiable (count guard).
	 *
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function backfillBatch(array $glLines, array $glTransactions): array;

	/**
	 * Count `GLLine` rows that still carry no administration.
	 *
	 * THE COMPLETENESS CHECK the whole change turns on (REQ-GLS-003). It is a
	 * TOTAL over every row handed to it, never a spot check or a sample: the
	 * gate it feeds is what tells `SpendAnalyticsService` that switching the
	 * `administrationId` filter on will narrow the totals rather than empty
	 * them, and a sampled "probably zero" is not evidence of that.
	 *
	 * @param array<int, array<string, mixed>> $glLines Every `GLLine` row.
	 *
	 * @return int How many rows lack a non-empty `administrationId`.
	 *
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function countMissingAdministrationId(array $glLines): int;
}//end interface
