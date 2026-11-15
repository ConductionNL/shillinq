<?php

/**
 * Contract of RevenueRecognitionService
 *
 * The public surface of RevenueRecognitionService that other components call, so they depend
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
 * Contract of RevenueRecognitionService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RevenueRecognitionServiceInterface {

	/**
	 * Compute recognized recurring revenue for one administration + period (REQ engine-001..004).
	 *
	 * Returns recognized RECURRING revenue (whole-month overlap × frequency-normalized
	 * monthly rate), the separate one-off figure, the annualized ARR run-rate, the
	 * currency and the count of RECURRING lines folded. Empty input yields a clean zero,
	 * never an exception. A RECURRING line with a null/unknown frequentie contributes 0
	 * and is logged (fail-closed).
	 *
	 * @param string $administrationId Administration scope (validated + RBAC-enforced by the caller).
	 * @param string $from ISO period start (YYYY-MM-DD).
	 * @param string $to ISO period end (YYYY-MM-DD).
	 *
	 * @return array{recognized:float, oneOff:float, arr:float, currency:string, lineCount:int}
	 *
	 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-1
	 */
	public function computeRecurring(string $administrationId, string $from, string $to): array;
}//end interface
