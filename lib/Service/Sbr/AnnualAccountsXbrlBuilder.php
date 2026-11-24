<?php

/**
 * Shillinq annual accounts XBRL builder
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sbr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sbr;

use OCA\Shillinq\Kernel\Contracts\AnnualAccountsXbrlBuilderInterface;
use Override;
use RuntimeException;

/**
 * Builds the XBRL instance that files a set of annual accounts
 * (FinancialStatement) with the KvK, on the kvk-jaarrekening entry point.
 *
 * The balance sheet is reported at the year end (instant context) and the
 * profit and loss over the year (duration context), in whole euros, with the
 * administration's KvK number as entity. Totals (assets, equity and
 * liabilities) are summed from the rounded parts, so they add up exactly.
 * Concepts are the defaults below, renamed by the taxonomy record's
 * `conceptMap.kvk-jaarrekening` and then by the administration's active
 * `XBRLMapping` rows whose `sourceAccountId` names a line; the entry point
 * schema is the taxonomy's `schemaRefs` entry for `kvk-jaarrekening:<size
 * class>`, else for `kvk-jaarrekening`.
 *
 * @spec openspec/changes/tax-digipoort-filing/specs/bookkeeping-sbr-xbrl-reporting/spec.md#requirement-annual-accounts-produce-their-kvk-xbrl-instance-req-tdf-002
 */
class AnnualAccountsXbrlBuilder implements AnnualAccountsXbrlBuilderInterface {

	/**
	 * The entry point annual accounts are filed on.
	 */
	public const ENTRY_POINT = 'kvk-jaarrekening';

	/**
	 * The filing type of the instance.
	 */
	public const FILING_TYPE = 'kvk-deponering';

	/**
	 * The identifier scheme of a KvK number.
	 */
	public const KVK_SCHEME = 'http://www.kvk.nl/kvk-id/';

	/**
	 * The namespace of the KvK concepts when the taxonomy names none.
	 */
	public const DEFAULT_NAMESPACES = ['jenv-bw2-i' => 'http://www.nltaxonomie.nl/nt/jenv/dictionary/jenv-bw2-data'];

	/**
	 * The concept of each balance sheet line, reported at the year end.
	 */
	public const BALANCE_CONCEPTS = [
		'fixedAssets'          => 'jenv-bw2-i:AssetsNoncurrent',
		'currentAssets'        => 'jenv-bw2-i:AssetsCurrent',
		'assets'               => 'jenv-bw2-i:Assets',
		'equity'               => 'jenv-bw2-i:Equity',
		'provisions'           => 'jenv-bw2-i:Provisions',
		'liabilities'          => 'jenv-bw2-i:Liabilities',
		'equityAndLiabilities' => 'jenv-bw2-i:EquityAndLiabilities',
	];

	/**
	 * The concept of each profit and loss line, reported over the year.
	 */
	public const RESULT_CONCEPTS = [
		'netTurnover'       => 'jenv-bw2-i:NetRevenue',
		'operatingExpenses' => 'jenv-bw2-i:OperatingExpenses',
		'financialResult'   => 'jenv-bw2-i:FinancialIncomeExpenses',
		'result'            => 'jenv-bw2-i:ResultAfterTax',
	];

	/**
	 * Constructor.
	 *
	 * @param SbrRecords         $records    Reads the statement and writes the instance.
	 * @param XbrlTaxonomyLookup $taxonomies Finds the taxonomy effective for the year.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.3
	 */
	public function __construct(
		private readonly SbrRecords $records,
		private readonly XbrlTaxonomyLookup $taxonomies,
	) {
	}//end __construct()

	/**
	 * Build (or rebuild, while it is a draft) the KvK instance of a statement.
	 *
	 * An instance of the statement that left draft is returned as it is.
	 *
	 * @param array<string,mixed> $statement        The FinancialStatement.
	 * @param string              $administrationId The administration it belongs to.
	 *
	 * @return array<string,mixed> The saved XbrlInstance.
	 *
	 * @throws RuntimeException When the statement has no id or no reporting period.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.3
	 */
	#[Override]
	public function build(array $statement, string $administrationId): array {
		$statementId = trim((string) ($statement['id'] ?? ($statement['@self']['id'] ?? '')));
		if ($statementId === '') {
			throw new RuntimeException('The financial statement has no id; save it before filing it.');
		}

		$existing = null;
		foreach ($this->records->findAll(schema: 'XbrlInstance', filters: ['sourceStatementId' => $statementId]) as $candidate) {
			if (($candidate['filingType'] ?? '') !== self::FILING_TYPE) {
				continue;
			}

			if (in_array((string) ($candidate['state'] ?? 'draft'), ['draft', ''], true) === false) {
				return $candidate;
			}

			$existing ??= $candidate;
		}

		$start          = substr(trim((string) ($statement['reportingPeriodStart'] ?? '')), 0, 10);
		$end            = substr(trim((string) ($statement['reportingPeriodEnd'] ?? '')), 0, 10);
		$taxonomy       = $this->taxonomies->effectiveFor(entryPoint: self::ENTRY_POINT, start: $start, end: $end);
		$administration = $this->records->find(schema: 'Administration', id: $administrationId, fallback: 'administrationCode');
		$mappings       = [];
		if ($taxonomy !== null) {
			$mappings = $this->records->findAll(
				schema: 'XBRLMapping',
				filters: ['administrationId' => $administrationId, 'taxonomyVersion' => (string) ($taxonomy['taxonomyVersion'] ?? '')]
			);
		}

		$instance = $this->instance(
			statement: $statement + ['id' => $statementId],
			administration: ($administration ?? []) + ['id' => $administrationId],
			taxonomy: $taxonomy,
			mappings: $mappings
		);
		if ($existing !== null) {
			$instance = array_merge($existing, $instance);
		}

		return $this->records->save(schema: 'XbrlInstance', record: $instance);
	}//end build()

