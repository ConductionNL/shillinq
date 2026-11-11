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
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
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
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
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
	 * A side the caller may not read: restricted, no rows, no amounts.
	 *
	 * @var array<string, mixed>
	 */
	private const RESTRICTED_SIDE = ['restricted' => true, 'rows' => [], 'invoiced' => null, 'open' => null];

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
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function forCustomer(string $administrationId, string $customerId, string $from, string $to, array $sides): array {
		$customer = $this->records->one(schema: 'CustomerMaster', administrationId: $administrationId, id: $customerId);
		if ($customer === null) {
			throw new DomainException('Not found');
		}

		$query       = new RelationReportQuery(administrationId: $administrationId, sides: $sides);
		$query->from = $from;
		$query->to   = $to;

		return $this->relation(customer: $customer, query: $query);

	}//end forCustomer()

	/**
	 * The customer linked to a supplier, for the supplier's page.
	 *
	 * @param string $administrationId The administration.
	 * @param string $payeeId          The supplier.
	 *
	 * @return string|null The customer id, or null when the supplier is not linked.
	 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
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
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function forAdministration(string $administrationId, string $from, string $to, array $sides): array {
		$query           = new RelationReportQuery(administrationId: $administrationId, sides: $sides);
		$query->from     = $from;
		$query->to       = $to;
		$query->withRows = false;

		$relations = [];
		foreach ($this->records->rows(schema: 'CustomerMaster', administrationId: $administrationId) as $customer) {
			if ((string)($customer['payeeId'] ?? '') !== '') {
				$relations[] = $this->reportRow(relation: $this->relation(customer: $customer, query: $query));
			}
		}

		usort($relations, static fn (array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));

		return [
			'relations'  => $relations,
			'restricted' => ['sent' => ($sides['sent'] === false), 'received' => ($sides['received'] === false)],
		];

	}//end forAdministration()

	/**
	 * One report row: the relation's ids and name with its five totals.
	 *
	 * @param array<string, mixed> $relation A relation without rows.
	 *
	 * @return array<string, mixed>
	 */
	private function reportRow(array $relation): array {
		return array_merge(
			[
				'id'         => $relation['customer']['id'],
				'customerId' => $relation['customer']['id'],
				'name'       => $relation['customer']['name'],
				'payeeId'    => $relation['payee']['id'],
			],
			$relation['totals']
		);

	}//end reportRow()

	/**
	 * The report as CSV: one row per relation with the five amounts.
	 *
	 * @param array<int, array<string, mixed>> $relations forAdministration()['relations'].
	 *
	 * @return string The CSV text.
	 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
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
	 * @param array<string, mixed> $customer The customer record.
	 * @param RelationReportQuery  $query    The administration, readable sides, range and listing.
	 *
	 * @return array<string, mixed> The relation.
	 */
	private function relation(array $customer, RelationReportQuery $query): array {
		$payeeId = (string)($customer['payeeId'] ?? '');
		$payee   = $this->records->one(schema: 'Payee', administrationId: $query->administrationId, id: $payeeId);

		$sent     = $this->sentSide(customer: $customer, query: $query);
		$received = $this->receivedSide(payee: $payee, query: $query);

		return [
			'customer' => ['id' => (string)$customer['id'], 'name' => (string)($customer['legalName'] ?? $customer['tradeName'] ?? '')],
			'payee'    => ['id' => $payeeId, 'name' => (string)($payee['name'] ?? $payee['tradingName'] ?? '')],
			'link'     => ($customer['payeeLink'] ?? null),
			'sent'     => $sent,
			'received' => $received,
			'totals'   => $this->totals(sent: $sent, received: $received),
		];

	}//end relation()

	/**
	 * The sales side, restricted when the caller may not read it.
	 *
	 * @param array<string, mixed> $customer The customer record.
	 * @param RelationReportQuery  $query    The readable sides, range and listing.
	 *
	 * @return array<string, mixed>
	 */
	private function sentSide(array $customer, RelationReportQuery $query): array {
		if ($query->sides['sent'] !== true) {
			return self::RESTRICTED_SIDE;
		}

		return $this->listed(side: $this->side(rows: $this->sentRows(customer: $customer, query: $query)), query: $query);

	}//end sentSide()

	/**
	 * The purchase side, restricted when the caller may not read it.
	 *
	 * @param array<string, mixed>|null $payee The supplier, null when it is gone.
	 * @param RelationReportQuery       $query The readable sides, range and listing.
	 *
	 * @return array<string, mixed>
	 */
	private function receivedSide(?array $payee, RelationReportQuery $query): array {
		if ($query->sides['received'] !== true) {
			return self::RESTRICTED_SIDE;
		}

		return $this->listed(side: $this->side(rows: $this->receivedRows(payee: $payee, query: $query)), query: $query);

	}//end receivedSide()

	/**
	 * The side with its rows dropped when the read only totals.
	 *
	 * @param array<string, mixed> $side  A side.
	 * @param RelationReportQuery  $query The listing choice.
	 *
	 * @return array<string, mixed>
	 */
	private function listed(array $side, RelationReportQuery $query): array {
		if ($query->withRows === false) {
			$side['rows'] = [];
		}

		return $side;

	}//end listed()

	/**
	 * The five totals of a relation; net only when both open amounts are known.
	 *
	 * @param array<string, mixed> $sent     The sales side.
	 * @param array<string, mixed> $received The purchase side.
	 *
	 * @return array<string, float|null> sales, purchases, openReceivable, openPayable, net.
	 */
	private function totals(array $sent, array $received): array {
		$net = null;
		if ($sent['open'] !== null && $received['open'] !== null) {
			$net = round($sent['open'] - $received['open'], 2);
		}

		return [
			'sales'          => $sent['invoiced'],
			'purchases'      => $received['invoiced'],
			'openReceivable' => $sent['open'],
			'openPayable'    => $received['open'],
			'net'            => $net,
		];

	}//end totals()

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
	 * @param array<string, mixed> $customer The customer.
	 * @param RelationReportQuery  $query    The administration and range.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function sentRows(array $customer, RelationReportQuery $query): array {
		$keys = array_unique(array_filter([(string)$customer['id'], (string)($customer['customerId'] ?? '')]));
		$rows = [];
		foreach ($keys as $key) {
			foreach ($this->records->rows(schema: 'ARInvoice', administrationId: $query->administrationId, filters: ['customerId' => $key]) as $invoice) {
				$state = (string)($invoice['lifecycleState'] ?? '');
				if (in_array($state, self::SENT_STATES, true) === true && $query->inRange(date: $invoice['invoiceDate'] ?? null) === true) {
					$rows[$invoice['id']] = $this->sentRow(invoice: $invoice, state: $state);
				}
			}
		}

		return array_values($rows);

	}//end sentRows()

	/**
	 * One listed sales invoice: a credit note counts negative, and an open
	 * invoice is open for its amount due when that is known.
	 *
	 * @param array<string, mixed> $invoice The ARInvoice.
	 * @param string               $state   Its lifecycle state.
	 *
	 * @return array<string, mixed>
	 */
	private function sentRow(array $invoice, string $state): array {
		$credit = ($invoice['invoiceType'] ?? '') === 'credit-note';
		$amount = $this->signed(amount: (float)($invoice['grossAmount'] ?? 0), credit: $credit);
		$open   = 0.0;
		if (in_array($state, self::SENT_OPEN, true) === true) {
			$open = $amount;
			if (isset($invoice['amountDue']) === true && is_numeric($invoice['amountDue']) === true) {
				$open = $this->signed(amount: (float)$invoice['amountDue'], credit: $credit);
			}
		}

		return $this->row(schema: 'ARInvoice', record: $invoice, state: $state, amounts: [$amount, $open]);

	}//end sentRow()

	/**
	 * The supplier's purchase invoices in the range: its payables, then the
	 * intake not yet handed to payables.
	 *
	 * @param array<string, mixed>|null $payee The supplier, null when it is gone.
	 * @param RelationReportQuery       $query The administration and range.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function receivedRows(?array $payee, RelationReportQuery $query): array {
		if ($payee === null) {
			return [];
		}

		return array_merge(
			$this->payableRows(payee: $payee, query: $query),
			$this->intakeRows(payee: $payee, query: $query)
		);

	}//end receivedRows()

	/**
	 * The supplier's counted APTransactions in the range.
	 *
	 * @param array<string, mixed> $payee The supplier.
	 * @param RelationReportQuery  $query The administration and range.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function payableRows(array $payee, RelationReportQuery $query): array {
		$rows         = [];
		$transactions = $this->records->rows(schema: 'APTransaction', administrationId: $query->administrationId, filters: ['vendorId' => $payee['id']]);
		foreach ($transactions as $transaction) {
			$state   = (string)($transaction['state'] ?? '');
			$counted = in_array($state, self::RECEIVED_STATES, true);
			if ($counted === true && $query->inRange(date: $transaction['invoiceDate'] ?? null) === true) {
				$rows[] = $this->payableRow(transaction: $transaction, state: $state);
			}
		}

		return $rows;

	}//end payableRows()

	/**
	 * One listed APTransaction, open for its whole amount in an open state.
	 *
	 * @param array<string, mixed> $transaction The APTransaction.
	 * @param string               $state       Its state.
	 *
	 * @return array<string, mixed>
	 */
	private function payableRow(array $transaction, string $state): array {
		$amount = (float)($transaction['totalAmount'] ?? 0);
		$open   = 0.0;
		if (in_array($state, self::RECEIVED_OPEN, true) === true) {
			$open = $amount;
		}

		return $this->row(schema: 'APTransaction', record: $transaction, state: $state, amounts: [$amount, $open]);

	}//end payableRow()

	/**
	 * The supplier's open intake invoices in the range that are not booked in payables.
	 *
	 * @param array<string, mixed> $payee The supplier.
	 * @param RelationReportQuery  $query The administration and range.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function intakeRows(array $payee, RelationReportQuery $query): array {
		$rows   = [];
		$intake = $this->records->rows(schema: 'SupplierInvoice', administrationId: $query->administrationId, filters: ['supplierId' => $payee['id']]);
		foreach ($intake as $invoice) {
			$state = (string)($invoice['statusCode'] ?? '');
			if ($this->isOpenIntake(invoice: $invoice, state: $state) === true && $query->inRange(date: $invoice['invoiceDate'] ?? null) === true) {
				$amount = (float)($invoice['totalInclVat'] ?? 0);
				$rows[] = $this->row(schema: 'SupplierInvoice', record: $invoice, state: $state, amounts: [$amount, $amount]);
			}
		}

		return $rows;

	}//end intakeRows()

	/**
	 * Whether an intake invoice is open and not yet handed to payables (where
	 * it is already counted as its APTransaction).
	 *
	 * @param array<string, mixed> $invoice The SupplierInvoice.
	 * @param string               $state   Its status code.
	 *
	 * @return bool
	 */
	private function isOpenIntake(array $invoice, string $state): bool {
		$booked = ((string)($invoice['apTransactionId'] ?? '') !== '');

		return $booked === false && in_array($state, self::INTAKE_OPEN, true) === true;

	}//end isOpenIntake()

	/**
	 * One listed invoice.
	 *
	 * @param string                    $schema  The schema it comes from.
	 * @param array<string, mixed>      $record  The record.
	 * @param string                    $state   Its state.
	 * @param array{0: float, 1: float} $amounts The signed amount and the open amount.
	 *
	 * @return array<string, mixed> id, schema, number, date, state, amount, open.
	 */
	private function row(string $schema, array $record, string $state, array $amounts): array {
		return [
			'id'     => (string)($record['id'] ?? ''),
			'schema' => $schema,
			'number' => (string)($record['invoiceNumber'] ?? ''),
			'date'   => (string)($record['invoiceDate'] ?? ''),
			'state'  => $state,
			'amount' => $amounts[0],
			'open'   => $amounts[1],
		];

	}//end row()

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

}//end class
