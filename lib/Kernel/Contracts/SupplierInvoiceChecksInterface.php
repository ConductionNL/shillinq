<?php

/**
 * Contract of SupplierInvoiceChecks
 *
 * The public surface of SupplierInvoiceChecks that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of SupplierInvoiceChecks.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SupplierInvoiceChecksInterface {

	/**
	 * Another supplier invoice or AP transaction with this number for this payee.
	 *
	 * @param string       $administrationId The administration.
	 * @param string       $supplierId       The payee id.
	 * @param string       $invoiceNumber    The invoice number.
	 * @param list<string> $ignoreIds        The invoice itself and the AP transaction it was handed to.
	 *
	 * @return string|null The earlier record's id, DUPLICATE_UNKNOWN when the lookup failed, null when none.
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-2.1
	 */
	public function duplicateOf(string $administrationId, string $supplierId, string $invoiceNumber, array $ignoreIds=[]): ?string;

	/**
	 * The duplicate rule for an invoice whose supplier is not a known payee:
	 * another supplier invoice stating the same supplier identifier and number.
	 *
	 * @param string $administrationId   The administration.
	 * @param string $supplierIdentifier The supplier as the invoice states it.
	 * @param string $invoiceNumber      The invoice number.
	 *
	 * @return string|null The earlier invoice's id, DUPLICATE_UNKNOWN when the lookup failed, null when none.
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-2.1
	 */
	public function duplicateOfUnresolved(string $administrationId, string $supplierIdentifier, string $invoiceNumber): ?string;

	/**
	 * The payee a supplier invoice names, null when it names none or an unknown one.
	 *
	 * @param string $supplierId The payee id.
	 *
	 * @return array<string, mixed>|null The payee, with its id.
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-3.2
	 */
	public function payee(string $supplierId): ?array;

	/**
	 * The payee of the administration with this KvK number, else this VAT number.
	 *
	 * @param string $administrationId The administration.
	 * @param string $kvkNumber        The supplier's KvK number, '' when unknown.
	 * @param string $vatNumber        The supplier's VAT number, '' when unknown.
	 *
	 * @return array<string, mixed>|null The payee, with its id.
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-1.1
	 */
	public function resolvePayee(string $administrationId, string $kvkNumber, string $vatNumber): ?array;

	/**
	 * The warning fields for one invoice, as the checks find them now.
	 *
	 * @param array<string, mixed> $invoice The SupplierInvoice.
	 *
	 * @return array{duplicateOfId: string, ibanMismatch: string} Each '' when clear (the register holds strings, not null).
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-2.1
	 */
	public function warnings(array $invoice): array;
}//end interface