	/**
	 * The draft XbrlInstance of a statement.
	 *
	 * @param array<string,mixed>       $statement      The FinancialStatement.
	 * @param array<string,mixed>       $administration Its administration.
	 * @param array<string,mixed>|null  $taxonomy       The taxonomy effective for the year, or null.
	 * @param list<array<string,mixed>> $mappings       The administration's XBRLMappings for that taxonomy.
	 *
	 * @return array<string,mixed> The instance record.
	 *
	 * @throws RuntimeException When the statement has no reporting period.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.3
	 */
	public function instance(array $statement, array $administration, ?array $taxonomy, array $mappings=[]): array {
		$period = self::period(statement: $statement);
		$xml    = XbrlInstanceWriter::write(
			header: self::header(statement: ['kvkNumber' => ($administration['kvkNumber'] ?? '')] + $statement, taxonomy: $taxonomy) + $period,
			facts: self::facts(statement: $statement, concepts: self::concepts(taxonomy: $taxonomy, mappings: $mappings))
		);

		$statement['administrationId'] = ($administration['id'] ?? ($statement['administrationId'] ?? ''));
		return XbrlInstanceWriter::record(identity: self::identity(statement: $statement, taxonomy: $taxonomy), xml: $xml);
	}//end instance()

	/**
	 * The statement's reporting period.
	 *
	 * @param array<string,mixed> $statement The FinancialStatement.
	 *
	 * @return array{start:string,end:string} First and last day (Y-m-d).
	 *
	 * @throws RuntimeException When the statement has no reporting period.
	 */
	private static function period(array $statement): array {
		$start = substr(trim((string) ($statement['reportingPeriodStart'] ?? '')), 0, 10);
		$end   = substr(trim((string) ($statement['reportingPeriodEnd'] ?? '')), 0, 10);
		if ($start === '' || $end === '') {
			throw new RuntimeException('The financial statement has no reporting period; it cannot be filed.');
		}

		return ['start' => $start, 'end' => $end];
	}//end period()

	/**
	 * Who reports (the KvK number), the concept namespaces and the entry point
	 * schema: the size class's, else the generic one.
	 *
	 * @param array<string,mixed>      $statement The FinancialStatement, with the administration's `kvkNumber`.
	 * @param array<string,mixed>|null $taxonomy  The taxonomy, or null.
	 *
	 * @return array<string,mixed> The instance header without its period.
	 */
	private static function header(array $statement, ?array $taxonomy): array {
		$sizeClass = strtolower(trim((string) ($statement['sizeClass'] ?? '')));
		$schemaRef = XbrlInstanceWriter::schemaRef(taxonomy: $taxonomy, entryPoint: self::ENTRY_POINT . ':' . $sizeClass);

		return [
			'entity'     => ['scheme' => self::KVK_SCHEME, 'identifier' => (string) preg_replace('/\D+/', '', (string) $statement['kvkNumber'])],
			'namespaces' => XbrlInstanceWriter::namespaces(taxonomy: $taxonomy, defaults: self::DEFAULT_NAMESPACES),
			'schemaRef'  => ($schemaRef ?? XbrlInstanceWriter::schemaRef(taxonomy: $taxonomy, entryPoint: self::ENTRY_POINT)),
		];
	}//end header()

	/**
	 * One fact per amount: balance lines at the instant, result lines over the period.
	 *
	 * @param array<string,mixed>  $statement The FinancialStatement.
	 * @param array<string,string> $concepts  The concept of each line.
	 *
	 * @return list<array{concept:string,value:int,context:string}> The facts.
	 */
	private static function facts(array $statement, array $concepts): array {
		$facts = [];
		foreach (self::amounts(statement: $statement) as $key => $amount) {
			$context = XbrlInstanceWriter::DURATION;
			if (isset(self::BALANCE_CONCEPTS[$key]) === true) {
				$context = XbrlInstanceWriter::INSTANT;
			}

			$facts[] = ['concept' => $concepts[$key], 'value' => $amount, 'context' => $context];
		}

		return $facts;
	}//end facts()

