<?php

/**
 * Contract of InvoicePdfGenerator
 *
 * The public surface of InvoicePdfGenerator that other components call, so they depend
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
 * Contract of InvoicePdfGenerator.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InvoicePdfGeneratorInterface {

	/**
	 * What a caller shows when docudesk is absent (REQ-SID-002).
	 */
	public const DOCUDESK_NEEDED = 'Docudesk is needed to produce invoice documents.';

	/**
	 * Whether an invoice PDF can be rendered: docudesk is installed and resolves.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-2.2
	 */
	public function documentsAvailable(): bool;

	/**
	 * Render the invoice PDF through docudesk.
	 *
	 * @param array<string,mixed> $invoice BillableInvoice or ARInvoice record.
	 * @param array<int,array<string,mixed>> $lines Its lines.
	 * @param array<string,mixed> $context `creditor`, `recipient`, `layout` (the InvoiceLayout),
	 *                                     `language` (the administration's default) and `userId`.
	 *
	 * @return array{filename:string,pdf:string,mimeType:string}
	 *
	 * @throws \OCA\Shillinq\Reporting\DocudeskUnavailableException When docudesk is not installed.
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-2.2
	 */
	public function generatePdf(array $invoice, array $lines, array $context = []): array;

	/**
	 * The invoice as HTML.
	 *
	 * @param array<string,mixed> $invoice BillableInvoice record.
	 * @param array<int,array<string,mixed>> $lines BillableInvoiceLine records.
	 * @param array<string,mixed> $creditor Creditor (issuing party) details.
	 * @param array<string,mixed> $recipient Recipient details.
	 *
	 * @return array{filename:string,html:string,mimeType:string}
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-2.2
	 */
	public function generateHtml(
		array $invoice,
		array $lines,
		array $creditor = [],
		array $recipient = [],
	): array;
}//end interface
