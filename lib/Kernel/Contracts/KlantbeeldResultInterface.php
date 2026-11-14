<?php

/**
 * Contract of KlantbeeldResult
 *
 * The public surface of KlantbeeldResult that other components call, so they depend
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
 * Contract of KlantbeeldResult.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface KlantbeeldResultInterface {

	/**
	 * Did the call succeed but return no transactions?
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
	 */
	public function isEmpty(): bool;

	/**
	 * Serialise to the API shape the controller (slice 05) returns.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
	 */
	public function jsonSerialize(): array;
}//end interface
