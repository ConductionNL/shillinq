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
 * @spec openspec/changes/archive/2026-11-24-sales-einvoice-exchange/tasks.md#task-3.4
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Kernel\Contracts\SelfBilledInvoiceReviewInterface;
use RuntimeException;

/**
 * Refuses to issue a self-billed invoice its agreement does not cover.
 *
 * @spec openspec/changes/archive/2026-11-24-sales-einvoice-exchange/tasks.md#task-3.4
 */
class SelfBillingAgreementGuard implements LifecycleActionInterface {
	/**
	 * Constructor.
	 *
	 * @param SelfBilledInvoiceReviewInterface $review The self-billing rules.
	 */
	public function __construct(
		private readonly SelfBilledInvoiceReviewInterface $review,
	) {
	}//end __construct()

	/**
	 * Check the invoice being issued.
	 *
	 * @param LifecycleActionContext $context The transition; its objectData is the invoice after its state moved.
	 *
	 * @return array<string,mixed> The invoice, unchanged.
	 *
	 * @throws RuntimeException When the invoice may not be issued.
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-einvoice-exchange/tasks.md#task-3.4
	 */
	public function execute(LifecycleActionContext $context): array {
		$objectData = $context->objectData;
		$this->review->requireSelfBillingAgreement(invoice: $objectData);

		return $objectData;
	}//end execute()
}//end class
