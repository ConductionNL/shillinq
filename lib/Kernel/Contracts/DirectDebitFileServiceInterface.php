<?php

/**
 * Contract of DirectDebitFileService
 *
 * The public surface of DirectDebitFileService that other components call, so they depend
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

use OCA\Shillinq\PaymentRun\RenderedFile;
use RuntimeException;

/**
 * Contract of DirectDebitFileService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DirectDebitFileServiceInterface {

	/**
	 * The batch with its validated pain.008 file.
	 *
	 * @param array<string, mixed> $batch The DirectDebitBatch being generated.
	 *
	 * @return array<string, mixed> The batch with pain008Xml, collectionCount and controlSum.
	 *
	 * @throws RuntimeException When the file has errors; the message lists them.
	 *
	 * @spec openspec/changes/archive/2026-11-24-receivables-sepa-direct-debit/tasks.md#task-1.2
	 */
	public function generate(array $batch): array;

	/**
	 * The stored file of a generated batch as a download, or null without one.
	 *
	 * @param array<string, mixed> $batch The DirectDebitBatch.
	 *
	 * @return RenderedFile|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-receivables-sepa-direct-debit/tasks.md#task-1.3
	 */
	public function storedFile(array $batch): ?RenderedFile;
}//end interface
