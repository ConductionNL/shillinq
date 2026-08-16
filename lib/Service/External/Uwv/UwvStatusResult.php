<?php

/**
 * Result value-object returned by a UWV adapter call.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Uwv
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/specs/req-pay-000-werkgever-setup.md
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/specs/req-pay-011-lh-aangifte.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Uwv;

/**
 * Result of a UWV loonaangifte-status or sectorindeling-lookup attempt.
 *
 * `outcome` is one of `ACCEPTED`, `REJECTED`, `FOUND`, `NOT_FOUND`,
 * `STATUS_DEFERRED`, `SECTOR_DEFERRED`, `ERROR`. The dormant default
 * uses `STATUS_DEFERRED` for pullStatus and `SECTOR_DEFERRED` for
 * lookupSector so a caller can branch on the prefix.
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/specs/req-pay-011-lh-aangifte.md
 */
final class UwvStatusResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $outcome ACCEPTED / REJECTED / FOUND /
	 *                        NOT_FOUND / STATUS_DEFERRED /
	 *                        SECTOR_DEFERRED / ERROR.
	 * @param string $reference UWV-side correlation kenmerk
	 *                        (synthetic for dormant).
	 * @param bool $dormant TRUE when the adapter was
	 *                      dormant.
	 * @param array<string,mixed> $extras Provider-specific extras —
	 *                                    rejectCodes[], rejectMessages[],
	 *                                    premieTarief, gediff,
	 *                                    sectorName.
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly string $reference,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
