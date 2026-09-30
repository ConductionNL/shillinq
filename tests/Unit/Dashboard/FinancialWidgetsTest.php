<?php

/**
 * Tests for the five financial dashboard widgets (reporting-custom-analysis REQ-RCA-001).
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
 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
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
use OCA\Shillinq\Service\FinancialSeriesCalculator;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Each widget shows its figure for the user's administration, or says there is none.
 */
class FinancialWidgetsTest extends TestCase {

	/**
	 * The register: one administration with an open and an overdue invoice, revenue this month and last year, a bank balance.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Rows per schema.
	 */
	private function register(): array {
		$month = (new \DateTimeImmutable())->format('Y-m');
		$lastYear = ((int)substr($month, 0, 4) - 1) . substr($month, 4);
		$adm = 'adm-1';

		return [
			'Account' => [
				['administrationId' => $adm, 'accountNumber' => '8000', 'name' => 'Omzet', 'accountType' => 'revenue'],
				['administrationId' => $adm, 'accountNumber' => '4000', 'name' => 'Lonen', 'accountType' => 'expenses'],
				['administrationId' => $adm, 'accountNumber' => '1000', 'name' => 'Bank', 'accountType' => 'assets'],
			],
			'GLTransaction' => [
				['id' => 'tx-now', 'administrationId' => $adm, 'state' => 'posted', 'postingDate' => $month . '-01'],
				['id' => 'tx-then', 'administrationId' => $adm, 'state' => 'posted', 'postingDate' => $lastYear . '-01'],
				['id' => 'tx-other', 'administrationId' => 'adm-2', 'state' => 'posted', 'postingDate' => $month . '-01'],
			],
			'GLLine' => [
				['administrationId' => $adm, 'transactionId' => 'tx-now', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 5000.0],
				['administrationId' => $adm, 'transactionId' => 'tx-now', 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 2000.0],
				['administrationId' => $adm, 'transactionId' => 'tx-now', 'accountNumber' => '1000', 'side' => 'debit', 'amount' => 3000.0],
				['administrationId' => $adm, 'transactionId' => 'tx-then', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 4000.0],
				['administrationId' => 'adm-2', 'transactionId' => 'tx-other', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 99999.0],
			],
			'ARInvoice' => [
				['administrationId' => $adm, 'customerId' => 'c-1', 'lifecycleState' => 'issued', 'invoiceDate' => $month . '-01', 'dueDate' => '2999-01-01', 'grossAmount' => 1210.0, 'netAmount' => 1000.0],
				['administrationId' => $adm, 'customerId' => 'c-2', 'lifecycleState' => 'overdue', 'invoiceDate' => $month . '-01', 'dueDate' => '2020-01-01', 'grossAmount' => 605.0, 'netAmount' => 500.0],
				['administrationId' => $adm, 'customerId' => 'c-1', 'lifecycleState' => 'draft', 'invoiceDate' => $month . '-01', 'grossAmount' => 9999.0, 'netAmount' => 9999.0],
			],
			'CustomerMaster' => [
				['administrationId' => $adm, 'customerId' => 'c-1', 'legalName' => 'Gemeente Tiel'],
				['administrationId' => $adm, 'customerId' => 'c-2', 'legalName' => 'Bakkerij De Korf'],
			],
		];
	}

	/**
	 * A widget of the given class for a user with or without an administration.
	 *
	 * @param class-string<FinancialWidget> $class            The widget class.
	 * @param string|null                   $administrationId The user's administration.
	 *
	 * @return IAPIWidgetV2 The widget.
	 */
	private function widget(string $class, ?string $administrationId): IAPIWidgetV2 {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new InMemoryObjectServiceStub($this->register()));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$dashboard = new FinancialDashboardService($container, $appConfig, new FinancialSeriesCalculator(), $this->createMock(LoggerInterface::class));

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('defaultAdministrationIdForUser')->with('director')->willReturn($administrationId);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$l10n->method('n')->willReturnCallback(static fn (string $one, string $many, int $count): string => str_replace('%n', (string)$count, $count === 1 ? $one : $many));
		$l10n->method('getLocaleCode')->willReturn('en');
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/shillinq/');

