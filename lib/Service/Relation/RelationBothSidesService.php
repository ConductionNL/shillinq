<?php

/**
 * Relation Both Sides Service
 *
 * Every invoice sent to and received from a linked relation, with invoiced
 * sales, invoiced purchases, open receivable, open payable and the net
 * position (reporting-relation-both-sides REQ-RRBS-002, REQ-RRBS-003).
 *
 * Sent invoices are ARInvoice rows of the customer. Received invoices are the
 * supplier's APTransaction rows, plus its SupplierInvoice rows that have not
 * been handed to accounts payable yet (one with an apTransactionId is already
 * counted as its APTransaction). A credit note counts negative on its side.
 * Drafts and cancelled documents do not count.
 *
 * Which side a caller sees is decided by the caller, not here: the
 * controller passes the sides the caller's role may read (REQ-RRBS-004), and
 * a side left out comes back as `restricted`, with no rows and no amounts.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Relation;

use DomainException;

/**
 * Totals both sides of a linked relation.
 *
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
 */
class RelationBothSidesService {

	/**
	 * Sales invoice states that count as invoiced; the first three are still open.
	 */
	public const SENT_STATES = ['issued', 'overdue', 'disputed', 'paid', 'written-off'];

	/**
	 * Sales invoice states still open.
	 */
	public const SENT_OPEN = ['issued', 'overdue', 'disputed'];

	/**
	 * Purchase transaction states that count as invoiced.
	 */
	public const RECEIVED_STATES = ['received', 'issued', 'partially-paid', 'overdue', 'disputed', 'paid', 'written-off'];

	/**
	 * Purchase transaction states still open.
	 */
	public const RECEIVED_OPEN = ['received', 'issued', 'partially-paid', 'overdue', 'disputed'];

	/**
	 * Supplier invoice states that are not yet paid or rejected.
	 */
	public const INTAKE_OPEN = ['received', 'matching', 'matched', 'exception', 'approved'];

	/**
	 * The most rows one side lists.
	 */
	public const MAX_ROWS = 50;

	/**
	 * Constructor.
	 *
	 * @param RelationRecords $records The administration-scoped reads.
	 */
	public function __construct(
		private readonly RelationRecords $records,
	) {

	}//end __construct()

	/**
	 * Both sides of one customer's relation.
	 *
	 * @param string                                   $administrationId The administration.
	 * @param string                                   $customerId       The customer.
	 * @param string                                   $from             First invoice date, YYYY-MM-DD.
	 * @param string                                   $to               Last invoice date, YYYY-MM-DD.
	 * @param array{sent: bool, received: bool}        $sides            The sides the caller may read.
	 *
	 * @return array<string, mixed> customer, payee, link, sent, received, totals.
	 *
	 * @throws DomainException When the customer is not in the administration.
	 *
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function forCustomer(string $administrationId, string $customerId, string $from, string $to, array $sides): array {
		$customer = $this->records->one(schema: 'CustomerMaster', administrationId: $administrationId, id: $customerId);
		if ($customer === null) {
			throw new DomainException('Not found');
		}

		return $this->relation(administrationId: $administrationId, customer: $customer, range: [$from, $to], sides: $sides, withRows: true);

	}//end forCustomer()

	/**
	 * The customer linked to a supplier, for the supplier's page.
	 *
	 * @param string $administrationId The administration.
	 * @param string $payeeId          The supplier.
	 *
	 * @return string|null The customer id, or null when the supplier is not linked.
	 *
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function customerOfPayee(string $administrationId, string $payeeId): ?string {
		if ($payeeId === '') {
			return null;
		}

		$customers = $this->records->rows(schema: 'CustomerMaster', administrationId: $administrationId, filters: ['payeeId' => $payeeId]);

		return ($customers[0]['id'] ?? null);

	}//end customerOfPayee()

	/**
	 * Every linked relation of an administration with its totals.
	 *
	 * @param string                            $administrationId The administration.
	 * @param string                            $from             First invoice date.
	 * @param string                            $to               Last invoice date.
	 * @param array{sent: bool, received: bool} $sides            The sides the caller may read.
	 *
	 * @return array<string, mixed> relations (one row each), restricted sides.
	 *
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function forAdministration(string $administrationId, string $from, string $to, array $sides): array {
		$relations = [];
		foreach ($this->records->rows(schema: 'CustomerMaster', administrationId: $administrationId) as $customer) {
			if ((string)($customer['payeeId'] ?? '') === '') {
				continue;
			}

			$relation    = $this->relation(administrationId: $administrationId, customer: $customer, range: [$from, $to], sides: $sides, withRows: false);
			$relations[] = array_merge(
				['id' => $relation['customer']['id'], 'customerId' => $relation['customer']['id'], 'name' => $relation['customer']['name'], 'payeeId' => $relation['payee']['id']],
				$relation['totals']
			);
		}

		usort($relations, static fn (array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));

		return [
			'relations'  => $relations,
			'restricted' => ['sent' => ($sides['sent'] === false), 'received' => ($sides['received'] === false)],
		];

	}//end forAdministration()

	/**
	 * The report as CSV: one row per relation with the five amounts.
	 *
	 * @param array<int, array<string, mixed>> $relations forAdministration()['relations'].
	 *
	 * @return string The CSV text.
	 *
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function toCsv(array $relations): string {
		$columns = ['name', 'sales', 'purchases', 'openReceivable', 'openPayable', 'net'];
		$lines   = [implode(',', $columns)];
		foreach ($relations as $relation) {
			$cells = [];
			foreach ($columns as $column) {
				$value   = ($relation[$column] ?? null);
				$cells[] = $this->csvCell(value: $value);
			}

			$lines[] = implode(',', $cells);
		}

		return implode("\n", $lines) . "\n";

	}//end toCsv()

	/**
	 * One CSV cell: amounts with two decimals, text quoted when needed, a restricted amount empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The cell.
	 */
	private function csvCell(mixed $value): string {
		if ($value === null) {
			return '';
		}

		if (is_float($value) === true || is_int($value) === true) {
			return number_format((float)$value, 2, '.', '');
		}

		$text = (string)$value;
		if (preg_match('/[",\n]/', $text) === 1) {
			return '"' . str_replace('"', '""', $text) . '"';
		}

		return $text;

	}//end csvCell()

