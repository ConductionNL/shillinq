<?php

/**
 * Contract of SelfBilledInvoiceIntake
 *
 * The public surface of SelfBilledInvoiceIntake that other components call, so they depend
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
 * Contract of SelfBilledInvoiceIntake.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SelfBilledInvoiceIntakeInterface {

	/**
	 * Take one inbound document integriq reported.
	 *
	 * @param array<string,mixed> $data The CloudEvent data: {senderPeppolId, documentType, payloadReference}.
	 *
	 * @return array{result:string,reason?:string,id?:string} `drafted`, `duplicate`, `refused`,
	 *                                                        `supplier` or `skipped` (not an
	 *                                                        invoice, or no administration).
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-einvoice-exchange/tasks.md#task-3.2
	 */
	public function receive(array $data): array;
}//end interface
