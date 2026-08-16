<?php

/**
 * Dormant default RvO aanvraag adapter.
 *
 * Records the would-be aanvraag to the structured logger and returns
 * a synthetic DEFERRED result so the surrounding lifecycle stays
 * observable until an openconnector-backed binding to RvO is wired
 * in via `Application::register()`. Mirrors the
 * `LogCbsBestandenAdapter` dormant-default pattern used across the
 * Shillinq external surface.
 *
 * Covers the WBSO / SnO progress reports + investment-allowance
 * (KIA / EIA / MIA / Vamil) annual submissions; one port + log-default
 * keeps both schemes' pipelines testable end-to-end on a clean
 * instance.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\RvO
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
 * @spec openspec/specs/bookkeeping-wbso-sno-administratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\RvO;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed RvO aanvraag adapter.
 *
 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
 * @spec openspec/specs/bookkeeping-wbso-sno-administratie/spec.md
 */
class LogRvOAanvraagAdapter implements RvOAanvraagAdapterInterface {
	/**
	 * Construct the log-backed RvO adapter.
	 *
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the intent + synthesise a DEFERRED aanvraag result.
	 *
	 * @param array<string,mixed> $payload The RvO aanvraag envelope.
	 *
	 * @return RvORequestResult The dispatch outcome.
	 */
	public function submit(array $payload): RvORequestResult {
		$sanitised = $payload;
		// Strip raw attachment bytes from the log entry — checksum is enough.
		unset($sanitised['attachmentBytes']);

		$aanvraagnummer = 'rvo-log-' . bin2hex(random_bytes(8));
		$scheme = (string)($payload['scheme'] ?? 'unknown');
		$this->logger->info(
			'Shillinq RvO aanvraag deferred (no outbound connector bound)',
			[
				'aanvraagnummer' => $aanvraagnummer,
				'scheme' => $scheme,
				'payload' => $sanitised,
			]
		);

		return new RvORequestResult(
			deliveryStatus: 'DEFERRED',
			aanvraagnummer: $aanvraagnummer,
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `rvo-aanvraag` (eHerkenning Level 3 + Mijn-RvO REST '
					. 'endpoint for the relevant scheme: wbso/sno/kia/eia/mia/vamil) and override '
					. 'RvOAanvraagAdapterInterface in Application::register() to enable real transport.',
				'scheme' => $scheme,
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