		return new $class($l10n, $urls, $context, $dashboard);
	}

	/**
	 * The item titles and subtitles of a widget.
	 *
	 * @param IAPIWidgetV2 $widget The widget.
	 *
	 * @return array<int, array{0: string, 1: string}> Title and subtitle per item.
	 */
	private function lines(IAPIWidgetV2 $widget): array {
		return array_map(
			static fn ($item): array => [$item->getTitle(), $item->getSubtitle()],
			$widget->getItemsV2('director')->getItems()
		);
	}

	/**
	 * Scenario "A director builds their own dashboard": open receivables and the overdue part.
	 *
	 * @return void
	 */
	public function testOpenReceivablesShowsTheOpenAndOverdueInvoices(): void {
		$this->assertSame([['€1,815.00', '2 open invoices'], ['€605.00', '1 invoice overdue']], $this->lines($this->widget(OpenReceivablesWidget::class, 'adm-1')));
	}

	/**
	 * Revenue this month against the same month last year, this administration only.
	 *
	 * @return void
	 */
	public function testRevenueThisMonthComparesWithLastYear(): void {
		$this->assertSame([['€5,000.00', 'Same month last year: €4,000.00']], $this->lines($this->widget(RevenueThisMonthWidget::class, 'adm-1')));
	}

	/**
	 * The cash position is the liquid accounts' balance.
	 *
	 * @return void
	 */
	public function testCashPositionIsTheBankBalance(): void {
		$this->assertSame([['€3,000.00', 'On the bank and cash accounts']], $this->lines($this->widget(CashPositionWidget::class, 'adm-1')));
	}

	/**
	 * The result of the last six months, newest first.
	 *
	 * @return void
	 */
	public function testResultByMonthNewestFirst(): void {
		$lines = $this->lines($this->widget(ResultByMonthWidget::class, 'adm-1'));

		$this->assertCount(6, $lines);
		$this->assertSame(['€3,000.00', (new \DateTimeImmutable())->format('Y-m')], $lines[0]);
	}

	/**
	 * Top customers by net amount invoiced this year; a draft does not count.
	 *
	 * @return void
	 */
	public function testTopCustomersByInvoicedAmount(): void {
		$this->assertSame(
			[['Gemeente Tiel', 'Invoiced this year: €1,000.00'], ['Bakkerij De Korf', 'Invoiced this year: €500.00']],
			$this->lines($this->widget(TopCustomersWidget::class, 'adm-1'))
		);
	}

	/**
	 * Without an administration every widget says so and shows no zero.
	 *
	 * @return void
	 */
	public function testEveryWidgetSaysSoWithoutAnAdministration(): void {
		foreach ([RevenueThisMonthWidget::class, OpenReceivablesWidget::class, CashPositionWidget::class, ResultByMonthWidget::class, TopCustomersWidget::class] as $class) {
			$items = $this->widget($class, null)->getItemsV2('director');
			$this->assertSame([], $items->getItems(), $class);
			$this->assertStringContainsString('no administration', $items->getEmptyContentMessage(), $class);
		}
	}

	/**
	 * Five distinct ids, each registered with Nextcloud.
	 *
	 * @return void
	 */
	public function testFiveWidgetsAreRegistered(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/AppInfo/DashboardWidgetRegistration.php');
		$ids = [];
		foreach ([RevenueThisMonthWidget::class, OpenReceivablesWidget::class, CashPositionWidget::class, ResultByMonthWidget::class, TopCustomersWidget::class] as $class) {
			$ids[] = $this->widget($class, 'adm-1')->getId();
			$short = substr($class, (int)strrpos($class, '\\') + 1);
			$this->assertStringContainsString('registerDashboardWidget(' . $short . '::class)', $source);
		}

		$this->assertCount(5, array_unique($ids));
	}
}
