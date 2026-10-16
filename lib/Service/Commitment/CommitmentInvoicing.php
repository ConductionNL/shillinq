<?php

/**
 * Commitment Invoicing
 *
 * Connects supplier invoices to the commitment of the order they were matched
 * to (planning-commitment-year-end, REQ-PCYE-002, REQ-PCYE-003):
 *
 * - book(): an approved invoice lowers what the commitment still has
 *   remaining (CommitmentLedger::invoiced()) and moves it to partially
 *   invoiced through the declared `factureren`; an invoice marked as the last
 *   one closes it through the declared `afsluiten`;
 * - lastInvoice(): what marking an approved invoice as the last one would
 *   release, for the confirmation;
 * - markLast(): sets `isLastInvoice` and closes the commitment.
 *
 * The purchase orders in `matchedPoIds` give their `poNumber`; the commitment
 * with that `sourceReference` in the same administration is the one invoiced
 * (CommitmentMaterialisationService writes it so).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Commitment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Commitment;

use DomainException;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;

/**
 * Invoices against commitments.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
class CommitmentInvoicing {
	/**
	 * States from which `factureren` moves a commitment to partially invoiced.
	 *
	 * @var array<int,string>
	 */
	private const INVOICEABLE = ['committed', 'partially_delivered'];

	/**
	 * States a commitment can be closed from.
	 *
	 * @var array<int,string>
	 */
	private const OPEN = ['committed', 'partially_delivered', 'partially_invoiced', 'partially_paid'];

	/**
	 * Constructor.
	 *
	 * @param CommitmentLedger       $ledger      Movements, lines and budgets.
	 * @param ObjectTransitionRunner $transitions Runs the declared transitions.
	 */
	public function __construct(
		private readonly CommitmentLedger $ledger,
		private readonly ObjectTransitionRunner $transitions,
	) {

	}//end __construct()

	/**
	 * Book an approved supplier invoice on its commitment.
	 *
	 * @param array<string,mixed> $invoice The invoice, with its id.
	 *
	 * @return array{commitmentNumber:string,remaining:int,closed:bool}|null Null when no commitment is found.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-2.2
	 */
	public function book(array $invoice): ?array {
		$commitment = $this->commitmentFor(invoice: $invoice);
		if ($commitment === null) {
			return null;
		}

		$remaining = $this->ledger->invoiced(commitment: $commitment, invoice: $invoice);
		$state = (string)($commitment['status'] ?? '');
		if (in_array($state, self::INVOICEABLE, true) === true) {
			$this->transitions->run(objectId: (string)$commitment['id'], action: 'factureren');
			$state = 'partially_invoiced';
		}

		$closed = false;
		if (($invoice['isLastInvoice'] ?? false) === true && in_array($state, self::OPEN, true) === true) {
			$this->transitions->run(objectId: (string)$commitment['id'], action: 'afsluiten');
			$closed = true;
		}

		return ['commitmentNumber' => (string)$commitment['commitmentNumber'], 'remaining' => $remaining, 'closed' => $closed];

	}//end book()

	/**
	 * What marking an invoice as the last one releases.
	 *
	 * @param string $administrationId The administration.
	 * @param string $invoiceId        The supplier invoice.
	 *
	 * @return array{invoiceNumber:string,commitmentNumber:string,release:int}
	 *
	 * @throws DomainException When the invoice is not approved or has no open commitment.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-2.3
	 */
	public function lastInvoice(string $administrationId, string $invoiceId): array {
		[$invoice, $commitment] = $this->invoiceAndCommitment(administrationId: $administrationId, invoiceId: $invoiceId);
		return [
			'invoiceNumber'    => (string)($invoice['invoiceNumber'] ?? ''),
			'commitmentNumber' => (string)$commitment['commitmentNumber'],
			'release'          => $this->ledger->remainingOf(commitmentNumber: (string)$commitment['commitmentNumber']),
		];

	}//end lastInvoice()

	/**
	 * Mark an approved invoice as the last one and close its commitment.
	 *
	 * @param string $administrationId The administration.
	 * @param string $invoiceId        The supplier invoice.
	 *
	 * @return array{invoiceNumber:string,commitmentNumber:string,release:int}
	 *
	 * @throws DomainException When the invoice is not approved or has no open commitment.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-2.3
	 */
	public function markLast(string $administrationId, string $invoiceId): array {
		$preview = $this->lastInvoice(administrationId: $administrationId, invoiceId: $invoiceId);
		[, $commitment] = $this->invoiceAndCommitment(administrationId: $administrationId, invoiceId: $invoiceId);
		$this->ledger->patch(schema: 'SupplierInvoice', id: $invoiceId, data: ['isLastInvoice' => true]);
		$this->transitions->run(objectId: (string)$commitment['id'], action: 'afsluiten');
		return $preview;

	}//end markLast()

	/**
	 * The approved invoice and its open commitment.
	 *
	 * @param string $administrationId The administration.
	 * @param string $invoiceId        The supplier invoice.
	 *
	 * @return array{0:array<string,mixed>,1:array<string,mixed>}
	 *
	 * @throws DomainException When the invoice is not approved or has no open commitment.
	 */
	private function invoiceAndCommitment(string $administrationId, string $invoiceId): array {
		$invoice = $this->ledger->find(schema: 'SupplierInvoice', id: $invoiceId);
		if ($invoice === null || (string)($invoice['administrationId'] ?? '') !== $administrationId) {
			throw new DomainException('This supplier invoice does not exist.');
		}

		if ((string)($invoice['statusCode'] ?? '') !== 'approved') {
			throw new DomainException('Only an approved invoice can be marked as the last one.');
		}

		$commitment = $this->commitmentFor(invoice: $invoice);
		if ($commitment === null || in_array((string)($commitment['status'] ?? ''), self::OPEN, true) === false) {
			throw new DomainException('This invoice has no open commitment to close.');
		}

		return [$invoice, $commitment];

	}//end invoiceAndCommitment()

	/**
	 * The commitment of the first matched order that has one.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 *
	 * @return array<string,mixed>|null
	 */
	private function commitmentFor(array $invoice): ?array {
		$administrationId = (string)($invoice['administrationId'] ?? '');
		foreach ((array)($invoice['matchedPoIds'] ?? []) as $poId) {
			$order = $this->ledger->find(schema: 'PurchaseOrder', id: (string)$poId);
			$poNumber = (string)($order['poNumber'] ?? '');
			if ($poNumber === '') {
				continue;
			}

			$found = $this->ledger->records(
				schema: 'Commitment',
				filters: ['administrationId' => $administrationId, 'sourceReference' => $poNumber]
			);
			if ($found !== []) {
				return $found[0];
			}
		}

		return null;

	}//end commitmentFor()
}//end class
