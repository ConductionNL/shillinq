<?php

/**
 * Dormant default Belastingdienst IB47 adapter.
 *
 * Records the would-be IB47 / UBD opgaaf to the structured logger
 * and returns a synthetic DEFERRED result so the surrounding
 * lifecycle stays observable until an openconnector-backed binding
 * to the Belastingdienst Gegevensportaal is wired in via
 * `Application::register()`. Mirrors the `LogCbsBestandenAdapter`
 * dormant-default pattern used across the Shillinq external
 * surface.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Ib47
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-detachering-payroll-administratie/spec.md
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Ib47;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed Belastingdienst IB47 adapter.
 *
 * @spec openspec/specs/bookkeeping-detachering-payroll-administratie/spec.md
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 */
class LogIb47Adapter implements Ib47AdapterInterface {
	/**
	 * Construct the log-backed IB47 adapter.
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
	 * Per-recipient rows are PII-heavy (BSN, address, birth date); the
	 * log entry MUST NOT contain BSN values or birth dates. The
	 * `recipients[]` array is reduced to row counts + nature-code
	 * histogram so the audit trail is preserved without leaking
	 * taxpayer identifiers into the structured logger.
	 *
	 * @param array<string,mixed> $payload The IB47 submission envelope.
	 *
	 * @return Ib47SubmissionResult The dispatch outcome.
	 */
	public function submit(array $payload): Ib47SubmissionResult {
		$sanitised = $payload;

		// Replace the recipients array with a privacy-safe summary —
		// never log BSN, name, address, or birth date.
		if (isset($sanitised['recipients']) === true && is_array($sanitised['recipients']) === true) {
			$natureHistogram = [];
			$totalRows = 0;
			$totalAmount = 0.0;
			foreach ($sanitised['recipients'] as $row) {
				if (is_array($row) === false) {
					continue;
				}

				$totalRows++;
				$natureCode = (string)($row['natureCode'] ?? 'unknown');
				$natureHistogram[$natureCode] = (($natureHistogram[$natureCode] ?? 0) + 1);
				$totalAmount += (float)($row['paidAmount'] ?? 0.0);
			}

			$sanitised['recipients'] = [
				'_redacted' => true,
				'rowCount' => $totalRows,
				'totalPaidAmount' => $totalAmount,
				'natureHistogram' => $natureHistogram,
			];
		}//end if

		$reference = 'ib47-log-' . bin2hex(random_bytes(8));
		$this->logger->info(
			'Shillinq Belastingdienst IB47 submission deferred (no outbound connector bound)',
			[
				'reference' => $reference,
				'payload' => $sanitised,
			]
		);

		return new Ib47SubmissionResult(
			deliveryStatus: 'DEFERRED',
			reference: $reference,
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `belastingdienst-ib47` (PKIoverheid '
					. 'Services-server cert + Gegevensportaal IB47 endpoint, or Renseigneringsstroom '
					. 'via Digipoort for intermediair flow) and override Ib47AdapterInterface in '
					. 'Application::register() to enable real transport.',
			],
		);
	}//end submit()

	/**
	 * Report whether this adapter is dormant.
	 *
	 * @return bool True when no outbound connector is bound.
	 *
	 * @inheritDoc
	 */
	public function isDormant(): bool {
		return true;
	}//end isDormant()
}//end class
