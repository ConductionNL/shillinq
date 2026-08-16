<?php

/**
 * Treasury reference-rate + FX-spot adapter port.
 *
 * The Shillinq treasury / in-house bank surface
 * (`bookkeeping-treasury-ihb`) needs three flavours of reference data
 * to roll its declarative aggregations:
 *
 *  1. Floating reference-rate snapshots for `IntercompanyLoan` accrual
 *     (EURIBOR-3M, SOFR, SARON — REQ-IHB-004 / Tasks 14 + 15) so the
 *     monthly interest aggregation can fix a rate-of-day per loan.
 *  2. FX spot rates for `FXPosition` mark-to-market (REQ-IHB-006 / Task
 *     17) so the period-close revaluation aggregation can compute
 *     unrealised P&L.
 *  3. Period-end cross-rates for the `LiquidityKPI` consolidation
 *     (REQ-IHB-010 / Task 22) so the group-cash dashboard surfaces a
 *     consolidated EUR amount.
 *
 * Per ADR-031 + ADR-022 the rate fetch itself is an n8n / openconnector
 * orchestration concern (a Bloomberg, Refinitiv, or ECB SDMX source
 * managed in openconnector — slug `treasury-rates`). The port lets the
 * surrounding lifecycle code (lifecycle guards, aggregation hosts,
 * KPI computations) stay decoupled from that orchestration so the
 * adapter can be swapped in without touching the treasury domain.
 *
 * Until that binding is configured, the default binding is dormant: it
 * logs the lookup and returns a synthetic SNAPSHOT_DEFERRED outcome so
 * the surrounding lifecycle stays observable in test + staging
 * environments. The dormant default carries the previously-stored
 * manual-entry rate when one is present on the `IntercompanyLoan` —
 * REQ-IHB-004 explicitly allows manual entry as a v1 path.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\TreasuryRate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://data.ecb.europa.eu/help/api/overview
 *
 * @spec openspec/specs/bookkeeping-treasury-ihb/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\TreasuryRate;

/**
 * Treasury reference-rate + FX-spot adapter port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is set;
 * a dormant adapter records the lookup (logger, audit trail) and
 * returns a synthetic SNAPSHOT_DEFERRED outcome so the surrounding
 * aggregation host (interest accrual, FX revaluation, liquidity-KPI
 * consolidation) can advance without contacting Bloomberg / Refinitiv /
 * ECB.
 *
 * Activation steps for a real binding:
 *  1. Provision the chosen source in openconnector — slug
 *     `treasury-rates`, pointing at the ECB SDMX endpoint
 *     (`data.ecb.europa.eu/service/data`) for EURIBOR / SARON or the
 *     vendor endpoint (Bloomberg BPIPE, Refinitiv RFA) for SOFR / FX
 *     spot. Store the API key or BPIPE token in the source.
 *  2. Configure rate-code aliases per tenant in the openconnector
 *     mapping (`EURIBOR-3M` → `FM.B.U2.EUR.RT.MM.EURIBOR3MD_.HSTA`,
 *     etc.).
 *  3. Override the TreasuryRateAdapterInterface DI binding in
 *     `Application::register()` to the openconnector-backed
 *     implementation.
 *
 * @spec openspec/specs/bookkeeping-treasury-ihb/spec.md
 */
interface TreasuryRateAdapterInterface {
	/**
	 * Fetch the floating reference-rate snapshot for a given date.
	 *
	 * @param string $rateCode Reference-rate code — `EURIBOR-3M`,
	 *                         `EURIBOR-6M`, `SOFR`, `SARON`, `ESTR`.
	 * @param string $asOf ISO-8601 date the snapshot should be
	 *                     taken against (`YYYY-MM-DD`).
	 *
	 * @return TreasuryRateResult The snapshot outcome (status +
	 *                            opaque rate id + decimal value).
	 */
	public function fetchReferenceRate(string $rateCode, string $asOf): TreasuryRateResult;

	/**
	 * Fetch the FX spot rate for a currency pair on a given date.
	 *
	 * @param string $baseCurrency ISO 4217 base currency, e.g. `EUR`.
	 * @param string $quoteCurrency ISO 4217 quote currency, e.g. `USD`.
	 * @param string $asOf ISO-8601 date.
	 *
	 * @return TreasuryRateResult The snapshot outcome (status +
	 *                            decimal cross-rate value).
	 */
	public function fetchFxSpot(string $baseCurrency, string $quoteCurrency, string $asOf): TreasuryRateResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting
	 * a rate provider.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 */
	public function isDormant(): bool;
}//end interface
