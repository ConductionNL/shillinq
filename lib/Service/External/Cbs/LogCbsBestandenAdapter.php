<?php

/**
 * Dormant default CBS Bestanden adapter.
 *
 * Records the would-be submission to the structured logger and returns
 * a synthetic DEFERRED result so the surrounding lifecycle stays
 * observable until an openconnector-backed binding to CBS is wired in
 * via `Application::register()`. Mirrors the `LogPostNLAdapter`
 * dormant-default pattern used across the Shillinq external surface.
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

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed CBS Bestanden adapter.
 *
 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
 */
class LogCbsBestandenAdapter implements CbsBestandenAdapterInterface {
	/**
	 * Construct the log-backed CBS Bestanden adapter.
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
	 * @param array<string,mixed> $payload The CBS submission envelope.
	 *
	 * @return CbsSubmissionResult The dispatch outcome.
	 */
	public function submit(array $payload): CbsSubmissionResult {
		$sanitised = $payload;
		// Strip the raw bytes from the log entry — checksum is enough.
		unset($sanitised['iv3FileBytes']);

		$trackingId = 'cbs-log-' . bin2hex(random_bytes(8));
		$this->logger->info(
			'Shillinq CBS Bestanden submission deferred (no outbound connector bound)',
			[
				'trackingId' => $trackingId,
				'payload' => $sanitised,
			]
		);

		return new CbsSubmissionResult(
			deliveryStatus: 'DEFERRED',
			trackingId: $trackingId,
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `cbs-bestanden` and override CbsBestandenAdapterInterface '
					. 'in Application::register() to enable real transport.',
			],
		);
	}//end submit()

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
