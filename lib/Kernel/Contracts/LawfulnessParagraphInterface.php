<?php

/**
 * Contract of LawfulnessParagraph
 *
 * The public surface of LawfulnessParagraph that other components call, so they
 * depend on this contract instead of the implementation.
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
 * Contract of LawfulnessParagraph.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LawfulnessParagraphInterface {

	/**
	 * The paragraph totals of one administration's financial year.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $financialYear    The financial year.
	 *
	 * @return array<string, mixed> The paragraph fields to store.
	 *
	 * @throws DomainException When the year has no tolerance.
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-rechtmatigheidsverantwoording/spec.md
	 */
	public function compute(string $administrationId, int $financialYear): array;
}//end interface
