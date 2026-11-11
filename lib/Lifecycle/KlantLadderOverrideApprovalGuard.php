<?php

/**
 * Klant Ladder Override Approval Guard
 *
 * ADR-031 exception-path lifecycle guard for the KlantLadderOverride.activate
 * transition. Per REQ-CCD-001 + design D6, any override that modifies stage 4
 * or stage 5 of the base ladder MUST carry an approval signature
 * (approvedBy + approvedAt) — operators may not silently exempt customers
 * from escalation without a controller / manager sign-off.
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
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-6
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guard for KlantLadderOverride.activate.
 *
 * Returns true unless the override modifies an elevated stage (4 or 5) AND
 * is missing approvedBy / approvedAt. Fail-closed on any exception.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-6
 *
 * @SuppressWarnings(PHPMD.ShortVariable) Pre-existing debt (issue #506):
 *     not in the project's curated idiomatic-abbreviation allowlist;
 *     deferred pending a dedicated rename pass.
 */
class KlantLadderOverrideApprovalGuard {
	/**
	 * Construct the guard with DI for OR ObjectService.
	 *
	 * @param IAppConfig $appConfig App config.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Whether the override is permitted to activate.
	 *
	 * @param string $overrideId The KlantLadderOverride.id.
	 *
	 * @return bool True when activation is allowed.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-6
	 */
	public function isApprovedForElevatedStages(string $overrideId): bool {
		try {
			$rows = $this->objectService
				->setRegister($this->registerSlug())
				->setSchema('KlantLadderOverride')
				->findAll(['filters' => ['id' => $overrideId]]);

			if ($rows === []) {
				$this->logger->warning('Shillinq: KlantLadderOverride ' . $overrideId . ' not found; fail-closed.');
				return false;
			}

			return $this->overrideIsApproved(override: $rows[0]);
		} catch (\Throwable $e) {
			$this->logger->warning('Shillinq: KlantLadderOverrideApprovalGuard failed: ' . $e->getMessage());
			return false;
		}//end try

	}//end isApprovedForElevatedStages()

	/**
	 * The configured register, defaulting to 'shillinq' when empty.
	 *
	 * @return string
	 */
	private function registerSlug(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;

	}//end registerSlug()

	/**
	 * Whether an override may activate: it touches no elevated stage, or it
	 * carries both an approver and an approval date.
	 *
	 * Untyped on purpose: a non-array row must fail exactly where it did
	 * before (an Error caught by the caller, fail-closed).
	 *
	 * @param mixed $override The override row.
	 *
	 * @return bool
	 */
	private function overrideIsApproved(mixed $override): bool {
		if ($this->touchesElevatedStage(override: $override) === false) {
			return true;
		}

		$approvedBy = (string)($override['approvedBy'] ?? '');
		$approvedAt = (string)($override['approvedAt'] ?? '');

		return ($approvedBy !== '' && $approvedAt !== '');

	}//end overrideIsApproved()

	/**
	 * Whether any overridden stage is number 4 or higher.
	 *
	 * @param mixed $override The override row.
	 *
	 * @return bool
	 */
	private function touchesElevatedStage(mixed $override): bool {
		foreach ((array)($override['overrides']['stages'] ?? []) as $stage) {
			if ((int)($stage['nr'] ?? 0) >= 4) {
				return true;
			}
		}

		return false;

	}//end touchesElevatedStage()
}//end class
