<?php

/**
 * Shillinq Cashflow Horizon Run
 *
 * One run of a job over the active cashflow forecast horizons.
 *
 * @category Cron
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-21
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

/**
 * The object service and register of one run over the active cashflow
 * horizons, and how many horizons the run acted on.
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-21
 */
final class CashflowHorizonRun {
	/**
	 * Horizons the run acted on.
	 *
	 * @var integer
	 */
	public int $count = 0;

	/**
	 * Start a run.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register The register slug.
	 */
	public function __construct(
		private readonly object $objectService,
		private readonly string $register,
	) {
	}//end __construct()

	/**
	 * The object service scoped to a schema of the register.
	 *
	 * @param string $schema The schema.
	 *
	 * @return object
	 *
	 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-21
	 */
	public function scoped(string $schema): object {
		return $this->objectService
			->setRegister($this->register)
			->setSchema($schema);

	}//end scoped()

	/**
	 * The active forecast horizons.
	 *
	 * @return iterable<mixed>
	 *
	 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-21
	 */
	public function activeHorizons(): iterable {
		return $this->scoped(schema: 'CashflowForecastHorizon')
			->findAll(
				[
					'filters' => ['lifecycleState' => 'active'],
				]
			);

	}//end activeHorizons()

	/**
	 * Read a field from an array or a getter-backed entity.
	 *
	 * @param mixed $entity Array or entity.
	 * @param string $field Field name.
	 *
	 * @return mixed The value, null when absent.
	 *
	 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-21
	 */
	public function field(mixed $entity, string $field): mixed {
		if (is_array($entity) === true) {
			return ($entity[$field] ?? null);
		}

		$getter = 'get' . ucfirst($field);
		if (method_exists($entity, $getter) === true) {
			return $entity->{$getter}();
		}

		return null;

	}//end field()
}//end class
