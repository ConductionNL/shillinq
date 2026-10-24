<?php

/**
 * Financial Widget
 *
 * The common part of shillinq's Nextcloud dashboard widgets
 * (reporting-custom-analysis REQ-RCA-001): each shows a figure of the user's
 * active administration, computed by FinancialDashboardService so it matches
 * the shillinq dashboard. The widgets serve their items through the
 * dashboard API, which the Nextcloud dashboard and launchpad both render, so
 * no widget ships a script of its own. A user without an administration sees
 * a message saying so instead of a zero.
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

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\FinancialDashboardService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Base class of the five financial widgets.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */
abstract class FinancialWidget implements IAPIWidgetV2 {

	/**
	 * Constructor.
	 *
	 * @param IL10N                        $l10n      Translations.
	 * @param IURLGenerator                $urls      Links into shillinq.
	 * @param AdministrationContextService $context   The user's administrations.
	 * @param FinancialDashboardService    $dashboard The figures.
	 */
	public function __construct(
		protected readonly IL10N $l10n,
		protected readonly IURLGenerator $urls,
		private readonly AdministrationContextService $context,
		private readonly FinancialDashboardService $dashboard,
	) {

	}//end __construct()

	/**
	 * The items of one figure.
	 *
	 * @param array<string, mixed> $figures FinancialDashboardService::widgetFigures().
	 *
	 * @return array<int, WidgetItem> The items.
	 */
	abstract protected function items(array $figures): array;

	/**
	 * Sort position among the dashboard's widgets.
	 *
	 * @return int The order.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function getOrder(): int {
		return 40;

	}//end getOrder()

	/**
	 * The icon class.
	 *
	 * @return string The class.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function getIconClass(): string {
		return 'icon-shillinq-widget';

	}//end getIconClass()

	/**
	 * The page the widget's header opens.
	 *
	 * @return string|null The absolute URL.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function getUrl(): ?string {
		return $this->urls->linkToRouteAbsolute(Application::APP_ID . '.dashboard.page');

	}//end getUrl()

	/**
	 * Nothing to load: the dashboard renders the API items itself.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function load(): void {
		// The items come from getItemsV2(); the dashboard draws them, so no script is added.
		return;

	}//end load()

	/**
	 * The figure for one user, from their active administration.
	 *
	 * @param string      $userId The user.
	 * @param string|null $since  Unused: a figure has no history to page through.
	 * @param int         $limit  The most items.
	 *
	 * @return WidgetItems The items, or a message when the user has no administration.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $since is the interface's; a figure has no history.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$administrationId = $this->context->defaultAdministrationIdForUser(userId: $userId);
		if ($administrationId === null) {
			return new WidgetItems([], $this->l10n->t('You have no administration in Shillinq yet, so there is no figure to show.'));
		}

		$figures = $this->dashboard->widgetFigures(administrationId: $administrationId, now: new DateTimeImmutable());

		return new WidgetItems(
			array_slice($this->items(figures: $figures), 0, max(1, $limit)),
			$this->l10n->t('Nothing booked yet.')
		);

	}//end getItemsV2()

	/**
	 * An amount in euros: Dutch notation for a Dutch locale, English otherwise.
	 *
	 * Written out rather than left to intl's NumberFormatter, which not every
	 * instance has, so a figure reads the same on every server.
	 *
	 * @param float $amount The amount.
	 *
	 * @return string The formatted amount.
	 */
	protected function money(float $amount): string {
		$sign = '';
		if ($amount < 0) {
			$sign = '-';
		}

		if (str_starts_with($this->l10n->getLocaleCode(), 'nl') === true) {
			return $sign . '€ ' . number_format(abs($amount), 2, ',', '.');
		}

		return $sign . '€' . number_format(abs($amount), 2, '.', ',');

	}//end money()

	/**
	 * One item linking to the shillinq dashboard.
	 *
	 * @param string $title    The main line.
	 * @param string $subtitle The second line.
	 * @param string $sinceId  A stable id for the item.
	 *
	 * @return WidgetItem The item.
	 */
	protected function item(string $title, string $subtitle, string $sinceId): WidgetItem {
		return new WidgetItem(
			$title,
			$subtitle,
			(string)$this->getUrl(),
			$this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg')),
			$sinceId
		);

	}//end item()
}//end class
