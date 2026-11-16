<?php

/**
 * Contract of PensionValuationPostingService
 *
 * The public surface of PensionValuationPostingService that other components call, so they depend
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
 * Contract of PensionValuationPostingService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PensionValuationPostingServiceInterface {

	/**
	 * Post the IAS 19 entry of one approved valuation.
	 *
	 * @param array<string, mixed> $valuation The ActuarialValuation, with its `id`.
	 *
	 * @return array{posted: bool, message: string, plCents?: int, ociCents?: int, transaction?: array<string, mixed>}
	 *
	 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-pension-ias19/spec.md
	 */
	public function postForValuation(array $valuation): array;
}//end interface
