<?php

/**
 * Recognition Run
 *
 * One {@see RevenueRecognitionService::computeRecurring()} run: the period, the orders, and the running cent totals.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * State of one recognition run.
 *
 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-1
 */
class RecognitionRun {

	/**
	 * The reporting period [from, to].
	 *
	 * @var RecognitionTerm
	 */
	public RecognitionTerm $period;

	/**
	 * orderId => SalesOrder.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $orders = [];

	/**
	 * Recognised RECURRING revenue so far, in cents.
	 *
	 * @var int
	 */
	public int $recognizedCents = 0;

	/**
	 * Recognised ONE_OFF revenue so far, in cents.
	 *
	 * @var int
	 */
	public int $oneOffCents = 0;

	/**
	 * Monthly run-rate of lines in force at period end, in cents.
	 *
	 * @var int
	 */
	public int $arrCents = 0;

	/**
	 * RECURRING lines folded.
	 *
	 * @var int
	 */
	public int $lineCount = 0;

	/**
	 * The currency, from the last order that names one.
	 *
	 * @var string
	 */
	public string $currency = 'EUR';
}//end class
