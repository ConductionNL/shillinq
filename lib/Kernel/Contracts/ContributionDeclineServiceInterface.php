<?php

/**
 * Contract of ContributionDeclineService
 *
 * The public surface of ContributionDeclineService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of ContributionDeclineService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContributionDeclineServiceInterface {

	/**
	 * The contribution is declined (or already was, by this guardian).
	 *
	 * @var string
	 */
	public const DECLINED = 'declined';

	/**
	 * OpenRegister failed.
	 *
	 * @var string
	 */
	public const DOWNSTREAM_ERROR = 'downstream_error';

	/**
	 * Decline the guardian's own open voluntary contribution.
	 *
	 * @param array<string, mixed> $claims The VERIFIED assertion claims.
	 * @param string $target The client-supplied opaque invoice id.
	 * @param DateTimeImmutable|null $now The moment to stamp; defaults to now.
	 *
	 * @return string One of DECLINED, FORBIDDEN, DOWNSTREAM_ERROR.
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
	 */
	public function decline(array $claims, string $target, ?DateTimeImmutable $now = null): string;
}//end interface
