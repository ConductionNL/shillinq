<?php

/**
 * Contract of PaymentRunExportService
 *
 * The public surface of PaymentRunExportService that other components call, so they depend
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
 * Contract of PaymentRunExportService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PaymentRunExportServiceInterface {

	/**
	 * Validate, render, store + tag, write back and transition the PaymentRun.
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 *
	 * @return array<string, mixed> An envelope: on success
	 *                              `{ exportedFileRef, exportedAt, lifecycleState,
	 *                              files: [...] }`; on rejection `{ error: ... }`.
	 *
	 * @spec openspec/specs/payment-run-sepa-export/spec.md#requirement-req-sepa-005-export-shall-write-back-the-file-reference-and-drive-the-lifecycle
	 */
	public function export(array $paymentRun): array;
}//end interface
