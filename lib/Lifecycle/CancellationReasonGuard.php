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

/**
 * Whether a profile may end: with a reason code from the fixed list.
 *
 * Staff choose a reason other than `not-given`. A customer may leave the
 * reason out; a customer end is one whose `cancellation.cancelledBy` the
 * portal endpoint has already set server-side to `customer`. That value is
 * never a transition input, so a staff member cannot claim it.
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-1.1
 */
class CancellationReasonGuard {

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
	 * The precondition of `endFromActive` and `endFromPaused`.
	 *
	 * @param array<string, mixed> $profile The profile, with the transition's inputs merged in.
	 *
	 * @return bool True when the end may proceed.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-1.1
	 */
	public function requireReason(array $profile): bool {
		$code = trim((string)($profile['cancellationReasonCode'] ?? ''));
		$byCustomer = self::endedByCustomer(profile: $profile);

		if ($code === '' || $code === self::NOT_GIVEN) {
			return $byCustomer;
		}

		return in_array($code, self::REASON_CODES, true);
	}//end requireReason()

	/**
	 * Whether the portal recorded this end as the customer's own.
	 *
	 * @param array<string, mixed> $profile The profile.
	 *
	 * @return bool True for a customer end.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-1.1
	 */
	public static function endedByCustomer(array $profile): bool {
		$cancellation = ($profile['cancellation'] ?? null);

		return is_array($cancellation) === true && ($cancellation['cancelledBy'] ?? null) === 'customer';
	}//end endedByCustomer()
}//end class
