<?php

/**
 * Digipoort / SBR submission port.
 *
 * Digipoort is the Dutch government's secure-delivery gateway for
 * Standard Business Reporting (SBR) traffic. Shillinq materialises a
 * single SBR XBRL filing (BTW aangifte, jaarrekening deponering bij
 * KvK/DNB, ICP-opgaaf, CSRD/ESRS XBRL pack) from the schemas declared
 * by `bookkeeping-vat-btw-filing` / `bookkeeping-financial-statements`
 * / `bookkeeping-sbr-xbrl-reporting` / `bookkeeping-csrd-esrs` and
 * hands the prepared XBRL instance + cover envelope to this adapter
 * for transport to Digipoort over WUS 2.0 / Aanleverservice.
 *
 * The port is intentionally narrow — one submit call returning a
 * dispatch outcome — so the production binding (PKIoverheid client
 * cert + Digipoort SOAP/REST endpoint) can be swapped in via
 * `Application::register()` without touching the orchestrator. Until
 * an openconnector-backed binding to source slug `digipoort-sbr` is
 * configured, the default binding is dormant: it logs the intent
 * without contacting Digipoort so the lifecycle stays observable in
 * test + staging environments.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Digipoort
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://www.logius.nl/diensten/digipoort
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 * @spec openspec/specs/bookkeeping-financial-statements/spec.md
 * @spec openspec/specs/bookkeeping-sbr-xbrl-reporting/spec.md
 * @spec openspec/changes/bookkeeping-csrd-esrs/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Digipoort;

/**
 * Digipoort / SBR submission port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is set;
 * a dormant adapter records the intent (logger, audit trail) and returns
 * a synthetic DEFERRED outcome so the surrounding lifecycle can advance
 * into `submitted` without contacting Digipoort.
 *
 * Activation steps for a real Digipoort binding:
 *  1. Provision a PKIoverheid Services-server certificate (RSA 4096 +
 *     OIN) and load it into the openconnector credential store.
 *  2. Create an openconnector source with slug `digipoort-sbr`,
 *     pointing at the Aanleverservice WUS 2.0 endpoint
 *     (https://digipoort.procesinfrastructuur.nl/wus/2.0/aanleverservice/1.2).
 *  3. Override the DigipoortSbrAdapterInterface DI binding in
 *     `Application::register()` to the openconnector-backed
 *     implementation (`OpenConnectorDigipoortSbrAdapter`).
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 * @spec openspec/specs/bookkeeping-financial-statements/spec.md
 * @spec openspec/specs/bookkeeping-sbr-xbrl-reporting/spec.md
 * @spec openspec/changes/bookkeeping-csrd-esrs/tasks.md
 */
interface DigipoortSbrAdapterInterface {
	/**
	 * Submit an SBR XBRL filing to Digipoort.
	 *
	 * @param array<string,mixed> $payload The Digipoort delivery envelope —
	 *                                     filingType (e.g. `btw-aangifte`,
	 *                                     `jaarrekening-kvk`, `icp-opgaaf`,
	 *                                     `csrd-xbrl-pack`),
	 *                                     reportingPeriod{Start,End}Date,
	 *                                     organizationLegalName, kvkNumber,
	 *                                     fiscalNumber, beconNumber (optional
	 *                                     intermediair), xbrlInstanceBytes
	 *                                     (binary), xbrlChecksum,
	 *                                     taxonomyVersion, identifierScheme,
	 *                                     correlationId.
	 *
	 * @return DigipoortSubmissionResult The dispatch outcome (status + kenmerk).
	 */
	public function submit(array $payload): DigipoortSubmissionResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting Digipoort.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 */
	public function isDormant(): bool;
}//end interface
