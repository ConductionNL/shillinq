<?php

/**
 * Dormant default CSRD / ESRS XBRL adapter.
 *
 * Records the would-be taxonomy mapping / mandatory-data-point
 * validation / iXBRL instance build to the structured logger and
 * returns a synthetic DEFERRED / VALIDATION_BLOCKED result so the
 * surrounding CSRD lifecycle (EsrsDataPoint `locked-for-assurance →
 * assured → published`) stays observable until the
 * `bookkeeping-sbr-xbrl-reporting` pipeline is merged + bound via
 * `Application::register()`. Mirrors the
 * `LogMolliePaymentAdapter` / `LogDigipoortSbrAdapter` dormant-default
 * pattern used across the Shillinq external surface.
 *
 * SAFETY: `validateMandatoryDataPoints()` ALWAYS returns
 * VALIDATION_BLOCKED with a sentinel `LOG_DEFERRED` entry in
 * `missingMandatory`. That is the deliberate guard — a dormant
 * binding MUST NOT let a deferred publish slip past the EFRAG IG-3
 * mandatory-point check.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\CsrdEsrsXbrl
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-csrd-esrs/specs/bookkeeping-csrd-esrs/index.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\CsrdEsrsXbrl;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed CSRD / ESRS XBRL adapter.
 *
 * @spec openspec/changes/bookkeeping-csrd-esrs/specs/bookkeeping-csrd-esrs/index.md
 */
class LogCsrdEsrsXbrlAdapter implements CsrdEsrsXbrlAdapterInterface {
	/**
	 * Construct the log-backed CSRD / ESRS XBRL adapter.
	 *
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the taxonomy-mapping intent + synthesise a DEFERRED result.
	 *
	 * The full data-point id list is logged unredacted because it is
	 * an audit artefact (per REQ-CSR-002 every taxonomy mapping is
	 * traceable to the assurance file).
	 *
	 * @param string $taxonomyVersion EFRAG taxonomy version.
	 * @param array<int,string> $dataPointIds Data-point ids to map.
	 *
	 * @return CsrdEsrsXbrlResult The dispatch outcome.
	 */
	public function mapTaxonomy(string $taxonomyVersion, array $dataPointIds): CsrdEsrsXbrlResult {
		$documentId = 'esrs_map_log_' . bin2hex(random_bytes(7));
		$this->logger->info(
			'Shillinq CSRD/ESRS mapTaxonomy deferred (no outbound XBRL connector bound)',
			[
				'documentId' => $documentId,
				'taxonomyVersion' => $taxonomyVersion,
				'dataPointIds' => $dataPointIds,
				'dataPointCount' => count($dataPointIds),
			]
		);

		return new CsrdEsrsXbrlResult(
			status: 'DEFERRED',
			documentId: $documentId,
			taxonomyVersion: $taxonomyVersion,
			contentType: 'application/json',
			payloadRef: '',
			missingMandatory: [],
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `efrag-esrs-xbrl` '
					. '(requires bookkeeping-sbr-xbrl-reporting dependency + EFRAG ESRS XML schema import) '
					. 'and override CsrdEsrsXbrlAdapterInterface in Application::register() to enable real taxonomy mapping.',
			],
		);
	}//end mapTaxonomy()

	/**
	 * Log the validation intent + return VALIDATION_BLOCKED with the
	 * `LOG_DEFERRED` sentinel.
	 *
	 * The sentinel guarantees that a dormant adapter cannot let a
	 * deferred publish slip past the mandatory-point check — the
	 * surrounding lifecycle MUST treat any non-empty
	 * `missingMandatory` as a publish block.
	 *
	 * @param string $reportingPeriod ISO reporting period.
	 *
	 * @return CsrdEsrsXbrlResult The validation outcome (always
	 *                            VALIDATION_BLOCKED in dormant mode).
	 */
	public function validateMandatoryDataPoints(string $reportingPeriod): CsrdEsrsXbrlResult {
		$documentId = 'esrs_val_log_' . bin2hex(random_bytes(7));
		$this->logger->info(
			'Shillinq CSRD/ESRS validateMandatoryDataPoints deferred — returning VALIDATION_BLOCKED sentinel (no outbound XBRL connector bound)',
			[
				'documentId' => $documentId,
				'reportingPeriod' => $reportingPeriod,
			]
		);

		return new CsrdEsrsXbrlResult(
			status: 'VALIDATION_BLOCKED',
			documentId: $documentId,
			taxonomyVersion: 'unknown',
			contentType: 'application/json',
			payloadRef: '',
			missingMandatory: ['LOG_DEFERRED'],
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'EFRAG IG-3 mandatory-data-point validation deferred; the LOG_DEFERRED '
					. 'sentinel deliberately blocks publish. Bind openconnector source slug '
					. '`efrag-esrs-xbrl` to enable real validation.',
			],
		);
	}//end validateMandatoryDataPoints()

	/**
	 * Log the iXBRL build intent + synthesise a DEFERRED result.
	 *
	 * The build envelope (materiality matrix, GHG inventory summary,
	 * policy/action/target extract, assurance opinion) is logged
	 * unredacted because the audit trail needs it to correlate the
	 * dormant intent back to a Shillinq report once the live binding
	 * is provisioned.
	 *
	 * @param string $reportingPeriod ISO reporting period.
	 * @param array<string,mixed> $payload Build envelope.
	 *
	 * @return CsrdEsrsXbrlResult The build outcome.
	 */
	public function buildInstance(string $reportingPeriod, array $payload): CsrdEsrsXbrlResult {
		$documentId = 'esrs_ixbrl_log_' . bin2hex(random_bytes(7));
		$this->logger->info(
			'Shillinq CSRD/ESRS buildInstance deferred (no outbound XBRL connector bound)',
			[
				'documentId' => $documentId,
				'reportingPeriod' => $reportingPeriod,
				'payload' => $payload,
			]
		);

		return new CsrdEsrsXbrlResult(
			status: 'DEFERRED',
			documentId: $documentId,
			taxonomyVersion: 'unknown',
			contentType: 'application/xbrl+xml',
			payloadRef: '',
			missingMandatory: [],
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `efrag-esrs-xbrl` to enable real iXBRL '
					. 'instance build; the produced instance is handed to DigipoortSbrAdapterInterface '
					. '(filingType: csrd-xbrl-pack) for KvK / AFM transport.',
			],
		);
	}//end buildInstance()

	/**
	 * Report whether this adapter is dormant (log-only).
	 *
	 * @return bool Always TRUE for the log-only adapter.
	 *
	 * @inheritDoc
	 */
	public function isDormant(): bool {
		return true;
	}//end isDormant()
}//end class
