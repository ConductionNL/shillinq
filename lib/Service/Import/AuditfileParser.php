<?php

/**
 * Auditfile (XAF 3.2) Parser
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Deterministic, XXE-safe parser for the Dutch XML Auditfile Financieel
 * (XAF 3.2 — http://www.auditfiles.nl/XAF/3.2). It is the load-bearing,
 * fully-real deliverable of the administration-import-migration change
 * (REQ-AIM-003): a single-purpose transform that turns a raw auditfile into
 * a normalised staged structure the import pipeline can validate, dry-run,
 * and post. It contains ZERO bookkeeping rules.
 *
 * Mirrors the StatementParser discipline:
 *  - XML loading is XXE-safe (libxml_use_internal_errors + LIBXML_NONET |
 *    LIBXML_NOENT; external entities never resolved on PHP 8 / libxml >= 2.9).
 *  - namespace-agnostic local-name() xpath so the parse works regardless of
 *    the declared prefix or namespace URI.
 *  - fail-closed: on a fatal parse error the parser returns an empty,
 *    findings-bearing structure rather than throwing.
 *  - never silently skips: unknown / malformed constructs (e.g. a
 *    transaction line missing its amount) produce an error-severity finding
 *    identifying the element, never a dropped row (REQ-AIM-003 scenario).
 *
 * Large-file note: parse() accepts a string for unit-testability and small
 * files; parseFile() streams via XMLReader (no DOM load) so a multi-100MB
 * auditfile never lives wholly in memory inside one HTTP request
 * (REQ-AIM-002). Both paths return the same normalised structure.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Import;

use Psr\Log\LoggerInterface;
use XMLReader;

/**
 * XAF 3.2 auditfile parser (REQ-AIM-003).
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-6
 */
class AuditfileParser {
	/**
	 * Findings severity: blocks the import.
	 *
	 * @var string
	 */
	public const SEVERITY_ERROR = 'error';

	/**
	 * Findings severity: informs the operator, does not block.
	 *
	 * @var string
	 */
	public const SEVERITY_WARNING = 'warning';

	/**
	 * The message of every error finding, by its stable code.
	 *
	 * @var array<string,string>
	 */
	private const ERROR_MESSAGES = [
		'empty-input' => 'Auditfile is empty.',
		'doctype-rejected' => 'Auditfile contains a DOCTYPE or ENTITY declaration and was rejected (XXE protection).',
		'malformed-xml' => 'Auditfile is not well-formed XML.',
		'unreadable-file' => 'Auditfile is not readable.',
		'open-failed' => 'Could not open auditfile for streaming.',
		'stream-error' => 'Streaming the auditfile failed.',
		'no-company' => 'No <company> element found in the auditfile.',
		'transaction-line-missing-amount' => 'A transaction line is missing its <amnt> amount.',
	];

	/**
	 * The company identity fields, each with the xpaths tried in order.
	 *
	 * @var array<string,list<string>>
	 */
	private const COMPANY_FIELDS = [
		'companyName' => ['//*[local-name()="company"]/*[local-name()="companyName"]'],
		'kvkNumber' => ['//*[local-name()="company"]/*[local-name()="companyIdent"]'],
		'taxRegIdent' => [
			'//*[local-name()="company"]/*[local-name()="taxRegistration"]/*[local-name()="taxRegIdent"]',
			'//*[local-name()="company"]/*[local-name()="taxRegIdent"]',
		],
		'currencyCode' => ['//*[local-name()="company"]/*[local-name()="currencyCode"]'],
	];

	/**
	 * The result keys a streamed company fragment adds to.
	 *
	 * @var list<string>
	 */
	private const MERGED_KEYS = ['ledgerAccounts', 'relations', 'openingBalances', 'journals', 'findings'];

	/**
	 * Optional logger for diagnostics.
	 *
	 * @var LoggerInterface|null
	 */
	private ?LoggerInterface $logger;

	/**
	 * Construct the parser.
	 *
	 * @param LoggerInterface|null $logger Optional logger for diagnostics.
	 */
	public function __construct(?LoggerInterface $logger = null) {
		$this->logger = $logger;

	}//end __construct()

