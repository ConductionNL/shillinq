<?php

/**
 * Contract of SelfBilledInvoiceReview
 *
 * The public surface of SelfBilledInvoiceReview that other components call, so they depend
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
 * Contract of SelfBilledInvoiceReview.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SelfBilledInvoiceReviewInterface {

	/**
	 * Refuse to issue a self-billed invoice the customer's agreement does not cover.
	 *
	 * A no-op for an invoice that is not self-billed.
	 *
	 * @param array<string,mixed> $invoice The invoice being issued.
	 *
	 * @return void
	 *
	 * @throws RuntimeException With the reason, when it may not be issued.
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-einvoice-exchange/tasks.md#task-3.4
	 */
	public function requireSelfBillingAgreement(array $invoice): void;

	/**
	 * Reject a draft self-billed invoice with a reason; it stays unbooked.
	 *
	 * @param string $administrationId The administration (checked against the user).
	 * @param string $invoiceNumber    The invoice's number.
	 * @param string $reason           Why it is rejected.
	 *
	 * @return array<string,mixed> The rejected invoice.
	 *
	 * @throws RuntimeException `ARInvoice not found` for a missing or inaccessible
	 *                          invoice; otherwise the refusal.
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-einvoice-exchange/tasks.md#task-3.4
	 */
	public function reject(string $administrationId, string $invoiceNumber, string $reason): array;
}//end interface
