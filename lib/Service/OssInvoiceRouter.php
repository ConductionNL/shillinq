<?php

/**
 * OSS / ICP Invoice Router
 *
 * ADR-031 exception-path service implementing the B2C-vs-B2B fork at invoice
 * creation time (REQ-OSS-006, REQ-OSS-015). On invoice save the system forks on
 * the counterparty `customerType`: B2C cross-border EU sales route to the OSS
 * pipeline (destination-country VAT, ossContext, threshold counter); B2B supplies
 * with a validated VAT-ID route to the reverse-charge / ICP path (0% VAT,
 * bookkeeping-icp-opgaaf); B2B supplies WITHOUT a validated VAT-ID are reclassified
 * as B2C and routed to OSS with a warning. NL domestic sales are never OSS.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Decides whether an invoice flows through OSS, ICP, or the domestic path.
 *
 * Pure decision logic: takes the counterparty + destination and returns a routing
 * decision, with no persistence. The caller (invoice-save) applies the decision —
 * OSS rate resolution (OssRateResolver) for `oss`, 0% reverse-charge for `icp`,
 * domestic BTW for `domestic`.
 *
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 */
class OssInvoiceRouter {
	/**
	 * Construct the router with the OSS-destination predicate.
	 *
	 * @param OssRateResolver $rateResolver Provides the EU-member-state predicate.
	 */
	public function __construct(
		private readonly OssRateResolver $rateResolver,
	) {
	}//end __construct()

	/**
	 * Route an invoice on customerType + destination + VAT-ID validity (REQ-OSS-015).
	 *
	 * Returns a decision array:
	 *  - route   : 'oss' | 'icp' | 'domestic'
	 *  - reason  : machine code for the chosen route
	 *  - warning : optional warning code when a B2B sale is reclassified to B2C
	 *
	 * @param string $customerType 'b2c' or 'b2b'.
	 * @param string $destinationCountry ISO 3166-1 alpha-2 destination country.
	 * @param string $vatValidationStatus 'valid' | 'invalid' | '' (only relevant for b2b).
	 *
	 * @return array{route: string, reason: string, warning: ?string}
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function route(string $customerType, string $destinationCountry, string $vatValidationStatus = ''): array {
		$type = strtolower(trim($customerType));

		// Domestic NL and non-EU destinations never enter OSS (REQ-OSS-001).
		if ($this->rateResolver->isOssDestination(countryCode: $destinationCountry) === false) {
			return ['route' => 'domestic', 'reason' => 'oss.route.domestic', 'warning' => null];
		}

		if ($type === 'b2b') {
			if (strtolower(trim($vatValidationStatus)) === 'valid') {
				// B2B with a validated VAT-ID: reverse-charge / ICP, never OSS (REQ-OSS-006).
				return ['route' => 'icp', 'reason' => 'oss.route.reverse_charge', 'warning' => null];
			}

			// B2B without a validated VAT-ID: reclassify to B2C, route to OSS, warn (REQ-OSS-006).
			return ['route' => 'oss', 'reason' => 'oss.route.b2b_reclassified', 'warning' => 'oss.vatid.missing_reclassified_b2c'];
		}

		// B2C cross-border EU: OSS (REQ-OSS-015).
		return ['route' => 'oss', 'reason' => 'oss.route.b2c', 'warning' => null];
	}//end route()
}//end class
