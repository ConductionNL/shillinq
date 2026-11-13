<?php

/**
 * Contract of ContributionRaiseService
 *
 * The public surface of ContributionRaiseService that other components call, so they depend
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

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract of ContributionRaiseService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContributionRaiseServiceInterface {

	/**
	 * Raise a contribution for every recipient in the call.
	 *
	 * The same array in and out as `POST /apps/shillinq/api/contributions/raise`
	 * (contract.md), so an app already inside the request can call it directly.
	 *
	 * @param array<string, mixed> $payload The call: chargeable, charge and recipients.
	 *
	 * @return array{batchId: string, raised: int, skipped: int, failed: int, results: array<int, array<string, mixed>>} The outcome per recipient.
	 *
	 * @throws RuntimeException When the caller lacks the payment.request action (message starts with 403).
	 * @throws InvalidArgumentException When the call cannot be raised as a whole.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-002)
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-003)
	 */
	public function raise(array $payload): array;
}//end interface
