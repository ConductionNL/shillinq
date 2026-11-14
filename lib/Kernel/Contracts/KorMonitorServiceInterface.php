<?php

/**
 * Contract of KorMonitorService
 *
 * The public surface of KorMonitorService that other components call, so they depend
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
 * Contract of KorMonitorService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface KorMonitorServiceInterface {

	/**
	 * Compute the KOR threshold-status for one administration + year (REQ-KOR-002, REQ-KOR-003).
	 *
	 * @param string $administrationId Administration scope (server-resolved, REQ-KOR-002).
	 * @param int $year Calendar year to report.
	 *
	 * @return array{
	 *   administrationId:string, year:int, currentRevenue:float, threshold:float,
	 *   thresholdUtilisation:float, perMonth:array<string,float>, forecastYearEnd:float,
	 *   prognoseStatus:string, severity:?string, trigger:?string, optOutPermitted:bool
	 * }
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function status(string $administrationId, int $year): array;
}//end interface
