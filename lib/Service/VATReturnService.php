<?php

/**
 * VAT Return Service
 *
 * Tier-3 BTW-aangifte preparation engine for the
 * bookkeeping-vat-btw-filing change (issue #127). Composes the
 * declarative VATReturn / VATDeclaration / VATLine schemas (ADR-031,
 * ADR-037) with PHP code that exercises the real OpenRegister
 * ObjectService API (find / findAll / saveObject / deleteObject) to:
 *
 *  - createReturn()       — instantiate a draft VATReturn for a period
 *                           and trigger GL derivation;
 *  - deriveVATLines()     — scan GL transactions in the period where
 *                           Account.vatApplicable = true and create
 *                           VATLine records grouped into VATDeclarations
 *                           by (type, taxRate) (REQ-VAT-002, REQ-VAT-011);
 *  - submitReturn()       — validate totals and transition draft →
 *                           submitted (REQ-VAT-005);
 *  - rebaseReturn()       — transition submitted → draft, drop the old
 *                           lines, re-derive from GL (REQ-VAT-008).
 *
 * Per ADR-031 the equivalent declarative shape lives on the schema
 * (x-openregister-aggregations.totalsByReturn); this service is the
 * engine-side fallback for the per-return GL → VATLine mapping the
 * declarative engine cannot yet express.
 *
 * Money is handled with multipleOf 0.01 (2-decimal Euro precision)
 * via the calculator helpers.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Composes VAT returns from GL transactions using the real OpenRegister API.
 *
 * Reads + writes are delegated to OpenRegister's ObjectService and scoped
 * to the server-resolved administration, never to a client-supplied trust
 * boundary (REQ-VAT-001..REQ-VAT-011, REQ-VAT-008).
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * Pre-existing debt (issue #506): early-return refactor and variable
 * renames deferred pending a dedicated pass.
 */
class VATReturnService {
	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Create a new VAT return for a period + regime and derive its lines (REQ-VAT-001).
	 *
	 * @param string $administrationId Server-resolved administration scope.
	 * @param string $period One of quarter | month | year.
	 * @param int $periodYear Fiscal year (e.g. 2026).
	 * @param int $periodNumber Period within year (Q: 1-4, M: 1-12, Y: 1).
	 * @param string $regime One of standard | kor | reverse-charge.
	 *
	 * @return array<string,mixed> The created VATReturn record (incl. id).
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function createReturn(
		string $administrationId,
		string $period,
		int $periodYear,
		int $periodNumber,
		string $regime,
	): array {
		[$startDate, $endDate] = $this->resolvePeriodBounds(period: $period, periodYear: $periodYear, periodNumber: $periodNumber);

		$returnNumber = $this->buildReturnNumber(periodYear: $periodYear, periodNumber: $periodNumber, period: $period, regime: $regime);
		$vatReturn = [
			'returnNumber' => $returnNumber,
			'period' => $period,
			'periodYear' => $periodYear,
			'periodNumber' => $periodNumber,
			'startDate' => $startDate,
			'endDate' => $endDate,
			'regime' => $regime,
			'administrationId' => $administrationId,
			'statusCode' => 'draft',
			'submissionDate' => null,
			'verificationDate' => null,
			'filingReference' => null,
			'totalVATCollected' => 0.0,
			'totalVATPaid' => 0.0,
			'vatBalance' => 0.0,
			'totalTaxableAmount' => 0.0,
			'notes' => null,
		];

		$persisted = $this->saveObject(schema: 'BtwAangifte', data: $vatReturn);
		$returnId = (string)($persisted['id'] ?? ($persisted['@self']['id'] ?? ''));

		if ($returnId === '') {
			throw new RuntimeException('VATReturn save did not return an identifier.');
		}

		$this->deriveVATLines(
			returnId: $returnId,
			administrationId: $administrationId,
			startDate: $startDate,
			endDate: $endDate,
			regime: $regime
		);

		// Re-read the return so totals reflect the derived lines.
		return $this->fetchReturn(returnId: $returnId);
	}//end createReturn()

