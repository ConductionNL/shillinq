<?php

/**
 * Contract of PaymentRunProposalService
 *
 * The public surface of PaymentRunProposalService that other components call, so they depend
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
 * Contract of PaymentRunProposalService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PaymentRunProposalServiceInterface {

	/**
	 * Every line goes out on the run's execution date.
	 *
	 * @var string
	 */
	public const DATES_RUN = 'run';

	/**
	 * Each line goes out on the later of its due date and the run's date.
	 *
	 * @var string
	 */
	public const DATES_DUE = 'due';

	/**
	 * Draft a run of every payable invoice due on or before a date.
	 *
	 * @param string $administrationId  The administration.
	 * @param string $dueOnOrBefore     The last due date to include (Y-m-d).
	 * @param string $debtorAccountIban The account the money leaves from.
	 * @param string $executionDate     The run's execution date (Y-m-d).
	 * @param string $lineDates         DATES_RUN, or DATES_DUE to give each line the later of its due date and the run date.
	 *
	 * @return array{paymentRun: array<string, mixed>|null, skipped: list<array<string, string>>} Draft run or null, and what was left out.
	 *
	 * @spec openspec/changes/archive/2026-09-29-banking-payment-run/tasks.md#task-3.1
	 */
	public function propose(
		string $administrationId,
		string $dueOnOrBefore,
		string $debtorAccountIban,
		string $executionDate,
		string $lineDates=self::DATES_RUN
	): array;
}//end interface
