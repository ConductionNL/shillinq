<?php

/**
 * Rate Schedule Overlap Guard
 *
 * Lifecycle precondition for the RateSchedule schema's `reactivate`
 * transition (on-hold... i.e. inactive -> active,
 * lib/Settings/shillinq_register.json, REQ-RATE-006).
 *
 * shillinq#425: originally declared under `x-openregister-lifecycle.
 * preconditions.save`, a key OpenRegister's LifecycleValidationListener
 * never reads at all (it only reads `transitions.<action>.requires` —
 * openregister/lib/Listener/LifecycleValidationListener.php:215-218) — so
 * this was dead, unconsumed configuration: the check never ran, ever,
 * silently, regardless of whether the class existed. Relocated here to the
 * `reactivate` transition's `requires` (a real, enforced hook) so the
 * already-documented business rule ("(tier, entityId) pairs MUST NOT have
 * overlapping effective-date windows") is actually enforced. Note this only
 * catches the reactivate path — OpenRegister does not dispatch
 * ObjectTransitionedEvent for a lifecycle field set at object-create time
 * (see openregister SaveObject::seedLifecycleFieldOnCreate() docblock), so a
 * brand-new RateSchedule created directly in `active` state still bypasses
 * this guard; that gap is pre-existing OpenRegister behaviour, not
 * introduced or fixed by this change.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Guard
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

namespace OCA\Shillinq\Guard;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Guards RateSchedule `reactivate` — no other active RateSchedule for the
 * same (tier, entityId, administrationId) may have an overlapping
 * effective-date window (REQ-RATE-006).
 *
 * Fail-closed: any lookup exception denies reactivation.
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 */
class RateScheduleOverlapGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for register slug resolution.
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
	 * Precondition for `reactivate`: no overlapping active sibling schedule.
	 *
	 * @param array<string, mixed> $schedule The RateSchedule object being transitioned.
	 *
	 * @return bool True when no overlapping active schedule exists.
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
	 */
	public function requireNonOverlappingWindow(array $schedule): bool {
		$tier = (string)($schedule['tier'] ?? '');
		$entityId = ($schedule['entityId'] ?? null);
		$administrationId = (string)($schedule['administrationId'] ?? '');
		$effectiveDate = trim((string)($schedule['effectiveDate'] ?? ''));
		if ($effectiveDate === '') {
			// No window to compare — nothing to gate against.
			return true;
		}

		$expiryDate = ($schedule['expiryDate'] ?? null);

		try {
			$siblings = $this->objectService
				->setRegister($this->register())
				->setSchema('RateSchedule')
				->findAll(
					[
						'filters' => [
							'tier' => $tier,
							'entityId' => $entityId,
							'administrationId' => $administrationId,
							'status' => 'active',
						],
					]
				);

			$currentId = ($schedule['id'] ?? null);
			foreach ($siblings as $sibling) {
				if ($currentId !== null && ($sibling['id'] ?? null) === $currentId) {
					continue;
				}

				if ($this->windowsOverlap(
					startA: $effectiveDate,
					endA: $expiryDate,
					startB: (string)($sibling['effectiveDate'] ?? ''),
					endB: ($sibling['expiryDate'] ?? null)
				) === true
				) {
					$this->logger->info(
						'RateScheduleOverlapGuard: overlapping active schedule found — denying reactivate',
						['tier' => $tier, 'entityId' => $entityId, 'conflictingId' => ($sibling['id'] ?? null)]
					);
					return false;
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'RateScheduleOverlapGuard: requireNonOverlappingWindow check failed — denying (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end requireNonOverlappingWindow()

	/**
	 * Whether two [start, end] date windows overlap. A null end means
	 * open-ended (extends to infinity).
	 *
	 * @param string $startA Window A start (Y-m-d).
	 * @param string|null $endA Window A end (Y-m-d), or null for open-ended.
	 * @param string $startB Window B start (Y-m-d).
	 * @param string|null $endB Window B end (Y-m-d), or null for open-ended.
	 *
	 * @return bool True when the windows overlap.
	 */
	private function windowsOverlap(string $startA, ?string $endA, string $startB, ?string $endB): bool {
		if ($startB === '') {
			return false;
		}

		// Two closed (or open-ended) intervals overlap iff each starts on or
		// before the other's end.
		$aEndsAfterOrOnBStart = ($endA === null || $endA >= $startB);
		$bEndsAfterOrOnAStart = ($endB === null || $endB >= $startA);

		return $aEndsAfterOrOnBStart && $bEndsAfterOrOnAStart;
	}//end windowsOverlap()

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
