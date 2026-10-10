<?php

/**
 * Invoice Ingest Requested Event
 *
 * The command another app raises when one of its customers' months is ready
 * to bill: "draft an invoice for whoever carries this reference, with these
 * lines". Dossiq raises it for a SaaS tenant's month (decision 174). The
 * asking app dispatches it with `IEventDispatcher::dispatchTyped()` and reads
 * the answer from the same object: `isHandled()` with `getResult()`, or
 * `getError()` (ADR-041: the target app defines the typed event).
 *
 * @category Event
 * @package  OCA\Shillinq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenant-month-invoice-from-dossiq/specs/usage-metered-billing/spec.md (REQ-UMB-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Event;

use OCP\EventDispatcher\Event;

/**
 * Draft an invoice for the customer that carries a sibling app's reference.
 *
 * @spec openspec/changes/tenant-month-invoice-from-dossiq/specs/usage-metered-billing/spec.md (REQ-UMB-005)
 */
final class InvoiceIngestRequestedEvent extends Event {

	/**
	 * The version of the answer's shape.
	 *
	 * @var int
	 */
	public const CONTRACT_VERSION = 1;

	/**
	 * The answer, once a listener accepted the command.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $result = null;

	/**
	 * Why the command was refused, once a listener refused it.
	 *
	 * @var string|null
	 */
	private ?string $error = null;

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp The app that asks, for example `dossiq`.
	 * @param string $externalReference The customer's id in the asking app, carried by CustomerMaster.externalReference.
	 * @param string $period The billed month, `YYYY-MM`.
	 * @param array<int, array<string, mixed>> $lines Lines: description, quantity, unitPrice (euros), optional currency and sourceId.
	 * @param string $correlationId The asking app's own id for this command.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $externalReference,
		private readonly string $period,
		private readonly array $lines,
		private readonly string $correlationId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The app that asks.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/tenant-month-invoice-from-dossiq/specs/usage-metered-billing/spec.md (REQ-UMB-005)
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The customer's id in the asking app.
	 *
	 * @return string The reference.
	 *
	 * @spec openspec/changes/tenant-month-invoice-from-dossiq/specs/usage-metered-billing/spec.md (REQ-UMB-005)
	 */
	public function getExternalReference(): string {
		return $this->externalReference;
	}//end getExternalReference()

	/**
	 * The billed month.
	 *
	 * @return string `YYYY-MM`.
	 *
	 * @spec openspec/changes/tenant-month-invoice-from-dossiq/specs/usage-metered-billing/spec.md (REQ-UMB-005)
	 */
	public function getPeriod(): string {
		return $this->period;
	}//end getPeriod()

	/**
	 * The lines to bill.
	 *
	 * @return array<int, array<string, mixed>> The lines.
	 *
	 * @spec openspec/changes/tenant-month-invoice-from-dossiq/specs/usage-metered-billing/spec.md (REQ-UMB-005)
	 */
	public function getLines(): array {
		return $this->lines;
	}//end getLines()

	/**
	 * The asking app's own id for this command.
	 *
	 * @return string The correlation id.
	 *
	 * @spec openspec/changes/tenant-month-invoice-from-dossiq/specs/usage-metered-billing/spec.md (REQ-UMB-005)
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

	/**
	 * Accept the command and answer with the invoice drafted for it.
	 *
	 * @param string $invoiceId The BillableInvoice uuid.
	 * @param string $invoiceNumber The invoice number.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenant-month-invoice-from-dossiq/specs/usage-metered-billing/spec.md (REQ-UMB-005)
	 */
	public function accept(string $invoiceId, string $invoiceNumber): void {
		$this->error  = null;
		$this->result = [
			'contractVersion' => self::CONTRACT_VERSION,
			'invoiceId' => $invoiceId,
			'invoiceNumber' => $invoiceNumber,
			'status' => 'draft',
			'duplicated' => false,
		];
	}//end accept()

	/**
	 * Accept a repeat: this month was drafted before, and that invoice is the answer.
	 *
	 * @param string $invoiceId The BillableInvoice uuid drafted earlier.
	 * @param string $invoiceNumber Its invoice number.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenant-month-invoice-from-dossiq/specs/usage-metered-billing/spec.md (REQ-UMB-005)
	 */
	public function acceptDuplicate(string $invoiceId, string $invoiceNumber): void {
		$this->accept(invoiceId: $invoiceId, invoiceNumber: $invoiceNumber);
		$this->result['duplicated'] = true;
	}//end acceptDuplicate()

	/**
	 * Refuse the command with a reason.
	 *
	 * @param string $error Why, in words the asking app can record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenant-month-invoice-from-dossiq/specs/usage-metered-billing/spec.md (REQ-UMB-005)
	 */
	public function refuse(string $error): void {
		$this->result = null;
		$this->error = $error;
	}//end refuse()

	/**
	 * Whether a listener accepted the command.
	 *
	 * @return bool True when accepted.
	 *
	 * @spec openspec/changes/tenant-month-invoice-from-dossiq/specs/usage-metered-billing/spec.md (REQ-UMB-005)
	 */
	public function isHandled(): bool {
		return $this->result !== null;
	}//end isHandled()

	/**
	 * The answer: contractVersion, invoiceId, invoiceNumber, status and duplicated.
	 *
	 * @return array<string, mixed>|null The answer, or null when not accepted.
	 *
	 * @spec openspec/changes/tenant-month-invoice-from-dossiq/specs/usage-metered-billing/spec.md (REQ-UMB-005)
	 */
	public function getResult(): ?array {
		return $this->result;
	}//end getResult()

	/**
	 * Why the command was refused.
	 *
	 * @return string|null The reason, or null when not refused.
	 *
	 * @spec openspec/changes/tenant-month-invoice-from-dossiq/specs/usage-metered-billing/spec.md (REQ-UMB-005)
	 */
	public function getError(): ?string {
		return $this->error;
	}//end getError()
}//end class
