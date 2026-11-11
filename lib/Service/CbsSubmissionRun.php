<?php

/**
 * CBS submission run
 *
 * The state of one CBSExportService::generateSubmission() call: the
 * administration and reporting period it covers, the organization metadata
 * it carries, and the submission number and persisted CBSLine rows the run
 * produces on its way. Never leaves the service.
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
 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeInterface;

/**
 * One CBS submission being generated.
 *
 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
 */
final class CbsSubmissionRun {
	/**
	 * The submission number, set once it is allocated.
	 *
	 * @var string
	 */
	public string $submissionNumber = '';

	/**
	 * The persisted CBSLine rows of the submission.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $lines = [];

	/**
	 * Hold the run.
	 *
	 * @param string $administrationId Administration scope.
	 * @param DateTimeInterface $periodStart Reporting period start (inclusive).
	 * @param DateTimeInterface $periodEnd Reporting period end (inclusive).
	 * @param array<string,mixed> $organization Organization metadata: legalName, kvkNumber, taxIdentificationNumber.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly DateTimeInterface $periodStart,
		public readonly DateTimeInterface $periodEnd,
		public readonly array $organization,
	) {
	}//end __construct()

	/**
	 * The period start as Y-m-d.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
	 */
	public function startDate(): string {
		return $this->periodStart->format('Y-m-d');
	}//end startDate()

	/**
	 * The period end as Y-m-d.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
	 */
	public function endDate(): string {
		return $this->periodEnd->format('Y-m-d');
	}//end endDate()
}//end class
