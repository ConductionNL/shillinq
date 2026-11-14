<?php

/**
 * Bindings of the Kernel contracts to their Reporting implementations
 *
 * @category  Reporting
 * @package   OCA\Shillinq\Reporting
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract wiring, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting;

use OCA\Shillinq\Kernel\Contracts\ReportGenerationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ReportRecipientPolicyInterface;
use OCA\Shillinq\Kernel\Contracts\ScheduledReportDeliveryInterface;
use OCA\Shillinq\Kernel\Contracts\ScheduledReportRunnerInterface;
use OCA\Shillinq\Kernel\Contracts\XafAuditfileGeneratorInterface;
use OCA\Shillinq\Reporting\Generator\XafAuditfileGenerator;
use OCA\Shillinq\Reporting\Schedule\ReportRecipientPolicy;
use OCA\Shillinq\Reporting\Schedule\ScheduledReportDelivery;
use OCA\Shillinq\Reporting\Schedule\ScheduledReportRunner;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Shillinq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec exclude Structural contract wiring, no functional requirement.
 */
final class ReportingContractRegistrar {

	/**
	 * Alias every contract to its implementation.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec exclude Structural contract wiring, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(ReportGenerationServiceInterface::class, ReportGenerationService::class);
		$context->registerServiceAlias(ReportRecipientPolicyInterface::class, ReportRecipientPolicy::class);
		$context->registerServiceAlias(ScheduledReportDeliveryInterface::class, ScheduledReportDelivery::class);
		$context->registerServiceAlias(ScheduledReportRunnerInterface::class, ScheduledReportRunner::class);
		$context->registerServiceAlias(XafAuditfileGeneratorInterface::class, XafAuditfileGenerator::class);
	}//end register()
}//end class
