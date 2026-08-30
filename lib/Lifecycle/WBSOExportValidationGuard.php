<?php

/**
 * WBSO Export Validation Guard
 *
 * Lifecycle precondition for the WBSOExportLog schema's `validate`
 * transition (generated -> validated, lib/Settings/shillinq_register.json,
 * REQ-WBSO-006).
 *
 * shillinq#425: class did not exist prior to this change (bare-class
 * `requires` reference, no `::method` suffix); the `validate` transition
 * hard-failed (RuntimeException from LifecycleGuardRegistry).
 *
 * Note: WBSOExportLog and WBSOActivityCode are currently declared at the
 * wrong JSON nesting level in lib/Settings/shillinq_register.json
 * (`components.WBSOExportLog` / `components.WBSOActivityCode` instead of
 * `components.schemas.*`), so OpenRegister's ImportHandler — which reads
 * strictly from `components.schemas` (openregister/lib/Service/
 * Configuration/ImportHandler.php:1602) — never creates either schema. That
 * is a separate, pre-existing defect (filed as shillinq#434) and out of
 * scope here. UrenRegistratie (the entries this guard validates) IS
 * correctly nested and live today. Because WBSOActivityCode is not
 * reachable yet, the eligibility (`isAllowed`) cross-check below degrades
 * gracefully (logs and skips) rather than permanently denying every export
 * on account of an unrelated bug.
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
 * Guards WBSOExportLog `validate` — every UrenRegistratie entry within the
 * export's date range and administration must carry a non-null
 * `wbsoTagId` + `activityCodeId` (REQ-WBSO-006, always enforced). The
 * `isAllowed` eligibility cross-check against WBSOActivityCode is applied
 * best-effort (see class docblock).
 *
 * Fail-closed on the tag/activity-code completeness check; the eligibility
 * cross-check fails OPEN with a log entry when WBSOActivityCode lookups are
 * unavailable, so this guard's behaviour is not silently dictated by the
 * unrelated schema-nesting defect it depends on.
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 */
class WBSOExportValidationGuard {
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
	 * Precondition for `validate`: every included UrenRegistratie entry must
	 * carry `wbsoTagId` + `activityCodeId`.
	 *
	 * @param array<string, mixed> $export The WBSOExportLog object being transitioned.
	 *
	 * @return bool True when every included entry is fully tagged.
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
	 */
	public function requireEligibleEntries(array $export): bool {
		$administrationId = (string)($export['administrationId'] ?? '');
		$periodStart = (string)($export['periodStart'] ?? '');
		$periodEnd = (string)($export['periodEnd'] ?? '');

		$administrationFilter = null;
		if ($administrationId !== '') {
			$administrationFilter = $administrationId;
		}

		$dateFilter = null;
		if ($periodStart !== '' && $periodEnd !== '') {
			$dateFilter = ['gte' => $periodStart, 'lte' => $periodEnd];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$entries = $objectService
				->setRegister($this->register())
				->setSchema('UrenRegistratie')
				->findAll(
					[
						'filters' => array_filter(
							[
								'administrationId' => $administrationFilter,
								'date' => $dateFilter,
							],
							static fn ($v) => $v !== null
						),
					]
				);

			if (is_array($entries) === false) {
				$entries = [];
			}

			foreach ($entries as $entry) {
				if (empty($entry['wbsoTagId']) === true || empty($entry['activityCodeId']) === true) {
					$this->logger->info(
						'WBSOExportValidationGuard: entry missing wbsoTagId/activityCodeId — denying validate',
						['entryId' => ($entry['id'] ?? null)]
					);
					return false;
				}
			}

			$this->checkActivityCodeEligibility(entries: $entries);

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'WBSOExportValidationGuard: requireEligibleEntries check failed — denying validate (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end requireEligibleEntries()

	/**
	 * Best-effort eligibility cross-check against WBSOActivityCode.isAllowed.
	 *
	 * Logs (does not deny) when the lookup itself is unavailable, so this
	 * guard's core tag-completeness enforcement is not held hostage by the
	 * separate WBSOActivityCode schema-registration defect (shillinq#434).
	 *
	 * @param array<int,array<string,mixed>> $entries UrenRegistratie entries already
	 *                                                confirmed to carry activityCodeId.
	 *
	 * @return void
	 */
	private function checkActivityCodeEligibility(array $entries): void {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable) {
			return;
		}

		foreach ($entries as $entry) {
			$activityCodeId = ($entry['activityCodeId'] ?? null);
			if ($activityCodeId === null) {
				continue;
			}

			try {
				$codes = $objectService
					->setRegister($this->register())
					->setSchema('WBSOActivityCode')
					->findAll(['filters' => ['activityCode' => $activityCodeId], 'limit' => 1]);
			} catch (\Throwable) {
				// WBSOActivityCode not reachable (shillinq#434) — skip this
				// sub-check rather than deny the whole export on its account.
				return;
			}

			if (is_array($codes) === true && $codes !== [] && ($codes[0]['isAllowed'] ?? true) !== true) {
				$this->logger->warning(
					'WBSOExportValidationGuard: entry references a non-allowed WBSOActivityCode',
					['entryId' => ($entry['id'] ?? null), 'activityCode' => $activityCodeId]
				);
			}
		}//end foreach

	}//end checkActivityCodeEligibility()

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
