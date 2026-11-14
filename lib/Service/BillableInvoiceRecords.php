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

use OCA\Shillinq\Service\Tax\VatTariffRefusedException;

/**
 * Builds the BillableInvoice, BillableInvoiceLine and GL journal records of
 * InvoiceGenerationService, which builds it; it saves nothing itself.
 *
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-invoice-generation-service
 */
final class BillableInvoiceRecords {

	/**
	 * The seeded tariff a line without a code is taxed at, by its rate.
	 *
	 * @var array<string, float>
	 */
	private const RATE_TARIFFS = ['high' => 21.0, 'low' => 9.0, 'zero' => 0.0];

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
	 * The tariff each line is taxed at, resolved on the invoice date
	 * (tax-vat-rates-and-deductibility REQ-TVRD-002): a line's own
	 * `vatTariff` code, else the seeded tariff its rate stands for (21 high,
	 * 9 low, 0 zero). Each line gets its `vatTariff`, the `documentDate` and
	 * the `vatRate` of the tariff valid on that date. A rate with no tariff,
	 * and a code that is unknown or not valid on the date, refuse the whole
	 * invoice; no default rate is put in its place.
	 *
	 * @param array<int,array<string,mixed>> $lineDrafts   The line drafts.
	 * @param string                         $documentDate The invoice date (Y-m-d).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws VatTariffRefusedException When a line has no tariff valid on the date.
	 *
	 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-2.2
	 */
	public function withVatTariffs(array $lineDrafts, string $documentDate): array {
		foreach ($lineDrafts as $index => $line) {
			$code = trim((string)($line['vatTariff'] ?? ''));
			if ($code === '') {
				$code = self::tariffForRate(line: $line, documentDate: $documentDate);
			}

			$line['vatTariff']    = $code;
			$line['documentDate'] = $documentDate;
			$line['vatRate']      = $this->vat->lineRate(line: $line);
			$lineDrafts[$index]   = $line;
		}

		return $lineDrafts;
	}//end withVatTariffs()

	/**
	 * The tariff code a line was taxed at, or null for a line without one.
	 *
	 * @param array<string,mixed> $line The line draft.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-2.2
	 */
	private static function tariffOf(array $line): ?string {
		$code = trim((string)($line['vatTariff'] ?? ''));
		if ($code === '') {
			return null;
		}

		return $code;
	}//end tariffOf()

	/**
	 * The seeded tariff code a line's numeric rate stands for.
	 *
	 * @param array<string,mixed> $line         The line draft.
	 * @param string              $documentDate The invoice date, named in a refusal.
	 *
	 * @return string
	 *
	 * @throws VatTariffRefusedException When no seeded tariff has that rate.
	 *
	 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-2.2
	 */
	private static function tariffForRate(array $line, string $documentDate): string {
		$rate = (float)($line['vatRate'] ?? BillingModelEngine::DEFAULT_VAT_RATE);
		foreach (self::RATE_TARIFFS as $code => $tariffRate) {
			if ($rate === $tariffRate) {
				return $code;
			}
		}

		$shown = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%';
		throw new VatTariffRefusedException(tariffCode: $shown, date: $documentDate, why: 'unknown');
	}//end tariffForRate()


	/**
	 * The draft BillableInvoice record, due 30 days after the invoice date.
	 *
	 * @param InvoiceDraft $draft The totalled draft.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-invoice-generation-service
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
			'dueDate' => $this->addDays(isoDate: $request->toDate, days: 30),
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
			'currency' => $draft->currency,
			'paymentTerms' => 'net 30',
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
			'vatTariff' => self::tariffOf(line: $line),
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
