<?php

/**
 * One open-items table being built.
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
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;

/**
 * Which record fields an open receivables or payables table reads, the party
 * names it resolves against, and the reference date of its overdue flag.
 * Built and read by FinancialSeriesCalculator.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */
final class OpenItemsTable {

	/**
	 * The record field holding the lifecycle state.
	 *
	 * @var string
	 */
	public string $stateField = '';

	/**
	 * The states that count as open.
	 *
	 * @var list<string>
	 */
	public array $openStates = [];

	/**
	 * The record field holding the party id.
	 *
	 * @var string
	 */
	public string $partyField = '';

	/**
	 * The amount fields, tried in order.
	 *
	 * @var list<string>
	 */
	public array $amountFields = [];

	/**
	 * Party id => display name.
	 *
	 * @var array<string,string>
	 */
	public array $names = [];

	/**
	 * Reference date for the overdue flag.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $now;
}//end class
