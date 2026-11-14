<?php

/**
 * Contract of CommitmentInvoicing
 *
 * The public surface of CommitmentInvoicing that other components call, so they depend
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

use DomainException;

/**
 * Contract of CommitmentInvoicing.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CommitmentInvoicingInterface {

	/**
	 * Book an approved supplier invoice on its commitment.
	 *
	 * @param array<string,mixed> $invoice The invoice, with its id.
	 *
	 * @return array{commitmentNumber:string,remaining:int,closed:bool}|null Null when no commitment is found.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-2.2
	 */
	public function book(array $invoice): ?array;

	/**
	 * What marking an invoice as the last one releases.
	 *
	 * @param string $administrationId The administration.
	 * @param string $invoiceId        The supplier invoice.
	 *
	 * @return array{invoiceNumber:string,commitmentNumber:string,release:int}
	 *
	 * @throws DomainException When the invoice is not approved or has no open commitment.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-2.3
	 */
	public function lastInvoice(string $administrationId, string $invoiceId): array;

	/**
	 * Mark an approved invoice as the last one and close its commitment.
	 *
	 * @param string $administrationId The administration.
	 * @param string $invoiceId        The supplier invoice.
	 *
	 * @return array{invoiceNumber:string,commitmentNumber:string,release:int}
	 *
	 * @throws DomainException When the invoice is not approved or has no open commitment.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-2.3
	 */
	public function markLast(string $administrationId, string $invoiceId): array;
}//end interface
