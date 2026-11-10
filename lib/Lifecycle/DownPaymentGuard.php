<?php

/**
 * Shillinq DownPaymentGuard
 *
 * The down-payment check and stamp on ARInvoice.issue (sales-down-payments
 * REQ-SDP-004), declared twice on that transition: `step: check` before the
 * posting action, `step: stamp` after it.
 *
 * Why an action and not a `requires` guard
 * ----------------------------------------
 * design.md D5 named a guard, `DownPaymentGuard::requireOpenDeductions`. The
 * transition's single `requires` slot is taken by
 * RuleComplianceGuard::validateInvoice (add-shillinq-rule-compliance-guard.json),
 * and a guard answers true or false, so it cannot name the invoice that already
 * deducted a down payment, which the spec scenario requires. A lifecycle action
 * that throws aborts the transition with its message, and the executor runs
 * the actions in the declared order, so the check runs before anything is
 * booked and the stamp only after the booking succeeded.
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
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Service\Sales\DownPaymentRefusedException;
use OCA\Shillinq\Service\Sales\DownPaymentService;
use RuntimeException;

/**
 * Checks and stamps the down payments a final invoice deducts.
 *
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 */
class DownPaymentGuard implements LifecycleActionInterface {
	/**
	 * Constructor.
	 *
	 * @param DownPaymentService $downPayments The down-payment rules.
	 */
	public function __construct(
		private readonly DownPaymentService $downPayments,
	) {

	}//end __construct()

	/**
	 * Run the declared step on the invoice being issued.
	 *
	 * @param LifecycleActionContext $context The transition; its objectData is the invoice after its state moved. Its
	 *                                        parameters are the declared `step`: check or stamp.
	 *
	 * @return array<string,mixed> The invoice, unchanged.
	 *
	 * @throws RuntimeException When the invoice may not be issued, or the step is unknown.
	 *
	 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
	 */
	public function execute(LifecycleActionContext $context): array {
		$objectData = $context->objectData;
		$parameters = $context->parameters;

		$step = (string)($parameters['step'] ?? '');
		if ($step === 'check') {
			$this->requireOpenDeductions(invoice: $objectData);
			return $objectData;
		}

		if ($step === 'stamp') {
			$this->downPayments->stampDeductions(invoice: $objectData);
			return $objectData;
		}

		throw new RuntimeException(sprintf('DownPaymentGuard has no step "%s"; declare check or stamp.', $step));

	}//end execute()

	/**
	 * Refuse a deduction already taken elsewhere, or deductions above the total.
	 *
	 * @param array<string,mixed> $invoice The invoice being issued.
	 *
	 * @return void
	 *
	 * @throws RuntimeException With the refusal as its message.
	 *
	 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
	 */
	public function requireOpenDeductions(array $invoice): void {
		try {
			$this->downPayments->requireOpenDeductions(invoice: $invoice);
		} catch (DownPaymentRefusedException $e) {
			throw new RuntimeException(message: $e->getMessage(), previous: $e);
		}

	}//end requireOpenDeductions()
}//end class
