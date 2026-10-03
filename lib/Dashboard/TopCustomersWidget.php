<?php

/**
 * Top customers widget
 *
 * The customers invoiced most this year (reporting-custom-analysis REQ-RCA-001).
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
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Dashboard;

/**
 * The customers invoiced most this year.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */
class TopCustomersWidget extends FinancialWidget {

	/**
	 * The widget id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function getId(): string {
		return 'shillinq-top-customers';

	}//end getId()

	/**
	 * The widget title.
	 *
	 * @return string The title.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function getTitle(): string {
		return $this->l10n->t('Top customers');

	}//end getTitle()

	/**
	 * The items.
	 *
	 * @param array<string, mixed> $figures The figures.
	 *
	 * @return array<int, \OCP\Dashboard\Model\WidgetItem> The items.
	 */
	protected function items(array $figures): array {
		$items = [];
		foreach ($figures['topCustomers'] as $customer) {
			$items[] = $this->item(
				title: (string)$customer['name'],
				subtitle: $this->l10n->t('Invoiced this year: %s', [$this->money(amount: (float)$customer['revenue'])]),
				sinceId: (string)$customer['customerId']
			);
		}

		return $items;

	}//end items()
}//end class
