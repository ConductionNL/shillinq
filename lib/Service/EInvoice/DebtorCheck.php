<?php

/**
 * Debtor Check
 *
 * The findings of one {@see EInvoiceValidationService::validate()} run: the
 * administration it runs for, and the errors and warnings collected so far.
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
 * Errors and warnings of one debtor validation.
 *
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 */
class DebtorCheck {

	/**
	 * Administration scope (server-resolved).
	 *
	 * @var string
	 */
	public string $administrationId = '';

	/**
	 * Blocking findings.
	 *
	 * @var array<int,array{field:string,code:string,message:string}>
	 */
	public array $errors = [];

	/**
	 * Non-blocking findings.
	 *
	 * @var array<int,array{field:string,code:string,message:string}>
	 */
	public array $warnings = [];
}//end class
