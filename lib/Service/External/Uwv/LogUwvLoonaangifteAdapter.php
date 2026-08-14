<?php

/**
 * Dormant default UWV Loonaangifte / Werkhervattingskas adapter.
 *
 * Records the would-be UWV pullStatus / lookupSector intent to the
 * structured logger and returns a synthetic
 * STATUS_DEFERRED / SECTOR_DEFERRED result so the surrounding
 * lifecycle (LHAfdracht acceptance, werkgever-setup wizard
 * sectorindeling validation) stays observable until an
 * openconnector-backed binding to UWV polisadministratie is wired
 * in via `Application::register()`. Mirrors the
 * `LogIb47Adapter` / `LogCbsBestandenAdapter` dormant-default
 * pattern used across the Shillinq external surface.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Uwv
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/specs/req-pay-000-werkgever-setup.md
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/specs/req-pay-011-lh-aangifte.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Uwv;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed UWV Loonaangifte / Werkhervattingskas adapter.
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/specs/req-pay-011-lh-aangifte.md
 */
class LogUwvLoonaangifteAdapter implements UwvLoonaangifteAdapterInterface {
	/**
	 * Construct the log-backed UWV adapter.
	 *
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the loonaangifte-status pull intent + synthesise a
	 * STATUS_DEFERRED result.
	 *
	 * `loonheffingsnummer` is logged as-is (it is a tax-identifier of
	 * the employer entity, not of a natural person, and a
	 * loonaangifte-pull always needs it correlated to the kenmerk).
	 *
	 * @param array<string,mixed> $payload Pull envelope.
	 *
	 * @return UwvStatusResult The dispatch outcome.
	 */
	public function pullStatus(array $payload): UwvStatusResult {
		$reference = 'uwv-pull-log-' . bin2hex(random_bytes(8));
		$this->logger->info(
			'Shillinq UWV loonaangifte pullStatus deferred (no outbound connector bound)',
			[
				'reference' => $reference,
				'payload' => $payload,
			]
		);

		return new UwvStatusResult(
			outcome: 'STATUS_DEFERRED',
			reference: $reference,
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `uwv-loonaangifte` (PKIoverheid Services-server cert '
					. '+ UWV polisadministratie endpoint) and override UwvLoonaangifteAdapterInterface in '
					. 'Application::register() to enable real status pull.',
			],
		);
	}//end pullStatus()

	/**
	 * Log the sectorindeling lookup intent + synthesise a
	 * SECTOR_DEFERRED result.
	 *
	 * @param string $sectorCode Werkhervattingskas sector code.
	 * @param int $peiljaar Calendar year.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return UwvStatusResult The lookup outcome.
	 */
	public function lookupSector(string $sectorCode, int $peiljaar, array $context = []): UwvStatusResult {
		$reference = 'uwv-sector-log-' . bin2hex(random_bytes(6));
		$this->logger->info(
			'Shillinq UWV Werkhervattingskas lookupSector deferred (no outbound connector bound)',
			[
				'reference' => $reference,
				'sectorCode' => $sectorCode,
				'peiljaar' => $peiljaar,
				'context' => $context,
			]
		);

		return new UwvStatusResult(
			outcome: 'SECTOR_DEFERRED',
			reference: $reference,
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `uwv-loonaangifte` + map sectorindeling endpoint, '
					. 'then override UwvLoonaangifteAdapterInterface in Application::register() to enable real '
					. 'sector validation.',
			],
		);
	}//end lookupSector()

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