	/**
	 * Parse a raw XAF 3.2 auditfile string into the normalised staged structure.
	 *
	 * Returns a structure shaped as:
	 *   [
	 *     'company'         => ['companyName'=>..,'kvkNumber'=>..,'taxRegIdent'=>..],
	 *     'ledgerAccounts'  => [['code','name','rgsCode','type'], ...],
	 *     'relations'       => [['code','name','kvk','vat','email','type'], ...],
	 *     'openingBalances' => [['accountCode','debit','credit'], ...],
	 *     'journals'        => [['journalId','transactions'=>[['lines'=>[...]]]], ...],
	 *     'findings'        => [['severity','code','message','context'], ...],
	 *   ]
	 *
	 * XXE-safe; never throws — a fatal error yields an empty structure carrying
	 * an error-severity finding (REQ-AIM-003).
	 *
	 * @param string $xml Raw auditfile contents.
	 *
	 * @return array<string,mixed> Normalised staged structure.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-6
	 */
	public function parse(string $xml): array {
		$result = $this->emptyResult();
		$loaded = $this->loadDocument(xml: trim($xml));
		if (is_array($loaded) === true) {
			$result['findings'][] = $loaded;
			return $result;
		}

		$result['company']         = $this->parseCompany(doc: $loaded);
		$result['ledgerAccounts']  = $this->parseLedgerAccounts(doc: $loaded);
		$result['relations']       = $this->parseRelations(doc: $loaded);
		$result['openingBalances'] = $this->parseOpeningBalances(doc: $loaded);

		$journalResult      = $this->parseJournals(doc: $loaded);
		$result['journals'] = $journalResult['journals'];
		$result['findings'] = array_merge($result['findings'], $journalResult['findings']);

		return $result;
	}//end parse()

	/**
	 * The parsed document, or the error finding that refuses it: empty input,
	 * a DOCTYPE or ENTITY declaration, or XML that is not well formed.
	 *
	 * @param string $xml The trimmed auditfile contents.
	 *
	 * @return \SimpleXMLElement|array<string,mixed> The document, or the finding.
	 */
	private function loadDocument(string $xml): \SimpleXMLElement|array {
		if ($xml === '') {
			return $this->error(code: 'empty-input');
		}

		// XXE-safe: LIBXML_NONET disables network access. We deliberately do
		// NOT pass LIBXML_NOENT (that flag ENABLES entity substitution). On
		// PHP 8 / libxml >= 2.9 external entities are not resolved by default,
		// and we additionally reject any DOCTYPE/entity declaration outright.
		if (preg_match('/<!DOCTYPE/i', $xml) === 1 || preg_match('/<!ENTITY/i', $xml) === 1) {
			$this->logger?->warning('AuditfileParser: rejected DOCTYPE/ENTITY (XXE protection)');
			return $this->error(code: 'doctype-rejected');
		}

		return $this->wellFormedDocument(xml: $xml);
	}//end loadDocument()

	/**
	 * The document of well-formed XML, or the malformed-xml finding with the
	 * first libxml error.
	 *
	 * @param string $xml The trimmed auditfile contents.
	 *
	 * @return \SimpleXMLElement|array<string,mixed> The document, or the finding.
	 */
	private function wellFormedDocument(string $xml): \SimpleXMLElement|array {
		$previous  = libxml_use_internal_errors(true);
		$doc       = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
		$libErrors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if ($doc !== false) {
			return $doc;
		}

		$detail = '';
		if (empty($libErrors) === false) {
			$detail = trim($libErrors[0]->message);
		}

		$this->logger?->warning('AuditfileParser: malformed XML', ['detail' => $detail]);
		return $this->error(code: 'malformed-xml', context: ['detail' => $detail]);
	}//end wellFormedDocument()

	/**
	 * Stream-parse a large auditfile from disk via XMLReader (no DOM load).
	 *
	 * Used by the pipeline for multi-100MB files (REQ-AIM-002). Buffers each
	 * top-level <company> subtree and hands it to the string parser so both
	 * paths produce the identical normalised structure. Returns an empty
	 * findings-bearing structure on a read/parse error.
	 *
	 * @param string $path Absolute filesystem path to the auditfile.
	 *
	 * @return array<string,mixed> Normalised staged structure.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-6
	 */
	public function parseFile(string $path): array {
		$result = $this->emptyResult();
		if (is_readable($path) === false) {
			$result['findings'][] = $this->error(code: 'unreadable-file', context: ['path' => $path]);
			return $result;
		}

		$read = $this->readCompanies(path: $path);
		$result['findings'] = $read['findings'];
		if ($read['opened'] === true && $read['fragments'] === []) {
			$result['findings'][] = $this->error(code: 'no-company', context: ['path' => $path]);
		}

		if ($read['fragments'] === []) {
			return $result;
		}

		return $this->mergedFragments(result: $result, fragments: $read['fragments']);
	}//end parseFile()

