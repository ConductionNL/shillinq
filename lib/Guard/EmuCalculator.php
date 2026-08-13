<?php

/**
 * EMU-saldo calculator — ADR-031 exception-path guard.
 *
 * Invoked by the x-openregister-aggregations engine (via the `guard:` clause on
 * `emuSaldoByQuarter` and `emuSaldoByYear`) when the declarative multi-sector +
 * emuInclusionRule combined filter cannot be expressed natively. The methods are
 * thin PHP seams per ADR-031 §"PHP guards remain a legitimate seam": single
 * public method per entry-point, ≤ 25 LOC of domain logic, no persistence.
 *
 * Exception documented in openspec/changes/add-shillinq-emu-reporting/design.md
 * under "Declarative-vs-imperative decision":
 *   The x-openregister-aggregations engine does not yet support cross-schema
 *   filter joins (GLLine → Account.esaClassifier) combined with a dynamic
 *   emuInclusionRule filter from a sibling schema (WaterschapHeffingPosting).
 *   This guard provides the fallback until the engine supports the pattern.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-shillinq-emu-reporting/tasks.md#task-11
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use Psr\Log\LoggerInterface;

/**
 * ADR-031 exception guard for EMU-saldo computation.
 *
 * Called when the aggregation engine passes control here for the multi-sector
 * ESA-2010 filter that it cannot express natively. Returns a sector-keyed
 * array of EMU saldo values (debit minus credit, in euro-cents to avoid
 * IEEE-754 rounding). Excluded lines are skipped; partial lines contribute
 * at 50% per the 2026 BBV handleiding default.
 *
 * @spec openspec/changes/add-shillinq-emu-reporting/tasks.md#task-11
 */
class EmuCalculator {
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
	 * Compute quarterly EMU-saldo from a pre-filtered set of GL lines.
	 *
	 * @param array<int,array<string,mixed>> $glLines GL lines already fetched by the engine for the quarter.
	 * @param array<string,mixed> $params Must include `quarter` (Q1–Q4) and `year` (int).
	 *
	 * @return array<string,int> Sector-keyed saldo in euro-cents (e.g. ['S.1313' => 150000]).
	 *
	 * @spec openspec/changes/add-shillinq-emu-reporting/tasks.md#task-11
	 */
	public function computeQuarterlySaldo(array $glLines, array $params): array {
		$this->logger->debug('EmuCalculator: quarterly saldo', ['params' => $params, 'lines' => count($glLines)]);
		return $this->aggregateBySector(glLines: $glLines);
	}//end computeQuarterlySaldo()

	/**
	 * Compute annual EMU-saldo (and EMU-schuld as negative saldo) from GL lines.
	 *
	 * @param array<int,array<string,mixed>> $glLines GL lines for the closed fiscal year.
	 * @param array<string,mixed> $params Must include `fiscalYear` (int).
	 *
	 * @return array<string,int> Sector-keyed saldo in euro-cents.
	 *
	 * @spec openspec/changes/add-shillinq-emu-reporting/tasks.md#task-11
	 */
	public function computeAnnualSaldo(array $glLines, array $params): array {
		$this->logger->debug('EmuCalculator: annual saldo', ['params' => $params, 'lines' => count($glLines)]);
		return $this->aggregateBySector(glLines: $glLines);
	}//end computeAnnualSaldo()

	/**
	 * Core sector-grouping logic shared by quarterly and annual paths.
	 *
	 * Applies emuInclusionRule: 'excluded' → skip; 'partial' → 50%; 'included' → 100%.
	 * Uses integer cents to avoid IEEE-754 float equality issues (same pattern as
	 * AccountBalanceGuard::requireZeroBalance).
	 *
	 * @param array<int,array<string,mixed>> $glLines Raw GL line objects from OR.
	 *
	 * @return array<string,int> ESA sector code → net saldo in euro-cents.
	 */
	private function aggregateBySector(array $glLines): array {
		$balance = [];
		foreach ($glLines as $line) {
			$sector = $line['account']['esaClassifier'] ?? ($line['esaClassifier'] ?? null);
			if ($sector === null) {
				continue;
			}

			$rule = $line['emuInclusionRule'] ?? 'included';
			if ($rule === 'excluded') {
				continue;
			}

			$debitCents = (int)round((float)($line['debit'] ?? 0) * 100);
			$creditCents = (int)round((float)($line['credit'] ?? 0) * 100);
			$netCents = $debitCents - $creditCents;

			if ($rule === 'partial') {
				// Integer-safe 50 % — multiply by 1 then divide by 2 to avoid float.
				$netCents = (int)round($netCents / 2);
			}

			$balance[$sector] = ($balance[$sector] ?? 0) + $netCents;
		}//end foreach

		return $balance;
	}//end aggregateBySector()
}//end class
