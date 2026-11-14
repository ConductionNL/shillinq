<?php

/**
 * The records an invoice draft and post save.
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
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-invoice-generation-service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Builds the BillableInvoice, BillableInvoiceLine and GL journal records of
 * InvoiceGenerationService, which builds it; it saves nothing itself.
 *
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-invoice-generation-service
 */
final class BillableInvoiceRecords {

	/**
	 * Construct the builder.
	 *
	 * @param VATCalculationService $vat VAT totaller.
	 */
	public function __construct(
		private readonly VATCalculationService $vat,
	) {
	}//end __construct()

	/**
	 * Clamp any per-line VAT rate to the four statutory Dutch rates
	 * (21 / 9 / 6 / 0) before totalling, so a stray rate from a fixture /
	 * migration doesn't slip through (REQ-ITE-009).
	 *
	 * @param array<int,array<string,mixed>> $lineDrafts The line drafts.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-invoice-generation-service
	 */
	public function withStatutoryVatRates(array $lineDrafts): array {
		foreach ($lineDrafts as $index => $line) {
			$rate = (float)($line['vatRate'] ?? BillingModelEngine::DEFAULT_VAT_RATE);
			if ($this->vat->isValidRate($rate) === false) {
				$lineDrafts[$index]['vatRate'] = BillingModelEngine::DEFAULT_VAT_RATE;
			}
		}

		return $lineDrafts;
	}//end withStatutoryVatRates()


	/**
	 * The draft BillableInvoice record, due the customer's payment term after
	 * the invoice date (sales-invoice-sending REQ-SIS-005).
	 *
	 * @param InvoiceDraft $draft The totalled draft.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-invoice-generation-service
	 * @spec openspec/changes/sales-invoice-sending/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-a-customer-carries-a-payment-term-and-a-delivery-method-req-sis-005
	 */
	public function invoicePayload(InvoiceDraft $draft): array {
		$request = $draft->request;
		$amounts = $this->amountsInEuros(totals: $draft->totals, currency: $draft->currency);

		return [
			'administrationId' => $request->administrationId,
			'invoiceNumber' => $draft->invoiceNumber,
			'billingModel' => $request->billingModel,
			'customerId' => $request->customerId,
			'projectId' => $request->projectId,
			'invoiceDate' => $request->toDate,
			'dueDate' => $this->addDays(isoDate: $request->toDate, days: $draft->paymentTermDays),
			'timeEntryIds' => $request->timeEntryIds,
			'expenseIds' => $request->expenseIds,
			'meterReadingIds' => $request->meterReadingIds,
			'rateCardId' => $request->rateCardId,
			'retainerScheduleId' => $request->retainerScheduleId,
			'lineItemsByModel' => $this->summariseLineItemsByModel(lineDrafts: $draft->lineDrafts),
			'summary' => $amounts + ['breakdown' => $this->breakdownInEuros(breakdown: $draft->totals['breakdown'])],
			'netAmount' => $amounts['netAmount'],
			'vatAmount' => $amounts['vatAmount'],
			'grossAmount' => $amounts['grossAmount'],
<<<<<<< HEAD
			'currency' => 'EUR',
			'paymentTerms' => 'net ' . $draft->paymentTermDays,
=======
			'currency' => $draft->currency,
			'paymentTerms' => 'net 30',
>>>>>>> origin/development
			'status' => 'draft',
			'posted' => false,
			'obligationId' => null,
			'notes' => $request->notes,
		];
	}//end invoicePayload()


	/**
	 * The BillableInvoiceLine record for one line draft.
	 *
	 * @param InvoiceDraft         $draft The saved draft.
	 * @param array<string,mixed> $line  One line draft.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-invoice-generation-service
	 */
	public function linePayload(InvoiceDraft $draft, array $line): array {
		$billableUnits = null;
		if (isset($line['billableUnits']) === true) {
			$billableUnits = (float)$line['billableUnits'];
		}

		$vatCents = $this->vat->vatOnNet(netCents: (int)$line['costAmountCents'], rate: (float)$line['vatRate']);

		return [
			'administrationId' => $draft->request->administrationId,
			'invoiceId' => $draft->invoiceId,
			'lineNumber' => (int)$line['lineNumber'],
			'sourceType' => (string)$line['sourceType'],
			'sourceId' => $line['sourceId'] ?? null,
			'description' => (string)$line['description'],
			'billableUnits' => $billableUnits,
			'rateApplied' => $line['rateApplied'] ?? null,
			'markup' => (float)($line['markup'] ?? 0),
			'costAmount' => round(((int)$line['costAmountCents']) / 100, 2),
			'vatRate' => (float)$line['vatRate'],
			'vatAmount' => round($vatCents / 100, 2),
			'modelSpecificFields' => $line['modelSpecificFields'] ?? null,
		];
	}//end linePayload()