	/**
	 * Derive VATLine + VATDeclaration records from GL transactions in the period (REQ-VAT-002).
	 *
	 * Scans GLTransaction rows in the administration whose dated entry falls in
	 * [startDate, endDate]; for each posting that lands on an Account where
	 * vatApplicable = true, groups by (taxRate, type=collected|paid|reverse-charge)
	 * and writes one VATLine per posting + one VATDeclaration per group. Totals
	 * are rolled up into the parent VATReturn.
	 *
	 * KOR returns short-circuit to zero totals per REQ-VAT-004.
	 *
	 * @param string $returnId The VATReturn id.
	 * @param string $administrationId Administration scope.
	 * @param string $startDate Period start (inclusive, ISO-8601).
	 * @param string $endDate Period end (inclusive, ISO-8601).
	 * @param string $regime Regime variant.
	 *
	 * @return array{lineCount:int,totalVATCollected:float,totalVATPaid:float,vatBalance:float,totalTaxableAmount:float}
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function deriveVATLines(
		string $returnId,
		string $administrationId,
		string $startDate,
		string $endDate,
		string $regime = 'standard',
	): array {
		if ($regime === 'kor') {
			// KOR exempts both collected and paid VAT (REQ-VAT-004).
			$this->updateReturnTotals(
				returnId: $returnId,
				totalVATCollected: 0.0,
				totalVATPaid: 0.0,
				vatBalance: 0.0,
				totalTaxableAmount: 0.0
			);
			return [
				'lineCount' => 0,
				'totalVATCollected' => 0.0,
				'totalVATPaid' => 0.0,
				'vatBalance' => 0.0,
				'totalTaxableAmount' => 0.0,
			];
		}

		$scan = $this->scanRubrieken(administrationId: $administrationId, startDate: $startDate, endDate: $endDate);
		$declarationsByKey = $scan['declarationsByKey'];
		$totalVATCollectedCt = $scan['totalVATCollectedCt'];
		$totalVATPaidCt = $scan['totalVATPaidCt'];
		$totalTaxableCt = $scan['totalTaxableCt'];
		$lineNumber = $scan['lineNumber'];

		// Persist declarations + their lines.
		foreach ($declarationsByKey as $group) {
			$declarationId = $this->persistDeclaration(
				returnId: $returnId,
				administrationId: $administrationId,
				type: $group['type'],
				taxRate: $group['taxRate'],
				totalVATCents: $group['totalVATAmountCents'],
				totalTaxableCents: $group['totalTaxableCents'],
				lineCount: $group['lineCount']
			);

			foreach ($group['pendingLines'] as $line) {
				$line['returnId'] = $returnId;
				$line['declarationId'] = $declarationId;
				$line['administrationId'] = $administrationId;
				$this->saveObject(schema: 'VATLine', data: $line);
			}
		}

		$totalVATCollected = $this->fromCents(cents: $totalVATCollectedCt);
		$totalVATPaid = $this->fromCents(cents: $totalVATPaidCt);
		$vatBalance = $this->fromCents(cents: ($totalVATPaidCt - $totalVATCollectedCt));
		$totalTaxable = $this->fromCents(cents: $totalTaxableCt);

		$this->updateReturnTotals(
			returnId: $returnId,
			totalVATCollected: $totalVATCollected,
			totalVATPaid: $totalVATPaid,
			vatBalance: $vatBalance,
			totalTaxableAmount: $totalTaxable
		);

		return [
			'lineCount' => $lineNumber,
			'totalVATCollected' => $totalVATCollected,
			'totalVATPaid' => $totalVATPaid,
			'vatBalance' => $vatBalance,
			'totalTaxableAmount' => $totalTaxable,
		];

	}//end deriveVATLines()

	/**
	 * Non-mutating recompute of the GL-derived per-rubriek grouping (REQ-VBTW-013).
	 *
	 * Mirrors the scan `deriveVATLines()` performs but never persists anything
	 * — no `VATLine`, `VATDeclaration`, or `VATReturn` write. Used by
	 * `VatSuppletieDetectionService::detect()` to compute "what the return
	 * would look like today" without disturbing the filed record, which is
	 * exactly the as-filed snapshot because nothing else re-derives it
	 * outside of an explicit `rebase`.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $startDate Period start (inclusive, ISO-8601).
	 * @param string $endDate Period end (inclusive, ISO-8601).
	 *
	 * @return array<int,array{type:string,taxRate:float,totalVATAmount:float,totalTaxableAmount:float,lineCount:int}>
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function computeCurrentDeclarations(string $administrationId, string $startDate, string $endDate): array {
		$scan = $this->scanRubrieken(administrationId: $administrationId, startDate: $startDate, endDate: $endDate);
		$result = [];
		foreach ($scan['declarationsByKey'] as $group) {
			$result[] = [
				'type' => $group['type'],
				'taxRate' => $group['taxRate'],
				'totalVATAmount' => $this->fromCents(cents: $group['totalVATAmountCents']),
				'totalTaxableAmount' => $this->fromCents(cents: $group['totalTaxableCents']),
				'lineCount' => $group['lineCount'],
			];
		}

		return $result;
	}//end computeCurrentDeclarations()

	/**
	 * Fetch the persisted (as-filed) `VATDeclaration` rows for a return, grouped
	 * the same shape `computeCurrentDeclarations()` returns, so callers can diff
	 * the two directly (REQ-VBTW-013).
	 *
	 * @param string $returnId The VATReturn id.
	 *
	 * @return array<int,array{type:string,taxRate:float,totalVATAmount:float,totalTaxableAmount:float,lineCount:int}>
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function fetchFiledDeclarations(string $returnId): array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('VATDeclaration')
			->findAll(['filters' => ['returnId' => $returnId]]);

		$result = [];
		foreach ($rows as $row) {
			$result[] = [
				'type' => (string)($row['type'] ?? ''),
				'taxRate' => (float)($row['taxRate'] ?? 0.0),
				'totalVATAmount' => (float)($row['totalVATAmount'] ?? 0.0),
				'totalTaxableAmount' => (float)($row['totalTaxableAmount'] ?? 0.0),
				'lineCount' => (int)($row['lineCount'] ?? 0),
			];
		}

		return $result;
	}//end fetchFiledDeclarations()

	/**
	 * Public accessor for a VATReturn record, used by
	 * VatSuppletieDetectionService to resolve the administration + period
	 * bounds it needs for detection without duplicating the OR lookup.
	 *
	 * @param string $returnId The VATReturn id.
	 *
	 * @return array<string,mixed> The VATReturn record.
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function getReturn(string $returnId): array {
		return $this->fetchReturn(returnId: $returnId);
	}//end getReturn()

	/**
	 * Scan GL transactions in the period and group VAT-applicable postings
	 * into rubriek buckets (type × taxRate). Shared scanning core for
	 * `deriveVATLines()` (persists) and `computeCurrentDeclarations()`
	 * (read-only) so the grouping logic exists exactly once.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $startDate Period start (inclusive, ISO-8601).
	 * @param string $endDate Period end (inclusive, ISO-8601).
	 *
	 * @return array{declarationsByKey:array<string,array<string,mixed>>,totalVATCollectedCt:int,totalVATPaidCt:int,totalTaxableCt:int,lineNumber:int}
	 */
	private function scanRubrieken(string $administrationId, string $startDate, string $endDate): array {
		$accounts = $this->fetchVATAccounts(administrationId: $administrationId);
		$transactions = $this->fetchGLTransactions(
			administrationId: $administrationId,
			startDate: $startDate,
			endDate: $endDate
		);

		$declarationsByKey = [];
		$totalVATCollectedCt = 0;
		$totalVATPaidCt = 0;
		$totalTaxableCt = 0;
		$lineNumber = 0;

		foreach ($transactions as $transaction) {
			$glTransactionId = (string)($transaction['id'] ?? ($transaction['@self']['id'] ?? ''));
			foreach ($this->postingsOf(transaction: $transaction) as $posting) {
				$accountNumber = (string)($posting['accountNumber'] ?? '');
				if ($accountNumber === '' || isset($accounts[$accountNumber]) === false) {
					continue;
				}

				$account = $accounts[$accountNumber];
				if (((bool)($account['vatApplicable'] ?? false)) === false) {
					continue;
				}

				$taxableAmount = $this->toCents(amount: ($posting['taxableAmount'] ?? $posting['amount'] ?? 0));
				if ($taxableAmount <= 0) {
					continue;
				}

				$type = $this->resolveLineType(account: $account, posting: $posting);
				$taxRate = (float)($posting['taxRate'] ?? ($account['vatRate'] ?? 0));
				if ($taxRate < 0 || $taxRate > 100) {
					continue;
				}

				$vatAmountCt = (int)round(($taxableAmount * $taxRate) / 100.0);

				$key = $type . ':' . number_format($taxRate, 2, '.', '');
				if (isset($declarationsByKey[$key]) === false) {
					$declarationsByKey[$key] = [
						'type' => $type,
						'taxRate' => $taxRate,
						'totalVATAmountCents' => 0,
						'totalTaxableCents' => 0,
						'lineCount' => 0,
						'declarationId' => null,
					];
				}

				$declarationsByKey[$key]['totalVATAmountCents'] += $vatAmountCt;
				$declarationsByKey[$key]['totalTaxableCents'] += $taxableAmount;
				$declarationsByKey[$key]['lineCount']++;

				$lineNumber++;
				$totalTaxableCt += $taxableAmount;
				if ($type === 'collected') {
					$totalVATCollectedCt += $vatAmountCt;
				} else {
					// Paid + reverse-charge both feed deductible VAT.
					$totalVATPaidCt += $vatAmountCt;
				}

				$declarationsByKey[$key]['pendingLines'][] = [
					'lineNumber' => $lineNumber,
					'glAccountNumber' => $accountNumber,
					'glAccountName' => ($account['name'] ?? null),
					'glTransactionId' => $glTransactionId,
					'type' => $type,
					'taxableAmount' => $this->fromCents(cents: $taxableAmount),
					'taxRate' => $taxRate,
					'vatAmount' => $this->fromCents(cents: $vatAmountCt),
					'description' => (string)($posting['description'] ?? ($transaction['description'] ?? '')),
					'reverseChargeApplicable' => ($type === 'reverse-charge'),
				];
			}//end foreach
		}//end foreach

		return [
			'declarationsByKey' => $declarationsByKey,
			'totalVATCollectedCt' => $totalVATCollectedCt,
			'totalVATPaidCt' => $totalVATPaidCt,
			'totalTaxableCt' => $totalTaxableCt,
			'lineNumber' => $lineNumber,
		];

	}//end scanRubrieken()

