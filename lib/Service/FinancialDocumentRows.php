<?php

/**
 * Financial Document Rows
 *
 * Turns a quote, invoice, credit note, payment request or payout into the one
 * row shape the financial documents leaf answers with, and blanks the parts of
 * a row the caller may not read. The payment state uses the words dossiq's
 * case already uses: none, outstanding, paid, waived.
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
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The row shape of the financial documents leaf.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
 */
final class FinancialDocumentRows {
	/**
	 * Per schema: the number, status, amount, issued and due fields.
	 *
	 * @var array<string, array{number: string, status: string, amount: string|null, issuedOn: string, dueOn: string}>
	 */
	private const FIELDS = [
		'Quote' => ['number' => 'quoteNumber', 'status' => 'status', 'amount' => null, 'issuedOn' => 'acceptedAt', 'dueOn' => 'validUntil'],
		'ARInvoice' => ['number' => 'invoiceNumber', 'status' => 'lifecycleState', 'amount' => 'grossAmount', 'issuedOn' => 'invoiceDate', 'dueOn' => 'dueDate'],
		'PaymentRequest' => ['number' => 'paymentReference', 'status' => 'state', 'amount' => 'amount', 'issuedOn' => 'requestedAt', 'dueOn' => 'dueAt'],
		'InboundPayable' => ['number' => 'reference', 'status' => 'state', 'amount' => 'amount', 'issuedOn' => 'requestedAt', 'dueOn' => 'dueOn'],
	];

	/**
	 * Per kind: status => payment state. A status not listed reads `outstanding`.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const PAYMENT_STATES = [
		'quote' => [],
		'credit-note' => [],
		'invoice' => ['draft' => 'none', 'paid' => 'paid', 'written-off' => 'waived', 'declined' => 'none'],
		'payment-request' => [
			'captured' => 'paid',
			'captured_unapplied' => 'paid',
			'voided' => 'none',
			'expired' => 'none',
			'refunded' => 'none',
			'credited' => 'none',
		],
		'payout' => ['paid' => 'paid', 'rejected' => 'none', 'cancelled' => 'none'],
	];

	/**
	 * The page path of a kind that has one in shillinq, with `{id}` for the document.
	 *
	 * @var array<string, string>
	 */
	private const PATHS = [
		'invoice' => '/apps/shillinq/bookkeeping/accounts-receivable/{id}',
		'credit-note' => '/apps/shillinq/bookkeeping/accounts-receivable/{id}',
	];

	/**
	 * The kind of a document read from a schema.
	 *
	 * @param string $schema The schema it was read from.
	 * @param array<string, mixed> $document The document.
	 *
	 * @return string One of quote, invoice, credit-note, payment-request, payout.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function kindOf(string $schema, array $document): string {
		$kinds = ['Quote' => 'quote', 'PaymentRequest' => 'payment-request', 'InboundPayable' => 'payout'];
		if (isset($kinds[$schema]) === true) {
			return $kinds[$schema];
		}

		if ((string)($document['invoiceType'] ?? '') === 'credit-note') {
			return 'credit-note';
		}

		return 'invoice';
	}//end kindOf()

	/**
	 * The row for one document.
	 *
	 * @param string $schema The schema it was read from.
	 * @param array<string, mixed> $document The document.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function row(string $schema, array $document): array {
		$fields = self::FIELDS[$schema];
		$kind = $this->kindOf(schema: $schema, document: $document);
		$status = (string)($document[$fields['status']] ?? '');
		$amount = null;
		if ($fields['amount'] !== null && is_numeric($document[$fields['amount']] ?? null) === true) {
			$amount = (float)$document[$fields['amount']];
		}

		$paymentState = $this->paymentState(kind: $kind, status: $status);
		$id = (string)$document['id'];

		return [
			'id' => $id,
			'kind' => $kind,
			'number' => (string)($document[$fields['number']] ?? ''),
			'status' => $status,
			'amount' => $amount,
			'currency' => (string)($document['currency'] ?? ''),
			'issuedOn' => substr((string)($document[$fields['issuedOn']] ?? ''), 0, 10),
			'dueOn' => substr((string)($document[$fields['dueOn']] ?? ''), 0, 10),
			'openAmount' => $this->openAmount(document: $document, amount: $amount, paymentState: $paymentState),
			'paymentState' => $paymentState,
			'path' => str_replace('{id}', rawurlencode($id), (self::PATHS[$kind] ?? '')),
			'restricted' => false,
		];
	}//end row()

	/**
	 * A row with everything but its kind, status and payment state blanked.
	 *
	 * @param array<string, mixed> $row The full row.
	 *
	 * @return array<string, mixed> The restricted row.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function restricted(array $row): array {
		return array_merge(
			$row,
			['number' => '', 'amount' => null, 'currency' => '', 'issuedOn' => '', 'dueOn' => '', 'openAmount' => null, 'path' => '', 'restricted' => true]
		);
	}//end restricted()

	/**
	 * The payment state a kind's status reads as.
	 *
	 * @param string $kind The kind.
	 * @param string $status The document's status.
	 *
	 * @return string none, outstanding, paid or waived.
	 */
	private function paymentState(string $kind, string $status): string {
		if ($kind === 'quote' || $kind === 'credit-note') {
			return 'none';
		}

		return (self::PAYMENT_STATES[$kind][$status] ?? 'outstanding');
	}//end paymentState()

	/**
	 * What is still owed on an outstanding document.
	 *
	 * @param array<string, mixed> $document The document.
	 * @param float|null $amount The document's amount.
	 * @param string $paymentState Its payment state.
	 *
	 * @return float|null The open amount, or null when nothing is owed.
	 */
	private function openAmount(array $document, ?float $amount, string $paymentState): ?float {
		if ($paymentState !== 'outstanding') {
			return null;
		}

		if (is_numeric($document['amountDue'] ?? null) === true) {
			return (float)$document['amountDue'];
		}

		return $amount;
	}//end openAmount()
}//end class
