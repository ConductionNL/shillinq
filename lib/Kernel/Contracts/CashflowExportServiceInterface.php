<?php

/**
 * Contract of CashflowExportService
 *
 * The public surface of CashflowExportService that other components call, so they depend
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
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of CashflowExportService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CashflowExportServiceInterface {

	/**
	 * Build the PDF export for the caller's current cashflow horizon (REQ-CF-016).
	 *
	 * @return array{filename:string,mimeType:string,payload:string}|null The
	 *         download envelope, or null when the caller has no accessible
	 *         administration or that administration has no horizon yet. Null is
	 *         NOT an empty document on purpose: a blank export of an
	 *         administration that has never been forecast reads, to a bank, as
	 *         a business with no cashflow.
	 *
	 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md#req-cf-016
	 */
	public function buildHorizonExport(): ?array;
}//end interface
