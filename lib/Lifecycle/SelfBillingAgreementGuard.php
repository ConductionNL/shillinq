<?php

/**
 * Shillinq SelfBillingAgreementGuard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `requireSelfBillingAgreement` on ARInvoice.issue (sales-einvoice-exchange
 * REQ-SEIX-007): a self-billed invoice is issued only while the customer's
 * self-billing agreement covers its date, and never after it was rejected.
 * Declared as a lifecycle action before the posting action, for the reason
 * DownPaymentGuard gives: the transition's one `requires` slot is taken, and a
 * guard answering false cannot say why. A throwing action aborts the transition
 * with its message, so nothing is booked.
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
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.4
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Service\EInvoice\SelfBilledInvoiceReview;
use RuntimeException;

/**
 * Refuses to issue a self-billed invoice its agreement does not cover.
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.4
 */
class SelfBillingAgreementGuard implements LifecycleActionInterface {
	/**
	 * Constructor.
	 *
	 * @param SelfBilledInvoiceReview $review The self-billing rules.
	 */
	public function __construct(
		private readonly SelfBilledInvoiceReview $review,
	) {
	}//end __construct()

	/**
	 * Check the invoice being issued.
	 *
	 * @param array<string,mixed> $objectData   The invoice after its state moved.
	 * @param array<string,mixed> $previousData The invoice before the transition.
	 * @param array<string,mixed> $parameters   Unused.
	 * @param string              $actionName   The declared action name.
	 *
	 * @return array<string,mixed> The invoice, unchanged.
	 *
	 * @throws RuntimeException When the invoice may not be issued.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.4
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$this->review->requireSelfBillingAgreement(invoice: $objectData);

		return $objectData;
	}//end execute()
}//end class
