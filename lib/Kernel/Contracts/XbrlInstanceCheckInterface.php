<?php

/**
 * Contract of XbrlInstanceCheck
 *
 * The public surface of XbrlInstanceCheck that other components call, so they depend
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
 * Contract of XbrlInstanceCheck.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface XbrlInstanceCheckInterface {

	/**
	 * The failing items of an instance; none when it may be validated.
	 *
	 * @param array<string, mixed>      $instance The XbrlInstance.
	 * @param array<string, mixed>|null $taxonomy The taxonomy effective for its period and entry point, or null.
	 *
	 * @return list<string> One sentence per failing item.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-2.1
	 */
	public function problems(array $instance, ?array $taxonomy): array;
}//end interface
