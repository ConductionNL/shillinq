<?php

/**
 * Payroll Event
 *
 * The CloudEvent id, the payroll and the data of one signed payroll webhook.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

/**
 * One decoded payroll CloudEvent.
 *
 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
 */
final class PayrollEvent {
	/**
	 * The CloudEvent id ('' when missing).
	 *
	 * @var string
	 */
	public readonly string $eventId;

	/**
	 * The event data ([] when it is not an object).
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $data;

	/**
	 * The payroll the event is about ('' when missing).
	 *
	 * @var string
	 */
	public readonly string $payrollId;

	/**
	 * Read a decoded CloudEvent.
	 *
	 * @param array<string, mixed> $event The decoded event.
	 */
	public function __construct(array $event) {
		$data = [];
		if (is_array($event['data'] ?? null) === true) {
			$data = $event['data'];
		}

		$this->eventId = (string)($event['id'] ?? '');
		$this->data = $data;
		$this->payrollId = (string)($data['payrollId'] ?? '');

	}//end __construct()

	/**
	 * Whether the event names its id and its payroll.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
	 */
	public function isComplete(): bool {
		return $this->eventId !== '' && $this->payrollId !== '';
	}//end isComplete()

	/**
	 * The external deductions the event carries ([] when none).
	 *
	 * @return array<int|string, mixed>
	 *
	 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
	 */
	public function deductions(): array {
		if (is_array($this->data['deductions'] ?? null) === true) {
			return $this->data['deductions'];
		}

		return [];
	}//end deductions()
}//end class
