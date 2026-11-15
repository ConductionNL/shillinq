<?php

/**
 * Contract of EInvoiceService
 *
 * The public surface of EInvoiceService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of EInvoiceService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EInvoiceServiceInterface {

	/**
	 * Run the Send e-invoice flow for one ARInvoice (REQ-EINV-005).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $invoiceNumber ARInvoice.invoiceNumber to send.
	 *
	 * @return array{deliveryStatus:string,transmissionId:?string,payloadFileUri:?string,fallback:bool,warnings:array<int,array<string,string>>}
	 *
	 * @throws RuntimeException When the invoice is missing/not accessible (IDOR-safe 404),
	 *                          not in the required `issued` lifecycle state, or pre-send
	 *                          validation fails (400 — no event is ever emitted on failure).
	 *
	 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
	 */
	public function sendEInvoice(string $administrationId, string $invoiceNumber): array;
}//end interface
