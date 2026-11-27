<?php

/**
 * Contract of SisaAppendix
 *
 * The public surface of SisaAppendix that other components call, so they
 * depend on this contract instead of the implementation.
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
 * Contract of SisaAppendix.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SisaAppendixInterface {

	/**
	 * The SiSa appendix rows of an administration's specific grants for a year.
	 *
	 * @param string $administrationId The administration.
	 * @param string $fiscalYear       The year, for example "2026".
	 *
	 * @return list<array<string,mixed>> One row per indicator.
	 *
	 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-sisa-reporting/spec.md#requirement-indicators-are-computed-per-specific-grant-req-sisa-012
	 */
	public function forYear(string $administrationId, string $fiscalYear): array;

	/**
	 * The appendix as CSV, one row per indicator, in the column order of the BZK template.
	 *
	 * @param list<array<string,mixed>> $rows The rows forYear() returned.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-sisa-reporting/spec.md#requirement-the-appendix-is-exported-for-the-annual-accounts-req-sisa-013
	 */
	public function toCsv(array $rows): string;
}//end interface
