<?php

/**
 * Cash position widget
 *
 * The balance of the liquid accounts (reporting-custom-analysis REQ-RCA-001).
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
 * The balance of the liquid accounts.
 *
 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
 */
class CashPositionWidget extends FinancialWidget {

	/**
	 * The widget id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
	 */
	public function getId(): string {
		return 'shillinq-cash-position';

	}//end getId()

	/**
	 * The widget title.
	 *
	 * @return string The title.
	 *
	 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
	 */
	public function getTitle(): string {
		return $this->l10n->t('Cash position');

	}//end getTitle()

	/**
	 * The items.
	 *
	 * @param array<string, mixed> $figures The figures.
	 *
	 * @return array<int, \OCP\Dashboard\Model\WidgetItem> The items.
	 */
	protected function items(array $figures): array {
		return [
			$this->item(
				title: $this->money(amount: (float)$figures['cashPosition']),
				subtitle: $this->l10n->t('On the bank and cash accounts'),
				sinceId: 'cash'
			),
		];

	}//end items()
}//end class
