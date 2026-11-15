<?php

/**
 * Revenue Window
 *
 * The administration and the date range one recurring-revenue projection is asked for.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * The trimmed administration and date range of one projection request.
 *
 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-2
 */
final class RevenueWindow {
	/**
	 * Accepted shape for a date: YYYY-MM-DD.
	 *
	 * @var string
	 */
	private const DATE_PATTERN = '/^\\d{4}-\\d{2}-\\d{2}$/';

	/**
	 * The administration.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The first day.
	 *
	 * @var string
	 */
	public readonly string $from;

	/**
	 * The last day.
	 *
	 * @var string
	 */
	public readonly string $to;

	/**
	 * Read the request parameters.
	 *
	 * @param IRequest $request The request.
	 */
	public function __construct(IRequest $request) {
		$this->administrationId = trim((string)$request->getParam('administrationId', ''));
		$this->from = trim((string)$request->getParam('from', ''));
		$this->to = trim((string)$request->getParam('to', ''));

	}//end __construct()

	/**
	 * Whether the administration id is a short slug (input validation, not authorisation).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-2
	 */
	public function wellFormedAdministration(): bool {
		return preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $this->administrationId) === 1;
	}//end wellFormedAdministration()

	/**
	 * Whether a date is YYYY-MM-DD.
	 *
	 * @param string $date The date.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-2
	 */
	public static function isIsoDate(string $date): bool {
		return preg_match(self::DATE_PATTERN, $date) === 1;
	}//end isIsoDate()
}//end class