	/**
	 * Submit a VAT return (REQ-VAT-005) — draft → submitted with non-negative totals.
	 *
	 * @param string $returnId The VATReturn id.
	 * @param string $userId The actor's user id (for the audit-trail log line).
	 *
	 * @return array<string,mixed> The updated VATReturn record.
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function submitReturn(string $returnId, string $userId): array {
		$vatReturn = $this->fetchReturn(returnId: $returnId);
		$status = (string)($vatReturn['statusCode'] ?? '');
		if ($status !== 'draft') {
			throw new RuntimeException(
				sprintf('VATReturn %s is %s, only draft returns can be submitted.', $returnId, $status)
			);
		}

		if (((float)($vatReturn['totalVATCollected'] ?? 0)) < 0
			|| ((float)($vatReturn['totalVATPaid'] ?? 0)) < 0
		) {
			throw new RuntimeException('VATReturn totals must be non-negative before submission.');
		}

		$vatReturn['statusCode'] = 'submitted';
		$vatReturn['submissionDate'] = gmdate(format: 'Y-m-d\TH:i:s\Z');

		$persisted = $this->saveObject(schema: 'BtwAangifte', data: $vatReturn);
		$this->logger->info(
			'VATReturnService: submitted return',
			[
				'returnId' => $returnId,
				'userId' => $userId,
			]
		);

		return $persisted;
	}//end submitReturn()

	/**
	 * Rebase a submitted return back to draft and re-derive its VAT lines (REQ-VAT-008).
	 *
	 * @param string $returnId The VATReturn id.
	 * @param string $userId Actor for the audit-trail log line.
	 *
	 * @return array<string,mixed> The refreshed VATReturn record.
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function rebaseReturn(string $returnId, string $userId): array {
		$vatReturn = $this->fetchReturn(returnId: $returnId);
		$status = (string)($vatReturn['statusCode'] ?? '');
		if ($status !== 'submitted') {
			throw new RuntimeException(
				sprintf('VATReturn %s is %s, only submitted returns can be rebased.', $returnId, $status)
			);
		}

		$vatReturn['statusCode'] = 'draft';
		$vatReturn['submissionDate'] = null;
		$vatReturn['verificationDate'] = null;
		$vatReturn['filingReference'] = null;

		$this->saveObject(schema: 'BtwAangifte', data: $vatReturn);

		// Drop existing VATLines + VATDeclarations for the return then re-derive.
		$this->purgeChildren(returnId: $returnId);

		$this->deriveVATLines(
			returnId: $returnId,
			administrationId: (string)($vatReturn['administrationId'] ?? ''),
			startDate: (string)($vatReturn['startDate'] ?? ''),
			endDate: (string)($vatReturn['endDate'] ?? ''),
			regime: (string)($vatReturn['regime'] ?? 'standard')
		);

		$this->logger->info(
			'VATReturnService: rebased return',
			[
				'returnId' => $returnId,
				'userId' => $userId,
			]
		);

		return $this->fetchReturn(returnId: $returnId);
	}//end rebaseReturn()

	/**
	 * Resolve [startDate, endDate] for a period + year + number.
	 *
	 * @param string $period quarter | month | year.
	 * @param int $periodYear Fiscal year.
	 * @param int $periodNumber Period within year.
	 *
	 * @return array{0:string,1:string} [startDate, endDate] in ISO-8601.
	 */
	private function resolvePeriodBounds(string $period, int $periodYear, int $periodNumber): array {
		if ($period === 'year') {
			return [sprintf('%04d-01-01', $periodYear), sprintf('%04d-12-31', $periodYear)];
		}

		if ($period === 'month') {
			$monthNumber = max(1, min(12, $periodNumber));
			$startDate = sprintf('%04d-%02d-01', $periodYear, $monthNumber);
			$endTs = strtotime(datetime: sprintf('last day of %s', $startDate));
			if ($endTs === false) {
				throw new RuntimeException(sprintf('Cannot resolve month-end for %s', $startDate));
			}

			return [$startDate, date(format: 'Y-m-d', timestamp: $endTs)];
		}

		// Default = quarter.
		$quarter = max(1, min(4, $periodNumber));
		$startM = (1 + (($quarter - 1) * 3));
		$endM = ($startM + 2);
		$startDate = sprintf('%04d-%02d-01', $periodYear, $startM);
		$endTs = strtotime(datetime: sprintf('last day of %04d-%02d-01', $periodYear, $endM));
		if ($endTs === false) {
			throw new RuntimeException(sprintf('Cannot resolve quarter-end for %s', $startDate));
		}

		return [$startDate, date(format: 'Y-m-d', timestamp: $endTs)];
	}//end resolvePeriodBounds()

