<?php

/**
 * Outcome value-object for PortalPaymentSessionService::initiate() (portal-payment-initiation).
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
 * One of four uniform outcomes the controller maps to an HTTP status:
 *
 *  - `ok`               -> 200 `{checkoutUrl}`
 *  - `forbidden`        -> 403 — the SAME response whether the target is
 *                          foreign-owned, non-payable, non-existent, or
 *                          malformed (no existence oracle, REQ-SPPI-003).
 *  - `deferred`         -> 503 — the bound provider is dormant; no checkout
 *                          URL is ever fabricated (REQ-SPPI-001).
 *  - `downstream_error` -> 502 — an OpenRegister or PSP call failed.
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002, REQ-SPPI-003)
 */
final class PortalPaymentSessionResult {
	/**
	 * Private constructor — use the named factory methods.
	 *
	 * @param string $status One of 'ok'|'forbidden'|'deferred'|'downstream_error'.
	 * @param string|null $checkoutUrl The Mollie checkout URL (only set for 'ok').
	 */
	private function __construct(
		public readonly string $status,
		public readonly ?string $checkoutUrl = null,
	) {
	}//end __construct()

	/**
	 * A checkout URL was minted.
	 *
	 * @param string $checkoutUrl The Mollie checkout URL.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002)
	 */
	public static function success(string $checkoutUrl): self {
		return new self(status: 'ok', checkoutUrl: $checkoutUrl);
	}//end success()

	/**
	 * Uniform not-authorised outcome (foreign/non-payable/non-existent/malformed).
	 *
	 * @return self
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-003)
	 */
	public static function forbidden(): self {
		return new self(status: 'forbidden');
	}//end forbidden()

	/**
	 * The bound provider is dormant — no checkout URL, honest degradation.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-001)
	 */
	public static function deferred(): self {
		return new self(status: 'deferred');
	}//end deferred()

	/**
	 * An OpenRegister or PSP call failed.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002)
	 */
	public static function downstreamError(): self {
		return new self(status: 'downstream_error');
	}//end downstreamError()
}//end class
