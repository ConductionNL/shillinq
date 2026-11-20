<?php

/**
 * One derived budget line being regenerated.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * One (annual budget, ledger group, source type) triple, the freshly summed
 * monthly cents of its rows for one fiscal year, and the derivation and
 * BudgetLine already stored for it. Built and read by KnownCostBudgetWriter.
 *
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md
 */
final class KnownCostTarget {

	/**
	 * The administration scope.
	 *
	 * @var string
	 */
	public string $administrationId = '';

	/**
	 * The default AnnualBudget id of the fiscal year.
	 *
	 * @var string
	 */
	public string $annualBudgetId = '';

	/**
	 * The group's LedgerGroup id/slug.
	 *
	 * @var string
	 */
	public string $ledgerGroupId = '';

	/**
	 * `contract` or `recurring`.
	 *
	 * @var string
	 */
	public string $sourceType = '';

	/**
	 * The summed `"01".."12" => cents` map.
	 *
	 * @var array<int|string,int>
	 */
	public array $monthlyCents = [];

	/**
	 * Every CashflowRecurring.recurId in the group.
	 *
	 * @var list<string>
	 */
	public array $contributingRecurIds = [];

	/**
	 * The stored derivation, or null when there is none.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $derivation = null;

	/**
	 * The derivation's stored BudgetLine, or null when it is gone.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $budgetLine = null;
}//end class
