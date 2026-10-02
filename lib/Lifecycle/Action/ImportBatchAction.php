<?php

/**
 * Import Batch Action
 *
 * The declared action of the ImportBatch parse, mapping, validate and dry-run transitions.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Service\Import\ImportBatchSteps;
use RuntimeException;

/**
 * Runs the step its declaration names (`actionParameters.step`) on the batch.
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
class ImportBatchAction implements LifecycleActionInterface {

	/**
	 * The steps a declaration may name.
	 *
	 * @var string[]
	 */
	private const STEPS = ['parse', 'startMapping', 'validate', 'dryRun'];

	/**
	 * Constructor.
	 *
	 * @param ImportBatchSteps $steps The import steps.
	 */
	public function __construct(
		private readonly ImportBatchSteps $steps,
	) {
	}//end __construct()

	/**
	 * Return the batch with the step's result and follow-up state.
	 *
	 * @param array<string,mixed> $objectData   The batch with the transition applied.
	 * @param array<string,mixed> $previousData The batch before.
	 * @param array<string,mixed> $parameters   Declared parameters: `step`.
	 * @param string              $actionName   The action name.
	 *
	 * @return array<string,mixed> The batch to save.
	 *
	 * @throws RuntimeException When the declaration names no known step.
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$step = (string)($parameters['step'] ?? '');
		if (in_array($step, self::STEPS, true) === false) {
			throw new RuntimeException(sprintf('ImportBatchAction does not know the step "%s".', $step));
		}

		return $this->steps->{$step}($objectData);

	}//end execute()
}//end class