	/**
	 * Build a returnNumber identifier.
	 *
	 * @param int $periodYear Fiscal year.
	 * @param int $periodNumber Period within year.
	 * @param string $period quarter | month | year.
	 * @param string $regime standard | kor | reverse-charge.
	 *
	 * @return string A NL-style identifier (e.g. NL-2026-Q1, NL-2026-M03-KOR).
	 */
	private function buildReturnNumber(int $periodYear, int $periodNumber, string $period, string $regime): string {
		$tag = match ($period) {
			'month' => sprintf('M%02d', $periodNumber),
			'year' => 'Y',
			default => sprintf('Q%d', $periodNumber),
		};

		$suffix = match ($regime) {
			'kor' => '-KOR',
			'reverse-charge' => '-RC',
			default => '',
		};

		return sprintf('NL-%04d-%s%s', $periodYear, $tag, $suffix);
	}//end buildReturnNumber()

	/**
	 * Persist (or upsert) a VATDeclaration row and return its id.
	 *
	 * @param string $returnId Parent VATReturn id.
	 * @param string $administrationId Administration scope.
	 * @param string $type collected | paid | reverse-charge.
	 * @param float $taxRate VAT rate %.
	 * @param int $totalVATCents VAT amount in cents.
	 * @param int $totalTaxableCents Taxable amount in cents.
	 * @param int $lineCount Number of underlying VATLine rows.
	 *
	 * @return string The persisted VATDeclaration id.
	 */
	private function persistDeclaration(
		string $returnId,
		string $administrationId,
		string $type,
		float $taxRate,
		int $totalVATCents,
		int $totalTaxableCents,
		int $lineCount,
	): string {
		$declarationNumber = sprintf(
			'VAT-%s-%s-%s',
			substr(string: $returnId, offset: 0, length: 32),
			strtoupper(string: $type),
			str_replace(search: '.', replace: '', subject: number_format(num: $taxRate, decimals: 2, decimal_separator: '.', thousands_separator: ''))
		);

		$declaration = [
			'declarationNumber' => $declarationNumber,
			'returnId' => $returnId,
			'type' => $type,
			'taxRate' => $taxRate,
			'totalVATAmount' => $this->fromCents(cents: $totalVATCents),
			'totalTaxableAmount' => $this->fromCents(cents: $totalTaxableCents),
			'lineCount' => $lineCount,
			'administrationId' => $administrationId,
		];

		$persisted = $this->saveObject(schema: 'VATDeclaration', data: $declaration);

		return (string)($persisted['id'] ?? ($persisted['@self']['id'] ?? $declarationNumber));
	}//end persistDeclaration()