	/**
	 * Both sides of one relation.
	 *
	 * @param string                            $administrationId The administration.
	 * @param array<string, mixed>              $customer         The customer record.
	 * @param array{0: string, 1: string}       $range            First and last invoice date.
	 * @param array{sent: bool, received: bool} $sides            The sides the caller may read.
	 * @param bool                              $withRows         Whether to list the invoices.
	 *
	 * @return array<string, mixed> The relation.
	 */
	private function relation(string $administrationId, array $customer, array $range, array $sides, bool $withRows): array {
		$payeeId = (string)($customer['payeeId'] ?? '');
		$payee   = $this->records->one(schema: 'Payee', administrationId: $administrationId, id: $payeeId);

		$sent = ['restricted' => true, 'rows' => [], 'invoiced' => null, 'open' => null];
		if ($sides['sent'] === true) {
			$sent = $this->side(rows: $this->sentRows(administrationId: $administrationId, customer: $customer, range: $range));
		}

		$received = ['restricted' => true, 'rows' => [], 'invoiced' => null, 'open' => null];
		if ($sides['received'] === true) {
			$received = $this->side(rows: $this->receivedRows(administrationId: $administrationId, payee: $payee, range: $range));
		}

		$net = null;
		if ($sent['open'] !== null && $received['open'] !== null) {
			$net = round($sent['open'] - $received['open'], 2);
		}

		if ($withRows === false) {
			$sent['rows']     = [];
			$received['rows'] = [];
		}

		return [
			'customer' => ['id' => (string)$customer['id'], 'name' => (string)($customer['legalName'] ?? $customer['tradeName'] ?? '')],
			'payee'    => ['id' => $payeeId, 'name' => (string)($payee['name'] ?? $payee['tradingName'] ?? '')],
			'link'     => ($customer['payeeLink'] ?? null),
			'sent'     => $sent,
			'received' => $received,
			'totals'   => [
				'sales'          => $sent['invoiced'],
				'purchases'      => $received['invoiced'],
				'openReceivable' => $sent['open'],
				'openPayable'    => $received['open'],
				'net'            => $net,
			],
		];

	}//end relation()

	/**
	 * One side's totals and its first MAX_ROWS rows, newest first.
	 *
	 * @param array<int, array<string, mixed>> $rows The side's rows (number, date, state, amount, open).
	 *
	 * @return array<string, mixed> restricted, rows, invoiced, open, more.
	 */
	private function side(array $rows): array {
		usort($rows, static fn (array $a, array $b): int => strcmp((string)$b['date'], (string)$a['date']));

		return [
			'restricted' => false,
			'rows'       => array_slice($rows, 0, self::MAX_ROWS),
			'more'       => (count($rows) > self::MAX_ROWS),
			'invoiced'   => round(array_sum(array_column($rows, 'amount')), 2),
			'open'       => round(array_sum(array_column($rows, 'open')), 2),
		];

	}//end side()

