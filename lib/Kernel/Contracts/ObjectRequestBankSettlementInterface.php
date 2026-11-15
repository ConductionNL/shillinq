<?php

/**
 * Contract of ObjectRequestBankSettlement
 *
 * The public surface of ObjectRequestBankSettlement that other components call, so they depend
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
 * Contract of ObjectRequestBankSettlement.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ObjectRequestBankSettlementInterface {

	/**
	 * Who a bank-match settlement is recorded for.
	 *
	 * @var string
	 */
	public const ACTOR = 'system:bank-match';

	/**
	 * Settle the request a confirmed payment-request match names.
	 *
	 * @param array<string, mixed> $match The confirmed ReconciliationMatch.
	 *
	 * @return string What happened: settled, invoice, unbooked, already or missing.
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-004)
	 */
	public function settle(array $match): string;
}//end interface
