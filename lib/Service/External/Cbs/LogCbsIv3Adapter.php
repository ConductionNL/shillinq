<?php

/**
 * Dormant default CBS Iv3 adapter.
 *
 * Records the would-be Iv3 quarterly/annual submission to the structured
 * logger and returns a synthetic DEFERRED outcome so the Iv3 reporting
 * lifecycle stays observable until an openconnector source slug
 * `cbs-iv3` is wired.
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
 * Dormant log-backed CBS Iv3 adapter.
 *
 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
 */
class LogCbsIv3Adapter implements CbsIv3AdapterInterface {
	/**
	 * Construct the log-backed CBS Iv3 adapter.
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
	 * @param array<string,mixed> $payload The Iv3 submission envelope.
	 *
	 * @return CbsSubmissionResult The dispatch outcome.
	 */
	public function submit(array $payload): CbsSubmissionResult {
		$sanitised = $payload;
		unset($sanitised['reportingXmlBytes']);

		$trackingId = 'iv3-log-' . bin2hex(random_bytes(8));
		$this->logger->info(
			'Shillinq CBS Iv3 submission deferred (no outbound connector bound)',
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
				'note' => 'Bind openconnector source slug `cbs-iv3` and override CbsIv3AdapterInterface in '
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
