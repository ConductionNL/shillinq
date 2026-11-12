<?php

/**
 * Portal payment target (portal-payment-initiation).
 *
 * What a portal subject asks to pay: the client-supplied opaque id and
 * whether it names an invoice or a payment request without one.
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
 * The client-supplied target of a pay-now initiation.
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002, REQ-SPPI-003)
 */
final class PortalPaymentTarget {
	/**
	 * The id names an ARInvoice (or its slug).
	 */
	public const INVOICE = 'invoice';

	/**
	 * The id names a PaymentRequest that stands without an invoice.
	 */
	public const REQUEST = 'request';

	/**
	 * Hold the target.
	 *
	 * @param string $id The client-supplied opaque id (untrimmed).
	 * @param string $kind self::INVOICE or self::REQUEST.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $kind,
	) {
	}//end __construct()
}//end class
