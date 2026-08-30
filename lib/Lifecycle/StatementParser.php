<?php

/**
 * Statement Parser
 *
 * ADR-031 exception-path lifecycle guard + parser for bank reconciliation.
 * Provides two single-purpose, stateless methods:
 *  - parse(): converts a CAMT.053 / MT940 / CSV bank-statement file into an
 *    array of normalised BankStatementLine field maps (REQ-BR-003).
 *  - allLinesResolved(): the FiscalPeriod/BankStatement reconciliation guard,
 *    referenced from the BankStatement schema's x-openregister-lifecycle
 *    transitions.complete-reconciliation.requires clause (REQ-BR-004).
 *
 * ADR-031 exception reason: OpenRegister's calculation extension does not yet
 * ship CAMT.053 / MT940 structured-text parsing primitives, and the
 * "no unmatched lines remain" precondition is a cross-schema aggregation
 * (COUNT of BankStatementLine where status='unmatched' for the statement) the
 * declarative lifecycle DSL cannot yet express inside a `requires:` clause.
 * When OR gains those capabilities, replace these references with declarative
 * calculations/conditions and delete this file.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md#req-br-003
 * @spec openspec/changes/add-shillinq-bank-reconciliation/tasks.md#task-10
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Bank statement parser + reconciliation-complete guard.
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md#req-br-003
 * @spec openspec/changes/add-shillinq-bank-reconciliation/tasks.md#task-10
 */
