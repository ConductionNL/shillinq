<?php

/**
 * Recognition Line
 *
 * One SalesOrderLine together with its effective term, as folded by {@see RevenueRecognitionService}.
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
 * A line and its term.
 *
 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-1
 */
class RecognitionLine {

	/**
	 * The SalesOrderLine.
	 *
	 * @var array<string,mixed>
	 */
	public array $line = [];

	/**
	 * Its effective [termStart, termEnd].
	 *
	 * @var RecognitionTerm
	 */
	public RecognitionTerm $term;
}//end class
