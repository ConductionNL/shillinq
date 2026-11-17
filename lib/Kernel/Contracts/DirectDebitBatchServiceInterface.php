<?php

/**
 * Contract of DirectDebitBatchService
 *
 * The public surface of DirectDebitBatchService that other components call, so they depend
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
 * Contract of DirectDebitBatchService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DirectDebitBatchServiceInterface {

	/**
	 * Draft the collections and batches for one collection date.
	 *
	 * @param string $administrationId The administration.
	 * @param string $collectionDate   The requested collection date (Y-m-d).
	 *
	 * @return array{batches: list<array<string, mixed>>, skipped: list<array<string, string>>} The draft batches, each
	 *         with its `collections`, and every invoice left out with its reason.
	 *
	 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-1.1
	 */
	public function propose(string $administrationId, string $collectionDate): array;
}//end interface
