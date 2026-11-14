<?php

/**
 * Hand To Accounts Payable Action
 *
 * The declared action of SupplierInvoice.bookWithoutOrder. It writes one
 * APTransaction for the invoice (amounts from cents to euros, each line on
 * its expense account), runs its receive and issue transitions so the AP
 * sub-ledger posts it to the ledger, links the two records (the transaction
 * carries the invoice uuid as supplierInvoiceId), and writes the
 * AP transaction payment blocked when the invoice's IBAN differs from the
 * supplier record (purchasing-supplier-invoice-intake design.md D1, D4).
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Kernel\Contracts\SupplierInvoiceChecksInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use RuntimeException;

/**
 * Writes and issues the APTransaction of a supplier invoice booked without an order.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md
 */
class HandToAccountsPayableAction implements LifecycleActionInterface {

	/**
	 * The payment block reason an IBAN mismatch writes.
	 *
	 * @var string
	 */
	public const IBAN_BLOCK_REASON = 'IBAN on invoice differs from supplier record';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param RegisterLocationInterface        $settings Where the register lives (slug + OpenRegister availability).
	 * @param SupplierInvoiceChecksInterface  $checks        Payee lookup and the IBAN rule.
	 * @param ObjectTransitionRunnerInterface $transitions   Runs the AP transaction's declared transitions.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocationInterface $settings,
		private readonly SupplierInvoiceChecksInterface $checks,
		private readonly ObjectTransitionRunnerInterface $transitions,
	) {

	}//end __construct()

	/**
	 * Hand the invoice to accounts payable, once.
	 *
	 * @param array<string, mixed> $objectData   The SupplierInvoice as the transition leaves it.
	 * @param array<string, mixed> $previousData The SupplierInvoice before.
	 * @param array<string, mixed> $parameters   Declared parameters (none).
	 * @param string               $actionName   The action name.
	 *
	 * @return array<string, mixed> The invoice with apTransactionId set.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-3.3
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		if ((string)($objectData['apTransactionId'] ?? '') !== '') {
			return $objectData;
		}

		$payee = $this->checks->payee(supplierId: (string)($objectData['supplierId'] ?? ''));
		if ($payee === null) {
			throw new RuntimeException('The invoice cannot be booked: its supplier is not recognised.');
		}

		$transaction = $this->apTransaction(invoice: $objectData, payee: $payee);
		$transaction['supplierInvoiceId'] = (string)($objectData['id'] ?? '');
		$saved = $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema('APTransaction')->saveObject(object: $transaction);
		$apId = ObjectIdentifier::resolve(saved: $saved);
		if ($apId === '') {
			throw new RuntimeException('The AP transaction for the invoice could not be written.');
		}

		$this->transitions->run(objectId: $apId, action: 'receive');
		$this->transitions->run(objectId: $apId, action: 'issue');

		$objectData['apTransactionId'] = $apId;
		return $objectData;

	}//end execute()

	/**
	 * The APTransaction for a supplier invoice.
	 *
	 * @param array<string, mixed> $invoice The SupplierInvoice (amounts in cents).
	 * @param array<string, mixed> $payee   Its payee.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-3.3
	 */
	public function apTransaction(array $invoice, array $payee): array {
		$lines = self::apLines(invoice: $invoice, payee: $payee);

		$invoiceDate = (string)($invoice['invoiceDate'] ?? '');
		$transaction = [
			'invoiceNumber' => (string)($invoice['invoiceNumber'] ?? ''),
			'vendorId' => (string)($payee['id'] ?? ''),
			'invoiceDate' => $invoiceDate,
			'dueDate' => $this->dueDate(invoice: $invoice, payee: $payee),
			'currency' => (string)($invoice['currency'] ?? 'EUR'),
			'totalAmount' => self::euros(cents: ($invoice['totalInclVat'] ?? 0)),
			'taxAmount' => self::euros(cents: ($invoice['totalVat'] ?? 0)),
			'lines' => $lines,
			'state' => 'draft',
			'administrationId' => (string)($invoice['administrationId'] ?? ''),
		];

		return self::withSourceAndPaymentBlock(transaction: $transaction, invoice: $invoice);

	}//end apTransaction()

	/**
	 * The transaction lines: one per invoice line on its account (else the payee's default), or one for the net.
	 *
	 * @param array<string, mixed> $invoice The supplier invoice.
	 * @param array<string, mixed> $payee   The payee.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function apLines(array $invoice, array $payee): array {
		$default = trim((string)($payee['defaultExpenseAccountNumber'] ?? ''));
		$lines   = [];
		foreach ((array)($invoice['lines'] ?? []) as $line) {
			$lines[] = [
				'description' => (string)($line['description'] ?? ($invoice['invoiceNumber'] ?? '')),
				'accountNumber' => self::lineAccount(line: $line, default: $default),
				'amount' => self::euros(cents: ($line['lineExtension'] ?? 0)),
			];
		}

		$net = self::euros(cents: ($invoice['totalExclVat'] ?? 0));
		if ($lines === []) {
			$lines[] = ['description' => (string)($invoice['invoiceNumber'] ?? ''), 'accountNumber' => $default, 'amount' => $net];
		}

		return $lines;

	}//end apLines()

	/**
	 * The line's own account, else the payee's default.
	 *
	 * @param mixed  $line    The invoice line.
	 * @param string $default The payee's default expense account.
	 *
	 * @return string
	 */
	private static function lineAccount(mixed $line, string $default): string {
		$account = trim((string)($line['accountNumber'] ?? ''));
		if ($account === '') {
			$account = $default;
		}

		return $account;

	}//end lineAccount()

	/**
	 * Carry the UBL source document, and block payment when the IBAN does not match.
	 *
	 * @param array<string, mixed> $transaction The AP transaction.
	 * @param array<string, mixed> $invoice     The supplier invoice.
	 *
	 * @return array<string, mixed>
	 */
	private static function withSourceAndPaymentBlock(array $transaction, array $invoice): array {
		$source = trim((string)($invoice['ublSourceUri'] ?? ''));
		if ($source !== '') {
			$transaction['sourceDocumentUri'] = $source;
		}

		if (trim((string)($invoice['ibanMismatch'] ?? '')) !== '') {
			$transaction['paymentBlocked']     = true;
			$transaction['paymentBlockReason'] = self::IBAN_BLOCK_REASON;
		}

		return $transaction;

	}//end withSourceAndPaymentBlock()

	/**
	 * The invoice's due date, else its date plus the payee's payment term.
	 *
	 * @param array<string, mixed> $invoice The SupplierInvoice.
	 * @param array<string, mixed> $payee   Its payee.
	 *
	 * @return string
	 */
	private function dueDate(array $invoice, array $payee): string {
		$due = trim((string)($invoice['dueDate'] ?? ''));
		if ($due !== '') {
			return $due;
		}

		$date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($invoice['invoiceDate'] ?? ''));
		if ($date === false) {
			throw new RuntimeException('The invoice cannot be booked: it has no invoice date.');
		}

		return $date->modify('+' . (int)($payee['paymentTermDays'] ?? 30) . ' days')->format('Y-m-d');

	}//end dueDate()

	/**
	 * Integer cents as euros.
	 *
	 * @param mixed $cents The cents.
	 *
	 * @return float
	 */
	private static function euros(mixed $cents): float {
		return round(((int)$cents) / 100, 2);

	}//end euros()
}//end class