	/**
	 * The GL journal of a posted invoice: debit AR, credit Revenue (by
	 * model), credit VAT Payable.
	 *
	 * @param array<string,mixed> $invoice Persisted BillableInvoice.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-invoice-generation-service
	 */
	public function journalFor(array $invoice): array {
		$grossCents     = $this->centsOf(invoice: $invoice, field: 'grossAmount');
		$netCents       = $this->centsOf(invoice: $invoice, field: 'netAmount');
		$vatCents       = $this->centsOf(invoice: $invoice, field: 'vatAmount');
		$revenueAccount = $this->revenueAccountFor(billingModel: (string)($invoice['billingModel'] ?? ''));

		return [
			'administrationId' => (string)($invoice['administrationId'] ?? ''),
			'description' => sprintf('Invoice %s', (string)($invoice['invoiceNumber'] ?? '')),
			'journalDate' => (string)($invoice['invoiceDate'] ?? ''),
			'isBalanced' => true,
			'invoiceId' => (string)($invoice['id'] ?? ($invoice['@self']['id'] ?? '')),
			'postings' => [
				['accountNumber' => '1130', 'debitCents' => $grossCents, 'creditCents' => 0, 'description' => 'Accounts Receivable'],
				['accountNumber' => $revenueAccount, 'debitCents' => 0, 'creditCents' => $netCents, 'description' => 'Revenue'],
				['accountNumber' => '1150', 'debitCents' => 0, 'creditCents' => $vatCents, 'description' => 'VAT Payable'],
			],
		];
	}//end journalFor()

	/**
	 * An invoice amount field in whole cents.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 * @param string              $field   The amount field.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-invoice-generation-service
	 */
	public function centsOf(array $invoice, string $field): int {
		return (int)round(((float)($invoice[$field] ?? 0)) * 100);
	}//end centsOf()


	/**
	 * Net, VAT and gross in the invoice currency's units, and the currency.
	 *
	 * @param array<string,mixed> $totals   The VAT totals in cents.
	 * @param string              $currency The invoice currency.
	 *
	 * @return array{netAmount: float, vatAmount: float, grossAmount: float, currency: string}
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-3.1
	 */
	private function amountsInEuros(array $totals, string $currency = 'EUR'): array {
		return [
			'netAmount' => round($totals['netCents'] / 100, 2),
			'vatAmount' => round($totals['vatCents'] / 100, 2),
			'grossAmount' => round($totals['grossCents'] / 100, 2),
			'currency' => $currency,
		];
	}//end amountsInEuros()


	/**
	 * Map billing model → revenue account number.
	 *
	 * @param string $billingModel Billing model.
	 *
	 * @return string GL account number.
	 */
	private function revenueAccountFor(string $billingModel): string {
		return match ($billingModel) {
			't_and_m' => '4100',
			'fixed_fee', 'milestone' => '4200',
			'retainer' => '4300',
			'mixed' => '4400',
			'usage' => '4500',
			default => '4000',
		};

	}//end revenueAccountFor()


	/**
	 * Summarise per-source-type counts + totals.
	 *
	 * @param array<int,array<string,mixed>> $lineDrafts Line item drafts.
	 *
	 * @return array<string,array{count:int,totalCents:int}>
	 */
	private function summariseLineItemsByModel(array $lineDrafts): array {
		$summary = [];
		foreach ($lineDrafts as $line) {
			$type = (string)($line['sourceType'] ?? 'manual');
			if (isset($summary[$type]) === false) {
				$summary[$type] = ['count' => 0, 'totalCents' => 0];
			}

			$summary[$type]['count']++;
			$summary[$type]['totalCents'] += (int)($line['costAmountCents'] ?? 0);
		}

		return $summary;
	}//end summariseLineItemsByModel()


	/**
	 * Format the per-rate breakdown in euros for the summary block.
	 *
	 * @param array<int,array{rate:float,netCents:int,vatCents:int,grossCents:int}> $breakdown Cents.
	 *
	 * @return array<int,array{rate:float,net:float,vat:float,gross:float}>
	 */
	private function breakdownInEuros(array $breakdown): array {
		$rows = [];
		foreach ($breakdown as $group) {
			$rows[] = [
				'rate' => (float)$group['rate'],
				'net' => round($group['netCents'] / 100, 2),
				'vat' => round($group['vatCents'] / 100, 2),
				'gross' => round($group['grossCents'] / 100, 2),
			];
		}

		return $rows;
	}//end breakdownInEuros()


	/**
	 * Add days to an ISO date.
	 *
	 * @param string $isoDate Source date.
	 * @param int $days Days to add.
	 *
	 * @return string New ISO date.
	 */
	private function addDays(string $isoDate, int $days): string {
		$ts = strtotime($isoDate);
		if ($ts === false) {
			return $isoDate;
		}

		return date('Y-m-d', ($ts + ($days * 86400)));
	}//end addDays()
}//end class