	/**
	 * Re-write the rolled-up totals on the parent VATReturn.
	 *
	 * @param string $returnId VATReturn id.
	 * @param float $totalVATCollected Sum of collected VAT.
	 * @param float $totalVATPaid Sum of paid + reverse-charge VAT.
	 * @param float $vatBalance totalVATPaid - totalVATCollected.
	 * @param float $totalTaxableAmount Sum of taxable amounts.
	 *
	 * @return void
	 */
	private function updateReturnTotals(
		string $returnId,
		float $totalVATCollected,
		float $totalVATPaid,
		float $vatBalance,
		float $totalTaxableAmount,
	): void {
		$vatReturn = $this->fetchReturn(returnId: $returnId);

		$vatReturn['totalVATCollected'] = $totalVATCollected;
		$vatReturn['totalVATPaid'] = $totalVATPaid;
		$vatReturn['vatBalance'] = $vatBalance;
		$vatReturn['totalTaxableAmount'] = $totalTaxableAmount;

		$this->saveObject(schema: 'BtwAangifte', data: $vatReturn);

	}//end updateReturnTotals()

	/**
	 * Drop existing VATDeclaration + VATLine children of a return.
	 *
	 * @param string $returnId VATReturn id.
	 *
	 * @return void
	 */
	private function purgeChildren(string $returnId): void {
		$register = $this->register();

		$lines = $this->objectService
			->setRegister($register)
			->setSchema('VATLine')
			->findAll(['filters' => ['returnId' => $returnId]]);
		foreach ($lines as $line) {
			$id = (string)($line['id'] ?? ($line['@self']['id'] ?? ''));
			if ($id !== '') {
				$this->objectService->setRegister($register)->setSchema('VATLine')->deleteObject($id);
			}
		}

		$declarations = $this->objectService
			->setRegister($register)
			->setSchema('VATDeclaration')
			->findAll(['filters' => ['returnId' => $returnId]]);
		foreach ($declarations as $declaration) {
			$id = (string)($declaration['id'] ?? ($declaration['@self']['id'] ?? ''));
			if ($id !== '') {
				$this->objectService->setRegister($register)->setSchema('VATDeclaration')->deleteObject($id);
			}
		}

	}//end purgeChildren()

