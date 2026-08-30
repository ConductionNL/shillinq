<?php

/**
 * Fiscal Year Guard
 *
 * Lifecycle precondition for the FiscalYear schema's `beginClose` transition
 * (open -> closing, lib/Settings/shillinq_register.json, REQ-YEC-007).
 * ADR-031 exception-path PHP guard: the cross-schema lookup (resolve every
 * FiscalPeriod within the year, inspect each one's state) is not expressible
 * in the declarative lifecycle DSL.
 *
 * shillinq#425: class did not exist prior to this change; the `beginClose`
 * transition hard-failed (RuntimeException from LifecycleGuardRegistry).
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
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards FiscalYear `beginClose` — REQ-YEC-007 requires every FiscalPeriod
 * within the year to already be `closed` (or `audit-locked`) before the
 * year-end close may start.
 *
 * Fail-closed: any lookup failure denies the transition.
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 */
class FiscalYearGuard {
	/**
	 * FiscalPeriod states that satisfy REQ-YEC-007.
	 *
	 * @var array<string>
	 */
	private const SATISFYING_STATES = [
		'closed',
		'audit-locked',
	];

	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Precondition for `beginClose`: every FiscalPeriod in this fiscal year
	 * (matched by `fiscalYear` + `administrationId`) must already be closed.
	 *
	 * A fiscal year with no FiscalPeriod records yet is permitted to close
	 * (nothing to gate against — mirrors PeriodCloseGuard::periodOpen()'s
	 * "no scope, allow" convention).
	 *
	 * @param array<string, mixed> $fiscalYear The FiscalYear object being transitioned.
	 *
	 * @return bool True when all periods are closed and year-close may begin.
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
	 */
	public function requireAllPeriodsClosedForYear(array $fiscalYear): bool {
		$yearNumber = ($fiscalYear['yearNumber'] ?? null);
		$administrationId = (string)($fiscalYear['administrationId'] ?? '');
		if ($yearNumber === null) {
			// No year scope to gate against.
			return true;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$filters = ['fiscalYear' => $yearNumber];
			if ($administrationId !== '') {
				$filters['administrationId'] = $administrationId;
			}

			$periods = $objectService
				->setRegister($this->register())
				->setSchema('FiscalPeriod')
				->findAll(['filters' => $filters]);

			if (is_array($periods) === false || $periods === []) {
				return true;
			}

			foreach ($periods as $period) {
				$state = (string)($period['state'] ?? '');
				if (in_array($state, self::SATISFYING_STATES, true) === false) {
					$this->logger->info(
						'FiscalYearGuard: beginClose rejected — an open FiscalPeriod remains',
						['yearNumber' => $yearNumber, 'periodId' => ($period['periodId'] ?? null), 'state' => $state]
					);
					return false;
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'FiscalYearGuard: requireAllPeriodsClosedForYear check failed — denying (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end requireAllPeriodsClosedForYear()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
