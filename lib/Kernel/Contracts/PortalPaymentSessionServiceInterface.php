<?php

/**
 * Contract of PortalPaymentSessionService
 *
 * The public surface of PortalPaymentSessionService that other components call, so they depend
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

use OCA\Shillinq\Service\Payment\PortalPaymentSessionResult;

/**
 * Contract of PortalPaymentSessionService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalPaymentSessionServiceInterface {

	/**
	 * Initiate (or reuse) a payment session for the subject's own invoice.
	 *
	 * @param array<string, mixed> $claims The VERIFIED assertion claims (never trust unverified input).
	 * @param string $target The client-supplied opaque invoice id/slug.
	 *
	 * @return PortalPaymentSessionResult
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002, REQ-SPPI-003, REQ-SPPI-004)
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-010)
	 */
	public function initiate(array $claims, string $target): PortalPaymentSessionResult;

	/**
	 * Initiate a payment session for the subject's own payment request that
	 * stands without an invoice, such as leges on a case (REQ-SOPR-005). The
	 * request's own amount is charged; nothing is minted.
	 *
	 * @param array<string, mixed> $claims The VERIFIED assertion claims.
	 * @param string $target The client-supplied opaque payment request id.
	 *
	 * @return PortalPaymentSessionResult
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	public function initiateForRequest(array $claims, string $target): PortalPaymentSessionResult;
}//end interface