class StatementParser {
	/**
	 * Construct the parser with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Parse a bank-statement file into an array of normalised line field maps.
	 *
	 * Supports CAMT.053 (ISO 20022 XML), MT940 (SWIFT), and CSV. XML parsing is
	 * XXE-safe: external entity loading is disabled before parsing. Returns an
	 * empty array on any parse error (the caller reports the failure to the
	 * operator; no partial import).
	 *
	 * @param string $contents Raw file contents.
	 * @param string $format One of 'camt053', 'mt940', 'csv'.
	 *
	 * @return array<int,array<string,mixed>> Normalised BankStatementLine field maps.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md#req-br-003
	 * @spec openspec/changes/add-shillinq-bank-reconciliation/tasks.md#task-10
	 */
	public function parse(string $contents, string $format): array {
		try {
			switch ($format) {
				case 'camt053':
					return $this->parseCamt053(contents: $contents);
				case 'mt940':
					return $this->parseMt940(contents: $contents);
				case 'csv':
					return $this->parseCsv(contents: $contents);
				default:
					return [];
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				'StatementParser: parse failed',
				['format' => $format, 'exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end parse()

	/**
	 * Returns true iff no BankStatementLine for the statement is still unmatched (REQ-BR-004).
	 *
	 * Fail-closed: returns false on any exception so a statement can never be
	 * marked reconciled while lines remain unresolved (CWE-863).
	 *
	 * @param string $statementId The BankStatement.statementId to check.
	 *
	 * @return bool True when every line is matched or routed-to-suspense.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md#req-br-003
	 * @spec openspec/changes/add-shillinq-bank-reconciliation/tasks.md#task-10
	 */
	public function allLinesResolved(string $statementId): bool {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
			if ($register === '') {
				$register = 'shillinq';
			}

			$unmatched = $objectService
				->setRegister($register)
				->setSchema('BankStatementLine')
				->findAll(
					[
						'filters' => [
							'statementId' => $statementId,
							'status' => 'unmatched',
						],
						'limit' => 1,
					]
				);

			return empty($unmatched) === true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'StatementParser: reconciliation-complete check failed — denying (fail-closed)',
				['statementId' => $statementId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end allLinesResolved()

	/**
	 * Parse a CAMT.053 (ISO 20022) statement into normalised line maps.
	 *
	 * XXE-safe (defence in depth): inputs declaring a DOCTYPE or an ENTITY are
	 * rejected outright before parsing, and the XML is loaded with LIBXML_NONET
	 * only. The previous LIBXML_NOENT flag was REMOVED because it *enables*
	 * external/internal entity substitution (XXE risk, CWE-611); without it
	 * simplexml_load_string never expands declared entities, so a file:///…
	 * payload can never leak host file contents into a BankStatementLine.
	 *
	 * @param string $contents Raw CAMT.053 XML.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function parseCamt053(string $contents): array {
		// Reject any DOCTYPE/ENTITY declaration before touching the parser
		// (XXE hardening — fail closed with the empty-return discipline).
		if (stripos($contents, '<!DOCTYPE') !== false || stripos($contents, '<!ENTITY') !== false) {
			return [];
		}

		$previous = libxml_use_internal_errors(true);
		$xml = simplexml_load_string($contents, \SimpleXMLElement::class, LIBXML_NONET);
		libxml_use_internal_errors($previous);
		if ($xml === false) {
			return [];
		}

		$lines = [];
		foreach ($xml->xpath('//*[local-name()="Ntry"]') as $entry) {
			$amount = (float)((string)($entry->xpath('.//*[local-name()="Amt"]')[0] ?? '0'));
			$cdtDbt = (string)($entry->xpath('.//*[local-name()="CdtDbtInd"]')[0] ?? 'CRDT');
			if ($cdtDbt === 'DBIT') {
				$amount = (-1 * $amount);
			}

			$valueDate = (string)($entry->xpath('.//*[local-name()="ValDt"]/*[local-name()="Dt"]')[0] ?? '');
			$remit = (string)($entry->xpath('.//*[local-name()="RmtInf"]//*[local-name()="Ustrd"]')[0] ?? '');
			$e2e = (string)($entry->xpath('.//*[local-name()="EndToEndId"]')[0] ?? '');

			$lines[] = [
				'valueDate' => $valueDate,
				'amount' => $amount,
				'currency' => 'EUR',
				'remittanceInfo' => $remit,
				'endToEndRef' => $e2e,
				'status' => 'unmatched',
			];
		}//end foreach

		return $lines;
	}//end parseCamt053()

	/**
	 * Parse an MT940 (SWIFT) statement into normalised line maps.
	 *
	 * Reads :61: (statement line) and :86: (information) field pairs.
	 *
	 * @param string $contents Raw MT940 text.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function parseMt940(string $contents): array {
		$lines = [];
		$current = null;
		foreach (preg_split('/\r\n|\r|\n/', $contents) as $line) {
			if (str_starts_with($line, ':61:') === true) {
				if ($current !== null) {
					$lines[] = $current;
				}

				$current = $this->parseMt940StatementLine(body: substr($line, 4));
				continue;
			}

			if (str_starts_with($line, ':86:') === true && $current !== null) {
				$current['remittanceInfo'] = trim(substr($line, 4));
			}
		}//end foreach

		if ($current !== null) {
			$lines[] = $current;
		}

		return $lines;
	}//end parseMt940()

	/**
	 * Parse a single MT940 :61: statement-line body into a normalised line map.
	 *
	 * The body begins with a YYMMDD value date, then a C/D credit/debit mark,
	 * then the amount with a comma decimal separator (e.g. 260115C1210,00).
	 *
	 * @param string $body The :61: field body (the line with the tag stripped).
	 *
	 * @return array<string,mixed> Normalised BankStatementLine field map.
	 */
	private function parseMt940StatementLine(string $body): array {
		$valueDate = '';
		if (preg_match('/^(\d{6})/', $body, $matches) === 1) {
			$valueDate = '20' . substr($matches[1], 0, 2) . '-' . substr($matches[1], 2, 2) . '-' . substr($matches[1], 4, 2);
		}

		$sign = 1;
		$amount = 0.0;
		if (preg_match('/([CD])R?(\d+,\d{2})/', $body, $matches) === 1) {
			if ($matches[1] === 'D') {
				$sign = -1;
			}

			$amount = ((float)str_replace(',', '.', $matches[2]) * $sign);
		}

		return [
			'valueDate' => $valueDate,
			'amount' => $amount,
			'currency' => 'EUR',
			'remittanceInfo' => '',
			'status' => 'unmatched',
		];

	}//end parseMt940StatementLine()

	/**
	 * Parse a CSV statement (valueDate,amount,currency,remittanceInfo,counterpartyName,counterpartyIban).
	 *
	 * @param string $contents Raw CSV text (header row required).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function parseCsv(string $contents): array {
		$rows = array_filter(preg_split('/\r\n|\r|\n/', trim($contents)));
		if (count($rows) < 2) {
			return [];
		}

		$header = str_getcsv(array_shift($rows), escape: '\\');
		$lines = [];
		foreach ($rows as $row) {
			$cols = str_getcsv($row, escape: '\\');
			$map = [];
			foreach ($header as $i => $key) {
				$map[trim($key)] = ($cols[$i] ?? '');
			}

			$lines[] = [
				'valueDate' => ($map['valueDate'] ?? ''),
				'amount' => (float)($map['amount'] ?? 0),
				'currency' => ($map['currency'] ?? 'EUR'),
				'remittanceInfo' => ($map['remittanceInfo'] ?? ''),
				'counterpartyName' => ($map['counterpartyName'] ?? ''),
				'counterpartyIban' => ($map['counterpartyIban'] ?? ''),
				'status' => 'unmatched',
			];
		}//end foreach

		return $lines;
	}//end parseCsv()
}//end class