	/**
	 * Look a VATReturn up by id, returning null when it genuinely does not exist.
	 *
	 * This is the ONLY correct way to resolve a BtwAangifte by id. OpenRegister's
	 * `ObjectService::find()` is declared `: ?ObjectEntity` — null when the row
	 * genuinely does not exist, an ObjectEntity when it does, and NEVER an array.
	 * Every caller therefore has to normalise the entity before reading fields
	 * off it; callers that tested `is_array()` on the return value reported "not
	 * found" for every row that WAS found (see VATReturnController::show()).
	 *
	 * Exposed so HTTP callers can distinguish "absent" (404) from "present"
	 * without catching an exception, while `fetchReturn()` keeps the
	 * throw-on-missing contract the internal pipeline relies on.
	 *
	 * @param string $returnId VATReturn id.
	 *
	 * @return array<string,mixed>|null The VATReturn record, or null when absent.
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function findReturn(string $returnId): ?array {
		$found = $this->objectService
			->setRegister($this->register())
			->setSchema('BtwAangifte')
			->find($returnId);

		if ($found === null) {
			return null;
		}

		return $this->normaliseRow(row: $found, context: 'find(' . $returnId . ')');
	}//end findReturn()

	/**
	 * Fetch a VATReturn by id.
	 *
	 * @param string $returnId VATReturn id.
	 *
	 * @return array<string,mixed> The VATReturn record.
	 */
	private function fetchReturn(string $returnId): array {
		$record = $this->findReturn(returnId: $returnId);
		if ($record === null) {
			throw new RuntimeException(sprintf('BtwAangifte %s not found', $returnId));
		}

		return $record;
	}//end fetchReturn()

