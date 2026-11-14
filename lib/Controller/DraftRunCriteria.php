<?php

/**
 * Draft Run Criteria
 *
 * What a payment-run proposal is asked for: the administration, the due
 * date cut-off, the execution date and the debtor account.
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
 * @spec openspec/changes/archive/2026-09-29-banking-payment-run/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * The proposal form of one draft payment run, read from the request.
 *
 * @spec openspec/changes/archive/2026-09-29-banking-payment-run/tasks.md#task-3.2
 */
final class DraftRunCriteria {
	/**
	 * The (already authorised) administration.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The due-date cut-off as given (Y-m-d expected).
	 *
	 * @var string
	 */
	public readonly string $dueOnOrBefore;

	/**
	 * The execution date as given (Y-m-d expected).
	 *
	 * @var string
	 */
	public readonly string $executionDate;

	/**
	 * The debtor IBAN, upper-cased without spaces.
	 *
	 * @var string
	 */
	public readonly string $debtorIban;

	/**
	 * Read the proposal form for an administration.
	 *
	 * @param IRequest $request          The request.
	 * @param string   $administrationId The authorised administration.
	 */
	public function __construct(IRequest $request, string $administrationId) {
		$this->administrationId = $administrationId;
		$this->dueOnOrBefore = (string)$request->getParam('dueOnOrBefore', '');
		$this->executionDate = (string)$request->getParam('executionDate', '');
		$this->debtorIban = strtoupper(str_replace(' ', '', (string)$request->getParam('debtorAccountIban', '')));

	}//end __construct()

	/**
	 * Whether both dates are Y-m-d dates and a debtor IBAN is given.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-09-29-banking-payment-run/tasks.md#task-3.2
	 */
	public function isComplete(): bool {
		return $this->isDate(value: $this->dueOnOrBefore) === true
			&& $this->isDate(value: $this->executionDate) === true
			&& $this->debtorIban !== '';
	}//end isComplete()

	/**
	 * Whether a value is a Y-m-d date.
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 */
	private function isDate(string $value): bool {
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		return $date !== false && $date->format('Y-m-d') === $value;

	}//end isDate()
}//end class
