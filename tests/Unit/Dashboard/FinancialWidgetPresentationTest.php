<?php

/**
 * Tests for how the financial dashboard widgets present themselves.
 *
 * Titles, order, icon, link, money formatting per locale and the item limit;
 * the figures themselves are pinned by FinancialWidgetsTest.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Dashboard
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

namespace OCA\Shillinq\Tests\Unit\Dashboard;

use OCA\Shillinq\Dashboard\CashPositionWidget;
use OCA\Shillinq\Dashboard\FinancialWidget;
use OCA\Shillinq\Dashboard\OpenReceivablesWidget;
use OCA\Shillinq\Dashboard\ResultByMonthWidget;
use OCA\Shillinq\Dashboard\RevenueThisMonthWidget;
use OCA\Shillinq\Dashboard\TopCustomersWidget;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\FinancialDashboardService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Widget presentation over fixed figures.
 *
 * @covers \OCA\Shillinq\Dashboard\FinancialWidget
 * @covers \OCA\Shillinq\Dashboard\CashPositionWidget
 * @covers \OCA\Shillinq\Dashboard\OpenReceivablesWidget
 * @covers \OCA\Shillinq\Dashboard\ResultByMonthWidget
 * @covers \OCA\Shillinq\Dashboard\RevenueThisMonthWidget
 * @covers \OCA\Shillinq\Dashboard\TopCustomersWidget
 */
class FinancialWidgetPresentationTest extends TestCase {

	/**
	 * Build a widget over fixed figures in the given locale.
	 *
	 * @param class-string<FinancialWidget> $class The widget class.
	 * @param array<string, mixed> $figures The dashboard figures.
	 * @param string $locale The user's locale code.
	 *
	 * @return FinancialWidget
	 */
	private function widget(string $class, array $figures = [], string $locale = 'en'): FinancialWidget {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => '[' . vsprintf($text, $params) . ']');
		$l10n->method('getLocaleCode')->willReturn($locale);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route): string => 'https://cloud.example/route/' . $route);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $image): string => '/apps/' . $app . '/img/' . $image);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('defaultAdministrationIdForUser')->willReturn('adm-1');

		$dashboard = $this->createMock(FinancialDashboardService::class);
		$dashboard->method('widgetFigures')->with('adm-1')->willReturn($figures);

		return new $class($l10n, $urls, $context, $dashboard);

	}//end widget()

	/**
	 * Every widget has its own translated title, and they share order, icon
	 * and a link to the Shillinq dashboard page.
	 *
	 * @return void
	 */
	public function testWidgetsShareOrderIconAndLinkButHaveTheirOwnTitle(): void {
		$titles = [
			CashPositionWidget::class => '[Cash position]',
			OpenReceivablesWidget::class => '[Open receivables]',
			ResultByMonthWidget::class => '[Result by month]',
			RevenueThisMonthWidget::class => '[Revenue this month]',
			TopCustomersWidget::class => '[Top customers]',
		];

		foreach ($titles as $class => $title) {
			$widget = $this->widget($class);
			$this->assertSame($title, $widget->getTitle(), $class);
			$this->assertSame(40, $widget->getOrder(), $class);
			$this->assertSame('icon-shillinq-widget', $widget->getIconClass(), $class);
			$this->assertSame('https://cloud.example/route/shillinq.dashboard.page', $widget->getUrl(), $class);
		}

	}//end testWidgetsShareOrderIconAndLinkButHaveTheirOwnTitle()

	/**
	 * A Dutch user sees a decimal comma and a spaced euro sign; others the
	 * English form; negative amounts carry a leading minus either way.
	 *
	 * @return void
	 */
	public function testMoneyIsFormattedForTheUsersLocale(): void {
		$figures = ['cashPosition' => -1234.5];

		$dutch = $this->widget(CashPositionWidget::class, $figures, 'nl_NL')->getItemsV2('director')->getItems();
		$english = $this->widget(CashPositionWidget::class, $figures, 'en_GB')->getItemsV2('director')->getItems();
		$positive = $this->widget(CashPositionWidget::class, ['cashPosition' => 1000000.0], 'nl')->getItemsV2('director')->getItems();

		$this->assertSame('-€ 1.234,50', $dutch[0]->getTitle());
		$this->assertSame('-€1,234.50', $english[0]->getTitle());
		$this->assertSame('€ 1.000.000,00', $positive[0]->getTitle());

	}//end testMoneyIsFormattedForTheUsersLocale()

	/**
	 * Each item links to the dashboard page with the app icon and its own
	 * since-id.
	 *
	 * @return void
	 */
	public function testItemsLinkToTheDashboardWithTheAppIcon(): void {
		$items = $this->widget(
			OpenReceivablesWidget::class,
			['receivables' => ['amount' => 10.0, 'count' => 1, 'overdueAmount' => 0.0, 'overdueCount' => 0]]
		)->getItemsV2('director')->getItems();

		$this->assertSame(['open', 'overdue'], array_map(static fn ($item): string => $item->getSinceId(), $items));
		$this->assertSame('https://cloud.example/route/shillinq.dashboard.page', $items[0]->getLink());
		$this->assertSame('https://cloud.example/apps/shillinq/img/app-dark.svg', $items[0]->getIconUrl());
		$this->assertSame('[Open invoices: 1]', $items[0]->getSubtitle());

	}//end testItemsLinkToTheDashboardWithTheAppIcon()

	/**
	 * The item list is cut to the requested limit, and never below one item.
	 *
	 * @return void
	 */
	public function testItemsAreCutToTheLimitButNeverBelowOne(): void {
		$months = [];
		foreach (['2026-01', '2026-02', '2026-03', '2026-04'] as $index => $month) {
			$months[] = ['month' => $month, 'result' => (float)($index * 100)];
		}

		$widget = $this->widget(ResultByMonthWidget::class, ['resultByMonth' => $months]);

		$two = $widget->getItemsV2('director', null, 2)->getItems();
		$none = $widget->getItemsV2('director', null, 0)->getItems();

		$this->assertSame(['2026-04', '2026-03'], array_map(static fn ($item): string => $item->getSubtitle(), $two));
		$this->assertCount(1, $none);
		$this->assertSame('€300.00', $none[0]->getTitle());

	}//end testItemsAreCutToTheLimitButNeverBelowOne()
}//end class
