<?php

/**
 * Shillinq AssignInvoiceNumberAction
 *
 * Handler declared first on the ARInvoice `issue` transition. It gives the
 * invoice the next number of its administration's series for the invoice's
 * fiscal year (InvoiceNumberSequenceService, under a lock), so a draft carries
 * no number and a deleted draft leaves no gap (REQ-SIIC-001).
 *
 * A self-billed invoice, drawn up by the customer, keeps the customer's number
 * and does not advance the seller's series. Any other number a draft carries
 * is provisional (the quick draft's `DRAFT-...`, a number typed before
 * numbering moved to issue) and is replaced by the series number.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use DateTimeImmutable;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Service\Sales\InvoiceNumberSequenceService;

/**
 * Numbers an AR invoice when it is issued.
 *
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.2
 */
class AssignInvoiceNumberAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param InvoiceNumberSequenceService $sequences The series of each administration.
	 */
	public function __construct(
		private readonly InvoiceNumberSequenceService $sequences,
	) {
	}//end __construct()

	/**
	 * Whether issuing this invoice takes a number from the series.
	 *
	 * @param array<string,mixed> $invoice The AR invoice.
	 *
	 * @return bool True for every invoice the seller drew up; false for a self-billed one.
	 *
	 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.2
	 */
	public static function takesNumber(array $invoice): bool {
		return self::isSelfBilled(invoice: $invoice) === false;
	}//end takesNumber()

	/**
	 * Number the issuing invoice.
	 *
	 * @param array<string,mixed> $objectData   The invoice after its state moved to issued.
	 * @param array<string,mixed> $previousData The invoice before the transition.
	 * @param array<string,mixed> $parameters   The declared actionParameters (none).
	 * @param string              $actionName   The declared action name.
	 *
	 * @return array<string,mixed> The invoice with its number.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 *
	 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.2
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		if (self::takesNumber(invoice: $objectData) === false) {
			return $objectData;
		}

		$objectData['invoiceNumber'] = $this->sequences->next(
			administrationId: (string)($objectData['administrationId'] ?? ''),
			kind: $this->kindOf(invoice: $objectData),
			year: $this->yearOf(invoice: $objectData)
		);

		return $objectData;
	}//end execute()

	/**
	 * Whether the customer drew up this invoice.
	 *
	 * @param array<string,mixed> $invoice The AR invoice.
	 *
	 * @return bool
	 */
	private static function isSelfBilled(array $invoice): bool {
		$flag = ($invoice['selfBilled'] ?? false);

		return $flag === true || $flag === 1 || $flag === '1' || $flag === 'true';
	}//end isSelfBilled()

	/**
	 * The series of the invoice: credit notes have their own.
	 *
	 * @param array<string,mixed> $invoice The AR invoice.
	 *
	 * @return string The kind.
	 */
	private function kindOf(array $invoice): string {
		if ((string)($invoice['invoiceType'] ?? '') === 'credit-note') {
			return InvoiceNumberSequenceService::KIND_CREDIT;
		}

		return InvoiceNumberSequenceService::KIND_SALES;
	}//end kindOf()

	/**
	 * The fiscal year of the invoice: the year of its invoice date, else this year.
	 *
	 * @param array<string,mixed> $invoice The AR invoice.
	 *
	 * @return int The year.
	 */
	private function yearOf(array $invoice): int {
		$date = trim((string)($invoice['invoiceDate'] ?? ''));
		if (preg_match('/^(\d{4})-\d{2}-\d{2}/', $date, $match) === 1) {
			return (int)$match[1];
		}

		return (int)(new DateTimeImmutable())->format('Y');
	}//end yearOf()
}//end class
