<?php

/**
 * Cancellation Reason Guard
 *
 * The precondition on both end transitions of a RecurringInvoiceProfile
 * (REQ-SCX-001): staff must say why a subscription ends.
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
 * @spec openspec/changes/sales-cancellation/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use DateTimeImmutable;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Whether a profile may end: staff give a reason code from the fixed list.
 *
 * Three ends, told apart without anything a person can type:
 * - the profile reached its own end condition (the occurrences ran out, or its
 *   end date passed): the generator ends it, and that is no cancellation;
 * - nobody is signed in: the customer's portal request, forwarded server to
 *   server, or another system run. A customer may leave the reason out;
 * - a signed-in user ends it: staff, who must choose a reason other than
 *   `not-given`.
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-1.1
 */
class CancellationReasonGuard implements LifecycleGuardInterface {

	/**
	 * The reason codes a cancellation may carry (design D1).
	 *
	 * @var list<string>
	 */
	public const REASON_CODES = [
		'too-expensive',
		'not-using',
		'switched-provider',
		'missing-feature',
		'service-quality',
		'business-closed',
		'other',
		'not-given',
	];

	/**
	 * The code that says no reason was given.
	 *
	 * @var string
	 */
	public const NOT_GIVEN = 'not-given';

	/**
	 * The refusal a staff end without a reason gets.
	 *
	 * @var string
	 */
	public const REFUSAL = 'A cancellation reason is required to end this subscription.';

	/**
	 * Judge an end of the profile.
	 *
	 * @param array<string, mixed> $object The profile, with the transition's inputs merged in.
	 * @param string               $action The transition.
	 * @param string               $userId The signed-in caller, or '' with nobody signed in.
	 *
	 * @return GuardResult Allowed, or refused with the reason.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-1.1
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->requireReason(profile: $object, userId: $userId) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::REFUSAL);
	}//end check()

	/**
	 * Whether the end may proceed.
	 *
	 * @param array<string, mixed>   $profile The profile, with the transition's inputs merged in.
	 * @param string                 $userId  The signed-in caller, or ''.
	 * @param DateTimeImmutable|null $today   Today, for the end date; null for now.
	 *
	 * @return bool True when the end may proceed.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-1.1
	 */
	public function requireReason(array $profile, string $userId, ?DateTimeImmutable $today = null): bool {
		if ($userId === '' || self::endConditionReached(profile: $profile, today: ($today ?? new DateTimeImmutable('today'))) === true) {
			return true;
		}

		$code = trim((string)($profile['cancellationReasonCode'] ?? ''));

		return $code !== self::NOT_GIVEN && in_array($code, self::REASON_CODES, true);
	}//end requireReason()

	/**
	 * Whether the profile reached its own end: no occurrences left, or past its end date.
	 *
	 * @param array<string, mixed> $profile The profile.
	 * @param DateTimeImmutable    $today   Today.
	 *
	 * @return bool True when the profile ends by itself.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-1.1
	 */
	public static function endConditionReached(array $profile, DateTimeImmutable $today): bool {
		if (array_key_exists('remainingOccurrences', $profile) === true
			&& $profile['remainingOccurrences'] !== null
			&& (int)$profile['remainingOccurrences'] <= 0
		) {
			return true;
		}

		$endDate = (string)(((array)($profile['endCondition'] ?? []))['endDate'] ?? '');
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $endDate) !== 1) {
			return false;
		}

		return substr($endDate, 0, 10) <= $today->format('Y-m-d');
	}//end endConditionReached()
}//end class
