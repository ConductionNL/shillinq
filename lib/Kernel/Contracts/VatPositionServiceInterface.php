<?php

/**
 * Contract of VatPositionService
 *
 * The public surface of VatPositionService that other components call, so they depend
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
 * Contract of VatPositionService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VatPositionServiceInterface {

	/**
	 * The VAT position of an administration for a period.
	 *
	 * @param string $administrationId The administration.
	 * @param string $period           `YYYY-Qn`, `YYYY-MM` or `YYYY`.
	 *
	 * @return array<string,mixed> The period, its bounds, the boxes with their lines, the totals,
	 *                             the amount payable (negative: refundable) and which of the two it is.
	 *
	 * @throws InvalidArgumentException When the period is not one of the three forms.
	 *
	 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-1.1
	 */
	public function positionFor(string $administrationId, string $period): array;

}//end interface
