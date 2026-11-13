<?php

/**
 * Contract of TimelineEventDto
 *
 * The public surface of TimelineEventDto that other components call, so they depend
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
 * Contract of TimelineEventDto.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TimelineEventDtoInterface {

	/**
	 * Event type for a freshly-created booking.
	 *
	 * @var string
	 */
	public const TYPE_BOOKING_CREATED = 'booking.created';

	/**
	 * Event type for a booking transitioning into `confirmed`.
	 *
	 * Slice 08 of the customer-bridge chain — pushed when the booking
	 * state machine transitions from any prior state into `confirmed`.
	 *
	 * @var string
	 */
	public const TYPE_BOOKING_CONFIRMED = 'booking.confirmed';

	/**
	 * Event type for a booking transitioning into `cancelled`.
	 *
	 * Slice 08 of the customer-bridge chain — pushed when the booking
	 * is cancelled. The originating appointment row's `cancellationReason`
	 * is forwarded into the timeline event metadata (when present).
	 *
	 * @var string
	 */
	public const TYPE_BOOKING_CANCELLED = 'booking.cancelled';

	/**
	 * Event type for a booking transitioning into `completed`.
	 *
	 * Slice 08 of the customer-bridge chain — pushed when the booking
	 * is marked complete (e.g. the appointment finished, work-done /
	 * payment-received transition).
	 *
	 * @var string
	 */
	public const TYPE_BOOKING_COMPLETED = 'booking.completed';

	/**
	 * Linked pipelinq Contact id accessor.
	 *
	 * @return string
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function contactId(): string;

	/**
	 * External id accessor (the booking's stable id).
	 *
	 * @return string
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function externalId(): string;

	/**
	 * Event type accessor.
	 *
	 * @return string
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function type(): string;
}//end interface
