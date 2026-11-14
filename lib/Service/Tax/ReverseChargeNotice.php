<?php

/**
 * Shillinq ReverseChargeNotice
 *
 * Whether a sales invoice is reverse-charged, and what it then has to say:
 * the reverse-charge notice, the buyer's VAT number and the UBL tax category
 * (K for an intra-EU supply, AE for a domestic reverse charge) with its
 * exemption reason. The invoice PDF, the UBL document, the ICP overlay and
 * the issue guard all read it here, so they cannot disagree
 * (tax-icp-and-reverse-charge design D1).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Tax
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#1-reverse-charge-on-the-invoice
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Tax;

/**
 * The reverse-charge state of one sales invoice.
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#1-reverse-charge-on-the-invoice
 */
class ReverseChargeNotice {

	/**
	 * The notice, as its translation key (English source; Dutch "Btw verlegd").
	 */
	public const NOTICE = 'VAT reverse-charged';

	/**
	 * The notice as a Dutch invoice prints it.
	 */
	public const NOTICE_NL = 'Btw verlegd';

	/**
	 * The refusal when a reverse-charged invoice names no buyer VAT number.
	 */
	public const MISSING_VAT_NUMBER = "The buyer's VAT number is required for reverse charge.";

	/**
	 * The reverse-charge categories (UNTDID 5305) with their EN 16931
	 * exemption reason code and text (BR-IC-10, BR-AE-10). K comes first: an
	 * invoice with an intra-EU line is an intra-EU supply.
	 *
	 * @var array<string, array{code: string, reason: string}>
	 */
	public const CATEGORIES = [
		'K' => ['code' => 'VATEX-EU-IC', 'reason' => 'Intra-Community supply'],
		'AE' => ['code' => 'VATEX-EU-AE', 'reason' => 'Reverse charge'],
	];

	/**
	 * The notice of an invoice, or null when no line is reverse-charged.
	 *
	 * @param array<string,mixed> $invoice  The ARInvoice record.
	 * @param array<string,mixed> $customer The buyer, when the caller has it.
	 *
	 * @return array{category: string, buyerVatNumber: string, notice: string, noticeNl: string,
	 *               exemptionReasonCode: string, exemptionReason: string}|null
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#1-reverse-charge-on-the-invoice
	 */
	public function forInvoice(array $invoice, array $customer = []): ?array {
		$category = $this->categoryOf(invoice: $invoice);
		if ($category === null) {
			return null;
		}

		return [
			'category' => $category,
			'buyerVatNumber' => $this->buyerVatNumber(invoice: $invoice, customer: $customer),
			'notice' => self::NOTICE,
			'noticeNl' => self::NOTICE_NL,
			'exemptionReasonCode' => self::CATEGORIES[$category]['code'],
			'exemptionReason' => self::CATEGORIES[$category]['reason'],
		];
	}//end forInvoice()

	/**
	 * The reverse-charge category of an invoice: K when any line or VAT group
	 * is an intra-EU supply, else AE when any is a domestic reverse charge,
	 * else null.
	 *
	 * @param array<string,mixed> $invoice The ARInvoice record.
	 *
	 * @return string|null K, AE or null.
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#1-reverse-charge-on-the-invoice
	 */
	public function categoryOf(array $invoice): ?string {
		$found = [];
		foreach ((array)($invoice['invoiceLines'] ?? []) as $line) {
			if (is_array($line) === true) {
				$found[] = strtoupper(trim((string)($line['vatCategory'] ?? '')));
			}
		}

		foreach ((array)($invoice['vatBreakdown'] ?? []) as $group) {
			if (is_array($group) === true) {
				$found[] = strtoupper(trim((string)($group['category'] ?? '')));
			}
		}

		foreach (array_keys(self::CATEGORIES) as $category) {
			if (in_array($category, $found, true) === true) {
				return $category;
			}
		}

		return null;
	}//end categoryOf()

	/**
	 * Whether an invoice is reverse-charged and names no buyer VAT number.
	 *
	 * @param array<string,mixed> $invoice  The ARInvoice record.
	 * @param array<string,mixed> $customer The buyer, when the caller has it.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#1-reverse-charge-on-the-invoice
	 */
	public function lacksBuyerVatNumber(array $invoice, array $customer = []): bool {
		return $this->categoryOf(invoice: $invoice) !== null
			&& $this->buyerVatNumber(invoice: $invoice, customer: $customer) === '';
	}//end lacksBuyerVatNumber()

	/**
	 * The buyer's VAT number: the one on the invoice, else the customer's
	 * canonical `vatId`, else its legacy `vatNumber`.
	 *
	 * @param array<string,mixed> $invoice  The ARInvoice record.
	 * @param array<string,mixed> $customer The buyer.
	 *
	 * @return string The number, '' when none is known.
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#1-reverse-charge-on-the-invoice
	 */
	public function buyerVatNumber(array $invoice, array $customer = []): string {
		foreach ([($invoice['buyerVatId'] ?? null), ($customer['vatId'] ?? null), ($customer['vatNumber'] ?? null)] as $candidate) {
			$number = trim((string)$candidate);
			if ($number !== '') {
				return $number;
			}
		}

		return '';
	}//end buyerVatNumber()
}//end class
