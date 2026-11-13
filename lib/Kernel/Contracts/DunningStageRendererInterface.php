<?php

/**
 * Contract of DunningStageRenderer
 *
 * The public surface of DunningStageRenderer that other components call, so they depend
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
 * Contract of DunningStageRenderer.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DunningStageRendererInterface {

	/**
	 * The unfilled text of a stage in a language: the stage's own text, then
	 * its other language, then the default text of that stage. Also what the
	 * ladder page shows (task 4.1), so the page and the letter agree.
	 *
	 * @param array<string, mixed> $stage    The stage.
	 * @param string               $language `nl` or `en`.
	 *
	 * @return array{subject: string, body: string}
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
	 */
	public function textFor(array $stage, string $language): array;
}//end interface
