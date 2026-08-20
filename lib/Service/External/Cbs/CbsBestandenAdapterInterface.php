<?php

/**
 * CBS Bestanden submission port.
 *
 * CBS (Centraal Bureau voor de Statistiek) ingests aggregated business
 * statistics ("CBS Bestanden") under the Verordening Statistieken
 * Bedrijven mandate. Shillinq materialises the submission payload from
 * the `CBSSubmission` + `CBSLine` schemas declared by the
 * `bookkeeping-cbs-bestanden-extended` capability and hands the bytes
 * over to this adapter for transport to CBS.
 *
 * The port is intentionally narrow — one upload call returning a
 * dispatch outcome — so the production binding can be swapped in via
 * `Application::register()` without touching the orchestrator. Until an
 * openconnector-backed binding is configured, the default binding is
 * dormant: it logs the intent without contacting CBS so the lifecycle
 * stays observable in test + staging environments.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Cbs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Cbs;

/**
 * CBS Bestanden submission port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is set;
 * a dormant adapter records the intent (logger, audit trail) and returns
 * a synthetic DEFERRED outcome so the surrounding lifecycle can advance
 * into `submitted` without contacting CBS.
 *
 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
 */
interface CbsBestandenAdapterInterface {
	/**
	 * Submit a CBS Bestanden payload.
	 *
	 * @param array<string,mixed> $payload The CBS submission envelope —
	 *                                     submissionNumber, reportingPeriod{Start,End}Date,
	 *                                     organizationLegalName, kvkNumber, taxIdentificationNumber,
	 *                                     administrationId, lines[] (CBSLine projections),
	 *                                     iv3FileBytes (binary), iv3Checksum.
	 *
	 * @return CbsSubmissionResult The dispatch outcome (status + tracking id).
	 */
	public function submit(array $payload): CbsSubmissionResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting CBS.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 */
	public function isDormant(): bool;
}//end interface
