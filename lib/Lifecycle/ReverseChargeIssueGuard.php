<?php

/**
 * Shillinq ReverseChargeIssueGuard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Refuses to issue a reverse-charged invoice that names no buyer VAT number
 * (tax-icp-and-reverse-charge REQ-ICR-001). Declared as a lifecycle action on
 * ARInvoice.issue before the posting action, for the reason DownPaymentGuard
 * gives: the transition's one `requires` slot is taken, and a refusal has to
 * say what is missing. An action that throws aborts the transition with its
 * message before anything is booked.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#1-reverse-charge-on-the-invoice
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Service\Tax\ReverseChargeNotice;
use RuntimeException;

/**
 * The buyer VAT number check on issuing a reverse-charged invoice.
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#1-reverse-charge-on-the-invoice
 */
class ReverseChargeIssueGuard implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param ReverseChargeNotice $notice Tells a reverse-charged invoice and its buyer VAT number.
	 */
	public function __construct(
		private readonly ReverseChargeNotice $notice = new ReverseChargeNotice(),
	) {
	}//end __construct()

	/**
	 * Refuse the issue when the invoice is reverse-charged without a buyer VAT number.
	 *
	 * @param array<string,mixed> $objectData   The invoice after its state moved.
	 * @param array<string,mixed> $previousData The invoice before the transition.
	 * @param array<string,mixed> $parameters   None.
	 * @param string              $actionName   The declared action name.
	 *
	 * @return array<string,mixed> The invoice, unchanged.
	 *
	 * @throws RuntimeException Naming the missing buyer VAT number.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#1-reverse-charge-on-the-invoice
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		if ($this->notice->lacksBuyerVatNumber(invoice: $objectData) === true) {
			throw new RuntimeException(ReverseChargeNotice::MISSING_VAT_NUMBER);
		}

		return $objectData;
	}//end execute()
}//end class