	/**
	 * Every top-level <company> subtree of a file, read with XMLReader, and
	 * the findings of a file that cannot be opened or streamed.
	 *
	 * @param string $path Absolute filesystem path to the auditfile.
	 *
	 * @return array{opened: bool, fragments: list<string>, findings: list<array<string,mixed>>}
	 */
	private function readCompanies(string $path): array {
		$previous = libxml_use_internal_errors(true);
		$reader   = new XMLReader();
		// LIBXML_NONET — no network. NOENT is deliberately omitted (it ENABLES
		// entity substitution). External entities are not resolved by default
		// on PHP 8 / libxml >= 2.9 (XXE-safe).
		$opened = $reader->open($path, null, LIBXML_NONET);
		if ($opened === false) {
			libxml_use_internal_errors($previous);
			return ['opened' => false, 'fragments' => [], 'findings' => [$this->error(code: 'open-failed', context: ['path' => $path])]];
		}

		$read = ['opened' => true, 'fragments' => [], 'findings' => []];
		try {
			// Collected one by one, so the fragments read before a stream error are kept.
			foreach ($this->companyFragments(reader: $reader) as $fragment) {
				$read['fragments'][] = $fragment;
			}
		} catch (\Throwable $e) {
			$read['findings'][] = $this->error(code: 'stream-error', context: ['detail' => $e->getMessage()]);
		} finally {
			$reader->close();
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		return $read;
	}//end readCompanies()

	/**
	 * The outer XML of each <company> element the reader meets, skipping the
	 * subtree it just buffered.
	 *
	 * @param XMLReader $reader The opened reader.
	 *
	 * @return \Generator<int, string>
	 */
	private function companyFragments(XMLReader $reader): \Generator {
		while ($reader->read() === true) {
			if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'company') {
				continue;
			}

			$fragment = $reader->readOuterXml();
			if ($fragment !== '') {
				yield $fragment;
			}

			$reader->next();
		}
	}//end companyFragments()

	/**
	 * The result with each buffered company fragment re-parsed through the
	 * string path, so the two entry points share one normalisation; the last
	 * non-empty company identity wins.
	 *
	 * @param array<string,mixed> $result    The result so far.
	 * @param list<string>        $fragments The company fragments.
	 *
	 * @return array<string,mixed>
	 */
	private function mergedFragments(array $result, array $fragments): array {
		foreach ($fragments as $fragment) {
			$parsed = $this->parse(xml: '<auditfile xmlns="http://www.auditfiles.nl/XAF/3.2">' . $fragment . '</auditfile>');
			if ($parsed['company'] !== []) {
				$result['company'] = $parsed['company'];
			}

			foreach (self::MERGED_KEYS as $key) {
				$result[$key] = array_merge($result[$key], $parsed[$key]);
			}
		}

		return $result;
	}//end mergedFragments()

	/**
	 * Extract company-level identity from the auditfile.
	 *
	 * @param \SimpleXMLElement $doc Parsed auditfile root.
	 *
	 * @return array<string,string> Company identity field map.
	 */
	private function parseCompany(\SimpleXMLElement $doc): array {
		$company = [];
		foreach (self::COMPANY_FIELDS as $field => $xpaths) {
			$value = $this->firstOf(node: $doc, xpaths: $xpaths);
			if ($value !== '') {
				$company[$field] = $value;
			}
		}

		return $company;
	}//end parseCompany()

	/**
	 * Extract the ledger account list, including RGS codes via leadReference.
	 *
	 * XAF 3.2 carries accounts under <generalLedger>/<ledgerAccount> or
	 * <ledgerAccountStructure>; both use <accID> / <accDesc> / <accTp> and
	 * frequently a <leadReference> holding the RGS code.
	 *
	 * @param \SimpleXMLElement $doc Parsed auditfile root.
	 *
	 * @return array<int,array<string,string>> Ledger account field maps.
	 */
	private function parseLedgerAccounts(\SimpleXMLElement $doc): array {
		$accounts = [];
		$nodes = $doc->xpath('//*[local-name()="ledgerAccount"]');
		if ($nodes === false || $nodes === null) {
			return $accounts;
		}

		foreach ($nodes as $node) {
			$code = $this->firstString(node: $node, xpath: './*[local-name()="accID"]');
			if ($code === '') {
				continue;
			}

			$accounts[] = [
				'code' => $code,
				'name' => $this->firstString(node: $node, xpath: './*[local-name()="accDesc"]'),
				'type' => $this->firstString(node: $node, xpath: './*[local-name()="accTp"]'),
				'rgsCode' => $this->firstString(node: $node, xpath: './*[local-name()="leadReference"]'),
			];
		}

		return $accounts;
	}//end parseLedgerAccounts()

