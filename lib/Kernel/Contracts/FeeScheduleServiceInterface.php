<?php

/**
 * Contract of FeeScheduleService
 *
 * The public surface of FeeScheduleService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

use InvalidArgumentException;

/**
 * Contract of FeeScheduleService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FeeScheduleServiceInterface {

	/**
	 * Refuse a schedule whose validity window overlaps an existing one for the
	 * same tuple and channel, naming the schedule it collides with.
	 *
	 * @param array<string, mixed> $schedule The schedule about to be written.
	 * @param array<int, array<string, mixed>>|null $existing Schedules already stored, or null to read them.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the window overlaps, or the schedule names no window.
	 *
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
	 */
	public function assertNoOverlap(array $schedule, ?array $existing = null): void;

	/**
	 * The citation as an administrator reads it beside the amount
	 * (REQ-FPCR-001).
	 *
	 * @param array<string, mixed> $schedule The schedule.
	 *
	 * @return string The citation, or an empty string when there is none.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function citation(array $schedule): string;

	/**
	 * The schedule that applies to an object the caller already has in hand.
	 *
	 * The leaf knows a register, a schema and an object; it does not know which
	 * property on that schema carries the type, because that is the schedule's
	 * own declaration. So the schedules for the register are read first and each
	 * one is asked which property it looks at, rather than the caller guessing a
	 * property name that would silently resolve nothing.
	 *
	 * @param string $register The object's register slug.
	 * @param string $schema The object's schema slug.
	 * @param array<string, mixed> $object The object itself.
	 * @param string $intakeChannel Where the application came in, or an empty string.
	 * @param string $onDate The day to resolve for, as Y-m-d; today when empty.
	 *
	 * @return array<string, mixed>|null The schedule with its amount resolved, or null.
	 *
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-008)
	 */
	public function resolveForObject(
		string $register,
		string $schema,
		array $object,
		string $intakeChannel = '',
		string $onDate = '',
	): ?array;
}//end interface
