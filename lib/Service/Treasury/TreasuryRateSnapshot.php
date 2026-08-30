<?php

/**
 * Typed snapshot returned by `TreasuryRateService`.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Treasury
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-treasury-ihb/tasks.md#external-adapter
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Treasury;

/**
 * Typed snapshot wrapper around a `TreasuryRateResult`.
 *
 * Carries the value AND the dormant flag so callers cannot accidentally
 * apply the synthetic `'0'` value to an accrual / FX posting — the
 * dormant branch is checkable via `isLive()` / `isDormant()` without
 * inspecting both the result status and the value.
 *
 * @spec openspec/changes/bookkeeping-treasury-ihb/tasks.md#external-adapter
 */
final class TreasuryRateSnapshot {
	/**
	 * Construct the snapshot value object.
	 *
	 * @param string $value Decimal string rate; `'0'` when dormant.
	 * @param string $source Provenance label (`ECB`, `BLOOMBERG`, `LOG_DEFERRED`).
	 * @param string $asOf ISO-8601 snapshot date.
	 * @param string $rateCode Original rate code / currency pair (audit).
	 * @param bool $dormant TRUE when no live source was contacted.
	 * @param string $rateId Opaque adapter-side rate id.
	 */
	public function __construct(
		public readonly string $value,
		public readonly string $source,
		public readonly string $asOf,
		public readonly string $rateCode,
		public readonly bool $dormant,
		public readonly string $rateId,
	) {
	}//end __construct()

	/**
	 * Whether the snapshot was produced by a live source binding.
	 *
	 * @return bool
	 */
	public function isLive(): bool {
		return ($this->dormant === false);
	}//end isLive()

	/**
	 * Whether the snapshot is a deferred sentinel.
	 *
	 * @return bool
	 */
	public function isDormant(): bool {
		return $this->dormant;
	}//end isDormant()

	/**
	 * Cast the decimal-string value to float for callers that need
	 * arithmetic. Dormant snapshots return 0.0 — callers MUST inspect
	 * `isLive()` before treating this value as meaningful.
	 *
	 * @return float
	 */
	public function asFloat(): float {
		return (float)$this->value;
	}//end asFloat()
}//end class