	/**
	 * Extract relations (customers / suppliers) with KvK + BTW + contact.
	 *
	 * XAF 3.2 carries these under <customersSuppliers>/<customerSupplier>
	 * with <custSupID>, <companyName>, taxRegistration/<taxRegIdent> (BTW),
	 * and a <contact> block.
	 *
	 * @param \SimpleXMLElement $doc Parsed auditfile root.
	 *
	 * @return array<int,array<string,string>> Relation field maps.
	 */
	private function parseRelations(\SimpleXMLElement $doc): array {
		$relations = [];
		$nodes = $doc->xpath('//*[local-name()="customerSupplier"]');
		if ($nodes === false || $nodes === null) {
			return $relations;
		}

		foreach ($nodes as $node) {
			$vat = $this->firstString(node: $node, xpath: './/*[local-name()="taxRegIdent"]');

			$relations[] = [
				'code' => $this->firstString(node: $node, xpath: './*[local-name()="custSupID"]'),
				'name' => $this->firstString(node: $node, xpath: './*[local-name()="companyName"]'),
				'kvk' => $this->firstString(node: $node, xpath: './*[local-name()="companyIdent"]'),
				'vat' => $vat,
				'email' => $this->firstString(node: $node, xpath: './/*[local-name()="eMail"]'),
				'phone' => $this->firstString(node: $node, xpath: './/*[local-name()="telephone"]'),
				'type' => $this->firstString(node: $node, xpath: './*[local-name()="custSupTp"]'),
			];
		}//end foreach

		return $relations;
	}//end parseRelations()

	/**
	 * Extract opening-balance lines from the XAF opening-balance block.
	 *
	 * XAF 3.2 carries these under <openingBalance>/<obLine> with <accID>,
	 * <amnt>, and <amntTp> (C = credit, D = debit).
	 *
	 * @param \SimpleXMLElement $doc Parsed auditfile root.
	 *
	 * @return array<int,array<string,mixed>> Opening-balance field maps.
	 */
	private function parseOpeningBalances(\SimpleXMLElement $doc): array {
		$lines = [];
		$nodes = $doc->xpath('//*[local-name()="openingBalance"]//*[local-name()="obLine"]');
		if ($nodes === false || $nodes === null) {
			return $lines;
		}

		foreach ($nodes as $node) {
			$accountCode = $this->firstString(node: $node, xpath: './*[local-name()="accID"]');
			if ($accountCode !== '') {
				$lines[] = ['accountCode' => $accountCode] + $this->debitAndCredit(node: $node);
			}
		}

		return $lines;
	}//end parseOpeningBalances()

	/**
	 * An opening-balance line's amount on its side: D is debit, C is credit,
	 * anything else neither.
	 *
	 * @param \SimpleXMLElement $node The obLine.
	 *
	 * @return array{debit: float, credit: float}
	 */
	private function debitAndCredit(\SimpleXMLElement $node): array {
		$amount = (float)$this->firstString(node: $node, xpath: './*[local-name()="amnt"]');
		$type   = strtoupper($this->firstString(node: $node, xpath: './*[local-name()="amntTp"]'));

		$sides = ['debit' => 0.0, 'credit' => 0.0];
		if ($type === 'D') {
			$sides['debit'] = $amount;
		}

		if ($type === 'C') {
			$sides['credit'] = $amount;
		}

		return $sides;
	}//end debitAndCredit()

	/**
	 * Extract journals + transaction lines, recording a finding for any
	 * malformed line (e.g. a trLine missing its amount) — never dropped.
	 *
	 * @param \SimpleXMLElement $doc Parsed auditfile root.
	 *
	 * @return array{journals:array<int,array<string,mixed>>,findings:array<int,array<string,mixed>>}
	 */
	private function parseJournals(\SimpleXMLElement $doc): array {
		$parsed = ['journals' => [], 'findings' => []];

		$journalNodes = $doc->xpath('//*[local-name()="transactions"]/*[local-name()="journal"]');
		if ($journalNodes === false || $journalNodes === null) {
			return $parsed;
		}

		foreach ($journalNodes as $journalNode) {
			$journalId    = $this->firstString(node: $journalNode, xpath: './*[local-name()="jrnID"]');
			$transactions = [];
			foreach (($journalNode->xpath('./*[local-name()="transaction"]') ?? []) as $txNode) {
				$transaction        = $this->parseTransaction(txNode: $txNode, journalId: $journalId);
				$parsed['findings'] = array_merge($parsed['findings'], $transaction['findings']);
				$transactions[]     = $transaction['transaction'];
			}

			$parsed['journals'][] = ['journalId' => $journalId, 'transactions' => $transactions];
		}

		return $parsed;
	}//end parseJournals()

