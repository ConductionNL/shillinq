<?php

/**
 * Dormant default Treasury rate adapter.
 *
 * Records the would-be reference-rate / FX-spot lookup to the structured
 * logger and returns a synthetic SNAPSHOT_DEFERRED result so the
 * surrounding aggregation host (intercompany-loan accrual, FX
 * revaluation, liquidity-KPI consolidation) stays observable until an
 * openconnector-backed binding to Bloomberg / Refinitiv / ECB SDMX is
 * wired in via `Application::register()`. Mirrors the
 * `LogMolliePaymentAdapter` / `LogDigipoortSbrAdapter` dormant-default
 * pattern used across the Shillinq external surface.
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

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed Treasury rate adapter.
 *
 * Returns a synthetic SNAPSHOT_DEFERRED with `value = '0'` so callers
 * branch on the dormant flag rather than the zero — applying the zero
 * accidentally would post zero interest, which is a visible audit hole
 * the caller MUST guard against.
 *
 * @spec openspec/specs/bookkeeping-treasury-ihb/spec.md
 */
class LogTreasuryRateAdapter implements TreasuryRateAdapterInterface {
	/**
	 * Construct the log-backed Treasury rate adapter.
	 *
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the reference-rate lookup + synthesise a SNAPSHOT_DEFERRED
	 * result.
	 *
	 * The decimal-string value `'0'` is the dormant sentinel; the
	 * caller MUST inspect `status === 'SNAPSHOT_DEFERRED'` (or the
	 * `dormant` flag) before using the value for accrual.
	 *
	 * @param string $rateCode Reference-rate code.
	 * @param string $asOf ISO-8601 snapshot date.
	 *
	 * @return TreasuryRateResult The dispatch outcome.
	 */
	public function fetchReferenceRate(string $rateCode, string $asOf): TreasuryRateResult {
		$rateId = 'tr_ref_log_' . bin2hex(random_bytes(7));
		$this->logger->info(
			'Shillinq Treasury fetchReferenceRate deferred (no outbound connector bound)',
			[
				'rateId' => $rateId,
				'rateCode' => $rateCode,
				'asOf' => $asOf,
			]
		);

		return new TreasuryRateResult(
			status: 'SNAPSHOT_DEFERRED',
			rateId: $rateId,
			rateCode: $rateCode,
			value: '0',
			asOf: $asOf,
			source: 'LOG_DEFERRED',
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `treasury-rates` (ECB SDMX / Bloomberg / Refinitiv '
					. 'per-tenant credentials) and override TreasuryRateAdapterInterface in Application::register() '
					. 'to enable real transport. Until then, IntercompanyLoan.interestRate manual-entry path '
					. '(REQ-IHB-004) carries the v1 value.',
			],
		);
	}//end fetchReferenceRate()

	/**
	 * Log the FX-spot lookup + synthesise a SNAPSHOT_DEFERRED result.
	 *
	 * @param string $baseCurrency ISO 4217 base currency.
	 * @param string $quoteCurrency ISO 4217 quote currency.
	 * @param string $asOf ISO-8601 snapshot date.
	 *
	 * @return TreasuryRateResult The dispatch outcome.
	 */
	public function fetchFxSpot(string $baseCurrency, string $quoteCurrency, string $asOf): TreasuryRateResult {
		$rateId = 'tr_fx_log_' . bin2hex(random_bytes(7));
		$pair = $baseCurrency . '/' . $quoteCurrency;
		$this->logger->info(
			'Shillinq Treasury fetchFxSpot deferred (no outbound connector bound)',
			[
				'rateId' => $rateId,
				'baseCurrency' => $baseCurrency,
				'quoteCurrency' => $quoteCurrency,
				'asOf' => $asOf,
			]
		);

		return new TreasuryRateResult(
			status: 'SNAPSHOT_DEFERRED',
			rateId: $rateId,
			rateCode: $pair,
			value: '0',
			asOf: $asOf,
			source: 'LOG_DEFERRED',
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `treasury-rates` to enable real FX snapshots; '
					. 'until then, FXPosition.spotRate manual-entry path carries the v1 value.',
			],
		);
	}//end fetchFxSpot()

	/**
	 * Report whether this adapter is a dormant log-only stand-in.
	 *
	 * @inheritDoc
	 *
	 * @return bool Always true for the log adapter.
	 */
	public function isDormant(): bool {
		return true;
	}//end isDormant()
}//end class
