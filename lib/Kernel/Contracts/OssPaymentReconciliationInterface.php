<?php

/**
 * Contract of OssPaymentReconciliation
 *
 * The public surface of OssPaymentReconciliation that other components call, so they depend
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
 * Contract of OssPaymentReconciliation.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OssPaymentReconciliationInterface {

	/**
	 * Lifecycle precondition: may an OssReturn / OssPayment be marked paid (REQ-OSS-008)?
	 *
	 * Permits the transition only when a matching bank transaction has been linked
	 * (bankTransactionId present) and the amount equals the return total.
	 *
	 * @param array<string,mixed> $ossPayment The OssPayment object array.
	 * @param array<string,mixed> $ossReturn The OssReturn being settled.
	 *
	 * @return bool True when the paid transition is permitted.
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function canMarkPaid(array $ossPayment, array $ossReturn): bool;

	/**
	 * Compare confirmed per-country distribution to the declared return (REQ-OSS-008).
	 *
	 * Returns 'reconciled' when every confirmed country amount equals the declared
	 * per-line VAT total (to the cent) and the country sets match, otherwise
	 * 'discrepancy' together with the per-country differences (confirmed - declared,
	 * in float money).
	 *
	 * @param array<string,mixed> $ossReturn The OssReturn carrying declared line items.
	 * @param array<string,int|float> $confirmation Belastingdienst per-country confirmation.
	 *
	 * @return array{status: string, differences: array<string,float>}
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function reconcileDistribution(array $ossReturn, array $confirmation): array;
}//end interface
