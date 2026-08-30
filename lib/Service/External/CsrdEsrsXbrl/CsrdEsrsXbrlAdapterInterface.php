<?php

/**
 * CSRD / ESRS XBRL taxonomy + instance build adapter port.
 *
 * The Shillinq sustainability surface (`bookkeeping-csrd-esrs`) needs
 * three artefacts to close out the CSRD reporting lifecycle:
 *
 *  1. EFRAG ESRS XBRL taxonomy mapping (Task 30) — every
 *     `esrs-data-point` ID (e.g.
 *     `E1-6_GrossScope1GHGEmissions_tCO2eq`) maps to a XBRL fact
 *     concept (`esrs-e_GrossScope1GHGEmissions`, context, unit,
 *     decimals). The taxonomy is an EFRAG XML schema released
 *     annually; the mapping itself is a tenant-scoped index over
 *     it.
 *  2. Mandatory-data-point validation (Task 31) — before submission,
 *     every mandatory EFRAG IG-3 point must be present + status =
 *     `assured`. The validator returns the list of missing or
 *     unassured points so the operator can complete the report.
 *  3. iXBRL instance build (Task 32) — Inline XBRL PDF with embedded
 *     `<ix:nonfraction>` markup so the original annual report and the
 *     machine-readable facts ship as a single artefact (per
 *     CSRD Directive 2022/2464 Art. 8 / ESEF technical-standard
 *     conformance).
 *
 * The actual Digipoort transport (KvK / AFM submission) is a SEPARATE
 * port — `DigipoortSbrAdapterInterface` already exists in this codebase
 * and ships the `csrd-xbrl-pack` filing-type contract (see csrd-esrs
 * tasks.md "External adapter" stanza). This port stops at the
 * iXBRL artefact: the produced instance is handed to the Digipoort
 * port for transport.
 *
 * Per ADR-031 + ADR-022 the taxonomy mapping + instance build itself
 * is the cross-app concern handled by `bookkeeping-sbr-xbrl-reporting`
 * (an unmerged dependency). This port lets the surrounding CSRD
 * lifecycle (`EsrsDataPoint::locked-for-assurance → assured →
 * published`) stay decoupled from that dependency.
 *
 * Until the cross-app binding is configured, the default binding is
 * dormant: it logs the build / validate intent and returns a
 * synthetic DEFERRED outcome so the lifecycle stays observable.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\CsrdEsrsXbrl
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://www.efrag.org/lab6
 *
 * @spec openspec/changes/bookkeeping-csrd-esrs/specs/bookkeeping-csrd-esrs/index.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\CsrdEsrsXbrl;

/**
 * CSRD / ESRS XBRL taxonomy + instance build adapter port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is
 * set; a dormant adapter records the intent (logger, audit trail) and
 * returns a synthetic DEFERRED outcome so the surrounding CSRD
 * lifecycle can advance without contacting the cross-app XBRL
 * pipeline. `validateMandatoryDataPoints()` on a dormant adapter
 * MUST return VALIDATION_BLOCKED so a deferred binding cannot
 * accidentally publish an unvalidated report.
 *
 * Activation steps for a real binding:
 *  1. Merge `bookkeeping-sbr-xbrl-reporting` (the XBRL pipeline
 *     dependency listed in the csrd-esrs spec header) and import the
 *     EFRAG ESRS XML schema (annual release).
 *  2. Bind the pipeline as an openconnector source — slug
 *     `efrag-esrs-xbrl`, pointing at the mapping + build endpoints.
 *     Store the EFRAG IG-3 mandatory-point list as a tenant-scoped
 *     index.
 *  3. Override the CsrdEsrsXbrlAdapterInterface DI binding in
 *     `Application::register()` to the openconnector-backed
 *     implementation.
 *  4. Hand the produced iXBRL instance to the existing
 *     `DigipoortSbrAdapterInterface` (filingType: `csrd-xbrl-pack`)
 *     for KvK / AFM transport.
 *
 * @spec openspec/changes/bookkeeping-csrd-esrs/specs/bookkeeping-csrd-esrs/index.md
 */
interface CsrdEsrsXbrlAdapterInterface {
	/**
	 * Map a list of esrs-data-point IDs to EFRAG ESRS XBRL fact
	 * concepts.
	 *
	 * @param string $taxonomyVersion EFRAG ESRS taxonomy
	 *                                version (e.g.
	 *                                `efrag-esrs-2024`).
	 * @param array<int,string> $dataPointIds EsrsDataPoint.esrsDataPointId
	 *                                        values to map.
	 *
	 * @return CsrdEsrsXbrlResult The mapping outcome (status MAPPED on
	 *                            success; extras carries the fact-concept
	 *                            dictionary).
	 */
	public function mapTaxonomy(string $taxonomyVersion, array $dataPointIds): CsrdEsrsXbrlResult;

	/**
	 * Validate that every mandatory EFRAG IG-3 data point is present
	 * and assured for the reporting period.
	 *
	 * Dormant implementations MUST return `VALIDATION_BLOCKED` with a
	 * non-empty `missingMandatory` list — never a clean validation —
	 * so a deferred adapter cannot accidentally publish an
	 * unvalidated report.
	 *
	 * @param string $reportingPeriod ISO reporting period (e.g. `2025`
	 *                                or `2025-FY`).
	 *
	 * @return CsrdEsrsXbrlResult The validation outcome.
	 */
	public function validateMandatoryDataPoints(string $reportingPeriod): CsrdEsrsXbrlResult;

	/**
	 * Build an iXBRL instance document (Inline XBRL PDF + embedded
	 * fact markup) for the reporting period.
	 *
	 * @param string $reportingPeriod ISO reporting period.
	 * @param array<string,mixed> $payload Build envelope —
	 *                                     materialityMatrixSnapshot,
	 *                                     ghgInventorySummary,
	 *                                     policyActionTargetExtract,
	 *                                     assuranceOpinion,
	 *                                     dataQualityNotes,
	 *                                     originalAnnualReportRef.
	 *
	 * @return CsrdEsrsXbrlResult The build outcome (status
	 *                            INSTANCE_BUILT on success; payloadRef
	 *                            carries the docudesk / openconnector
	 *                            artefact handle).
	 */
	public function buildInstance(string $reportingPeriod, array $payload): CsrdEsrsXbrlResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting
	 * the cross-app XBRL pipeline.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 */
	public function isDormant(): bool;
}//end interface
