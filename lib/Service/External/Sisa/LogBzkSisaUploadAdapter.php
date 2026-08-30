<?php

/**
 * Dormant default BZK SiSa upload adapter.
 *
 * Records the would-be SiSa upload to the structured logger and returns
 * a synthetic DEFERRED outcome so the SiSa reporting lifecycle stays
 * observable until an openconnector source slug `bzk-sisa` is wired in
 * via `Application::register()`.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Sisa
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-sisa-reporting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Sisa;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed BZK SiSa upload adapter.
 *
 * @spec openspec/specs/bookkeeping-sisa-reporting/spec.md
 */
class LogBzkSisaUploadAdapter implements BzkSisaUploadAdapterInterface {
	/**
	 * Construct the log-backed BZK SiSa adapter.
	 *
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the intent + synthesise a DEFERRED upload result.
	 *
	 * @param array<string,mixed> $payload The SiSa upload envelope.
	 *
	 * @return BzkSisaUploadResult The dispatch outcome.
	 */
	public function upload(array $payload): BzkSisaUploadResult {
		$sanitised = $payload;
		unset($sanitised['reportXmlBytes']);
		unset($sanitised['signedPdfBytes']);

		$trackingId = 'sisa-log-' . bin2hex(random_bytes(8));
		$this->logger->info(
			'Shillinq BZK SiSa upload deferred (no outbound connector bound)',
			[
				'trackingId' => $trackingId,
				'payload' => $sanitised,
			]
		);

		return new BzkSisaUploadResult(
			deliveryStatus: 'DEFERRED',
			trackingId: $trackingId,
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `bzk-sisa` and override BzkSisaUploadAdapterInterface '
					. 'in Application::register() to enable real transport.',
			],
		);
	}//end upload()

	/**
	 * Report whether this adapter is dormant (logs only, no outbound connector).
	 *
	 * @inheritDoc
	 *
	 * @return bool Always true for the dormant log adapter.
	 */
	public function isDormant(): bool {
		return true;
	}//end isDormant()
}//end class
