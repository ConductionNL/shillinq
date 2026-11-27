<?php

/**
 * Contract of FxRateFeedStatus
 *
 * The public surface of FxRateFeedStatus that other components call, so they depend
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
 * Contract of FxRateFeedStatus.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FxRateFeedStatusInterface {

	/**
	 * The status envelope the FX rates page reads.
	 *
	 * Shape:
	 *  - fromFeed: true when the bound adapter delivers rates, false when they are typed.
	 *  - statusLine: the translated sentence saying which.
	 *  - newest: one entry per currency pair, newest date first, then by pair.
	 *  - newestLine: the translated "newest rate" line, empty when there are no rates.
	 *
	 * @return array{fromFeed:bool,statusLine:string,newest:array<int,array{transactionCurrency:string,baseCurrency:string,newestDate:string}>,newestLine:string}
	 *
	 * @spec openspec/changes/archive/2026-11-24-banking-fx-at-booking/tasks.md#task-3.1
	 */
	public function status(): array;
}//end interface
