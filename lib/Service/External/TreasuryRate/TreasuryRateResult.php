<?php

/**
 * Result value-object returned by a TreasuryRateAdapter call.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\TreasuryRate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-treasury-ihb/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\TreasuryRate;

/**
 * Result of a Treasury reference-rate or FX-spot snapshot fetch.
 *
 * `status` is one of `SNAPSHOT_OK`, `SNAPSHOT_STALE`, `SNAPSHOT_DEFERRED`,
 * `SNAPSHOT_ERROR`. `SNAPSHOT_OK` means a fresh value was obtained from a
 * source quoted in the same business day. `SNAPSHOT_STALE` means the
 * underlying source returned a value but it is older than the requested
 * `asOf` date — the caller may still use the value but MUST surface the
 * staleness flag in the GL audit trail. `SNAPSHOT_DEFERRED` is the dormant
 * default — no outbound rate provider is bound, so the snapshot was logged
 * and a stub rate is returned. `SNAPSHOT_ERROR` is reserved for live-binding
 * failures.
 *
 * @spec openspec/specs/bookkeeping-treasury-ihb/spec.md
 */
final class TreasuryRateResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $status One of SNAPSHOT_OK |
	 *                       SNAPSHOT_STALE |
	 *                       SNAPSHOT_DEFERRED |
	 *                       SNAPSHOT_ERROR.
	 * @param string $rateId Adapter-side opaque id of the
	 *                       rate snapshot (synthetic for
	 *                       dormant).
	 * @param string $rateCode Reference-rate code or
	 *                         ISO 4217 currency
	 *                         pair, e.g.
	 *                         `EURIBOR-3M`, `SOFR`,
	 *                         `SARON`, `EUR/USD`,
	 *                         `EUR/GBP`.
	 * @param string $value Rate value as a decimal string
	 *                      to avoid float drift (e.g.
	 *                      `0.03875` for 3.875% or
	 *                      `1.0832` for an FX spot).
	 * @param string $asOf ISO-8601 date the rate refers
	 *                     to (`YYYY-MM-DD`).
	 * @param string $source Source slug —
	 *                       `bloomberg`, `refinitiv`,
	 *                       `ecb-sdmx`, `manual`,
	 *                       `LOG_DEFERRED`.
	 * @param bool $dormant TRUE when the adapter was
	 *                      dormant.
	 * @param array<string,mixed> $extras Provider-specific extras
	 *                                    (e.g. quoteCurrency,
	 *                                    tenor, provider message
	 *                                    id).
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $rateId,
		public readonly string $rateCode,
		public readonly string $value,
		public readonly string $asOf,
		public readonly string $source,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
