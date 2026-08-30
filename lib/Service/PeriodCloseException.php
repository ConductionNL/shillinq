<?php

/**
 * Period Close Exception
 *
 * Domain exception raised by PeriodCloseService when a lifecycle action is
 * forbidden, the target record is missing, the period is in the wrong state, or
 * input validation fails. Carries a stable status sentinel
 * (PeriodCloseService::ERR_*) so the controller can map it to the right HTTP
 * status without leaking a stack trace to the client (ADR-005).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Carries a stable status sentinel for HTTP mapping in the controller.
 *
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-7
 */
class PeriodCloseException extends \RuntimeException {

	/**
	 * Stable status sentinel (PeriodCloseService::ERR_*).
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Construct the exception with a client-safe message and status sentinel.
	 *
	 * @param string $message Client-safe message (no stack trace, no internals).
	 * @param string $status Status sentinel from PeriodCloseService::ERR_*.
	 */
	public function __construct(string $message, string $status) {
		parent::__construct(message: $message);
		$this->status = $status;

	}//end __construct()

	/**
	 * Return the status sentinel for HTTP mapping.
	 *
	 * @return string The status sentinel.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-7
	 */
	public function getStatus(): string {
		return $this->status;
	}//end getStatus()
}//end class
