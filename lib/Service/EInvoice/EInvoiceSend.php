<?php

/**
 * One e-invoice send, as EInvoiceService works it.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\EInvoice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\EInvoice;

/**
 * The validated invoice, its tenant and Peppol recipient, the validation
 * warnings, and what the send produced. Built and read by EInvoiceService.
 *
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 */
final class EInvoiceSend {

	/**
	 * Administration scope.
	 *
	 * @var string
	 */
	public string $administrationId = '';

	/**
	 * The ARInvoice.invoiceNumber asked for.
	 *
	 * @var string
	 */
	public string $invoiceNumber = '';

	/**
	 * The ARInvoice record.
	 *
	 * @var array<string,mixed>
	 */
	public array $invoice = [];

	/**
	 * The debtor's Peppol participant id.
	 *
	 * @var string
	 */
	public string $participantId = '';

	/**
	 * The pre-send validation warnings.
	 *
	 * @var array<int,array<string,string>>
	 */
	public array $warnings = [];

	/**
	 * The stored artefact URI.
	 *
	 * @var string
	 */
	public string $payloadFileUri = '';

	/**
	 * The Peppol transmission id.
	 *
	 * @var string
	 */
	public string $transmissionId = '';
}//end class
