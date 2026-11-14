<?php

/**
 * Contract of FinancialDocumentRows
 *
 * The public surface of FinancialDocumentRows that other components call, so they depend
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
 * Contract of FinancialDocumentRows.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FinancialDocumentRowsInterface {

	/**
	 * A row with everything but its kind, status and payment state blanked.
	 *
	 * @param array<string, mixed> $row The full row.
	 *
	 * @return array<string, mixed> The restricted row.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function restricted(array $row): array;

	/**
	 * The row for one document.
	 *
	 * @param string $schema The schema it was read from.
	 * @param array<string, mixed> $document The document.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function row(string $schema, array $document): array;
	/**
	 * The kind of a document read from a schema.
	 *
	 * @param string $schema The schema it was read from.
	 * @param array<string, mixed> $document The document.
	 *
	 * @return string One of quote, invoice, credit-note, payment-request, payout.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function kindOf(string $schema, array $document): string;
}//end interface
