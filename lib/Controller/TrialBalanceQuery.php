<?php

/**
 * Trial Balance Query
 *
 * The period, administration and optional prior period of one trial balance,
 * as read from the request.
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
 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-4-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * Which period of which administration, compared with which prior period.
 *
 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-4-1
 */
final class TrialBalanceQuery {
	/**
	 * Period and administration identifiers are short slugs (REQ-TB-015).
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * The period, trimmed.
	 *
	 * @var string
	 */
	public readonly string $periodId;

	/**
	 * The administration, trimmed.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The prior period, trimmed, '' when not given.
	 *
	 * @var string
	 */
	public readonly string $priorPeriodId;

	/**
	 * Read the query from the request.
	 *
	 * @param IRequest $request The request.
	 */
	public function __construct(IRequest $request) {
		$this->periodId = trim((string)$request->getParam('period_id', ''));
		$this->administrationId = trim((string)$request->getParam('administration_id', ''));
		$this->priorPeriodId = trim((string)$request->getParam('prior_period_id', ''));

	}//end __construct()

	/**
	 * Why the input is refused, null when it is complete and well formed.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-4-1
	 */
	public function inputError(): ?string {
		if ($this->periodId === '') {
			return 'period_id is required';
		}

		if ($this->administrationId === '') {
			return 'administration_id is required';
		}

		return $this->shapeError();

	}//end inputError()

	/**
	 * The computation filters: the prior period when it is a valid identifier.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-4-1
	 */
	public function filters(): array {
		if ($this->priorPeriodId !== '' && preg_match(self::ID_PATTERN, $this->priorPeriodId) === 1) {
			return ['priorPeriodId' => $this->priorPeriodId];
		}

		return [];

	}//end filters()

	/**
	 * Why an identifier is malformed, null when both are well formed.
	 *
	 * @return string|null
	 */
	private function shapeError(): ?string {
		if (preg_match(self::ID_PATTERN, $this->periodId) !== 1) {
			return 'period_id must be a valid period identifier';
		}

		if (preg_match(self::ID_PATTERN, $this->administrationId) !== 1) {
			return 'administration_id must be a valid administration identifier';
		}

		return null;

	}//end shapeError()
}//end class
