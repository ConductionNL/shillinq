<?php

/**
 * Recurring invoice period
 *
 * One billing period a recurring invoice profile generates an ARInvoice for:
 * the profile, its billing-period key, the period start, issue and due dates,
 * the document language and the invoice's number inside its profile and
 * period.
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
 * @spec openspec/specs/recurring-invoicing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The period an ARInvoice is generated for from a recurring profile.
 *
 * @spec openspec/specs/recurring-invoicing/spec.md
 */
final class RecurringInvoicePeriod {
	/**
	 * The billing-period key.
	 *
	 * @var string
	 */
	public string $billingPeriod = '';

	/**
	 * Period start date (Y-m-d).
	 *
	 * @var string
	 */
	public string $periodStart = '';

	/**
	 * Invoice issue date (Y-m-d).
	 *
	 * @var string
	 */
	public string $issueDate = '';

	/**
	 * Invoice due date (Y-m-d).
	 *
	 * @var string
	 */
	public string $dueDate = '';

	/**
	 * Document language for tokens.
	 *
	 * @var string
	 */
	public string $language = '';

	/**
	 * The invoice's number inside its profile and period, from 1.
	 *
	 * @var int
	 */
	public int $sequence = 1;

	/**
	 * Start a period for a profile.
	 *
	 * @param array<string,mixed> $profile   The RecurringInvoiceProfile.
	 * @param string              $profileId The profile id.
	 */
	public function __construct(
		public readonly array $profile,
		public readonly string $profileId,
	) {
	}//end __construct()
}//end class
