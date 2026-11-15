<?php

/**
 * Contract of SlotService
 *
 * The public surface of SlotService that other components call, so they depend
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

/**
 * Contract of SlotService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SlotServiceInterface {

	/**
	 * Cache TTL in seconds per REQ-WSW-002.
	 */
	public const SLOT_CACHE_TTL_SECONDS = 300;

	/**
	 * Compute available slots for a service + resource on a given calendar date.
	 *
	 * The returned shape is `{slots: [...], etag: string, cached: bool}` so
	 * the controller can attach the ETag header and a 304 fast-path. The
	 * computation honours REQ-WSW-002:
	 *
	 *   1. Slots outside Resource opening/closing hours are excluded.
	 *   2. Slots starting in the past are excluded.
	 *   3. Slots that overlap a non-cancelled Appointment for the same
	 *      resource are excluded (unless the Resource allows overlap).
	 *
	 * @param string $serviceId Service identifier (logical key, not @self.id).
	 * @param string $resourceId Resource identifier (logical key, not @self.id).
	 * @param string $date Calendar date in UTC as YYYY-MM-DD.
	 *
	 * @return array{slots: array<int,array<string,string>>, etag: string, cached: bool}
	 *
	 * @spec openspec/specs/bookings-self-service-widget/spec.md#req-wsw-002
	 */
	public function getAvailableSlots(string $serviceId, string $resourceId, string $date): array;
}//end interface
