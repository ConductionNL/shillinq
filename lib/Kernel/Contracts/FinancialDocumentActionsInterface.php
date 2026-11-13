<?php

/**
 * Contract of FinancialDocumentActions
 *
 * The public surface of FinancialDocumentActions that other components call, so they depend
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
 * Contract of FinancialDocumentActions.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FinancialDocumentActionsInterface {

	/**
	 * The action that issues an invoice.
	 *
	 * @var string
	 */
	public const ACTION_ISSUE_INVOICE = 'invoice.issue';

	/**
	 * Issue one invoice linked to the host object.
	 *
	 * Payload: amount, description, debtor (`customerMasterId`, or `name` and
	 * `email`), and optionally currency, administrationId and objectType.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $payload The invoice.
	 *
	 * @return array{id: string} The issued invoice.
	 *
	 * @throws FinancialDocumentActionRefusedException When the caller, the administration or the payload is refused.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
	 */
	public function issueInvoice(string $register, string $schema, string $objectId, array $payload): array;

	/**
	 * Ask for payment of an outstanding invoice through shillinq.
	 *
	 * The request carries the invoice's open amount and its linked objects, so
	 * it appears on the same objects as the invoice and settles it on capture.
	 *
	 * @param string $invoiceId The invoice.
	 *
	 * @return array<string, mixed> The payment request.
	 *
	 * @throws FinancialDocumentActionRefusedException When the caller, the invoice or an open request refuses it.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-004)
	 */
	public function requestPayment(string $invoiceId): array;
}//end interface
