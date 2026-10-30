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
use OCA\Shillinq\Service\Vat\VatLineStamper;
use OCA\Shillinq\Service\Vat\VatReturnBox;
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
	 * Derive VATLine + VATDeclaration records from the booked ledger lines in the period (REQ-VBTW-004).
	 *
	 * Sums the posted GLLine records of the period per VAT return box and
	 * amount kind, as booked, and writes one VATDeclaration per box and one
	 * VATLine per contributing ledger line, both carrying `returnBox`. Totals
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
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.1
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
				returnBox: $group['returnBox'],
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
	 * @return array<int,array{type:string,taxRate:float,returnBox:string,totalVATAmount:float,totalTaxableAmount:float,lineCount:int}>
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.1
	 */
	public function computeCurrentDeclarations(string $administrationId, string $startDate, string $endDate): array {
		$scan = $this->scanRubrieken(administrationId: $administrationId, startDate: $startDate, endDate: $endDate);
		$result = [];
		foreach ($scan['declarationsByKey'] as $group) {
			$result[] = [
				'type' => $group['type'],
				'taxRate' => $group['taxRate'],
				'returnBox' => $group['returnBox'],
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
	 * @return array<int,array{type:string,taxRate:float,returnBox:string,totalVATAmount:float,totalTaxableAmount:float,lineCount:int}>
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.1
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
				'returnBox' => (string)($row['returnBox'] ?? ''),
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
	 * The key by which a filed and a current declaration are compared.
	 *
	 * A declaration prepared from stamped lines is one per box, so the box is
	 * its key. A declaration filed before boxes existed has none and keeps its
	 * old key of type and rate, so a correction check on such a return
	 * compares the way it did when it was filed.
	 *
	 * @param array<string,mixed> $bucket A declaration as computeCurrentDeclarations() or fetchFiledDeclarations() return it.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.1
	 */
	public function declarationKey(array $bucket): string {
		$box = (string)($bucket['returnBox'] ?? '');
		if ($box !== '') {
			return $box;
		}

		return ((string)($bucket['type'] ?? '')) . ':' . number_format((float)($bucket['taxRate'] ?? 0.0), 2, '.', '');
	}//end declarationKey()

	/**
	 * Sum the booked ledger lines of the period per VAT return box (REQ-VBTW-004).
	 *
	 * Reads the GLLine records of the administration that carry a box and an
	 * amount kind, keeps those of a booked transaction posted in the period,
	 * and adds each line's amount as booked to its box: the base lines to the
	 * box's base, the VAT lines to its VAT. No VAT is recalculated from a
	 * rate. A line booked on the opposite side of its box (a credit note)
	 * lowers the box. Shared core of `deriveVATLines()` (persists) and
	 * `computeCurrentDeclarations()` (read-only), so the derivation exists
	 * exactly once.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $startDate Period start (inclusive, ISO-8601).
	 * @param string $endDate Period end (inclusive, ISO-8601).
	 *
	 * @return array{declarationsByKey:array<string,array<string,mixed>>,totalVATCollectedCt:int,totalVATPaidCt:int,totalTaxableCt:int,lineNumber:int}
	 */
	private function scanRubrieken(string $administrationId, string $startDate, string $endDate): array {
		$transactions = $this->fetchBookedTransactions(
			administrationId: $administrationId,
			startDate: $startDate,
			endDate: $endDate
		);
		$tariffs = new VatLineStamper(objectService: $this->objectService, register: $this->register());
		$boxes = new VatReturnBox();

		$declarationsByKey = [];
		$totals = ['collected' => 0, 'paid' => 0, 'taxable' => 0];
		$lineNumber = 0;

		foreach ($this->fetchStampedLines(administrationId: $administrationId) as $line) {
			$transactionId = (string)($line['transactionId'] ?? '');
			if (isset($transactions[$transactionId]) === false) {
				continue;
			}

			$box = (string)$line['vatReturnBox'];
			$type = $boxes->type(box: $box);
			$cents = $boxes->bookedCents(line: $line, box: $box);
			$code = (string)($line['vatTariffCode'] ?? '');
			$taxRate = ($tariffs->ratePercentage(code: $code) ?? 0.0);

			$vatCt = 0;
			$baseCt = $cents;
			if ($line['vatAmountKind'] === 'vat') {
				$vatCt = $cents;
				$baseCt = 0;
			}

			if (isset($declarationsByKey[$box]) === false) {
				$declarationsByKey[$box] = [
					'type' => $type,
					'taxRate' => $taxRate,
					'returnBox' => $box,
					'rates' => [],
					'totalVATAmountCents' => 0,
					'totalTaxableCents' => 0,
					'lineCount' => 0,
					'pendingLines' => [],
				];
			}

			$declarationsByKey[$box]['rates'][number_format($taxRate, 2, '.', '')] = $taxRate;
			$declarationsByKey[$box]['totalVATAmountCents'] += $vatCt;
			$declarationsByKey[$box]['totalTaxableCents'] += $baseCt;
			$declarationsByKey[$box]['lineCount']++;

			$lineNumber++;
			$totals['taxable'] += $baseCt;
			$totals[$boxes->totalOf(box: $box)] += $vatCt;

			$declarationsByKey[$box]['pendingLines'][] = [
				'lineNumber' => $lineNumber,
				'returnBox' => $box,
				'glAccountNumber' => (string)($line['accountNumber'] ?? ''),
				'glTransactionId' => $transactionId,
				'type' => $type,
				'taxableAmount' => $this->fromCents(cents: $baseCt),
				'taxRate' => $taxRate,
				'vatAmount' => $this->fromCents(cents: $vatCt),
				'description' => (string)($line['description'] ?? ($transactions[$transactionId]['description'] ?? '')),
				'reverseChargeApplicable' => $tariffs->isReverseCharge(code: $code),
			];
		}//end foreach

		ksort($declarationsByKey);
		foreach ($declarationsByKey as $box => $group) {
			// A box of one tariff shows that tariff's rate; a box that mixes tariffs (5b) shows none.
			$declarationsByKey[$box]['taxRate'] = 0.0;
			if (count($group['rates']) === 1) {
				$declarationsByKey[$box]['taxRate'] = (float)current($group['rates']);
			}

			unset($declarationsByKey[$box]['rates']);
		}

		return [
			'declarationsByKey' => $declarationsByKey,
			'totalVATCollectedCt' => $totals['collected'],
			'totalVATPaidCt' => $totals['paid'],
			'totalTaxableCt' => $totals['taxable'],
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
	 * @param string $returnBox The VAT return box the declaration totals.
	 * @param string $type collected | paid | reverse-charge.
	 * @param float $taxRate VAT rate % (the box's tariff rate, 0 when the box mixes tariffs).
	 * @param int $totalVATCents VAT amount in cents.
	 * @param int $totalTaxableCents Taxable amount in cents.
	 * @param int $lineCount Number of underlying VATLine rows.
	 *
	 * @return string The persisted VATDeclaration id.
	 */
	private function persistDeclaration(
		string $returnId,
		string $administrationId,
		string $returnBox,
		string $type,
		float $taxRate,
		int $totalVATCents,
		int $totalTaxableCents,
		int $lineCount,
	): string {
		$declarationNumber = sprintf(
			'VAT-%s-%s',
			substr(string: $returnId, offset: 0, length: 32),
			strtoupper(string: $returnBox)
		);

		$declaration = [
			'declarationNumber' => $declarationNumber,
			'returnId' => $returnId,
			'returnBox' => $returnBox,
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
	 * The booked GLTransaction rows of the administration posted in the
	 * period, keyed by id. A reversed transaction stays booked: its reversal
	 * is a posted transaction of its own that cancels it. A draft is not
	 * booked.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $startDate Period start (ISO-8601).
	 * @param string $endDate Period end (ISO-8601).
	 *
	 * @return array<string,array<string,mixed>> GLTransaction by id.
	 */
	private function fetchBookedTransactions(string $administrationId, string $startDate, string $endDate): array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('GLTransaction')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$booked = [];
		foreach ($rows as $row) {
			$row = $this->normaliseRow(row: $row, context: 'findAll(GLTransaction)');
			$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
			$date = substr((string)($row['postingDate'] ?? ''), 0, 10);
			$state = (string)($row['state'] ?? '');
			if ($id === '' || $date < $startDate || $date > $endDate || in_array($state, ['posted', 'reversed'], true) === false) {
				continue;
			}

			$booked[$id] = $row;
		}

		return $booked;
	}//end fetchBookedTransactions()

	/**
	 * The GLLine rows of the administration that carry a VAT return box and
	 * an amount kind (stamped at posting, or by the backfill).
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int,array<string,mixed>> GLLine rows.
	 */
	private function fetchStampedLines(string $administrationId): array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('GLLine')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$stamped = [];
		foreach ($rows as $row) {
			$row = $this->normaliseRow(row: $row, context: 'findAll(GLLine)');
			if ((string)($row['vatReturnBox'] ?? '') === ''
				|| in_array(($row['vatAmountKind'] ?? null), ['base', 'vat'], true) === false
			) {
				continue;
			}

			$stamped[] = $row;
		}

		return $stamped;
	}//end fetchStampedLines()

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
