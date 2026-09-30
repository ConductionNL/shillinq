<?php

/**
 * Open receivables widget
 *
 * The invoices customers still have to pay, and the overdue part (reporting-custom-analysis REQ-RCA-001).
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
 * The invoices customers still have to pay, and the overdue part.
 *
 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
 */
class OpenReceivablesWidget extends FinancialWidget {

	/**
	 * The widget id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
	 */
	public function getId(): string {
		return 'shillinq-open-receivables';

	}//end getId()

	/**
	 * The widget title.
	 *
	 * @return string The title.
	 *
	 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
	 */
	public function getTitle(): string {
		return $this->l10n->t('Open receivables');

	}//end getTitle()

	/**
	 * The items.
	 *
	 * @param array<string, mixed> $figures The figures.
	 *
	 * @return array<int, \OCP\Dashboard\Model\WidgetItem> The items.
	 */
	protected function items(array $figures): array {
		$open = $figures['receivables'];

		return [
			$this->item(
				title: $this->money(amount: (float)$open['amount']),
				subtitle: $this->l10n->n('%n open invoice', '%n open invoices', (int)$open['count']),
				sinceId: 'open'
			),
			$this->item(
				title: $this->money(amount: (float)$open['overdueAmount']),
				subtitle: $this->l10n->n('%n invoice overdue', '%n invoices overdue', (int)$open['overdueCount']),
				sinceId: 'overdue'
			),
		];

	}//end items()
}//end class
