<?php

/**
 * Reporting Registration
 *
 * Registers the reporting listeners and services. GLLineResultStampListener
 * handles the two events that change a ledger line's result stamps: a
 * GLTransaction transition (posted, reversed) and the creation of a GLLine
 * under an already posted transaction (reporting-segment-results
 * REQ-RSR-002). Kept out of Application so that class stays under its
 * length limit.
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
 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Listener\GLLineResultStampListener;
use OCA\Shillinq\Notification\ScheduledReportNotifier;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the reporting listeners and services.
 *
 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
 */
final class ReportingRegistration {

	/**
	 * Register the listener for both events.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: GLLineResultStampListener::class
		);
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: GLLineResultStampListener::class
		);
		// Reporting-data-delivery: tells recipients a scheduled report is ready.
		$context->registerNotifierService(ScheduledReportNotifier::class);

	}//end register()
}//end class
