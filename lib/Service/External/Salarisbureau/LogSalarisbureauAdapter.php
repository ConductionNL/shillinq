<?php

/**
 * Dormant default Salarisbureau adapter.
 *
 * Records the would-be payroll-run delta to the structured logger
 * and returns a synthetic DEFERRED result so the surrounding
 * lifecycle stays observable until a vendor-specific
 * openconnector-backed binding (ADP RUN / Loket / Nmbrs / Visma)
 * is wired in via `Application::register()`. Mirrors the
 * `LogCbsBestandenAdapter` dormant-default pattern used across the
 * Shillinq external surface.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Salarisbureau
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-detachering-payroll-administratie/spec.md
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Salarisbureau;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed Salarisbureau adapter.
 *
 * @spec openspec/specs/bookkeeping-detachering-payroll-administratie/spec.md
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 */
class LogSalarisbureauAdapter implements SalarisbureauAdapterInterface {
	/**
	 * Construct the log-backed Salarisbureau adapter.
	 *
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the intent + synthesise a DEFERRED payroll-run result.
	 *
	 * Per-employee rows are PII-heavy (BSN, bruto loon); the log entry
	 * MUST NOT contain BSN values. The `employees[]` array is reduced to
	 * employeeNumber + counts so the audit trail is preserved without
	 * leaking taxpayer identifiers into the structured logger.
	 *
	 * @param array<string,mixed> $payload The payroll-run envelope.
	 *
	 * @return SalarisbureauPayrollRunResult The dispatch outcome.
	 */
	public function submit(array $payload): SalarisbureauPayrollRunResult {
		$sanitised = $payload;

		// Strip BSN values from per-employee rows — never log Burger
		// Service Numbers; employeeNumber is sufficient for audit.
		if (isset($sanitised['employees']) === true && is_array($sanitised['employees']) === true) {
			$sanitised['employees'] = array_map(
				static function ($row): array {
					if (is_array($row) === false) {
						return ['_redacted' => true];
					}

					unset($row['bsn']);
					return $row;
				},
				$sanitised['employees']
			);
		}

		$runId = 'salarisbureau-log-' . bin2hex(random_bytes(8));
		$bureau = (string)($payload['bureau'] ?? 'unknown');
		$this->logger->info(
			'Shillinq salarisbureau payroll-run deferred (no outbound connector bound)',
			[
				'runId' => $runId,
				'bureau' => $bureau,
				'payload' => $sanitised,
			]
		);

		return new SalarisbureauPayrollRunResult(
			deliveryStatus: 'DEFERRED',
			runId: $runId,
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `salarisbureau-<vendor>` (adp / loket / nmbrs / visma) '
					. 'and override SalarisbureauAdapterInterface in Application::register() '
					. 'to enable real transport.',
				'bureau' => $bureau,
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
