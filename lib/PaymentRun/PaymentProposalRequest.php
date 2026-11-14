<?php

/**
 * One PaymentRunProposalService::propose request.
 *
 * @category PaymentRun
 * @package  OCA\Shillinq\PaymentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\PaymentRun;

/**
 * The administration, due cut-off, debtor account, execution date and the
 * line-date mode of a payment-run proposal.
 *
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 */
final class PaymentProposalRequest {

	/**
	 * Construct the request.
	 *
	 * @param string $administrationId The administration.
	 * @param string $dueOnOrBefore The last due date to include.
	 * @param string $debtorAccountIban The paying account.
	 * @param string $executionDate The run's execution date.
	 * @param string $lineDates DATES_RUN or DATES_DUE.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly string $dueOnOrBefore,
		public readonly string $debtorAccountIban,
		public readonly string $executionDate,
		public readonly string $lineDates,
	) {

	}//end __construct()
}//end class
