<?php

/**
 * DateWindow — whether two validity windows overlap.
 *
 * Two places in this app answer the same question and answered it twice:
 * FeeScheduleService, deciding whether a new fee collides with a published
 * one, and RateScheduleOverlapGuard, deciding the same for a rate. They even
 * disagreed on how to spell "no end date": the fee service used an empty
 * string, the guard used null. Both mean the window is still open.
 *
 * The predicate itself is the standard one: two intervals overlap when each
 * starts on or before the other ends. The only subtlety is the open end, and
 * having one place decide what an open end is, is the point of this class.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\Shillinq\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Support;

/**
 * Overlap of two date windows, with one definition of an open end.
 *
 * @spec exclude infrastructure utility with no feature requirement of its own; it is
 *   exercised through the fee-overlap and rate-overlap rules that call it
 */
final class DateWindow {

	/**
	 * The date an open end is compared as. A window with no end runs until it
	 * is closed, and this is the far end it stands in for.
	 *
	 * @var string
	 */
	public const OPEN_ENDED = '9999-12-31';

	/**
	 * Whether two validity windows overlap.
	 *
	 * An end of null or '' means open-ended. A start of '' means the caller
	 * has no window to compare at all, and nothing overlaps an absent window.
	 *
	 * @param string $aFrom Window A start (Y-m-d).
	 * @param ?string $aTo Window A end (Y-m-d), null or '' for open-ended.
	 * @param string $bFrom Window B start (Y-m-d).
	 * @param ?string $bTo Window B end (Y-m-d), null or '' for open-ended.
	 *
	 * @return bool True when the two windows share at least one day.
	 */
	public static function overlaps(string $aFrom, ?string $aTo, string $bFrom, ?string $bTo): bool {
		if ($aFrom === '' || $bFrom === '') {
			return false;
		}

		$aEnd = self::end(to: $aTo);
		$bEnd = self::end(to: $bTo);

		return ($aFrom <= $bEnd && $bFrom <= $aEnd);
	}//end overlaps()

	/**
	 * The comparable end of a window, open ends included.
	 *
	 * @param ?string $to The declared end, null or '' when there is none.
	 *
	 * @return string The end to compare against.
	 */
	private static function end(?string $to): string {
		if ($to === null || $to === '') {
			return self::OPEN_ENDED;
		}

		return $to;
	}//end end()
}//end class
