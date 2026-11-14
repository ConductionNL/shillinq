<?php

/**
 * Contract of ImportBatchSteps
 *
 * The public surface of ImportBatchSteps that other components call, so they depend
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
 * Contract of ImportBatchSteps.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ImportBatchStepsInterface {

	/**
	 * Dry-run: write the would-be opening entry, open items and relations.
	 *
	 * @param array<string,mixed> $batch The batch in state dry_run_complete.
	 *
	 * @return array<string,mixed> The batch with its dry-run report.
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function dryRun(array $batch): array;

	/**
	 * Parse: stage the auditfile, then move on to staged.
	 *
	 * @param array<string,mixed> $batch The batch in state parsing.
	 *
	 * @return array<string,mixed> The batch in state staged.
	 *
	 * @throws DomainException When the file cannot be read or parsed (REQ-AIW-002).
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function parse(array $batch): array;

	/**
	 * Post: write the opening entry and customers; a refused write moves to posting failed.
	 *
	 * @param array<string,mixed> $batch The batch in state posting.
	 *
	 * @return array<string,mixed> The batch in state posted or posting_failed, with its refs and findings.
	 *
	 * @throws DomainException When another batch already posted the same files and mappings.
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function post(array $batch): array;

	/**
	 * Reverse: post the reversing entry and remove the customers the import wrote.
	 *
	 * @param array<string,mixed> $batch The batch in state reversed.
	 *
	 * @return array<string,mixed> The batch with the reversal refs.
	 *
	 * @throws DomainException When the period is closed or a write is refused; the batch stays posted.
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function reverse(array $batch): array;

	/**
	 * Start mapping: save a mapping row per staged account.
	 *
	 * @param array<string,mixed> $batch The batch in state mapping.
	 *
	 * @return array<string,mixed> The batch, unchanged.
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function startMapping(array $batch): array;

	/**
	 * Validate: write the findings; an error finding moves to validation failed.
	 *
	 * @param array<string,mixed> $batch The batch in state validated.
	 *
	 * @return array<string,mixed> The batch in state validated or validation_failed.
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function validate(array $batch): array;
}//end interface
