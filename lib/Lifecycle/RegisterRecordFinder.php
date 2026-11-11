<?php

/**
 * Register Record Finder
 *
 * Runs exact-match lookups in the configured shillinq register through
 * OpenRegister's ObjectService, resolved lazily from the container. Uses the
 * real fluent API (ADR-022): setRegister/setSchema/findAll.
 *
 * @category Guard
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;

/**
 * The register lookups the commitment guards share. A failure propagates;
 * the guard decides how to treat it.
 *
 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.4
 */
final class RegisterRecordFinder {

	/**
	 * Hold the container and the app config.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig         $appConfig App config for register slug resolution.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The records matching the query, re-indexed; an answer that is not an
	 * array counts as none.
	 *
	 * @param string      $schema Schema name.
	 * @param RecordQuery $query  Filters and limit.
	 *
	 * @return array<int, array<string, mixed>> Matching records (possibly empty).
	 *
	 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.4
	 */
	public function records(string $schema, RecordQuery $query): array {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$config = $query->toConfig();

		$result = $objectService
			->setRegister(register: $this->registerSlug())
			->setSchema(schema: $schema)
			->findAll($config);

		if (is_array($result) === false) {
			return [];
		}

		return array_values($result);
	}//end records()

	/**
	 * The first of a set of records, or null when there are none.
	 *
	 * @param array<int, array<string, mixed>> $records The records.
	 *
	 * @return array<string, mixed>|null The first record, or null.
	 *
	 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.4
	 */
	public function first(array $records): array|null {
		if (count($records) === 0) {
			return null;
		}

		return reset($records);
	}//end first()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
	 *
	 * @return string
	 */
	private function registerSlug(): string {
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end registerSlug()
}//end class