	/**
	 * Fetch chart-of-accounts where vatApplicable = true, keyed by accountNumber.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string,array<string,mixed>> accountNumber => Account.
	 */
	private function fetchVATAccounts(string $administrationId): array {
		$accounts = $this->objectService
			->setRegister($this->register())
			->setSchema('Account')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$byNumber = [];
		foreach ($accounts as $account) {
			if (((bool)($account['vatApplicable'] ?? false)) === false) {
				continue;
			}

			$number = (string)($account['accountNumber'] ?? '');
			if ($number !== '') {
				$byNumber[$number] = $account;
			}
		}

		return $byNumber;
	}//end fetchVATAccounts()

	/**
	 * Fetch GLTransaction rows in the period for the administration.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $startDate Period start (ISO-8601).
	 * @param string $endDate Period end (ISO-8601).
	 *
	 * @return array<int,array<string,mixed>> List of GLTransaction objects.
	 */
	private function fetchGLTransactions(string $administrationId, string $startDate, string $endDate): array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('GLTransaction')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$in = [];
		foreach ($rows as $row) {
			$date = (string)($row['transactionDate'] ?? ($row['date'] ?? ''));
			if ($date === '') {
				continue;
			}

			if ($date >= $startDate && $date <= $endDate) {
				$in[] = $row;
			}
		}