	/**
	 * One transaction and a finding per line missing its amount; such a line
	 * is NOT silently dropped — it is kept with a null amount so the count is
	 * honest and the finding is traceable.
	 *
	 * @param \SimpleXMLElement $txNode    The transaction node.
	 * @param string            $journalId The journal it belongs to.
	 *
	 * @return array{transaction: array<string,mixed>, findings: list<array<string,mixed>>}
	 */
	private function parseTransaction(\SimpleXMLElement $txNode, string $journalId): array {
		$lines    = [];
		$findings = [];
		foreach (($txNode->xpath('./*[local-name()="trLine"]') ?? []) as $lineNode) {
			$line = $this->transactionLine(lineNode: $lineNode);
			if ($line['amount'] === null) {
				$findings[] = $this->error(
					code: 'transaction-line-missing-amount',
					context: ['journalId' => $journalId, 'accountCode' => $line['accountCode']]
				);
			}

			$lines[] = $line;
		}

		return [
			'transaction' => ['transactionId' => $this->firstString(node: $txNode, xpath: './*[local-name()="nr"]'), 'lines' => $lines],
			'findings' => $findings,
		];
	}//end parseTransaction()

	/**
	 * One transaction line: its account, its amount (null when missing) and
	 * its upper-cased amount type.
	 *
	 * @param \SimpleXMLElement $lineNode The trLine node.
	 *
	 * @return array{accountCode: string, amount: float|null, amountType: string}
	 */
	private function transactionLine(\SimpleXMLElement $lineNode): array {
		$amountRaw = $this->firstString(node: $lineNode, xpath: './*[local-name()="amnt"]');
		$amount    = null;
		if ($amountRaw !== '') {
			$amount = (float)$amountRaw;
		}

		return [
			'accountCode' => $this->firstString(node: $lineNode, xpath: './*[local-name()="accID"]'),
			'amount' => $amount,
			'amountType' => strtoupper($this->firstString(node: $lineNode, xpath: './*[local-name()="amntTp"]')),
		];
	}//end transactionLine()

	/**
	 * Return the first xpath text match as a trimmed string, or '' if absent.
	 *
	 * @param \SimpleXMLElement $node Context node.
	 * @param string $xpath The xpath expression (local-name based).
	 *
	 * @return string Trimmed text of the first match, or empty string.
	 */
	private function firstString(\SimpleXMLElement $node, string $xpath): string {
		$matches = $node->xpath($xpath);
		if ($matches === false || $matches === null || isset($matches[0]) === false) {
			return '';
		}

		return trim((string)$matches[0]);
	}//end firstString()

	/**
	 * The first non-empty text of the xpaths, tried in order, or ''.
	 *
	 * @param \SimpleXMLElement $node   Context node.
	 * @param list<string>      $xpaths The xpath expressions.
	 *
	 * @return string
	 */
	private function firstOf(\SimpleXMLElement $node, array $xpaths): string {
		foreach ($xpaths as $xpath) {
			$value = $this->firstString(node: $node, xpath: $xpath);
			if ($value !== '') {
				return $value;
			}
		}

		return '';
	}//end firstOf()

	/**
	 * Build an error-severity finding with its fixed message.
	 *
	 * @param string              $code    Stable machine code, a key of ERROR_MESSAGES.
	 * @param array<string,mixed> $context Optional contextual data.
	 *
	 * @return array<string,mixed> The finding.
	 */
	private function error(string $code, array $context = []): array {
		return [
			'severity' => self::SEVERITY_ERROR,
			'code' => $code,
			'message' => self::ERROR_MESSAGES[$code],
			'context' => $context,
		];

	}//end error()

	/**
	 * The empty normalised structure used as a base / fail-closed return.
	 *
	 * @return array<string,mixed>
	 */
	private function emptyResult(): array {
		return [
			'company' => [],
			'ledgerAccounts' => [],
			'relations' => [],
			'openingBalances' => [],
			'journals' => [],
			'findings' => [],
		];

	}//end emptyResult()
}//end class
