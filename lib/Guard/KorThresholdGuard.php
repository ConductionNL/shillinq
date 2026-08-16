<?php

/**
 * KOR omzetdrempel YTD-revenue aggregation — ADR-031 exception-path guard.
 *
 * Invoked by the x-openregister-calculations engine (via the `guard:` clause on
 * KorRegime.ytdRevenue) when the declarative cross-period sum of revenue cannot
 * be expressed natively. Single public method, no persistence, pure aggregation
 * per ADR-031 §"PHP guards remain a legitimate seam". Cancelled invoices and
 * credit notes are excluded from the omzetdrempel per Wet OB 1968 art. 25.
 *
 * Exception documented in
 * openspec/changes/add-shillinq-bookkeeping-operations/design.md §D5.
 *
 * @category Guard
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use Psr\Log\LoggerInterface;

/**
 * ADR-031 exception guard computing YTD revenue for the KOR omzetdrempel.
 *
 * Called when the calculation engine passes a pre-fetched set of Invoice records
 * for an administration and calendar year. Sums issued-invoice amounts in the
 * year, excluding cancelled invoices and credit notes (negative documents). The
 * resulting total drives the KOR threshold-warning/threshold-exceeded lifecycle.
 *
 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
 */
class KorThresholdGuard {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Nextcloud logger for computation diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute YTD revenue for an administration in a calendar year.
	 *
	 * @param array<int,array<string,mixed>> $invoices Pre-fetched Invoice records.
	 * @param string $administrationId The administration whose revenue is summed.
	 * @param int $year The calendar year to bound the sum.
	 *
	 * @return float Sum of qualifying invoice revenue for the administration in the year.
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function currentYtdRevenue(array $invoices, string $administrationId, int $year): float {
		$this->logger->debug(
			'KorThresholdGuard: currentYtdRevenue',
			['administrationId' => $administrationId, 'year' => $year, 'invoices' => count($invoices)]
		);

		$total = 0.0;
		foreach ($invoices as $invoice) {
			if (($invoice['administrationId'] ?? null) !== $administrationId) {
				continue;
			}

			$invoiceDate = (string)($invoice['invoiceDate'] ?? ($invoice['issueDate'] ?? ''));
			if (substr($invoiceDate, 0, 4) !== (string)$year) {
				continue;
			}

			$status = (string)($invoice['status'] ?? ($invoice['state'] ?? ''));
			if ($status === 'cancelled' || $status === 'credited') {
				continue;
			}

			if (($invoice['documentType'] ?? '') === 'credit-note') {
				continue;
			}

			$total += (float)($invoice['amount'] ?? ($invoice['netAmount'] ?? 0));
		}//end foreach

		return $total;
	}//end currentYtdRevenue()
}//end class
