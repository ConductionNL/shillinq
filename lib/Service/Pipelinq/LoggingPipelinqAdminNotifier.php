<?php

/**
 * Default PipelinqAdminNotifier binding — logs the alert at ERROR.
 *
 * Slice 08 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * The giant's auth-error decision requires an admin alert when the
 * pipelinq token is rejected (HTTP 401) — but the canonical NC
 * notification surface (cooldown / target-group selection / parameter
 * shape) lives in a later slice. Until that lands, the default binding
 * is a log-only implementation: each call emits one ERROR line that
 * names the *config location* of the bad token (NEVER its value, per
 * ADR-005). Operators monitoring the log stream can rotate the token
 * the same way they would for a manual notification.
 *
 * Slice 09+ swaps this binding for one that creates a real
 * {@see \OCP\Notification\INotification} for every administrator.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Pipelinq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-08-lifecycle-events/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Pipelinq;

use OCA\Shillinq\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * ERROR-level logging fallback for the admin-notifier port.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-08-lifecycle-events/tasks.md
 */
final class LoggingPipelinqAdminNotifier implements PipelinqAdminNotifier {
	/**
	 * Construct the logging fallback notifier.
	 *
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Emit one ERROR-level alert describing the rejected publish.
	 *
	 * The log entry references {@see PipelinqContactAdapter::CONFIG_KEY_TOKEN}
	 * so operators know *where* to rotate the token without us ever
	 * naming its value (ADR-005).
	 *
	 * @param TimelineEventDto $event The event whose publish was rejected.
	 *
	 * @return void
	 */
	public function notifyAuthFailure(TimelineEventDto $event): void {
		$this->logger->error(
			'Invalid pipelinq API token; check config',
			[
				'app' => Application::APP_ID,
				'configKey' => PipelinqContactAdapter::CONFIG_KEY_TOKEN,
				'type' => $event->type(),
				'externalId' => $event->externalId(),
				'contactId' => $event->contactId(),
			]
		);

	}//end notifyAuthFailure()
}//end class
