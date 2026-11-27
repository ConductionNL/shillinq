<?php

/**
 * Contract of WidgetCorsPolicy
 *
 * The public surface of WidgetCorsPolicy that other components call, so they depend
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
 * Contract of WidgetCorsPolicy.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WidgetCorsPolicyInterface {

	/**
	 * The origin to echo in Access-Control-Allow-Origin, or null when the business's key does not allow it.
	 *
	 * @param string $businessId The widget's business id.
	 * @param string $origin     The request's Origin header.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-bookings-messages-and-embedding/specs/bookings-self-service-widget/spec.md#requirement-the-widget-books-from-another-website-req-wsw-011
	 */
	public function allowedOrigin(string $businessId, string $origin): ?string;

	/**
	 * Replace the origins of a business's active key; every origin must be valid.
	 *
	 * @param string            $businessId The widget's business id.
	 * @param array<int, mixed> $origins    The origins.
	 * @param string            $actor      The administrator making the change.
	 *
	 * @return array{success: bool, message?: string, allowedOrigins?: array<int, string>}
	 *
	 * @spec openspec/changes/archive/2026-11-24-bookings-messages-and-embedding/tasks.md#task-1.5
	 */
	public function setAllowedOrigins(string $businessId, array $origins, string $actor): array;

}//end interface
