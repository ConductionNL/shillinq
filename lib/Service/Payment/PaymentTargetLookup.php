<?php

/**
 * Payment target lookup (portal-payment-initiation).
 *
 * The lookup state of one pay-now initiation in PortalPaymentSessionService:
 * OpenRegister's object service, the trimmed opaque target id and the kind of
 * target it names. Never leaves the service.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Payment
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002, REQ-SPPI-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Payment;

/**
 * Where and what a pay-now initiation looks up.
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002, REQ-SPPI-003)
 */
final class PaymentTargetLookup {
	/**
	 * Hold the lookup.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $targetId The trimmed, opaque target id.
	 * @param string $kind PortalPaymentTarget::INVOICE or PortalPaymentTarget::REQUEST.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $targetId,
		public readonly string $kind,
	) {
	}//end __construct()
}//end class
