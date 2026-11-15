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
	 * Generate the renderable invoice payload.
	 *
	 * @param array<string,mixed> $invoice BillableInvoice record.
	 * @param array<int,array<string,mixed>> $lines BillableInvoiceLine records.
	 * @param array<string,mixed> $creditor Creditor (issuing party) details.
	 * @param array<string,mixed> $recipient Recipient details.
	 *
	 * @return array{filename:string,html:string,mimeType:string}
	 *
	 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
	 */
	public function generatePdf(
		array $invoice,
		array $lines,
		array $creditor = [],
		array $recipient = [],
	): array;
}//end interface
