<?php

/**
 * Contract of LedgerPivotService
 *
 * The public surface of LedgerPivotService that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of LedgerPivotService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LedgerPivotServiceInterface {

	/**
	 * Pivot one administration's result lines.
	 *
	 * @param string $administrationId The administration, already checked for access.
	 * @param string $rowAxis          One of AXES.
	 * @param string $columnAxis       One of AXES, different from the row axis.
	 * @param string $from             First posting date, YYYY-MM-DD.
	 * @param string $to               Last posting date, YYYY-MM-DD.
	 *
	 * @return array<string, mixed> rows, columns, cells, rowTotals, columnTotals, total, capped, truncated.
	 *
	 * @throws InvalidArgumentException When an axis, a date or the range is not accepted.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function pivot(string $administrationId, string $rowAxis, string $columnAxis, string $from, string $to): array;
}//end interface
