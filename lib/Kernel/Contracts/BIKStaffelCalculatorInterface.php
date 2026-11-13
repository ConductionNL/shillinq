<?php

/**
 * Contract of BIKStaffelCalculator
 *
 * The public surface of BIKStaffelCalculator that other components call, so they depend
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
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

use DateTimeImmutable;

/**
 * Contract of BIKStaffelCalculator.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BIKStaffelCalculatorInterface {

	/**
	 * Default BTW percentage applied under art. 2 lid 2 Besluit BIK.
	 */
	public const DEFAULT_BTW_PERCENTAGE = 0.21;

	/**
	 * Assemble the full IncassoKostenBerekening record body per REQ-CCD-003.
	 *
	 * @param string $invoiceId Invoice FK.
	 * @param string $administrationId Administration scope.
	 * @param string $partyType 'B2B' / 'B2C' / 'GOVERNMENT'.
	 * @param float $principal Outstanding principal in EUR.
	 * @param DateTimeImmutable $effectiveDate First day the rente accrues.
	 * @param DateTimeImmutable $calculatedOn Calculation date.
	 * @param float|null $rateB2B Override the B2B handelsrente.
	 * @param float|null $rateB2C Override the B2C wettelijke rente.
	 * @param bool $vatOffsettable True when the creditor CAN offset VAT (no surcharge).
	 * @param float $vatPercentage VAT rate applied when !vatOffsettable (default 21%).
	 *
	 * @return array<string,mixed> Body ready to persist via ObjectService::saveObject.
	 *
	 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
	 */
	public function compose(
		string $invoiceId,
		string $administrationId,
		string $partyType,
		float $principal,
		DateTimeImmutable $effectiveDate,
		DateTimeImmutable $calculatedOn,
		?float $rateB2B = null,
		?float $rateB2C = null,
		bool $vatOffsettable = true,
		float $vatPercentage = self::DEFAULT_BTW_PERCENTAGE,
	): array;

	/**
	 * Check whether B2C incasso-cost calculation is permitted on the given day.
	 *
	 * Per art. 6:96 lid 6 BW, B2C debiteuren receive a mandatory 14-day grace
	 * after the stage-3 aanmaning before incassokosten may be levied. Stage 3
	 * fires on daysAfterExpiryDate = 30, so the earliest permitted day is 44.
	 *
	 * @param string $partyType 'B2B' / 'B2C'.
	 * @param int $daysInArrears Number of days the invoice is overdue.
	 *
	 * @return bool True when the calculation is permitted.
	 *
	 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
	 */
	public function isCalculationPermitted(string $partyType, int $daysInArrears): bool;
}//end interface