		return $in;
	}//end fetchGLTransactions()

	/**
	 * Yield individual postings from a GLTransaction; supports either a `lines`
	 * sub-array or a flat accountNumber + amount.
	 *
	 * @param array<string,mixed> $transaction GLTransaction record.
	 *
	 * @return iterable<int,array<string,mixed>> Postings.
	 */
	private function postingsOf(array $transaction): iterable {
		if (isset($transaction['lines']) === true && is_array($transaction['lines']) === true) {
			foreach ($transaction['lines'] as $line) {
				if (is_array($line) === true) {
					yield $line;
				}
			}

			return;
		}

		// Flat shape — treat the transaction as a single posting.
		if (isset($transaction['accountNumber']) === true) {
			yield $transaction;
		}

	}//end postingsOf()

	/**
	 * Decide whether a posting is collected / paid / reverse-charge.
	 *
	 * @param array<string,mixed> $account The Account record.
	 * @param array<string,mixed> $posting The posting.
	 *
	 * @return string One of collected | paid | reverse-charge.
	 */
	private function resolveLineType(array $account, array $posting): string {
		if (((bool)($posting['reverseChargeApplicable'] ?? ($account['reverseChargeApplicable'] ?? false))) === true) {
			return 'reverse-charge';
		}

		$accountType = (string)($account['accountType'] ?? '');
		if ($accountType === 'revenue') {
			return 'collected';
		}

		return 'paid';
	}//end resolveLineType()

	/**
	 * Persist a record via the real OR ObjectService API.
	 *
	 * OpenRegister's `ObjectService::saveObject()` is declared `: ObjectEntity`
	 * — it NEVER returns an array. This method used to demand one and throw
	 * otherwise, so every VAT write threw unconditionally:
	 *
	 *   RuntimeException: ObjectService::saveObject(...) did not return an array
	 *
	 * which VATReturnController turned into HTTP 500 on POST /api/vat-returns.
	 * The bug was invisible for as long as the VATReturn/VatReturn slug
	 * collision existed, because OR rejected the payload on the WRONG schema's
	 * required list before the return value was ever inspected. Fixing the
	 * collision simply moved the 500 one line down.
	 *
	 * Normalisation follows the same house idiom as CogsPosterService,
	 * FifoValuationService, AccountantDashboardService and
	 * AdministrationContextService: jsonSerialize(), then getObject(), then
	 * give up loudly rather than silently returning an empty row.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $data Record body.
	 *
	 * @return array<string,mixed> The saved record (with id).
	 *
	 * @throws RuntimeException When the row cannot be normalised to an array.
	 */
	private function saveObject(string $schema, array $data): array {
		$saved = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->saveObject($data);

		return $this->normaliseRow(row: $saved, context: 'saveObject(' . $schema . ')');
	}//end saveObject()

	/**
	 * Normalise an OpenRegister row (ObjectEntity or array) to a plain array.
	 *
	 * @param mixed $row The value returned by ObjectService.
	 * @param string $context Caller description, used in the failure message.
	 *
	 * @return array<string,mixed> The row as a plain array.
	 *
	 * @throws RuntimeException When the value is neither an array nor a
	 *                          convertible object.
	 */
	private function normaliseRow(mixed $row, string $context): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$out = $row->jsonSerialize();
			if (is_array($out) === true) {
				return $out;
			}
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$out = $row->getObject();
			if (is_array($out) === true) {
				return $out;
			}
		}

		// UNREACHABLE under the ADR-084 contract, and deliberately kept: both
		// callers hold an ObjectServiceInterface, whose find()/saveObject() can
		// only return an ObjectEntityInterface, and that interface DECLARES
		// getObject(): array — so the branch above always returns. It becomes
		// reachable again if $objectService is ever widened to an untyped or
		// duck-typed source, or if ObjectEntityInterface stops declaring
		// getObject(): array. Cheap tripwire at a type boundary; not dead code
		// left behind by accident.
		throw new RuntimeException(
			sprintf('VATReturnService: unsupported row type from ObjectService::%s', $context)
		);

	}//end normaliseRow()

	/**
	 * Convert a money amount to integer cents.
	 *
	 * @param mixed $amount Money amount.
	 *
	 * @return int Whole cents.
	 */
	private function toCents(mixed $amount): int {
		return (int)round((float)($amount ?? 0) * 100);
	}//end toCents()

	/**
	 * Convert integer cents back to a 2-decimal float.
	 *
	 * @param int $cents Whole cents.
	 *
	 * @return float 2-decimal float.
	 */
	private function fromCents(int $cents): float {
		return round(($cents / 100), 2);
	}//end fromCents()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
