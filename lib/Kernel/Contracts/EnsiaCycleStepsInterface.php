<?php

/**
 * Contract of EnsiaCycleSteps
 *
 * The public surface of EnsiaCycleSteps that other components call, so they
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
use OCA\Shillinq\PaymentRun\RenderedFile;

/**
 * Contract of EnsiaCycleSteps.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EnsiaCycleStepsInterface {

	/**
	 * Store a Bevinding for every shortfall in the cycle's answers that has none yet.
	 *
	 * @param array<string, mixed> $cycle The ENSIAJaarcyclus.
	 *
	 * @return list<string> The ids of the findings created.
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
	 */
	public function createFindings(array $cycle): array;

	/**
	 * The cycle with its rendered college statement.
	 *
	 * @param array<string, mixed> $cycle The ENSIAJaarcyclus.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
	 */
	public function withStatement(array $cycle): array;

	/**
	 * The cycle with its submission XML.
	 *
	 * @param array<string, mixed> $cycle The ENSIAJaarcyclus.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws DomainException When the cycle is not approved or has no statement.
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
	 */
	public function withSubmission(array $cycle): array;

	/**
	 * The stored college statement as a download, or null without one.
	 *
	 * @param array<string, mixed> $cycle The ENSIAJaarcyclus.
	 *
	 * @return RenderedFile|null
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
	 */
	public function statementFile(array $cycle): ?RenderedFile;

	/**
	 * The stored submission XML as a download, or null without one.
	 *
	 * @param array<string, mixed> $cycle The ENSIAJaarcyclus.
	 *
	 * @return RenderedFile|null
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
	 */
	public function submissionFile(array $cycle): ?RenderedFile;
}//end interface