	/**
	 * The customer's sales invoices in the range.
	 *
	 * @param string                      $administrationId The administration.
	 * @param array<string, mixed>        $customer         The customer.
	 * @param array{0: string, 1: string} $range            The dates.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function sentRows(string $administrationId, array $customer, array $range): array {
		$keys = array_unique(array_filter([(string)$customer['id'], (string)($customer['customerId'] ?? '')]));
		$rows = [];
		foreach ($keys as $key) {
			foreach ($this->records->rows(schema: 'ARInvoice', administrationId: $administrationId, filters: ['customerId' => $key]) as $invoice) {
				$state = (string)($invoice['lifecycleState'] ?? '');
				if (in_array($state, self::SENT_STATES, true) === false || $this->inRange(date: $invoice['invoiceDate'] ?? null, range: $range) === false) {
					continue;
				}

				$amount = $this->signed(amount: (float)($invoice['grossAmount'] ?? 0), credit: ($invoice['invoiceType'] ?? '') === 'credit-note');
				$open   = 0.0;
				if (in_array($state, self::SENT_OPEN, true) === true) {
					$open = $amount;
					if (isset($invoice['amountDue']) === true && is_numeric($invoice['amountDue']) === true) {
						$open = $this->signed(amount: (float)$invoice['amountDue'], credit: ($invoice['invoiceType'] ?? '') === 'credit-note');
					}
				}

				$rows[$invoice['id']] = ['id' => $invoice['id'], 'schema' => 'ARInvoice', 'number' => (string)($invoice['invoiceNumber'] ?? ''), 'date' => (string)($invoice['invoiceDate'] ?? ''), 'state' => $state, 'amount' => $amount, 'open' => $open];
			}
		}

		return array_values($rows);

	}//end sentRows()

	/**
	 * The supplier's purchase invoices in the range.
	 *
	 * @param string                      $administrationId The administration.
	 * @param array<string, mixed>|null   $payee            The supplier, null when it is gone.
	 * @param array{0: string, 1: string} $range            The dates.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function receivedRows(string $administrationId, ?array $payee, array $range): array {
		if ($payee === null) {
			return [];
		}

		$rows = [];
		foreach ($this->records->rows(schema: 'APTransaction', administrationId: $administrationId, filters: ['vendorId' => $payee['id']]) as $transaction) {
			$state = (string)($transaction['state'] ?? '');
			if (in_array($state, self::RECEIVED_STATES, true) === false || $this->inRange(date: $transaction['invoiceDate'] ?? null, range: $range) === false) {
				continue;
			}

			$amount = (float)($transaction['totalAmount'] ?? 0);
			$open   = 0.0;
			if (in_array($state, self::RECEIVED_OPEN, true) === true) {
				$open = $amount;
			}

			$rows[] = ['id' => $transaction['id'], 'schema' => 'APTransaction', 'number' => (string)($transaction['invoiceNumber'] ?? ''), 'date' => (string)($transaction['invoiceDate'] ?? ''), 'state' => $state, 'amount' => $amount, 'open' => $open];
		}

		foreach ($this->records->rows(schema: 'SupplierInvoice', administrationId: $administrationId, filters: ['supplierId' => $payee['id']]) as $invoice) {
			$state = (string)($invoice['statusCode'] ?? '');
			if ((string)($invoice['apTransactionId'] ?? '') !== '' || in_array($state, self::INTAKE_OPEN, true) === false || $this->inRange(date: $invoice['invoiceDate'] ?? null, range: $range) === false) {
				continue;
			}

			$amount = (float)($invoice['totalInclVat'] ?? 0);
			$rows[] = ['id' => $invoice['id'], 'schema' => 'SupplierInvoice', 'number' => (string)($invoice['invoiceNumber'] ?? ''), 'date' => (string)($invoice['invoiceDate'] ?? ''), 'state' => $state, 'amount' => $amount, 'open' => $amount];
		}

		return $rows;

	}//end receivedRows()

	/**
	 * A credit note's amount is negative whatever sign it was typed with.
	 *
	 * @param float $amount The amount.
	 * @param bool  $credit Whether the document is a credit note.
	 *
	 * @return float The signed amount.
	 */
	private function signed(float $amount, bool $credit): float {
		if ($credit === true) {
			return -abs($amount);
		}

		return $amount;

	}//end signed()

	/**
	 * Whether a date lies inside the range.
	 *
	 * @param mixed                       $date  The date.
	 * @param array{0: string, 1: string} $range First and last date.
	 *
	 * @return bool True when inside.
	 */
	private function inRange(mixed $date, array $range): bool {
		$day = substr((string)$date, 0, 10);

		return $day !== '' && $day >= $range[0] && $day <= $range[1];

	}//end inRange()
}//end class
