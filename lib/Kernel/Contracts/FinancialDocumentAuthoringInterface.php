<?php

/**
 * Contract of FinancialDocumentAuthoring
 *
 * The public surface of FinancialDocumentAuthoring that other components call, so they depend
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

use OCA\Shillinq\Service\FinancialDocumentActionRefusedException;

/**
 * Contract of FinancialDocumentAuthoring.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FinancialDocumentAuthoringInterface {

	/**
	 * The action that starts a quote.
	 *
	 * @var string
	 */
	public const ACTION_CREATE_QUOTE = 'quote.create';

	/**
	 * The action that credits an invoice.
	 *
	 * @var string
	 */
	public const ACTION_CREDIT_INVOICE = 'invoice.credit';

	/**
	 * The action that raises a payout.
	 *
	 * @var string
	 */
	public const ACTION_RAISE_PAYOUT = 'payable.raise';

	/**
	 * Start a draft quote on the host object, with one line.
	 *
	 * Payload: description, amount, debtor (`customerMasterId`, or `name` and
	 * `email`), and optionally currency, administrationId and objectType.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $payload The quote.
	 *
	 * @return array{id: string} The quote.
	 *
	 * @throws FinancialDocumentActionRefusedException When the caller, the administration or the payload is refused.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-009)
	 */
	public function createQuote(string $register, string $schema, string $objectId, array $payload): array;

	/**
	 * Issue a credit note for the whole of an invoice, on the same objects.
	 *
	 * @param string $invoiceId The invoice.
	 *
	 * @return array{id: string} The credit note.
	 *
	 * @throws FinancialDocumentActionRefusedException When the caller, the administration or the invoice refuses it.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-009)
	 */
	public function creditInvoice(string $invoiceId): array;

	/**
	 * Raise a payout on the host object, with shillinq as the raising app.
	 *
	 * Payload: amount, payeeName, iban, and optionally description, dueOn,
	 * category, administrationId and objectType.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $payload The payout.
	 *
	 * @return array{payableId: string, state: string} The payout.
	 *
	 * @throws FinancialDocumentActionRefusedException When the caller, the administration or finance refuses it.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-009)
	 */
	public function raisePayout(string $register, string $schema, string $objectId, array $payload): array;
}//end interface
