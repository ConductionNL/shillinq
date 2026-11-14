<?php

/**
 * Payroll Event Run
 *
 * Applying one payroll event: the register, the event and the payroll it found.
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
 * The state of applying one payroll event.
 *
 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
 */
final class PayrollEventRun {
	/**
	 * The register slug.
	 *
	 * @var string
	 */
	public readonly string $register;

	/**
	 * The event.
	 *
	 * @var PayrollEvent
	 */
	public readonly PayrollEvent $event;

	/**
	 * The payroll the event is about, once found.
	 *
	 * @var array<string, mixed>
	 */
	public array $payroll = [];

	/**
	 * Start applying an event in a register.
	 *
	 * @param string       $register The register slug.
	 * @param PayrollEvent $event    The event.
	 */
	public function __construct(string $register, PayrollEvent $event) {
		$this->register = $register;
		$this->event = $event;

	}//end __construct()

	/**
	 * Whether the event was already applied to the payroll (idempotency).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
	 */
	public function isReplay(): bool {
		return (string)($this->payroll['externalReference'] ?? '') === $this->event->eventId;
	}//end isReplay()

	/**
	 * The payroll's administration.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
	 */
	public function administrationId(): string {
		return (string)($this->payroll['administrationId'] ?? '');
	}//end administrationId()

	/**
	 * The tax year of the payroll's period.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
	 */
	public function taxYear(): int {
		return (int)substr((string)($this->payroll['period'] ?? ''), 0, 4);
	}//end taxYear()
}//end class
