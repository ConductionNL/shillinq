<?php

/**
 * Contract of ObjectRequestInvoiceService
 *
 * The public surface of ObjectRequestInvoiceService that other components call, so they depend
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

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract of ObjectRequestInvoiceService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ObjectRequestInvoiceServiceInterface {

	/**
	 * Issue one invoice for a request and answer its uuid.
	 *
	 * @param array<string, mixed> $request The validated request, before it is saved.
	 * @param string $administrationId The administration the invoice belongs to.
	 * @param string $today The invoice date (Y-m-d).
	 *
	 * @return string The ARInvoice uuid.
	 *
	 * @throws InvalidArgumentException When the request has no administration, no debtor, or no amount.
	 * @throws RuntimeException When OpenRegister answers the save without an id.
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-006)
	 */
	public function issue(array $request, string $administrationId, string $today): string;
}//end interface
