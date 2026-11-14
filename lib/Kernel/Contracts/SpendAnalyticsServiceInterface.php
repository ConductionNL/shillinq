<?php

/**
 * Contract of SpendAnalyticsService
 *
 * The public surface of SpendAnalyticsService that other components call, so they depend
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
 * Contract of SpendAnalyticsService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SpendAnalyticsServiceInterface {

	/**
	 * Spend grouped by expense category (GL accountNumber) over the debit AP
	 * expense postings, scoped to one administration.
	 *
	 * Scoped through `GLLine.administrationId`, denormalised from the parent
	 * `GLTransaction` (REQ-GLS-001). Raises rather than returning anything at
	 * all while the backfill gate is shut — see the class docblock.
	 *
	 * @param string $administrationId The administration the caller is a member of.
	 *
	 * @return array<string,mixed> `{ dimension, groups:[{key,amount}], total, backend }`.
	 *
	 * @throws RuntimeException When the GLLine administration backfill is not proven complete.
	 *
	 * @spec openspec/specs/spend-analytics/spec.md
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function spendByCategory(string $administrationId): array;

	/**
	 * Spend grouped by analytical cost centre (GL costCenterCode) over the
	 * debit AP expense postings, scoped to one administration.
	 *
	 * @param string $administrationId The administration the caller is a member of.
	 *
	 * @return array<string,mixed> `{ dimension, groups:[{key,amount}], total, backend }`.
	 *
	 * @throws RuntimeException When the GLLine administration backfill is not proven complete.
	 *
	 * @spec openspec/specs/spend-analytics/spec.md
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function spendByCostCentre(string $administrationId): array;

	/**
	 * Spend grouped by fiscal period (GL periodId) over the debit AP expense
	 * postings, scoped to one administration.
	 *
	 * @param string $administrationId The administration the caller is a member of.
	 *
	 * @return array<string,mixed> `{ dimension, groups:[{key,amount}], total, backend }`.
	 *
	 * @throws RuntimeException When the GLLine administration backfill is not proven complete.
	 *
	 * @spec openspec/specs/spend-analytics/spec.md
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function spendByPeriod(string $administrationId): array;

	/**
	 * Spend grouped by supplier (vendorId) over the committed AP states, scoped
	 * to one administration.
	 *
	 * `APTransaction.administrationId` is a declared, filterable property, so
	 * the caller's administration is pushed into the aggregation filter and the
	 * returned totals contain no other administration's invoices. The caller
	 * MUST have proven membership of `$administrationId` first — this service
	 * treats the value purely as a scope term, exactly like every other
	 * administration-scoped service in the app.
	 *
	 * @param string $administrationId The administration the caller is a member of.
	 *
	 * @return array<string,mixed> `{ dimension, groups:[{key,amount}], total, backend }`.
	 *
	 * @spec openspec/specs/spend-analytics/spec.md
	 */
	public function spendBySupplier(string $administrationId): array;
}//end interface
