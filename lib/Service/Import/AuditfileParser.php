<?php

/**
 * Auditfile (XAF 3.2) Parser
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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

		$trimmed = trim($xml);
		if ($trimmed === '') {
			$result['findings'][] = $this->finding(
				severity: self::SEVERITY_ERROR,
				code: 'empty-input',
				message: 'Auditfile is empty.'
			);
			return $result;
		}

		// XXE-safe: LIBXML_NONET disables network access. We deliberately do
		// NOT pass LIBXML_NOENT (that flag ENABLES entity substitution). On
		// PHP 8 / libxml >= 2.9 external entities are not resolved by default,
		// and we additionally reject any DOCTYPE/entity declaration outright.
		if (preg_match('/<!DOCTYPE/i', $trimmed) === 1 || preg_match('/<!ENTITY/i', $trimmed) === 1) {
			$result['findings'][] = $this->finding(
				severity: self::SEVERITY_ERROR,
				code: 'doctype-rejected',
				message: 'Auditfile contains a DOCTYPE or ENTITY declaration and was rejected (XXE protection).'
			);
			if ($this->logger !== null) {
				$this->logger->warning('AuditfileParser: rejected DOCTYPE/ENTITY (XXE protection)');
			}

			return $result;
		}

		$previous = libxml_use_internal_errors(true);
		$doc = simplexml_load_string($trimmed, \SimpleXMLElement::class, LIBXML_NONET);
		$libErrors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($doc === false) {
			$detail = '';
			if (empty($libErrors) === false) {
				$detail = trim($libErrors[0]->message);
			}

			$result['findings'][] = $this->finding(
				severity: self::SEVERITY_ERROR,
				code: 'malformed-xml',
				message: 'Auditfile is not well-formed XML.',
				context: ['detail' => $detail]
			);
			if ($this->logger !== null) {
				$this->logger->warning('AuditfileParser: malformed XML', ['detail' => $detail]);
			}

			return $result;
		}//end if

		$result['company'] = $this->parseCompany(doc: $doc);
		$result['ledgerAccounts'] = $this->parseLedgerAccounts(doc: $doc);
		$result['relations'] = $this->parseRelations(doc: $doc);
		$result['openingBalances'] = $this->parseOpeningBalances(doc: $doc);

		$journalResult = $this->parseJournals(doc: $doc);
		$result['journals'] = $journalResult['journals'];
		$result['findings'] = array_merge($result['findings'], $journalResult['findings']);

		return $result;
	}//end parse()

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
			$result['findings'][] = $this->finding(
				severity: self::SEVERITY_ERROR,
				code: 'unreadable-file',
				message: 'Auditfile is not readable.',
				context: ['path' => $path]
			);
			return $result;
		}

		$previous = libxml_use_internal_errors(true);
		$reader = new XMLReader();
		// LIBXML_NONET — no network. NOENT is deliberately omitted (it ENABLES
		// entity substitution). External entities are not resolved by default
		// on PHP 8 / libxml >= 2.9 (XXE-safe).
		$opened = $reader->open($path, null, LIBXML_NONET);
		if ($opened === false) {
			libxml_use_internal_errors($previous);
			$result['findings'][] = $this->finding(
				severity: self::SEVERITY_ERROR,
				code: 'open-failed',
				message: 'Could not open auditfile for streaming.',
				context: ['path' => $path]
			);
			return $result;
		}

		$fragments = [];
		try {
			while ($reader->read() === true) {
				if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'company') {
					$fragment = $reader->readOuterXml();
					if ($fragment !== '') {
						$fragments[] = $fragment;
					}

					// Skip the subtree we just buffered.
					$reader->next();
				}
			}
		} catch (\Throwable $e) {
			$result['findings'][] = $this->finding(
				severity: self::SEVERITY_ERROR,
				code: 'stream-error',
				message: 'Streaming the auditfile failed.',
				context: ['detail' => $e->getMessage()]
			);
		} finally {
			$reader->close();
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}//end try

		if (empty($fragments) === true) {
			$result['findings'][] = $this->finding(
				severity: self::SEVERITY_ERROR,
				code: 'no-company',
				message: 'No <company> element found in the auditfile.',
				context: ['path' => $path]
			);
			return $result;
		}

		// Re-parse each buffered company fragment through the string path so the
		// two entry points share one normalisation implementation.
		foreach ($fragments as $fragment) {
			$wrapped = '<auditfile xmlns="http://www.auditfiles.nl/XAF/3.2">' . $fragment . '</auditfile>';
			$parsed = $this->parse(xml: $wrapped);

			if ($parsed['company'] !== []) {
				$result['company'] = $parsed['company'];
			}

			$result['ledgerAccounts'] = array_merge($result['ledgerAccounts'], $parsed['ledgerAccounts']);
			$result['relations'] = array_merge($result['relations'], $parsed['relations']);
			$result['openingBalances'] = array_merge($result['openingBalances'], $parsed['openingBalances']);
			$result['journals'] = array_merge($result['journals'], $parsed['journals']);
			$result['findings'] = array_merge($result['findings'], $parsed['findings']);
		}

		return $result;
	}//end parseFile()

	/**
	 * Extract company-level identity from the auditfile.
	 *
	 * @param \SimpleXMLElement $doc Parsed auditfile root.
	 *
	 * @return array<string,string> Company identity field map.
	 */
	private function parseCompany(\SimpleXMLElement $doc): array {
		$company = [];

		$name = $this->firstString(node: $doc, xpath: '//*[local-name()="company"]/*[local-name()="companyName"]');
		if ($name !== '') {
			$company['companyName'] = $name;
		}

		$kvk = $this->firstString(node: $doc, xpath: '//*[local-name()="company"]/*[local-name()="companyIdent"]');
		if ($kvk !== '') {
			$company['kvkNumber'] = $kvk;
		}

		$taxIdent = $this->firstString(
			node: $doc,
			xpath: '//*[local-name()="company"]/*[local-name()="taxRegistration"]/*[local-name()="taxRegIdent"]'
		);
		if ($taxIdent === '') {
			$taxIdent = $this->firstString(
				node: $doc,
				xpath: '//*[local-name()="company"]/*[local-name()="taxRegIdent"]'
			);
		}

		if ($taxIdent !== '') {
			$company['taxRegIdent'] = $taxIdent;
		}

		$currency = $this->firstString(node: $doc, xpath: '//*[local-name()="company"]/*[local-name()="currencyCode"]');
		if ($currency !== '') {
			$company['currencyCode'] = $currency;
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
			if ($accountCode === '') {
				continue;
			}

			$amount = (float)$this->firstString(node: $node, xpath: './*[local-name()="amnt"]');
			$type = strtoupper($this->firstString(node: $node, xpath: './*[local-name()="amntTp"]'));

			$debit = 0.0;
			if ($type === 'D') {
				$debit = $amount;
			}

			$credit = 0.0;
			if ($type === 'C') {
				$credit = $amount;
			}

			$lines[] = [
				'accountCode' => $accountCode,
				'debit' => $debit,
				'credit' => $credit,
			];
		}//end foreach

		return $lines;
	}//end parseOpeningBalances()

	/**
	 * Extract journals + transaction lines, recording a finding for any
	 * malformed line (e.g. a trLine missing its amount) — never dropped.
	 *
	 * @param \SimpleXMLElement $doc Parsed auditfile root.
	 *
	 * @return array{journals:array<int,array<string,mixed>>,findings:array<int,array<string,mixed>>}
	 */
	private function parseJournals(\SimpleXMLElement $doc): array {
		$journals = [];
		$findings = [];

		$journalNodes = $doc->xpath('//*[local-name()="transactions"]/*[local-name()="journal"]');
		if ($journalNodes === false || $journalNodes === null) {
			return ['journals' => $journals, 'findings' => $findings];
		}

		foreach ($journalNodes as $journalNode) {
			$journalId = $this->firstString(node: $journalNode, xpath: './*[local-name()="jrnID"]');
			$transactions = [];

			$txNodes = $journalNode->xpath('./*[local-name()="transaction"]');
			foreach (($txNodes ?? []) as $txNode) {
				$lines = [];
				$lineNodes = $txNode->xpath('./*[local-name()="trLine"]');
				foreach (($lineNodes ?? []) as $lineNode) {
					$accountCode = $this->firstString(node: $lineNode, xpath: './*[local-name()="accID"]');
					$amountRaw = $this->firstString(node: $lineNode, xpath: './*[local-name()="amnt"]');

					if ($amountRaw === '') {
						$findings[] = $this->finding(
							severity: self::SEVERITY_ERROR,
							code: 'transaction-line-missing-amount',
							message: 'A transaction line is missing its <amnt> amount.',
							context: ['journalId' => $journalId, 'accountCode' => $accountCode]
						);
						// The row is NOT silently dropped — it is kept with a null
						// amount so the count is honest and the finding is traceable.
						$lines[] = [
							'accountCode' => $accountCode,
							'amount' => null,
							'amountType' => strtoupper($this->firstString(node: $lineNode, xpath: './*[local-name()="amntTp"]')),
						];
						continue;
					}

					$lines[] = [
						'accountCode' => $accountCode,
						'amount' => (float)$amountRaw,
						'amountType' => strtoupper($this->firstString(node: $lineNode, xpath: './*[local-name()="amntTp"]')),
					];
				}//end foreach

				$transactions[] = [
					'transactionId' => $this->firstString(node: $txNode, xpath: './*[local-name()="nr"]'),
					'lines' => $lines,
				];
			}//end foreach

			$journals[] = [
				'journalId' => $journalId,
				'transactions' => $transactions,
			];
		}//end foreach

		return ['journals' => $journals, 'findings' => $findings];
	}//end parseJournals()

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
	 * Build a structured finding.
	 *
	 * @param string $severity One of SEVERITY_ERROR / SEVERITY_WARNING.
	 * @param string $code Stable machine code.
	 * @param string $message Human-readable message (English source string).
	 * @param array<string,mixed> $context Optional contextual data.
	 *
	 * @return array<string,mixed> The finding.
	 */
	private function finding(string $severity, string $code, string $message, array $context = []): array {
		return [
			'severity' => $severity,
			'code' => $code,
			'message' => $message,
			'context' => $context,
		];

	}//end finding()

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
