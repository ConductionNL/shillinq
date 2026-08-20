<?php

/**
 * UWV Loonaangifte / Werkhervattingskas adapter port.
 *
 * UWV (Uitvoeringsinstituut Werknemersverzekeringen) is the Dutch
 * agency that operates the werkloosheids- and arbeidsongeschiktheids-
 * regimes (WW, WIA, ZW). Shillinq materialises two UWV interactions
 * on the payroll lifecycle:
 *  1. Loonaangifte verification — after the periodic loonaangifte
 *     XBRL has been dispatched via the
 *     `LogDigipoortSbrAdapter` flow, UWV publishes a
 *     `loonaangifte-status` envelope (accepted / rejected with
 *     code-list + corrections-required). This adapter pulls that
 *     envelope so the `bookkeeping-detachering-payroll-administratie`
 *     cycle can advance the `LHAfdracht` record from `submitted` to
 *     `accepted` / `rejected`.
 *  2. Werkhervattingskas sectorindeling lookup — per
 *     `bookkeeping-payroll-engine-nl/req-pay-000-werkgever-setup`,
 *     the werkgever-setup wizard MUST validate the operator's
 *     SBI-code against UWV's published sector list (foutieve sector
 *     wordt afgewezen) and pull the per-sector
 *     Werkhervattingskas-premie-tarief for the engine's
 *     bruto→netto-calculation.
 *
 * The port is intentionally narrow — two methods returning
 * structured results — so the production binding (openconnector
 * source slug `uwv-loonaangifte`, PKIoverheid Services-server cert +
 * UWV polisadministratie endpoint) can be swapped in via
 * `Application::register()` without touching the payroll engine.
 *
 * Until that binding is configured, the default binding is dormant:
 * it logs the intent and returns a synthetic
 * STATUS_DEFERRED / SECTOR_DEFERRED outcome so the payroll
 * lifecycle stays observable in test + staging environments.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Uwv
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://www.uwv.nl/werkgevers/gegevens-doorgeven-en-aanvragen/polisadministratie
 *
 * @spec openspec/specs/bookkeeping-detachering-payroll-administratie/spec.md
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/specs/req-pay-000-werkgever-setup.md
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/specs/req-pay-011-lh-aangifte.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Uwv;

/**
 * UWV Loonaangifte / Werkhervattingskas adapter port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is
 * set; a dormant adapter records the intent (logger, audit trail)
 * and returns a synthetic STATUS_DEFERRED / SECTOR_DEFERRED outcome
 * so the surrounding lifecycle can advance into
 * `awaiting-uwv-verdict` / `pending-sector-validation` without
 * contacting UWV.
 *
 * Activation steps for a real UWV binding:
 *  1. Provision a PKIoverheid Services-server certificate registered
 *     with UWV polisadministratie.
 *  2. Create an openconnector source with slug
 *     `uwv-loonaangifte`, pointing at the UWV polisadministratie
 *     endpoint for the loonaangifte-status pull + the
 *     werkhervattingskas sectorindeling endpoint.
 *  3. Override the UwvLoonaangifteAdapterInterface DI binding in
 *     `Application::register()` to the openconnector-backed
 *     implementation.
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/specs/req-pay-000-werkgever-setup.md
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/specs/req-pay-011-lh-aangifte.md
 */
interface UwvLoonaangifteAdapterInterface {
	/**
	 * Pull the UWV-side status of a previously-dispatched loonaangifte.
	 *
	 * @param array<string,mixed> $payload Loonaangifte query envelope —
	 *                                     loonheffingsnummer, aangiftePeriode
	 *                                     (YYYYMM), digipoortKenmerk
	 *                                     (echo of the Digipoort kenmerk
	 *                                     for correlation), correlationId.
	 *
	 * @return UwvStatusResult The dispatch outcome (status + reject-codes
	 *                         + correction guidance).
	 */
	public function pullStatus(array $payload): UwvStatusResult;

	/**
	 * Look up the Werkhervattingskas sectorindeling + premie-tarief
	 * for a sectorcode (e.g. `32` = Overige zakelijke dienstverlening)
	 * in a given peiljaar.
	 *
	 * @param string $sectorCode 2- or 3-digit Werkhervattingskas
	 *                           sector code.
	 * @param int $peiljaar Calendar year (e.g. 2026).
	 * @param array<string,mixed> $context Optional context —
	 *                                     loonheffingsnummer,
	 *                                     correlationId.
	 *
	 * @return UwvStatusResult The lookup outcome — `extras.premieTarief`
	 *                         + `extras.gediff` (gedifferentieerde premie?
	 *                         applicable per-werkgever).
	 */
	public function lookupSector(string $sectorCode, int $peiljaar, array $context = []): UwvStatusResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting
	 * UWV.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 */
	public function isDormant(): bool;
}//end interface
