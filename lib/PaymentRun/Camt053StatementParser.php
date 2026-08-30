<?php

/**
 * CAMT.053 bank-statement parser (payment-run reconciliation)
 *
 * Parses an ISO 20022 CAMT.053 (bank-to-customer account statement) document
 * natively via SimpleXML and extracts the booked OUTGOING credit-transfer
 * entries — the mirror image of the SepaPain001Generator. Each yielded entry
 * carries its EndToEndId (from Ntry/NtryDtls/TxDtls/Refs/EndToEndId), its amount
 * (the TxDtls amount when present, else the Ntry amount, with the Ccy
 * attribute), and its creditor IBAN (TxDtls/CdtrAcct/Id/IBAN). Only entries with
 * Sts=BOOK and CdtDbtInd=DBIT (money leaving the debtor account) are returned.
 *
 * Parsing is imperative file ingestion (ADR-031 explicit exception, justified
 * exactly like the pain.001 export generator). It uses only the bundled native
 * XML reader — no new composer dependency. Element access is XPath-only (the
 * namespace is stripped first) so no member-variable property access is needed.
 * All fixture values are SAFE placeholders (IBAN NL00BANK0123456789, EndToEndId
 * PR-2026-001-1, statement id STMT-PLACEHOLDER).
 *
 * @category PaymentRun
 * @package  OCA\Shillinq\PaymentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\PaymentRun;

use SimpleXMLElement;

/**
 * Deterministic native CAMT.053 booked-outgoing-entry parser.
 */
class Camt053StatementParser {
	/**
	 * Parse the booked outgoing entries of a CAMT.053 statement.
	 *
	 * Yields one entry per booked DBIT credit-transfer transaction.
	 *
	 * @param string $contents The raw CAMT.053 XML.
	 *
	 * @return array<int, array{endToEndId: string, amount: float, creditorIban: string, currency: string}>
	 */
	public function parse(string $contents): array {
		$contents = trim(string: $contents);
		if ($contents === '') {
			return [];
		}

		// Reparse with all namespaces stripped so plain (un-prefixed) XPath
		// works regardless of the statement's CAMT.053 minor version namespace.
		$clean = $this->stripNamespaces(contents: $contents);
		if ($clean === null) {
			return [];
		}

		$entries = [];
		$entryNodes = $clean->xpath(expression: '//Ntry');
		if (is_array(value: $entryNodes) === false) {
			return [];
		}

		foreach ($entryNodes as $ntry) {
			$status = strtoupper(string: trim(string: $this->firstString(node: $ntry, expression: './Sts')));
			$cdtDbt = strtoupper(string: trim(string: $this->firstString(node: $ntry, expression: './CdtDbtInd')));

			// Only booked outgoing transfers (money leaving the debtor account).
			if ($status !== 'BOOK' || $cdtDbt !== 'DBIT') {
				continue;
			}

			// A single Ntry may carry multiple TxDtls; emit one entry each.
			$txDetails = $ntry->xpath(expression: './/TxDtls');
			if (is_array(value: $txDetails) === true && $txDetails !== []) {
				foreach ($txDetails as $tx) {
					$entries[] = $this->mapTransaction(tx: $tx, ntry: $ntry);
				}

				continue;
			}

			// No TxDtls — fall back to the Ntry-level amount only.
			$entries[] = [
				'endToEndId' => '',
				'amount' => $this->amount(node: $ntry),
				'creditorIban' => '',
				'currency' => $this->currency(node: $ntry),
			];
		}//end foreach

		return $entries;
	}//end parse()

	/**
	 * Map one TxDtls (with its parent Ntry for amount fallback) to an entry.
	 *
	 * @param SimpleXMLElement $tx The TxDtls element.
	 * @param SimpleXMLElement $ntry The owning Ntry element (amount fallback).
	 *
	 * @return array{endToEndId: string, amount: float, creditorIban: string, currency: string}
	 */
	private function mapTransaction(SimpleXMLElement $tx, SimpleXMLElement $ntry): array {
		$endToEnd = trim(string: $this->firstString(node: $tx, expression: './/Refs/EndToEndId'));
		if (strtoupper(string: $endToEnd) === 'NOTPROVIDED') {
			$endToEnd = '';
		}

		$iban = trim(string: $this->firstString(node: $tx, expression: './/CdtrAcct/Id/IBAN'));

		// Prefer the TxDtls amount; fall back to the Ntry amount.
		$amount = $this->amount(node: $tx);
		$currency = $this->currency(node: $tx);
		if ($amount === 0.0) {
			$amount = $this->amount(node: $ntry);
			if ($currency === '') {
				$currency = $this->currency(node: $ntry);
			}
		}

		return [
			'endToEndId' => $endToEnd,
			'amount' => $amount,
			'creditorIban' => $iban,
			'currency' => $currency,
		];

	}//end mapTransaction()

	/**
	 * Read the (first) direct-child Amt value of an element as a float.
	 *
	 * @param SimpleXMLElement $node An Ntry or TxDtls element.
	 *
	 * @return float
	 */
	private function amount(SimpleXMLElement $node): float {
		$value = $this->firstString(node: $node, expression: './Amt');
		if ($value === '') {
			return 0.0;
		}

		return round(num: (float)$value, precision: 2);
	}//end amount()

	/**
	 * Read the Ccy attribute of the (first) direct-child Amt of an element.
	 *
	 * @param SimpleXMLElement $node An Ntry or TxDtls element.
	 *
	 * @return string
	 */
	private function currency(SimpleXMLElement $node): string {
		$amtNodes = $node->xpath(expression: './Amt');
		if (is_array(value: $amtNodes) === false || $amtNodes === []) {
			return '';
		}

		$attrs = $amtNodes[0]->attributes();
		if ($attrs !== null && isset($attrs['Ccy']) === true) {
			return strtoupper(string: trim(string: (string)$attrs['Ccy']));
		}

		return '';
	}//end currency()

	/**
	 * Return the trimmed string value of the first node matching an XPath, or ''.
	 *
	 * @param SimpleXMLElement $node The context element.
	 * @param string $expression The relative XPath expression.
	 *
	 * @return string
	 */
	private function firstString(SimpleXMLElement $node, string $expression): string {
		$found = $node->xpath(expression: $expression);
		if (is_array(value: $found) === false || $found === []) {
			return '';
		}

		return (string)$found[0];
	}//end firstString()

	/**
	 * Reparse the document with all namespace declarations + element prefixes
	 * removed so that plain (un-prefixed) XPath works regardless of the
	 * statement's CAMT.053 minor version namespace.
	 *
	 * @param string $contents The raw CAMT.053 XML.
	 *
	 * @return SimpleXMLElement|null
	 */
	private function stripNamespaces(string $contents): ?SimpleXMLElement {
		$stripped = preg_replace(pattern: '/\sxmlns(:\w+)?="[^"]*"/', replacement: '', subject: $contents);
		if (is_string(value: $stripped) === false) {
			return null;
		}

		$stripped = preg_replace(pattern: '/<(\/?)\w+:/', replacement: '<$1', subject: $stripped);
		if (is_string(value: $stripped) === false) {
			return null;
		}

		$previous = libxml_use_internal_errors(use_errors: true);
		$xml = simplexml_load_string(data: $stripped);
		libxml_clear_errors();
		libxml_use_internal_errors(use_errors: $previous);

		if ($xml === false) {
			return null;
		}

		return $xml;
	}//end stripNamespaces()
}//end class
