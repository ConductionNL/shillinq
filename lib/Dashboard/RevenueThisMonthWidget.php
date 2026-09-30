<?php

/**
 * Revenue this month widget
 *
 * Revenue booked this month, against the same month last year (reporting-custom-analysis REQ-RCA-001).
 *
 * @category Dashboard
 * @package  OCA\Shillinq\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Dashboard;

/**
 * Revenue booked this month, against the same month last year.
 *
 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
 */
class RevenueThisMonthWidget extends FinancialWidget {

	/**
	 * The widget id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
	 */
	public function getId(): string {
		return 'shillinq-revenue-this-month';

	}//end getId()

	/**
	 * The widget title.
	 *
	 * @return string The title.
	 *
	 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
	 */
	public function getTitle(): string {
		return $this->l10n->t('Revenue this month');

	}//end getTitle()

	/**
	 * The items.
	 *
	 * @param array<string, mixed> $figures The figures.
	 *
	 * @return array<int, \OCP\Dashboard\Model\WidgetItem> The items.
	 */
	protected function items(array $figures): array {
		$revenue = $figures['revenue'];

		return [
			$this->item(
				title: $this->money(amount: (float)$revenue['thisMonth']),
				subtitle: $this->l10n->t('Same month last year: %s', [$this->money(amount: (float)$revenue['sameMonthLastYear'])]),
				sinceId: (string)$revenue['month']
			),
		];

	}//end items()
}//end class
