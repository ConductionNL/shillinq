<?php

/**
 * Dormant default Digipoort/SBR adapter.
 *
 * Records the would-be submission to the structured logger and returns
 * a synthetic DEFERRED result so the surrounding lifecycle stays
 * observable until an openconnector-backed binding to Digipoort is
 * wired in via `Application::register()`. Mirrors the
 * `LogCbsBestandenAdapter` dormant-default pattern used across the
 * Shillinq external surface.
 *
 * Used by every SBR-bearing filing capability — VAT/BTW aangifte,
 * financial-statements jaarrekening deponering, ICP-opgaaf,
 * CSRD/ESRS XBRL pack — so a single port + log-default keeps the
 * tax-filing pipelines testable end-to-end on a clean instance.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Digipoort
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
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

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed Digipoort/SBR adapter.
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 * @spec openspec/specs/bookkeeping-financial-statements/spec.md
 * @spec openspec/specs/bookkeeping-sbr-xbrl-reporting/spec.md
 * @spec openspec/changes/bookkeeping-csrd-esrs/tasks.md
 */
class LogDigipoortSbrAdapter implements DigipoortSbrAdapterInterface {
	/**
	 * Construct the log-backed Digipoort adapter.
	 *
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the intent + synthesise a DEFERRED submission result.
	 *
	 * @param array<string,mixed> $payload The Digipoort delivery envelope.
	 *
	 * @return DigipoortSubmissionResult The dispatch outcome.
	 */
	public function submit(array $payload): DigipoortSubmissionResult {
		$sanitised = $payload;
		// Strip the raw XBRL bytes from the log entry — checksum is enough.
		unset($sanitised['xbrlInstanceBytes']);

		$reference = 'digipoort-log-' . bin2hex(random_bytes(8));
		$this->logger->info(
			'Shillinq Digipoort/SBR submission deferred (no outbound connector bound)',
			[
				'reference' => $reference,
				'payload' => $sanitised,
			]
		);

		return new DigipoortSubmissionResult(
			deliveryStatus: 'DEFERRED',
			reference: $reference,
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `digipoort-sbr` (PKIoverheid Services-server cert '
					. '+ Aanleverservice WUS 2.0 endpoint) and override DigipoortSbrAdapterInterface in '
					. 'Application::register() to enable real transport.',
			],
		);
	}//end submit()

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
