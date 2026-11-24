<?php

/**
 * Contract of EuDeclarationBuilder
 *
 * The public surface of EuDeclarationBuilder that other components call, so
 * they depend on this contract instead of the implementation.
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
 * Contract of EuDeclarationBuilder.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EuDeclarationBuilderInterface {

	/**
	 * Propose and store the draft declaration of a project for a period.
	 *
	 * @param array<string,mixed> $project    The EuProject, with its id.
	 * @param string              $periodFrom The first day of the period (Y-m-d).
	 * @param string              $periodTo   The last day of the period (Y-m-d).
	 *
	 * @return array<string,mixed> The stored draft EuDeclaration, with its id.
	 *
	 * @throws DomainException When the period is invalid or an open irregularity blocks the project.
	 *
	 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-single-audit-eu-fondsen/spec.md#requirement-a-declaration-bundles-eligible-expenditure-with-evidence-req-euf-012
	 */
	public function propose(array $project, string $periodFrom, string $periodTo): array;

	/**
	 * Submit every expenditure of a declaration being submitted.
	 *
	 * @param array<string,mixed> $declaration The EuDeclaration as the submit transition leaves it.
	 *
	 * @return array<string,mixed> The declaration with submittedAt set.
	 *
	 * @throws DomainException When an expenditure of the declaration can no longer be submitted.
	 *
	 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-single-audit-eu-fondsen/spec.md#requirement-submitting-a-declaration-submits-its-expenditures-req-euf-013
	 */
	public function submit(array $declaration): array;
}//end interface
