<?php

/**
 * Credit Limit Run
 *
 * The customer, administration, register and invoice one credit-limit check
 * of an ARInvoice issue works on.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-accounts-receivable-core/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

/**
 * One credit-limit check: what it reads and which invoices count toward it.
 *
 * @spec openspec/changes/bookkeeping-accounts-receivable-core/specs.md
 */
final class CreditLimitRun {
	/**
	 * ARInvoice records read per page.
	 *
	 * @var integer
	 */
	public const PAGE_SIZE = 500;

	/**
	 * Statuses that count toward a customer's outstanding receivable.
	 *
	 * Paid and written-off invoices are excluded — they no longer consume
	 * credit. Mirrors the ARInvoice outstandingByCustomer aggregation filter.
	 */
	private const OUTSTANDING_STATUSES = ['draft', 'issued', 'overdue', 'disputed'];

	/**
	 * The configured register slug.
	 *
	 * @var string
	 */
	public readonly string $registerSlug;

	/**
	 * The customer FK.
	 *
	 * @var string
	 */
	public readonly string $customerNumber;

	/**
	 * The administration scope (multi-tenant).
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The invoice being issued, excluded from the outstanding sum ('' excludes nothing).
	 *
	 * @var string
	 */
	public readonly string $invoiceNumber;

	/**
	 * The invoice being issued, in integer cents.
	 *
	 * @var integer
	 */
	public readonly int $totalCents;

	/**
	 * Start a check for an invoice.
	 *
	 * @param array<string, mixed> $invoice      ARInvoice object array.
	 * @param string               $registerSlug The configured register slug.
	 */
	public function __construct(array $invoice, string $registerSlug) {
		$this->registerSlug = $registerSlug;
		$this->customerNumber = (string)($invoice['customerNumber'] ?? '');
		$this->administrationId = (string)($invoice['administrationId'] ?? '');
		$this->invoiceNumber = (string)($invoice['invoiceNumber'] ?? '');
		$this->totalCents = (int)($invoice['totalCents'] ?? 0);

	}//end __construct()

	/**
	 * The CustomerMaster query for the customer in the administration.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/bookkeeping-accounts-receivable-core/specs.md
	 */
	public function customerQuery(): array {
		return [
			'filters' => $this->customerFilters(),
			'limit' => 1,
		];
	}//end customerQuery()

	/**
	 * The ARInvoice query for one page of the customer's invoices in the administration.
	 *
	 * @param integer $page The 1-based page.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/bookkeeping-accounts-receivable-core/specs.md
	 */
	public function invoicePageQuery(int $page): array {
		return [
			'filters' => $this->customerFilters(),
			'limit' => self::PAGE_SIZE,
			'offset' => (($page - 1) * self::PAGE_SIZE),
		];
	}//end invoicePageQuery()

	/**
	 * Whether an invoice counts toward the outstanding receivable: an
	 * outstanding status, and not the invoice being issued (so a re-issue or
	 * idempotent retry doesn't double-count itself).
	 *
	 * @param mixed $line An ARInvoice record.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-accounts-receivable-core/specs.md
	 */
	public function countsTowardOutstanding(mixed $line): bool {
		$status = (string)($line['status'] ?? '');
		if (in_array($status, self::OUTSTANDING_STATUSES, true) === false) {
			return false;
		}

		return ((string)($line['invoiceNumber'] ?? '') === $this->invoiceNumber && $this->invoiceNumber !== '') === false;
	}//end countsTowardOutstanding()

	/**
	 * The customer and administration filters.
	 *
	 * @return array<string, string>
	 */
	private function customerFilters(): array {
		return [
			'customerNumber' => $this->customerNumber,
			'administrationId' => $this->administrationId,
		];
	}//end customerFilters()
}//end class
