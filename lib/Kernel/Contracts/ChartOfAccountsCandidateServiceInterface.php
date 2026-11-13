<?php

/**
 * Contract of ChartOfAccountsCandidateService
 *
 * The public surface of ChartOfAccountsCandidateService that other components call, so they depend
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
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of ChartOfAccountsCandidateService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ChartOfAccountsCandidateServiceInterface {

	/**
	 * Resolve the active, administration-scoped chart-of-accounts candidates
	 * (REQ-GAC-002).
	 *
	 * @param string $administrationId The draft's administration id.
	 *
	 * @return array<int, array{code: string, label: string|null}> The candidate accounts. Empty
	 *                                                             (never an error) when OR is unavailable or the administration has no active
	 *                                                             accounts.
	 *
	 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
	 */
	public function activeCandidates(string $administrationId): array;
}//end interface
