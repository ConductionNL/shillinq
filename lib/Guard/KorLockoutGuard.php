<?php

/**
 * KOR Lockout Guard
 *
 * Lifecycle precondition for the KorRegime schema's `returnToOutside`
 * transition (opted-out -> outside, lib/Settings/shillinq_register.json).
 * Enforces the 3-year re-entry lock-out for the small-business VAT scheme
 * (kleineondernemersregeling) per Wet OB 1968 art. 25 lid 3.
 *
 * shillinq#425: class did not exist prior to this change; the
 * `returnToOutside` transition hard-failed (RuntimeException from
 * LifecycleGuardRegistry).
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

use DateInterval;
use DateTimeImmutable;

/**
 * Guards KorRegime `returnToOutside` — an administration that opted out of
 * KOR may only return to the "outside" (regime-free) state after a 3-year
 * lock-out measured from `optedOutAt` (Wet OB 1968 art. 25 lid 3).
 *
 * Fail-closed: a record missing `optedOutAt` (should be impossible given the
 * `from: opted-out` transition guard, but defence-in-depth) is denied rather
 * than silently permitted.
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 */
class KorLockoutGuard {
	/**
	 * Lock-out period per Wet OB 1968 art. 25 lid 3.
	 *
	 * @var string
	 */
	private const LOCKOUT_PERIOD = 'P3Y';

	/**
	 * Precondition for `returnToOutside`: the 3-year lock-out since
	 * `optedOutAt` must have fully elapsed.
	 *
	 * @param array<string, mixed> $regime The KorRegime object being transitioned.
	 *
	 * @return bool True when the lock-out has expired and re-entry is permitted.
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
	 */
	public function requireLockoutExpired(array $regime): bool {
		$optedOutAt = trim((string)($regime['optedOutAt'] ?? ''));
		if ($optedOutAt === '') {
			// No opt-out timestamp recorded — cannot establish the lock-out
			// window has elapsed. Fail-closed.
			return false;
		}

		try {
			$optedOut = new DateTimeImmutable($optedOutAt);
		} catch (\Throwable) {
			return false;
		}

		$lockoutEnds = $optedOut->add(new DateInterval(self::LOCKOUT_PERIOD));
		$now = new DateTimeImmutable('now');

		return $now >= $lockoutEnds;
	}//end requireLockoutExpired()
}//end class