	/**
	 * The identity fields of the statement's instance record, in record order.
	 *
	 * @param array<string,mixed>      $statement The FinancialStatement (with a period and its administrationId).
	 * @param array<string,mixed>|null $taxonomy  The taxonomy, or null.
	 *
	 * @return array<string,string> The fields.
	 */
	private static function identity(array $statement, ?array $taxonomy): array {
		$period = self::period(statement: $statement);

		return [
			'administrationId'     => (string) $statement['administrationId'],
			'instanceNumber'       => self::number(statement: $statement, end: $period['end']),
			'entryPoint'           => self::ENTRY_POINT,
			'filingType'           => self::FILING_TYPE,
			'sourceStatementId'    => (string) ($statement['id'] ?? ''),
			'taxonomyVersion'      => (string) ($taxonomy['taxonomyVersion'] ?? ''),
			'reportingPeriodStart' => $period['start'],
			'reportingPeriodEnd'   => $period['end'],
		];
	}//end identity()

	/**
	 * The instance number: `KVK-<year>`, with `-<size class>` when the statement has one.
	 *
	 * @param array<string,mixed> $statement The FinancialStatement.
	 * @param string              $end       The last day of the period.
	 *
	 * @return string The number.
	 */
	private static function number(array $statement, string $end): string {
		$number    = 'KVK-' . substr($end, 0, 4);
		$sizeClass = strtolower(trim((string) ($statement['sizeClass'] ?? '')));
		if ($sizeClass !== '') {
			$number .= '-' . $sizeClass;
		}

		return $number;
	}//end number()

	/**
	 * The statement's figures in whole euros, keyed as the concept maps are.
	 *
	 * Assets is fixed plus current assets, equity and liabilities is equity,
	 * provisions and liabilities, both from the rounded parts; the result is
	 * the statement's, else turnover less expenses plus the financial result.
	 *
	 * @param array<string,mixed> $statement The FinancialStatement.
	 *
	 * @return array<string,int> The amounts.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.3
	 */
	public static function amounts(array $statement): array {
		$balance = self::figures(value: ($statement['balanceSheet'] ?? []));
		$pnl     = self::figures(value: ($statement['profitAndLoss'] ?? []));

		$amounts = [];
		foreach (['fixedAssets', 'currentAssets', 'equity', 'provisions', 'liabilities'] as $line) {
			$amounts[$line] = (int) round($balance[$line] ?? 0.0);
		}

		$amounts['assets']               = ($amounts['fixedAssets'] + $amounts['currentAssets']);
		$amounts['equityAndLiabilities'] = ($amounts['equity'] + $amounts['provisions'] + $amounts['liabilities']);
		foreach (['netTurnover', 'operatingExpenses', 'financialResult'] as $line) {
			$amounts[$line] = (int) round($pnl[$line] ?? 0.0);
		}

		$amounts['result'] = ($amounts['netTurnover'] - $amounts['operatingExpenses'] + $amounts['financialResult']);
		if (isset($pnl['result']) === true && (float) $pnl['result'] !== 0.0) {
			$amounts['result'] = (int) round($pnl['result']);
		}

		$ordered = [];
		foreach (array_keys(self::BALANCE_CONCEPTS + self::RESULT_CONCEPTS) as $key) {
			$ordered[$key] = $amounts[$key];
		}

		return $ordered;
	}//end amounts()

	/**
	 * The numeric figures of a statement section.
	 *
	 * @param mixed $value The section.
	 *
	 * @return array<string,float> The figures.
	 */
	private static function figures(mixed $value): array {
		$figures = [];
		if (is_array($value) === false) {
			return $figures;
		}

		foreach ($value as $key => $figure) {
			if (is_string($key) === true && is_numeric($figure) === true) {
				$figures[$key] = (float) $figure;
			}
		}

		return $figures;
	}//end figures()

	/**
	 * The concept of each line: defaults, then the taxonomy's conceptMap, then active mappings.
	 *
	 * @param array<string,mixed>|null  $taxonomy The taxonomy record.
	 * @param list<array<string,mixed>> $mappings The XBRLMappings.
	 *
	 * @return array<string,string> The concepts.
	 */
	private static function concepts(?array $taxonomy, array $mappings): array {
		$concepts  = (self::BALANCE_CONCEPTS + self::RESULT_CONCEPTS);
		$overrides = (($taxonomy['conceptMap'] ?? [])[self::ENTRY_POINT] ?? []);
		if (is_array($overrides) === false) {
			$overrides = [];
		}

		foreach ($mappings as $mapping) {
			if (strtolower((string) ($mapping['status'] ?? 'active')) === 'active') {
				$overrides[(string) ($mapping['sourceAccountId'] ?? '')] = ($mapping['targetXBRLConcept'] ?? null);
			}
		}

		foreach ($overrides as $key => $concept) {
			if (isset($concepts[$key]) === true && is_string($concept) === true && str_contains($concept, ':') === true) {
				$concepts[$key] = trim($concept);
			}
		}

		return $concepts;
	}//end concepts()
}//end class
