<?php

/**
 * Contract of OssRateResolver
 *
 * The public surface of OssRateResolver that other components call, so they depend
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
 * Contract of OssRateResolver.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OssRateResolverInterface {

	/**
	 * Decide whether a destination country is an EU member state other than NL (REQ-OSS-001).
	 *
	 * @param string $countryCode ISO 3166-1 alpha-2 destination country.
	 *
	 * @return bool True when the country is an OSS destination (EU, non-NL).
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function isOssDestination(string $countryCode): bool;

	/**
	 * Resolve the applicable destination-country VAT rate at invoice time (REQ-OSS-001).
	 *
	 * Returns the resolved ossContext fields (appliedVatRate, appliedRateCategory,
	 * tedbRateVersion) keyed for direct merge into Invoice.ossContext, or null when
	 * no rate is in force on the invoice date (caller blocks with `oss.rate.missing`).
	 *
	 * @param string $countryCode ISO 3166-1 alpha-2 destination member state.
	 * @param string $rateCategory Rate category (standard / reduced1 / ...).
	 * @param string $invoiceDate Invoice date in YYYY-MM-DD.
	 *
	 * @return array{appliedVatRate: float, appliedRateCategory: string, tedbRateVersion: string}|null
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function resolve(string $countryCode, string $rateCategory, string $invoiceDate): ?array;
}//end interface
