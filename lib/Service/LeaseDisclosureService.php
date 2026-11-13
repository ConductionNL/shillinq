<?php

/**
 * Lease Disclosure Service
 *
 * Aggregates the IFRS 16.51–60 quantitative disclosure table for a fiscal period
 * (REQ-LD-001..REQ-LD-005). Reads the period's active LeaseContract records (and
 * their amortization schedules) via the real OpenRegister ObjectService API
 * (findAll) and computes: closing RoU asset by asset class, current vs non-current
 * lease liability, the undiscounted maturity analysis (REQ-LD-002), the
 * liability-weighted average IBR per asset class (REQ-LD-003), and the expense
 * breakdown including straight-line short-term / low-value exemption expense
 * (REQ-LE-003). The result is the materialised LeaseDisclosureTable payload
 * (design.md D5); no parallel GL table is written.
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
 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Computes the period-end IFRS 16 disclosure table for one administration.
 *
 * Reads are scoped to a single administration (ADR-005 IDOR safety): the
 * administrationId is server-resolved from the authenticated user's context. The
 * aggregation is deterministic and side-effect-free given the lease set, so the
 * arithmetic core (aggregateFromLeases) is unit-testable without OpenRegister.
 *
 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class LeaseDisclosureService {
	/**
	 * Asset classes disclosed, in stable order (REQ-LD-001).
	 *
	 * @var array<int,string>
	 */
	private const ASSET_CLASSES = ['vehicle', 'real-estate', 'IT-hardware', 'machinery', 'other'];

	/**
	 * The totals the CSV export lists, in order.
	 *
	 * @var array<int,string>
	 */
	private const CSV_TOTALS = [
		'totalRouAdditionsInPeriod',
		'totalRouDepreciationInPeriod',
		'totalRouDisposalsInPeriod',
		'totalLeaseLiabilityCurrent',
		'totalLeaseLiabilityNoncurrent',
		'totalInterestExpense',
		'totalShortTermLeaseExpense',
		'totalLowValueLeaseExpense',
		'totalVariableLeaseExpense',
	];

	/**
	 * The totals the HTML note lists, in order.
	 *
	 * @var array<int,string>
	 */
	private const HTML_TOTALS = [
		'totalLeaseLiabilityCurrent',
		'totalLeaseLiabilityNoncurrent',
		'totalInterestExpense',
		'totalShortTermLeaseExpense',
		'totalLowValueLeaseExpense',
		'totalVariableLeaseExpense',
	];

	/**
	 * IFRS 16 facts of the XBRL skeleton (rough mapping to the IFRS
	 * taxonomy) => the disclosure total they read.
	 *
	 * @var array<string,string>
	 */
	private const XBRL_FACTS = [
		'ifrs-full:RightofuseAssets' => 'totalRouAsset',
		'ifrs-full:LeaseLiabilitiesCurrent' => 'totalLeaseLiabilityCurrent',
		'ifrs-full:LeaseLiabilitiesNoncurrent' => 'totalLeaseLiabilityNoncurrent',
		'ifrs-full:InterestExpenseOnLeaseLiabilities' => 'totalInterestExpense',
		'ifrs-full:ExpenseRelatingToShorttermLeases' => 'totalShortTermLeaseExpense',
		'ifrs-full:ExpenseRelatingToLeasesOfLowvalueAssets' => 'totalLowValueLeaseExpense',
		'ifrs-full:ExpenseRelatingToVariableLeasePayments' => 'totalVariableLeaseExpense',
	];

	/**
	 * The disclosure note labels per language.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const NOTE_LABELS = [
		'nl' => [
			'title' => 'IFRS 16-toelichting',
			'rouByClass' => 'Boekwaarde gebruiksrechtactivum per activaklasse',
			'maturity' => 'Looptijdanalyse (niet-verdisconteerd)',
			'weightedIbr' => 'Gewogen gemiddelde marginale rentevoet per activaklasse',
			'totals' => 'Overige toelichtingen',
			'narrative' => 'Toelichting op de leaseactiviteiten',
			'footerNote' => 'Opgesteld op basis van IFRS 16.51–60. Een externe PDF-render levert het ondertekende exemplaar.',
		],
		'en' => [
			'title' => 'IFRS 16 Disclosure Note',
			'rouByClass' => 'Closing right-of-use asset by class',
			'maturity' => 'Undiscounted maturity analysis',
			'weightedIbr' => 'Weighted-average incremental borrowing rate by class',
			'totals' => 'Other disclosures',
			'narrative' => 'Qualitative narrative',
			'footerNote' => 'Compiled per IFRS 16.51–60. A signed PDF copy is produced by the docudesk render pipeline.',
		],
	];

	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LeaseAmortizationCalculator $calculator Pure-logic IFRS 16 arithmetic helper.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LeaseAmortizationCalculator $calculator,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Generate the disclosure table for a fiscal period (REQ-LD-001).
	 *
	 * @param string $administrationId Administration scope (server-resolved, ADR-005).
	 * @param string $fiscalPeriod Fiscal period label (e.g. "2026").
	 *
	 * @return array<string,mixed> The LeaseDisclosureTable payload.
	 *
	 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
	 */
	public function generateForPeriod(string $administrationId, string $fiscalPeriod): array {
		try {
			$leases = $this->objectService()
				->setRegister($this->register())
				->setSchema('LeaseContract')
				->findAll(['filters' => ['administrationId' => $administrationId]]);
		} catch (\Throwable $e) {
			// Fail soft: an OpenRegister read failure yields an empty disclosure
			// rather than a stack trace to the client (ADR-005). The administration
			// id is logged for diagnostics but never special-category data.
			$this->logger->warning(
				'LeaseDisclosureService: failed to read leases for disclosure',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			$leases = [];
		}

		$leases = array_values(
			array_filter(
				$leases,
				static function ($lease): bool {
					return is_array($lease) === true
						&& in_array(($lease['status'] ?? ''), ['active', 'modified'], true) === true;
				}
			)
		);

		$table = $this->aggregateFromLeases(leases: $leases);
		$table['fiscalPeriod'] = $fiscalPeriod;
		$table['administrationId'] = $administrationId;
		$table['materializedAtPeriodClose'] = true;
		$table['qualitativeNarrative'] = $this->narrativeSeed(leaseCount: count($leases));

		return $table;
	}//end generateForPeriod()

	/**
	 * Aggregate the quantitative disclosure figures from a set of leases (REQ-LD-001..005).
	 *
	 * Side-effect-free: given the lease set it computes RoU-by-class, the
	 * current/non-current liability split, the undiscounted maturity analysis,
	 * the liability-weighted IBR per class, and the expense breakdown. This is the
	 * unit-testable core (no OpenRegister dependency).
	 *
	 * @param array<int,array<string,mixed>> $leases Active / modified LeaseContract arrays.
	 *
	 * @return array<string,mixed> Quantitative disclosure figures.
	 *
	 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
	 */
	public function aggregateFromLeases(array $leases): array {
		$acc = [
			'rouByClass' => array_fill_keys(self::ASSET_CLASSES, 0),
			'liabilityCur' => 0,
			'liabilityNon' => 0,
			'maturity' => ['lt1y' => 0, 'y1to2' => 0, 'y2to3' => 0, 'y3to4' => 0, 'y4to5' => 0, 'gt5y' => 0],
			'ibrWeighted' => array_fill_keys(self::ASSET_CLASSES, 0),
			'ibrWeightBase' => array_fill_keys(self::ASSET_CLASSES, 0),
			'interestCents' => 0,
			'depCents' => 0,
			'shortTermCents' => 0,
			'lowValueCents' => 0,
		];

		foreach ($leases as $lease) {
			$acc = $this->accumulateLease(acc: $acc, lease: $lease);
		}

		return [
			'totalRouAssetByClass' => $this->centsMapToFloat(map: $acc['rouByClass']),
			'closingRouByClass' => $this->centsMapToFloat(map: $acc['rouByClass']),
			'totalRouAdditionsInPeriod' => 0.0,
			'totalRouDepreciationInPeriod' => $this->calculator->fromCents(cents: $acc['depCents']),
			'totalRouDisposalsInPeriod' => 0.0,
			'totalLeaseLiabilityCurrent' => $this->calculator->fromCents(cents: $acc['liabilityCur']),
			'totalLeaseLiabilityNoncurrent' => $this->calculator->fromCents(cents: $acc['liabilityNon']),
			'maturityAnalysis' => $this->centsMapToFloat(map: $acc['maturity']),
			'weightedAverageIbrByClass' => $this->weightedAverages(weighted: $acc['ibrWeighted'], base: $acc['ibrWeightBase']),
			'totalInterestExpense' => $this->calculator->fromCents(cents: $acc['interestCents']),
			'totalShortTermLeaseExpense' => $this->calculator->fromCents(cents: $acc['shortTermCents']),
			'totalLowValueLeaseExpense' => $this->calculator->fromCents(cents: $acc['lowValueCents']),
			'totalVariableLeaseExpense' => 0.0,
		];

	}//end aggregateFromLeases()

	/**
	 * Fold one lease's contribution into the running aggregate (REQ-LD-001).
	 *
	 * Exempt leases add only straight-line expense; capitalised leases add RoU,
	 * liability split, maturity buckets, interest, depreciation and weighted IBR.
	 *
	 * @param array<string,mixed> $acc The running aggregate.
	 * @param array<string,mixed> $lease One LeaseContract array.
	 *
	 * @return array<string,mixed> The updated aggregate.
	 *
	 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
	 */
	private function accumulateLease(array $acc, array $lease): array {
		$class = $this->assetClassOf(lease: $lease);
		$classification = (string)($lease['classification'] ?? '');

		// Exempt leases carry straight-line expense, no RoU / liability (REQ-LE-003).
		if (in_array($classification, ['short-term-exempt', 'low-value-exempt'], true) === true) {
			return $this->accumulateExempt(acc: $acc, lease: $lease);
		}

		$rows = [];
		if ($classification === 'IFRS16-capitalised') {
			$rows = $this->calculator->buildSchedule(lease: $lease);
		}

		if ($rows === []) {
			return $acc;
		}

		return $this->accumulateCapitalised(acc: $acc, lease: $lease, class: $class, rows: $rows);
	}//end accumulateLease()

	/**
	 * The lease's asset class, `other` when it names none of ASSET_CLASSES.
	 *
	 * @param array<string,mixed> $lease One LeaseContract array.
	 *
	 * @return string
	 */
	private function assetClassOf(array $lease): string {
		$class = (string)($lease['assetClass'] ?? 'other');
		if (in_array($class, self::ASSET_CLASSES, true) === false) {
			return 'other';
		}

		return $class;
	}//end assetClassOf()

	/**
	 * Add an exempt lease's straight-line expense to the short-term or
	 * low-value total.
	 *
	 * @param array<string,mixed> $acc   The running aggregate.
	 * @param array<string,mixed> $lease One exempt LeaseContract array.
	 *
	 * @return array<string,mixed> The updated aggregate.
	 */
	private function accumulateExempt(array $acc, array $lease): array {
		$periods = $this->calculator->scheduleLength(lease: $lease);
		$expense = $this->calculator->toCents(amount: ($lease['basePaymentAmount'] ?? 0)) * $periods;
		$total = 'lowValueCents';
		if ((string)($lease['classification'] ?? '') === 'short-term-exempt') {
			$total = 'shortTermCents';
		}

		$acc[$total] += $expense;

		return $acc;
	}//end accumulateExempt()

	/**
	 * Fold a capitalised lease's amortization schedule into the aggregate.
	 *
	 * @param array<string,mixed> $acc The running aggregate.
	 * @param array<string,mixed> $lease One LeaseContract array.
	 * @param string $class The resolved asset class.
	 * @param array<int,array<string,float|int>> $rows The amortization schedule.
	 *
	 * @return array<string,mixed> The updated aggregate.
	 *
	 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
	 */
	private function accumulateCapitalised(array $acc, array $lease, string $class, array $rows): array {
		$opening = $rows[0];
		// Closing RoU = closing of the final row (period-end snapshot).
		$closingRou = (float)$rows[(count($rows) - 1)]['closingRouAsset'];
		$acc['rouByClass'][$class] += $this->calculator->toCents(amount: $closingRou);

		// Liability split: principal due in the next periodsPerYear periods is
		// current, the remainder non-current (REQ-LD-001 51(d)).
		$perYear = $this->calculator->periodsPerYear((string)($lease['paymentFrequency'] ?? 'monthly'));
		foreach ($rows as $index => $row) {
			$principal = $this->calculator->toCents(amount: $row['paymentPrincipalPortion']);
			$acc['liabilityNon'] += $principal;
			if ($index < $perYear) {
				$acc['liabilityCur'] += $principal;
				$acc['liabilityNon'] -= $principal;
			}

			// Undiscounted maturity buckets on the contractual payment (REQ-LD-002).
			$payment = $this->calculator->toCents(amount: $row['paymentAppliedTotal']);
			$yearIndex = intdiv($index, max(1, $perYear));
			$acc['maturity'][$this->maturityBucket(yearIndex: $yearIndex)] += $payment;
		}//end foreach

		$acc['interestCents'] += $this->sumColumnCents(rows: $rows, column: 'interestAccrued');
		$acc['depCents'] += $this->sumColumnCents(rows: $rows, column: 'depreciationCharge');

		// Liability-weighted IBR per class (REQ-LD-003).
		$openingLiabCents = $this->calculator->toCents(amount: $opening['openingLeaseLiability']);
		$acc['ibrWeighted'][$class] += (int)round($openingLiabCents * (float)($lease['ibrPercent'] ?? 0));
		$acc['ibrWeightBase'][$class] += $openingLiabCents;

		return $acc;
	}//end accumulateCapitalised()

	/**
	 * Sum a numeric column across schedule rows, in cents.
	 *
	 * @param array<int,array<string,float|int>> $rows Schedule rows.
	 * @param string $column Column key.
	 *
	 * @return int Sum in cents.
	 *
	 * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called via named args from accumulateCapitalised().
	 */
	private function sumColumnCents(array $rows, string $column): int {
		$total = 0;
		foreach ($rows as $row) {
			$total += $this->calculator->toCents(amount: ($row[$column] ?? 0));
		}

		return $total;
	}//end sumColumnCents()

	/**
	 * Map a zero-based year index onto a maturity bucket key (REQ-LD-002).
	 *
	 * @param int $yearIndex Years from period start (0 = within 12 months).
	 *
	 * @return string Bucket key.
	 *
	 * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called via named args from accumulateCapitalised().
	 */
	private function maturityBucket(int $yearIndex): string {
		return match (true) {
			$yearIndex <= 0 => 'lt1y',
			$yearIndex === 1 => 'y1to2',
			$yearIndex === 2 => 'y2to3',
			$yearIndex === 3 => 'y3to4',
			$yearIndex === 4 => 'y4to5',
			default => 'gt5y',
		};

	}//end maturityBucket()

	/**
	 * Convert a cents-keyed map to two-decimal floats.
	 *
	 * @param array<string,int> $map Cents map.
	 *
	 * @return array<string,float> Float map.
	 */
	private function centsMapToFloat(array $map): array {
		$out = [];
		foreach ($map as $key => $cents) {
			$out[$key] = $this->calculator->fromCents(cents: (int)$cents);
		}

		return $out;
	}//end centsMapToFloat()

	/**
	 * Compute the liability-weighted IBR per class (REQ-LD-003).
	 *
	 * @param array<string,int> $weighted Sum of (opening-liability × ibr) per class, in cents·percent.
	 * @param array<string,int> $base Sum of opening-liability per class, in cents.
	 *
	 * @return array<string,float> Weighted-average IBR per class (percent, two decimals).
	 */
	private function weightedAverages(array $weighted, array $base): array {
		$out = [];
		foreach ($weighted as $class => $sum) {
			$denominator = (int)($base[$class] ?? 0);
			if ($denominator === 0) {
				$out[$class] = 0.0;
				continue;
			}

			$out[$class] = round($sum / $denominator, 2);
		}

		return $out;
	}//end weightedAverages()

	/**
	 * Flatten the disclosure payload into CSV rows (REQ-LD-001, task 10.3).
	 *
	 * Produces a header + one row per disclosure figure suitable for direct
	 * download. The structured payload (generateForPeriod / aggregateFromLeases)
	 * is the canonical input: this method just walks the rouByClass / maturity
	 * maps and the scalar totals into a flat 2-column table (label,value) plus
	 * a section-tag column so the consumer can group on import.
	 *
	 * No PDF / docudesk dependency — pure RFC 4180 CSV string. Tests cover the
	 * flattening shape; the streaming response wrapper lands with the
	 * controller surface.
	 *
	 * @param array<string,mixed> $disclosure The structured disclosure payload.
	 *
	 * @return string CSV body (RFC 4180, comma delimited, LF terminated).
	 *
	 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
	 */
	public function exportToCSV(array $disclosure): string {
		return $this->joinCsv(
			rows: array_merge(
				[['section', 'label', 'value']],
				$this->csvHeaderRows(disclosure: $disclosure),
				$this->keyedRows(section: 'rou-by-class', values: (array)($disclosure['closingRouByClass'] ?? [])),
				$this->keyedRows(section: 'maturity-analysis', values: (array)($disclosure['maturityAnalysis'] ?? [])),
				$this->keyedRows(section: 'weighted-average-ibr', values: (array)($disclosure['weightedAverageIbrByClass'] ?? [])),
				$this->keyedRows(section: 'totals', values: $this->presentTotals(disclosure: $disclosure, keys: self::CSV_TOTALS)),
				$this->csvNarrativeRows(disclosure: $disclosure)
			)
		);
	}//end exportToCSV()

	/**
	 * The CSV header rows: fiscal period, administration and materialisation.
	 *
	 * @param array<string,mixed> $disclosure The disclosure payload.
	 *
	 * @return array<int,array<int,string>>
	 */
	private function csvHeaderRows(array $disclosure): array {
		$materialized = 'false';
		if (empty($disclosure['materializedAtPeriodClose']) === false) {
			$materialized = 'true';
		}

		return [
			['header', 'fiscalPeriod', (string)($disclosure['fiscalPeriod'] ?? '')],
			['header', 'administrationId', (string)($disclosure['administrationId'] ?? '')],
			['header', 'materializedAtPeriodClose', $materialized],
		];
	}//end csvHeaderRows()

	/**
	 * One CSV row per key → value of a section, values as two-decimal numbers.
	 *
	 * @param string              $section Section name.
	 * @param array<string,mixed> $values  Key → value.
	 *
	 * @return array<int,array<int,string>>
	 */
	private function keyedRows(string $section, array $values): array {
		$rows = [];
		foreach ($values as $key => $value) {
			$rows[] = [$section, (string)$key, $this->csvNumber(value: $value)];
		}

		return $rows;
	}//end keyedRows()

	/**
	 * The listed totals the disclosure carries (null values included), in list order.
	 *
	 * @param array<string,mixed> $disclosure The disclosure payload.
	 * @param array<int,string>   $keys       Total keys, in output order.
	 *
	 * @return array<string,mixed>
	 */
	private function presentTotals(array $disclosure, array $keys): array {
		$totals = [];
		foreach ($keys as $key) {
			if (array_key_exists($key, $disclosure) === true) {
				$totals[$key] = $disclosure[$key];
			}
		}

		return $totals;
	}//end presentTotals()

	/**
	 * The narrative row, when the disclosure carries one.
	 *
	 * @param array<string,mixed> $disclosure The disclosure payload.
	 *
	 * @return array<int,array<int,string>>
	 */
	private function csvNarrativeRows(array $disclosure): array {
		if (isset($disclosure['qualitativeNarrative']) === false) {
			return [];
		}

		return [['narrative', 'qualitativeNarrative', (string)$disclosure['qualitativeNarrative']]];
	}//end csvNarrativeRows()

	/**
	 * Render the disclosure note as a self-contained HTML document for the
	 * Phase-2 docudesk PDF pipeline (Task 10.2 — skeleton).
	 *
	 * Produces deterministic, screen-reader-friendly HTML mirroring the
	 * IFRS 16.51–60 section layout (quantitative summary, RoU-by-class,
	 * undiscounted maturity analysis, weighted-average IBR, expense
	 * breakdown, qualitative narrative). The HTML is the input contract
	 * for the docudesk-driven PDF renderer when that pipeline lands; the
	 * caller can also stream the HTML directly as a print-friendly export.
	 *
	 * Pure-logic helper: NO PDF binary is produced here. The Phase-2
	 * docudesk pipeline (RfC bookkeeping-pdf-pipeline) consumes this
	 * HTML + a docudesk template id to render the final PDF.
	 *
	 * @param array<string,mixed> $disclosure The disclosure payload from generateForPeriod.
	 * @param string $language ISO 639-1 language code ('en' or 'nl'); defaults to 'en'.
	 *
	 * @return string The rendered HTML document.
	 *
	 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
	 */
	public function exportDisclosureNoteToHtml(array $disclosure, string $language = 'en'): string {
		$lang = $this->noteLanguage(language: $language);
		$labels = $this->disclosureLabels(language: $lang);
		$title = $this->escape(text: $labels['title']) . ' ' . $this->escape(text: (string)($disclosure['fiscalPeriod'] ?? ''));

		return '<!DOCTYPE html><html lang="' . $lang . '"><head><meta charset="UTF-8"><title>' . $title . '</title></head><body>'
			. '<h1>' . $title . '</h1>'
			. $this->htmlSections(disclosure: $disclosure, labels: $labels)
			. $this->htmlNarrative(disclosure: $disclosure, labels: $labels)
			. '<footer><p><em>' . $this->escape(text: $labels['footerNote']) . '</em></p></footer></body></html>';
	}//end exportDisclosureNoteToHtml()

	/**
	 * The note language: Dutch when asked for, English otherwise.
	 *
	 * @param string $language Requested ISO 639-1 code.
	 *
	 * @return string 'nl' or 'en'.
	 */
	private function noteLanguage(string $language): string {
		if ($language === 'nl') {
			return 'nl';
		}

		return 'en';
	}//end noteLanguage()

	/**
	 * The RoU, maturity, IBR and totals sections of the note.
	 *
	 * @param array<string,mixed>  $disclosure The disclosure payload.
	 * @param array<string,string> $labels     Localised labels.
	 *
	 * @return string
	 */
	private function htmlSections(array $disclosure, array $labels): string {
		return $this->htmlSection(heading: $labels['rouByClass'], rows: (array)($disclosure['closingRouByClass'] ?? []))
			. $this->htmlSection(heading: $labels['maturity'], rows: (array)($disclosure['maturityAnalysis'] ?? []))
			. $this->htmlSection(heading: $labels['weightedIbr'], rows: (array)($disclosure['weightedAverageIbrByClass'] ?? []))
			. $this->htmlSection(heading: $labels['totals'], rows: $this->presentTotals(disclosure: $disclosure, keys: self::HTML_TOTALS));
	}//end htmlSections()

	/**
	 * The narrative heading and paragraph, when the narrative is not empty.
	 *
	 * @param array<string,mixed>  $disclosure The disclosure payload.
	 * @param array<string,string> $labels     Localised labels.
	 *
	 * @return string
	 */
	private function htmlNarrative(array $disclosure, array $labels): string {
		$narrative = (string)($disclosure['qualitativeNarrative'] ?? '');
		if ($narrative === '') {
			return '';
		}

		return '<h2>' . $this->escape(text: $labels['narrative']) . '</h2><p>' . nl2br(string: $this->escape(text: $narrative)) . '</p>';
	}//end htmlNarrative()

	/**
	 * HTML-escape text for the note.
	 *
	 * @param string $text Raw text.
	 *
	 * @return string
	 */
	private function escape(string $text): string {
		return htmlspecialchars(string: $text, flags: (ENT_QUOTES | ENT_HTML5), encoding: 'UTF-8');
	}//end escape()

	/**
	 * Render the disclosure note for the Phase-2 PDF pipeline (Task 10.2).
	 *
	 * Wraps exportDisclosureNoteToHtml in the docudesk-render envelope:
	 *   - kind: 'lease-disclosure-note'
	 *   - status: 'pending-pdf-pipeline'  (rendered when docudesk lands)
	 *   - html : self-contained HTML body
	 *
	 * The envelope shape is what the docudesk integration will consume
	 * to produce the final signed PDF (REQ-LD-004(1)). Until the
	 * pipeline lands the caller can still surface the HTML to the
	 * operator as a print-friendly preview.
	 *
	 * @param array<string,mixed> $disclosure The disclosure payload.
	 * @param string $language 'en' or 'nl'.
	 *
	 * @return array<string,mixed> The render envelope.
	 *
	 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
	 */
	public function exportDisclosureNoteToPDF(array $disclosure, string $language = 'en'): array {
		return [
			'kind' => 'lease-disclosure-note',
			'fiscalPeriod' => ($disclosure['fiscalPeriod'] ?? ''),
			'administrationId' => ($disclosure['administrationId'] ?? ''),
			'language' => $this->noteLanguage(language: $language),
			'status' => 'pending-pdf-pipeline',
			'html' => $this->exportDisclosureNoteToHtml(disclosure: $disclosure, language: $language),
		];

	}//end exportDisclosureNoteToPDF()

	/**
	 * Skeleton XBRL/ESEF export for the bookkeeping-sbr-xbrl-reporting
	 * integration (Task 10.4).
	 *
	 * Emits an iXBRL fragment with the IFRS 16 disclosure facts the
	 * SBR XBRL pipeline will tag against the EFRAG taxonomy. The full
	 * ESEF wrapper + taxonomy linking lands with the
	 * `bookkeeping-sbr-xbrl-reporting` change; this skeleton stamps the
	 * facts in a stable contextRef so the consumer can map them.
	 *
	 * Pure-logic helper: NO XBRL validation is performed; the
	 * sbr-xbrl-reporting service will validate against the taxonomy.
	 *
	 * @param array<string,mixed> $disclosure The disclosure payload.
	 *
	 * @return array<string,mixed> { status, contextRef, facts:array<string,string|float|int> }
	 *
	 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
	 */
	public function exportToXBRL(array $disclosure): array {
		$period = (string)($disclosure['fiscalPeriod'] ?? '');
		$periodSegment = 'period';
		if ($period !== '') {
			$periodSegment = str_replace(' ', '_', $period);
		}

		return [
			'kind' => 'lease-disclosure-xbrl',
			'status' => 'pending-sbr-xbrl-reporting',
			'contextRef' => ('ctx-' . $periodSegment),
			'facts' => array_map(static fn (string $key): float => (float)($disclosure[$key] ?? 0), self::XBRL_FACTS),
			'taxonomy' => 'ifrs-full-2024',
			'note' => 'Skeleton: full ESEF iXBRL wrapper + taxonomy linkbase land with the bookkeeping-sbr-xbrl-reporting change.',
		];

	}//end exportToXBRL()

	/**
	 * Resolve the per-language label set for the disclosure note.
	 *
	 * @param string $language 'en' or 'nl'.
	 *
	 * @return array<string,string>
	 */
	private function disclosureLabels(string $language): array {
		return (self::NOTE_LABELS[$language] ?? self::NOTE_LABELS['en']);

	}//end disclosureLabels()

	/**
	 * Render one labelled key→value section for the HTML disclosure note.
	 *
	 * @param string $heading Section heading (already-localised).
	 * @param array<string,mixed> $rows Key→value
	 *                                  rows.
	 *
	 * @return string The HTML fragment.
	 */
	private function htmlSection(string $heading, array $rows): string {
		if ($rows === []) {
			return '';
		}

		$html = '<h2>' . $this->escape(text: $heading) . '</h2><table><tbody>';
		foreach ($rows as $label => $value) {
			$cell = $this->escape(text: $this->csvNumber(value: $value));
			$html .= '<tr><th scope="row">' . $this->escape(text: (string)$label) . '</th><td>' . $cell . '</td></tr>';
		}

		return $html . '</tbody></table>';
	}//end htmlSection()

	/**
	 * Render a numeric value as a two-decimal CSV string.
	 *
	 * @param mixed $value Numeric value (float|int|string).
	 *
	 * @return string Two-decimal string.
	 */
	private function csvNumber(mixed $value): string {
		return number_format((float)($value ?? 0), 2, '.', '');
	}//end csvNumber()

	/**
	 * Join CSV rows with RFC 4180 quoting.
	 *
	 * @param array<int,array<int,string>> $rows CSV rows.
	 *
	 * @return string CSV body.
	 */
	private function joinCsv(array $rows): string {
		$lines = [];
		foreach ($rows as $row) {
			$lines[] = implode(',', array_map(fn (mixed $cell): string => $this->csvCell(cell: (string)$cell), $row));
		}

		return implode("\n", $lines) . "\n";
	}//end joinCsv()

	/**
	 * RFC 4180-quote a cell that carries a comma, quote or newline.
	 *
	 * @param string $cell Raw cell.
	 *
	 * @return string
	 */
	private function csvCell(string $cell): string {
		if (str_contains($cell, ',') === true || str_contains($cell, '"') === true || str_contains($cell, "\n") === true) {
			return '"' . str_replace('"', '""', $cell) . '"';
		}

		return $cell;
	}//end csvCell()

	/**
	 * Qualitative narrative seed for the disclosure note (IFRS 16.59, REQ-LD-001).
	 *
	 * @param int $leaseCount Number of leases in the period.
	 *
	 * @return string Seed text for the operator to refine.
	 */
	private function narrativeSeed(int $leaseCount): string {
		return 'The entity leases assets recognised under IFRS 16. ' . $leaseCount
			. ' lease(s) were active or modified in the period. Extension and termination options '
			. 'are reassessed each period close; refer to the maturity analysis for undiscounted future '
			. 'commitments and to the weighted-average incremental borrowing rate for the discounting basis.';

	}//end narrativeSeed()

	/**
	 * Resolve OpenRegister's ObjectService lazily.
	 *
	 * @return object The ObjectService instance.
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
