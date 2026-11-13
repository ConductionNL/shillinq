<?php

/**
 * Contract of ArInvoiceIcpPdfRenderer
 *
 * The public surface of ArInvoiceIcpPdfRenderer that other components call, so they depend
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
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

use InvalidArgumentException;

/**
 * Contract of ArInvoiceIcpPdfRenderer.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ArInvoiceIcpPdfRendererInterface {

	/**
	 * Error code raised when a treatAsIcp invoice lacks a buyer VAT-ID
	 * (REQ-ICP-007, REQ-ICP-001).
	 *
	 * @var string
	 */
	public const ERROR_VATID_MISSING = 'icp.invoice.vatid.missing';

	/**
	 * Render the ICP overlay PDF payload for an AR invoice (REQ-ICP-007).
	 *
	 * The result mirrors the InvoicePdfGenerator shape (filename / html / mimeType)
	 * and additionally exposes the ICP-overlay metadata so callers can persist or
	 * inspect it. Throws when treatAsIcp is true but the buyer VAT-ID is missing
	 * (`icp.invoice.vatid.missing` per REQ-ICP-007).
	 *
	 * @param array<string,mixed> $invoice The ARInvoice record (read).
	 * @param array<string,mixed> $customer The CustomerMaster record (read).
	 * @param array<string,mixed> $seller The seller administration record (read):
	 *                                    MUST contain {vatId, legalName}.
	 *
	 * @return array{filename:string,html:string,mimeType:string,icp:array<string,mixed>}
	 *
	 * @throws InvalidArgumentException When buyer VAT-ID is missing on a treatAsIcp
	 *                                  invoice (error code icp.invoice.vatid.missing).
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function render(array $invoice, array $customer, array $seller): array;
}//end interface
