<?php

/**
 * ICP supplies read from posted invoices
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Icp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Icp;

use OCA\Shillinq\Service\Tax\ReverseChargeNotice;

/**
 * Turns posted sales invoices into ICP supply rows, the shape of an
 * `IcpSupply` record, so the ICP statement is prepared from the books
 * (REQ-ICR-002).
 *
 * An invoice counts when it is posted (it has a ledger transaction, or its
 * lifecycle state is past draft) and it is an intra-community supply: its
 * ICP overlay says so, or a line or VAT group carries category K. A domestic
 * reverse charge (AE) is not an ICP supply. Side-effect free.
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.1
 */
class IcpInvoiceSupplies {

	/**
	 * Lifecycle states of an invoice that has been booked.
	 *
	 * @var array<int,string>
	 */
	public const POSTED_STATES = ['issued', 'paid', 'overdue', 'disputed', 'written-off'];

	/**
	 * UNTDID 1001 code of a credit note.
	 *
	 * @var string
	 */
	private const CREDIT_NOTE_CODE = '381';

	/**
	 * Construct the reader.
	 *
	 * @param ReverseChargeNotice $notice The reverse-charge reading of an invoice.
	 */
	public function __construct(
		private readonly ReverseChargeNotice $notice=new ReverseChargeNotice(),
	) {
	}//end __construct()

	/**
	 * The ICP supplies of the posted invoices among the given ones.
	 *
	 * @param array<int,array<string,mixed>> $invoices         ARInvoice records.
	 * @param string                         $administrationId The administration they belong to.
	 *
	 * @return array<int,array<string,mixed>> One IcpSupply-shaped row per invoice that is an ICP supply.
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.1
	 */
	public function fromInvoices(array $invoices, string $administrationId): array {
		$supplies = [];
		foreach ($invoices as $invoice) {
			if (is_array($invoice) === false || $this->isPosted(invoice: $invoice) === false
				|| $this->isIcpSupply(invoice: $invoice) === false
			) {
				continue;
			}

			$buyer = strtoupper(str_replace(' ', '', $this->notice->buyerVatNumber(invoice: $invoice)));
			$supplies[] = [
				'invoiceId' => $this->invoiceKey(invoice: $invoice),
				'supplyDate' => $this->supplyDate(invoice: $invoice),
				'buyerVatId' => $buyer,
				'buyerCountry' => $this->buyerCountry(invoice: $invoice, buyerVatId: $buyer),
				'supplyType' => $this->supplyType(invoice: $invoice),
				'amountExclVat' => $this->amount(invoice: $invoice),
				'currency' => (string)($invoice['currency'] ?? 'EUR'),
				'viesValidationId' => ($invoice['icpContext']['viesValidationId'] ?? null),
				'administrationId' => $administrationId,
				'source' => 'invoice',
			];
		}//end foreach

		return $supplies;
	}//end fromInvoices()

	/**
	 * The keys an IcpSupply may use to name this invoice: its number, its id
	 * and its slug.
	 *
	 * @param array<string,mixed> $invoice The ARInvoice record.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.1
	 */
	public function keysOf(array $invoice): array {
		$keys = [];
		foreach ([
			($invoice['invoiceNumber'] ?? null),
			($invoice['@self']['id'] ?? null),
			($invoice['@self']['slug'] ?? null),
			($invoice['id'] ?? null),
		] as $candidate) {
			$key = trim((string)$candidate);
			if ($key !== '') {
				$keys[] = $key;
			}
		}

		return array_values(array_unique($keys));
	}//end keysOf()

	/**
	 * Whether the invoice has been booked.
	 *
	 * @param array<string,mixed> $invoice The ARInvoice record.
	 *
	 * @return bool
	 */
	private function isPosted(array $invoice): bool {
		if (trim((string)($invoice['glTransactionId'] ?? '')) !== '') {
			return true;
		}

		return in_array((string)($invoice['lifecycleState'] ?? 'draft'), self::POSTED_STATES, true);
	}//end isPosted()

	/**
	 * Whether the invoice is an intra-community supply.
	 *
	 * @param array<string,mixed> $invoice The ARInvoice record.
	 *
	 * @return bool
	 */
	private function isIcpSupply(array $invoice): bool {
		$context = $invoice['icpContext'] ?? null;
		if (is_array($context) === true && ($context['treatAsIcp'] ?? false) === true) {
			return true;
		}

		return $this->notice->categoryOf(invoice: $invoice) === 'K';
	}//end isIcpSupply()

