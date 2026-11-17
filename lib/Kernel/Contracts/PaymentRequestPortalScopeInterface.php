<?php

/**
 * Contract of PaymentRequestPortalScope
 *
 * The public surface of PaymentRequestPortalScope that other components call, so they depend
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
 * Contract of PaymentRequestPortalScope.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PaymentRequestPortalScopeInterface {

	/**
	 * True when the request has no invoice, names a customer debtor and does
	 * not carry that customer as its scope yet.
	 *
	 * @param array<string, mixed> $request The payment request payload.
	 *
	 * @return bool True when stamp() would change the request.
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	public function needsStamp(array $request): bool;

	/**
	 * The request with `customerId` set when it has no invoice and a customer
	 * debtor; otherwise unchanged.
	 *
	 * @param array<string, mixed> $request The payment request payload.
	 *
	 * @return array<string, mixed> The request, stamped when the rule applies.
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	public function stamp(array $request): array;
}//end interface
