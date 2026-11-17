<?php

/**
 * Contract of SbrFilingFileStore
 *
 * The public surface of SbrFilingFileStore that other components call, so they depend
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
 * Contract of SbrFilingFileStore.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SbrFilingFileStoreInterface {

	/**
	 * Store (or replace) the filing's file and return its URI.
	 *
	 * @param string $administrationId The administration.
	 * @param string $fileName         The file name.
	 * @param string $content          The XML.
	 *
	 * @return string The file's URI.
	 *
	 * @throws RuntimeException When no user is signed in or the folder is taken by a file.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.2
	 */
	public function store(string $administrationId, string $fileName, string $content): string;
}//end interface