	/**
	 * The invoice's own key: its number, else its id.
	 *
	 * @param array<string,mixed> $invoice The ARInvoice record.
	 *
	 * @return string
	 */
	private function invoiceKey(array $invoice): string {
		return ($this->keysOf(invoice: $invoice)[0] ?? '');
	}//end invoiceKey()

	/**
	 * The time of supply: the supply date, else the tax point, else the
	 * invoice date (YYYY-MM-DD).
	 *
	 * @param array<string,mixed> $invoice The ARInvoice record.
	 *
	 * @return string
	 */
	private function supplyDate(array $invoice): string {
		foreach (['supplyDate', 'taxPointDate', 'invoiceDate'] as $field) {
			$date = substr(trim((string)($invoice[$field] ?? '')), 0, 10);
			if ($date !== '') {
				return $date;
			}
		}

		return '';
	}//end supplyDate()

	/**
	 * The buyer's country: the invoice's, else the prefix of the VAT number.
	 *
	 * @param array<string,mixed> $invoice    The ARInvoice record.
	 * @param string              $buyerVatId The buyer's VAT number.
	 *
	 * @return string
	 */
	private function buyerCountry(array $invoice, string $buyerVatId): string {
		$country = strtoupper(trim((string)($invoice['buyerCountryCode'] ?? '')));
		if ($country !== '') {
			return $country;
		}

		if (preg_match('/^([A-Z]{2})/', $buyerVatId, $match) === 1) {
			return $match[1];
		}

		return '';
	}//end buyerCountry()

	/**
	 * The ICP supply code: the overlay's, else T for a triangulation, L for
	 * goods, S for any other declared supply, L when nothing says.
	 *
	 * @param array<string,mixed> $invoice The ARInvoice record.
	 *
	 * @return string
	 */
	private function supplyType(array $invoice): string {
		$context = $invoice['icpContext'] ?? [];
		if (is_array($context) === false) {
			$context = [];
		}

		$declared = strtoupper(trim((string)($context['supplyType'] ?? '')));
		if (in_array($declared, ['L', 'S', 'T'], true) === true) {
			return $declared;
		}

		if (($context['triangulation'] ?? false) === true || ($invoice['triangulation'] ?? false) === true) {
			return 'T';
		}

		$nature = trim((string)($invoice['supplyType'] ?? ''));
		if ($nature === '' || $nature === 'goods') {
			return 'L';
		}

		return 'S';
	}//end supplyType()

	/**
	 * The amount excluding VAT of the category-K lines, else of the K VAT
	 * groups, else the invoice's net amount; negative on a credit note.
	 *
	 * @param array<string,mixed> $invoice The ARInvoice record.
	 *
	 * @return float
	 */
	private function amount(array $invoice): float {
		$cents = $this->categoryCents(rows: (array)($invoice['invoiceLines'] ?? []), categoryKey: 'vatCategory', amountKey: 'netAmount');
		if ($cents === null) {
			$cents = $this->categoryCents(rows: (array)($invoice['vatBreakdown'] ?? []), categoryKey: 'category', amountKey: 'taxableAmount');
		}

		if ($cents === null) {
			$cents = (int)round((float)($invoice['netAmount'] ?? 0) * 100);
		}

		if ((string)($invoice['invoiceTypeCode'] ?? '') === self::CREDIT_NOTE_CODE) {
			$cents = -abs($cents);
		}

		return ($cents / 100);
	}//end amount()

	/**
	 * The sum in cents of the rows of category K, or null when none is K.
	 *
	 * @param array<int|string,mixed> $rows        Lines or VAT groups.
	 * @param string                  $categoryKey The field holding the category.
	 * @param string                  $amountKey   The field holding the amount.
	 *
	 * @return integer|null
	 */
	private function categoryCents(array $rows, string $categoryKey, string $amountKey): ?int {
		$cents = null;
		foreach ($rows as $row) {
			if (is_array($row) === false || strtoupper(trim((string)($row[$categoryKey] ?? ''))) !== 'K') {
				continue;
			}

			$cents = (($cents ?? 0) + (int)round((float)($row[$amountKey] ?? 0) * 100));
		}

		return $cents;
	}//end categoryCents()
}//end class
