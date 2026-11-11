<?php

/**
 * Meter Reading Import Run
 *
 * The state of one meter reading import while its rows are processed: the
 * administration, the row number, the created and refused rows, and the rate
 * plan found per resource type. Used only by MeterReadingImportService.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Usage
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Usage;

/**
 * One import in progress.
 *
 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
 */
final class MeterReadingImportRun {

	/**
	 * The administration the readings belong to.
	 *
	 * @var string
	 */
	public string $administrationId;

	/**
	 * The number of the row being processed; the first row is row 1.
	 *
	 * @var integer
	 */
	public int $row = 0;

	/**
	 * The ids of the created readings.
	 *
	 * @var list<string>
	 */
	public array $created = [];

	/**
	 * The refused rows with their reasons.
	 *
	 * @var list<array{row:int,reason:string}>
	 */
	public array $refused = [];

	/**
	 * The rate plan id per resource type, null when there is none to pick.
	 *
	 * @var array<string,string|null>
	 */
	public array $plans = [];

	/**
	 * Constructor.
	 *
	 * @param string $administrationId The administration the readings belong to.
	 */
	public function __construct(string $administrationId) {
		$this->administrationId = $administrationId;

	}//end __construct()

	/**
	 * Refuse the current row.
	 *
	 * @param string $reason The reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
	 */
	public function refuse(string $reason): void {
		$this->refused[] = ['row' => $this->row, 'reason' => $reason];

	}//end refuse()

	/**
	 * The outcome of the import.
	 *
	 * @return array{created:list<string>,refused:list<array{row:int,reason:string}>}
	 *
	 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
	 */
	public function result(): array {
		return ['created' => $this->created, 'refused' => $this->refused];

	}//end result()
}//end class
