<?php

/**
 * Shillinq FxRevaluationRun
 *
 * One period-end revaluation run of FxRevaluationService: the administration
 * and period it revalues, the functional currency, the period-end date the
 * closing rates are looked up for and the run timestamp every mark and
 * posting of the run carries. Never leaves the service.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Treasury
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Treasury;

use DateTimeImmutable;

/**
 * The scope, currency and dates of one revaluation run.
 *
 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
 */
final class FxRevaluationRun {
	/**
	 * Hold the run.
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $periodId The yyyy-mm period.
	 * @param string $functionalCurrency Administration's functional currency.
	 * @param DateTimeImmutable $periodEndDate Period-end date used for the rate lookup.
	 * @param DateTimeImmutable $asOf Run timestamp used for postedAt/lastUpdated.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly string $periodId,
		public readonly string $functionalCurrency,
		public readonly DateTimeImmutable $periodEndDate,
		public readonly DateTimeImmutable $asOf,
	) {
	}//end __construct()
}//end class
