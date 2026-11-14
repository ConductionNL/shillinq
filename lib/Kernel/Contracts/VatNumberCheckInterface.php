<?php

/**
 * Contract of VatNumberCheck
 *
 * The public surface of VatNumberCheck that other components call, so they depend
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

use DomainException;

/**
 * Contract of VatNumberCheck.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VatNumberCheckInterface {

	/**
	 * The records a person can check, by route type: schema and the field holding the number.
	 *
	 * @var array<string,array{schema:string,field:string}>
	 */
	public const RECORDS = [
		'customer' => ['schema' => 'CustomerMaster', 'field' => 'vatId'],
		'supplier' => ['schema' => 'Payee', 'field' => 'vatNumber'],
	];

	/**
	 * Check a customer's or supplier's VAT number and write the outcome onto it (REQ-TVNC-001).
	 *
	 * A valid answer sets the status, the check date and the validity; an
	 * invalid one sets the status and the check date. When VIES cannot be
	 * reached the status reads not reachable and the date of the last valid
	 * result is kept, so the page shows when the number was last confirmed.
	 *
	 * @param string              $type   `customer` or `supplier`.
	 * @param array<string,mixed> $record The record, with `id` and `administrationId`.
	 *
	 * @return array{status:string,vatId:string,checkedAt:string,lastValidAt:?string,name:string,address:string}
	 *
	 * @throws DomainException When the record carries no VAT number.
	 *
	 * @spec openspec/changes/tax-vat-number-check/tasks.md#task-2.1
	 */
	public function checkRecord(string $type, array $record): array;
}//end interface
