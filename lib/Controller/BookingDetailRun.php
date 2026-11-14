<?php

/**
 * Booking Detail Run
 *
 * One booking-detail response being enriched with its pipelinq customer.
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
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-05-detail-controller-inject/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

/**
 * The booking, its pipelinq contact, the history paging and the payload
 * being built.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-05-detail-controller-inject/tasks.md
 */
final class BookingDetailRun {
	/**
	 * History page size.
	 *
	 * @var integer
	 */
	public int $limit = 0;

	/**
	 * History offset.
	 *
	 * @var integer
	 */
	public int $offset = 0;

	/**
	 * The response payload.
	 *
	 * @var array<string,mixed>
	 */
	public array $payload = [];

	/**
	 * Start the run.
	 *
	 * @param string $bookingId The booking.
	 * @param string $contactId Its pipelinq contact.
	 */
	public function __construct(
		public readonly string $bookingId,
		public readonly string $contactId,
	) {
	}//end __construct()
}//end class
