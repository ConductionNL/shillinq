<?php

/**
 * Contract of OssReturnGenerator
 *
 * The public surface of OssReturnGenerator that other components call, so they depend
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
 * Contract of OssReturnGenerator.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OssReturnGeneratorInterface {

	/**
	 * Generate a draft OssReturn for a closed quarter (REQ-OSS-004).
	 *
	 * Fetches every OSS-eligible invoice and credit note for the administration in
	 * the reporting period (YYYY-Qn), aggregates them, and returns a draft return
	 * payload (status:draft, type:regular) ready to persist.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param int $periodYear Fiscal year.
	 * @param string $periodQuarter Quarter (Q1..Q4).
	 * @param string $registrationId FK to the active OssRegistration.
	 *
	 * @return array<string,mixed> Draft OssReturn payload.
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function generateDraft(string $administrationId, int $periodYear, string $periodQuarter, string $registrationId): array;
}//end interface
