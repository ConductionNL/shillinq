<?php

/**
 * Contract of QuickDraftInvoiceService
 *
 * The public surface of QuickDraftInvoiceService that other components call, so they depend
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

use OCA\Shillinq\Service\Sales\QuickDraftRefusedException;

/**
 * Contract of QuickDraftInvoiceService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface QuickDraftInvoiceServiceInterface {

	/**
	 * Save a draft invoice for a customer of the administration.
	 *
	 * @param string              $administrationId The caller's administration, resolved on the server.
	 * @param array<string,mixed> $request          customerId, invoiceDate, dueDate, reference, glAccount, lines.
	 *
	 * @return array<string,mixed> The saved invoice with its id.
	 *
	 * @throws QuickDraftRefusedException When the request cannot become a draft.
	 *
	 * @spec openspec/specs/shillinq-invoice-quick-draft/spec.md
	 */
	public function draft(string $administrationId, array $request): array;
}//end interface
