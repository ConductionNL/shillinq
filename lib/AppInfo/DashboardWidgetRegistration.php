<?php

/**
 * Dashboard Widget Registration
 *
 * Registers shillinq's financial figures as Nextcloud dashboard widgets
 * (reporting-custom-analysis REQ-RCA-001), so a user can put them on the
 * Nextcloud dashboard or on a launchpad dashboard of their own.
 *
 * @category AppInfo
 * @package  OCA\Shillinq\AppInfo
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

namespace OCA\Shillinq\AppInfo;

use OCA\Shillinq\Dashboard\CashPositionWidget;
use OCA\Shillinq\Dashboard\OpenReceivablesWidget;
use OCA\Shillinq\Dashboard\ResultByMonthWidget;
use OCA\Shillinq\Dashboard\RevenueThisMonthWidget;
use OCA\Shillinq\Dashboard\TopCustomersWidget;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the five financial widgets.
 *
 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
 */
final class DashboardWidgetRegistration {

	/**
	 * Register the widgets.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(RevenueThisMonthWidget::class);
		$context->registerDashboardWidget(OpenReceivablesWidget::class);
		$context->registerDashboardWidget(CashPositionWidget::class);
		$context->registerDashboardWidget(ResultByMonthWidget::class);
		$context->registerDashboardWidget(TopCustomersWidget::class);

	}//end register()
}//end class
