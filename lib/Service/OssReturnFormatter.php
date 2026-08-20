<?php

/**
 * OSS Return Payload Formatter
 *
 * ADR-031 exception-path service that renders a draft OssReturn into the
 * Belastingdienst OSS upload payloads (REQ-OSS-005): an XSD-shaped XML document and
 * a CSV fallback, both carrying the seller OSS-identifier, the period in YYYY-Qn
 * format, ISO 3166-1 alpha-2 country codes, EUR amounts with two decimals, and the
 * seller IBAN. The XML is built with DOMDocument (construction only — no parsing of
 * untrusted input, so no XXE surface) and entity-escaped. `canFinalize()` is the
 * lifecycle precondition that refuses finalisation when the registration is missing
 * or inactive (`oss.registration.invalid`).
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
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DOMDocument;

/**
 * Renders OssReturn records into XML / CSV upload payloads (REQ-OSS-005).
 *
 * Pure rendering logic with no persistence: the caller archives the returned
 * strings on the OssReturn record for the 10-year bewaarplicht (REQ-OSS-016).
 *
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 */
class OssReturnFormatter {
	/**
	 * The OSS VAT return XML namespace this formatter targets.
	 *
	 * @var string
	 */
	private const NS = 'urn:belastingdienst:oss:vat-return:v1';

	/**
	 * Lifecycle precondition: may a draft return be finalised (REQ-OSS-005)?
	 *
	 * Permits finalisation only when an OSS registration is present and its status
	 * is active or voluntaryBelowThreshold; otherwise the caller refuses with
	 * `oss.registration.invalid`. Also requires at least the period + identifier.
	 *
	 * @param array<string,mixed> $ossReturn Draft OssReturn object array.
	 * @param array<string,mixed> $registration The OssRegistration the return belongs to.
	 *
	 * @return bool True when finalisation is permitted.
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function canFinalize(array $ossReturn, array $registration): bool {
		$status = (string)($registration['registrationStatus'] ?? '');
		$activeStatuses = ['active', 'voluntaryBelowThreshold'];
		if (in_array($status, $activeStatuses, true) === false) {
			return false;
		}

		if (empty($registration['ossIdentifier']) === true) {
			return false;
		}

		return empty($ossReturn['periodYear']) === false && empty($ossReturn['periodQuarter']) === false;
	}//end canFinalize()

	/**
	 * Format a money amount as a EUR string with exactly two decimals (REQ-OSS-005).
	 *
	 * @param mixed $amount Money amount.
	 *
	 * @return string Amount with two decimal places, dot separator.
	 */
	private function money(mixed $amount): string {
		return number_format((float)($amount ?? 0), 2, '.', '');
	}//end money()

	/**
	 * Render a draft OssReturn as an XSD-shaped XML payload (REQ-OSS-005).
	 *
	 * Built entirely with DOMDocument node construction — no XML is parsed, so there
	 * is no XXE attack surface. Country codes, the OSS-identifier, the period, EUR
	 * amounts (two decimals) and the seller IBAN are emitted per the Belastingdienst
	 * OSS upload specification.
	 *
	 * @param array<string,mixed> $ossReturn The draft OssReturn to render.
	 * @param string $ossId Seller OSS-identifier from the registration.
	 * @param string $sellerIban Seller IBAN for refund routing.
	 *
	 * @return string The XML payload.
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function toXml(array $ossReturn, string $ossId, string $sellerIban): string {
		$doc = new DOMDocument('1.0', 'UTF-8');
		$doc->formatOutput = true;

		$root = $doc->createElementNS(self::NS, 'OSSVATReturn');
		$doc->appendChild($root);

		$period = (string)($ossReturn['periodYear'] ?? '') . '-' . (string)($ossReturn['periodQuarter'] ?? '');
		$root->appendChild($doc->createElement('OSSIdentifier', htmlspecialchars($ossId, ENT_XML1)));
		$root->appendChild($doc->createElement('Period', htmlspecialchars($period, ENT_XML1)));
		$root->appendChild($doc->createElement('Type', htmlspecialchars((string)($ossReturn['type'] ?? 'regular'), ENT_XML1)));
		$root->appendChild($doc->createElement('SellerIBAN', htmlspecialchars($sellerIban, ENT_XML1)));

		$lines = $doc->createElement('Lines');
		$root->appendChild($lines);
		foreach (($ossReturn['lineItems'] ?? []) as $line) {
			$node = $doc->createElement('Line');
			$node->appendChild($doc->createElement('CountryCode', htmlspecialchars((string)($line['countryCode'] ?? ''), ENT_XML1)));
			$node->appendChild($doc->createElement('RateCategory', htmlspecialchars((string)($line['rateCategory'] ?? ''), ENT_XML1)));
			$node->appendChild($doc->createElement('TaxableBase', $this->money(amount: ($line['taxableBase'] ?? 0))));
			$node->appendChild($doc->createElement('VatRate', $this->money(amount: ($line['vatRate'] ?? 0))));
			$node->appendChild($doc->createElement('VatAmount', $this->money(amount: ($line['vatAmount'] ?? 0))));
			$lines->appendChild($node);
		}

		$totals = $doc->createElement('Totals');
		$totals->appendChild($doc->createElement('TotalTaxableBase', $this->money(amount: ($ossReturn['totalTaxableBase'] ?? 0))));
		$totals->appendChild($doc->createElement('TotalVatAmount', $this->money(amount: ($ossReturn['totalVatAmount'] ?? 0))));
		$root->appendChild($totals);

		return (string)$doc->saveXML();
	}//end toXml()

	/**
	 * Render a draft OssReturn as a CSV fallback payload (REQ-OSS-005).
	 *
	 * One header row plus one row per (country, rate category) line, then a totals
	 * row. Amounts are EUR with two decimals; country codes are ISO 3166-1 alpha-2.
	 *
	 * @param array<string,mixed> $ossReturn The draft OssReturn to render.
	 * @param string $ossId Seller OSS-identifier.
	 *
	 * @return string The CSV payload.
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function toCsv(array $ossReturn, string $ossId): string {
		$period = (string)($ossReturn['periodYear'] ?? '') . '-' . (string)($ossReturn['periodQuarter'] ?? '');
		$rows = [];
		$rows[] = ['ossIdentifier', 'period', 'countryCode', 'rateCategory', 'taxableBase', 'vatRate', 'vatAmount'];
		foreach (($ossReturn['lineItems'] ?? []) as $line) {
			$rows[] = [
				$ossId,
				$period,
				(string)($line['countryCode'] ?? ''),
				(string)($line['rateCategory'] ?? ''),
				$this->money(amount: ($line['taxableBase'] ?? 0)),
				$this->money(amount: ($line['vatRate'] ?? 0)),
				$this->money(amount: ($line['vatAmount'] ?? 0)),
			];
		}

		$totalBase = $this->money(amount: ($ossReturn['totalTaxableBase'] ?? 0));
		$totalVat = $this->money(amount: ($ossReturn['totalVatAmount'] ?? 0));
		$rows[] = ['TOTAL', $period, '', '', $totalBase, '', $totalVat];

		$out = '';
		foreach ($rows as $row) {
			$escaped = array_map(
				static function ($cell): string {
					$value = (string)$cell;
					if (str_contains($value, ',') === true || str_contains($value, '"') === true) {
						return '"' . str_replace('"', '""', $value) . '"';
					}

					return $value;
				},
				$row
			);
			$out .= implode(',', $escaped) . "\n";
		}

		return $out;
	}//end toCsv()
}//end class
