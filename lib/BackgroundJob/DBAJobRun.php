<?php

/**
 * Shillinq DBA job run.
 *
 * The state one run of a DBA background job works with: the object service, the register and the moment of the run.
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;

/**
 * One run of a DBA background job.
 *
 * Built by the job that runs; carries the number of objects the run wrote
 * and, for the flag job, the assignment it is looking at.
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */
final class DBAJobRun {
	/**
	 * OpenRegister's object service.
	 *
	 * @var object
	 */
	public readonly object $objectService;

	/**
	 * The register slug, 'shillinq' unless configured.
	 *
	 * @var string
	 */
	public readonly string $register;

	/**
	 * The moment the run started.
	 *
	 * @var DateTimeImmutable
	 */
	public readonly DateTimeImmutable $now;

	/**
	 * Objects written so far.
	 *
	 * @var integer
	 */
	public int $written = 0;

	/**
	 * The assignment being looked at.
	 *
	 * @var array<string,mixed>
	 */
	public array $assignment = [];

	/**
	 * Start a run.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param IAppConfig $appConfig App config holding the register slug.
	 */
	public function __construct(object $objectService, IAppConfig $appConfig) {
		$this->objectService = $objectService;
		$this->register = self::registerOf(appConfig: $appConfig);
		$this->now = new DateTimeImmutable();

	}//end __construct()

	/**
	 * The object service scoped to a schema of the register.
	 *
	 * @param string $schema The schema.
	 *
	 * @return object
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function scoped(string $schema): object {
		return $this->objectService->setRegister($this->register)->setSchema($schema);

	}//end scoped()

	/**
	 * An object from the service as an array, null when it is neither an array
	 * nor an entity with array data.
	 *
	 * @param mixed $entity The object.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function record(mixed $entity): ?array {
		if (is_array($entity) === true) {
			return $entity;
		}

		if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
			return self::arrayOrNull(data: $entity->getObject());
		}

		return null;

	}//end record()

	/**
	 * The data when it is an array, otherwise null.
	 *
	 * @param mixed $data The entity data.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function arrayOrNull(mixed $data): ?array {
		if (is_array($data) === true) {
			return $data;
		}

		return null;

	}//end arrayOrNull()

	/**
	 * The configured register slug, 'shillinq' when it is empty.
	 *
	 * @param IAppConfig $appConfig App config.
	 *
	 * @return string
	 */
	private static function registerOf(IAppConfig $appConfig): string {
		$register = $appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;

	}//end registerOf()
}//end class
