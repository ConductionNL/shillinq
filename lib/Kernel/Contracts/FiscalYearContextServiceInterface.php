<?php

/**
 * Contract of FiscalYearContextService
 *
 * The public surface of FiscalYearContextService that other components call, so they depend
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

use DateTimeInterface;

/**
 * Contract of FiscalYearContextService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FiscalYearContextServiceInterface {

	/**
	 * Resolve the active fiscal-year window for an administration.
	 *
	 * The "current" fiscal year is the one whose half-open
	 * `[startDate, endDate)` window contains the reference instant (the
	 * service "now" when null). For the default calendar-year config the
	 * active FY is the current calendar year; for a July-start non-
	 * calendar boekjaar the active FY rolls over on July 1.
	 *
	 * Returns null when the administration cannot be accessed by the
	 * authenticated user (ADR-005 / IDOR mask) or when the administration
	 * record cannot be loaded — the caller treats that exactly the same
	 * as "no data" and masks the response as 404 / empty envelope.
	 *
	 * @param string $administrationId Administration id (REQ-MA-001).
	 * @param DateTimeInterface|null $now Reference instant; defaults to "now" UTC.
	 *
	 * @return array{fiscalYear:int,startDate:string,endDate:string,administrationId:string}|null
	 *
	 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
	 */
	public function resolveActiveWindow(string $administrationId, ?DateTimeInterface $now = null): ?array;
}//end interface
