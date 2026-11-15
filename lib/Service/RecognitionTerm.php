<?php

/**
 * Recognition Term
 *
 * An inclusive [start, end] ISO date interval: a line's effective term, or the reporting period.
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
 * One date interval.
 *
 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-1
 */
class RecognitionTerm {

	/**
	 * Interval start (ISO date), or '' when unknown.
	 *
	 * @var string
	 */
	public string $start = '';

	/**
	 * Interval end (ISO date), or '' when unknown.
	 *
	 * @var string
	 */
	public string $end = '';
}//end class
