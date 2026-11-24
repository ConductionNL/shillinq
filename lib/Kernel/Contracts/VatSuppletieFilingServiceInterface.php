<?php

/**
 * Contract of VatSuppletieFilingService
 *
 * The public surface of VatSuppletieFilingService that other components call, so they depend
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

use OCA\Shillinq\Service\Tax\SuppletieFilingUnavailableException;
use RuntimeException;

/**
 * Contract of VatSuppletieFilingService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VatSuppletieFilingServiceInterface {

	/**
	 * The supplementary return of a prepared correction, as filed or downloaded.
	 *
	 * @param array<string,mixed> $correction The VatCorrection.
	 *
	 * @return array<string,mixed> The return it corrects, its period, the per-box differences,
	 *                             the net correction and the threshold decision.
	 *
	 * @throws RuntimeException When the correction was not prepared.
	 *
	 * @spec openspec/changes/archive/2026-11-24-tax-vat-position-and-suppletie/tasks.md#task-2.2
	 */
	public function document(array $correction): array;

	/**
	 * File a prepared correction's supplementary return through the Digipoort port.
	 *
	 * On success the correction is submitted, with when and the delivery reference.
	 *
	 * @param array<string,mixed> $correction The VatCorrection.
	 *
	 * @return array<string,mixed> The correction as saved.
	 *
	 * @throws SuppletieFilingUnavailableException When no Digipoort connection is bound.
	 * @throws RuntimeException When the correction is not a prepared draft, or the delivery failed.
	 *
	 * @spec openspec/changes/archive/2026-11-24-tax-vat-position-and-suppletie/tasks.md#task-2.2
	 */
	public function file(array $correction): array;
}//end interface
