<?php

/**
 * Contract of StatementParser
 *
 * The public surface of StatementParser that other components call, so they depend
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
 * Contract of StatementParser.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StatementParserInterface {

	/**
	 * Parse a bank-statement file into an array of normalised line field maps.
	 *
	 * Supports CAMT.053 (ISO 20022 XML), MT940 (SWIFT), and CSV. XML parsing is
	 * XXE-safe: external entity loading is disabled before parsing. Returns an
	 * empty array on any parse error (the caller reports the failure to the
	 * operator; no partial import).
	 *
	 * @param string $contents Raw file contents.
	 * @param string $format One of 'camt053', 'mt940', 'csv'.
	 *
	 * @return array<int,array<string,mixed>> Normalised BankStatementLine field maps.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md#req-br-003
	 * @spec openspec/changes/add-shillinq-bank-reconciliation/tasks.md#task-10
	 */
	public function parse(string $contents, string $format): array;
}//end interface
