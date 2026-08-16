<?php

/**
 * Begrotingswijziging Guard
 *
 * ADR-031 exception-path lifecycle guard for the Begrotingswijziging register
 * (bookkeeping-programmabegroting, T2). The vaststellen transition requires the
 * raadsbesluit FK to be set before the wijziging may become vastgesteld and its
 * delta-mutaties start to stack onto the begroting stand (REQ-009). This is a
 * single-field precondition kept in PHP for symmetry with the Programmabegroting
 * lifecycle guard and so the same fail-closed diagnostics apply.
 *
 * ADR-031 exception reason: parity with ProgrammabegrotingGuard / the
 * vaststellings-workflow; trivially replaceable by a declarative
 * `requires: raadsbesluit != null` clause once the lifecycle DSL supports
 * field-presence preconditions.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-26
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guard for Begrotingswijziging vaststellen.
 *
 * Referenced from the Begrotingswijziging schema (register.d fragment)
 * x-openregister-lifecycle transitions.vaststellen.requires as
 * OCA\Shillinq\Lifecycle\BegrotingswijzigingGuard::canVaststellen.
 *
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-26
 */
class BegrotingswijzigingGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Returns true iff the wijziging carries a raadsbesluit FK.
	 *
	 * REQ-009: a begrotingswijziging may only be vastgesteld once the raad has
	 * approved it (raadsbesluit reference set). Without the FK the delta-mutaties
	 * must not take effect. Fail-closed: returns false on any exception (CWE-863).
	 *
	 * @param string $wijzigingId The Begrotingswijziging.id being transitioned.
	 * @param array<string,mixed>|null $object The Begrotingswijziging object being transitioned.
	 *
	 * @return bool True when the wijziging may be vastgesteld.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-26
	 */
	public function canVaststellen(string $wijzigingId, ?array $object = null): bool {
		try {
			$wijziging = $object;
			if ($wijziging === null || array_key_exists('councilResolution', $wijziging) === false) {
				$wijziging = $this->resolveWijziging(wijzigingId: $wijzigingId);
			}

			if ($wijziging === null) {
				return false;
			}

			$councilResolution = (string)($wijziging['councilResolution'] ?? '');
			return trim($councilResolution) !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'BegrotingswijzigingGuard: vaststellen check failed — denying transition (fail-closed)',
				['wijzigingId' => $wijzigingId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canVaststellen()

	/**
	 * Resolve the Begrotingswijziging object by id via ObjectService.
	 *
	 * @param string $wijzigingId The Begrotingswijziging.id to look up.
	 *
	 * @return array<string,mixed>|null The wijziging, or null when not found.
	 */
	private function resolveWijziging(string $wijzigingId): ?array {
		if ($wijzigingId === '') {
			return null;
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			$register = 'shillinq';
		}

		$rows = $this->objectService
			->setRegister($register)
			->setSchema('Begrotingswijziging')
			->findAll(['filters' => ['id' => $wijzigingId]]);

		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end resolveWijziging()
}//end class
