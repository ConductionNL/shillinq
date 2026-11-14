<?php

/**
 * Contract of StatementIntakeService
 *
 * The public surface of StatementIntakeService that other components call, so they depend
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
 * Contract of StatementIntakeService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StatementIntakeServiceInterface {

	/**
	 * Write one statement with its lines.
	 *
	 * @param string                         $administrationId The owning administration.
	 * @param string                         $iban             The bank account IBAN, '' when unknown.
	 * @param array<int,array<string,mixed>> $lines            Normalised lines.
	 * @param array<string,mixed>            $meta             `source` (file|feed), `format`, `bankConnectionId`,
	 *                                                         `sourceBatchUri`, `glAccountId`, `statementDate`,
	 *                                                         `closingBalance`.
	 *
	 * @return array{statementId:string,written:array<int,array<string,mixed>>,skipped:int,duplicateBatch:bool}
	 *
	 * @spec openspec/specs/bookkeeping-bank-connectors/spec.md
	 */
	public function ingest(string $administrationId, string $iban, array $lines, array $meta): array;
}//end interface
