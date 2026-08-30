<?php

/**
 * Peppol BIS Ordering 3.0 Mapper
 *
 * Pure transformation of a persisted PurchaseOrder array into a UBL 2.1 Order
 * XML document conforming to the Peppol BIS Ordering 3.0 profile (REQ-PO3W-002).
 * The mapper is deliberately I/O-free: it consumes the PO record (with normalised
 * line items + euro-float monetary fields) and returns an XML string. The
 * transmission adapter is responsible for the HTTP submission and never sees
 * the PO record directly — the orchestration layer hands it the XML.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\PurchaseOrder
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PurchaseOrder;

/**
 * Stateless PO → UBL 2.1 Order document mapper (Peppol BIS Ordering 3.0).
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
 */
final class PeppolBisOrderMapper {

	/**
	 * Customisation identifier for the Peppol BIS Ordering 3.0 profile.
	 *
	 * @var string
	 */
	private const CUSTOMIZATION_ID = 'urn:fdc:peppol.eu:poacc:trns:order:3';

	/**
	 * Profile identifier for the Peppol BIS Ordering 3.0 profile.
	 *
	 * @var string
	 */
	private const PROFILE_ID = 'urn:fdc:peppol.eu:poacc:bis:order_only:3';

	/**
	 * Map a persisted PurchaseOrder into a UBL 2.1 Order XML string.
	 *
	 * The PO record is expected in the shape persisted by PurchaseOrderService
	 * (poNumber, supplierId, currency, lines[], totalAmount, costCenter,
	 * projectCode, notes). Missing optional fields fall back to empty UBL
	 * elements so the document still validates structurally.
	 *
	 * @param array<string,mixed> $purchaseOrder The persisted PurchaseOrder record.
	 * @param string $buyerParticipantId Buyer Peppol participant id (`scheme:identifier`).
	 * @param string $supplierParticipantId Supplier Peppol participant id (`scheme:identifier`).
	 * @param string $issueDate ISO date (Y-m-d) the order is issued.
	 *
	 * @return string The UBL 2.1 Order XML document.
	 */
	public function toUblOrderXml(
		array $purchaseOrder,
		string $buyerParticipantId,
		string $supplierParticipantId,
		string $issueDate,
	): string {
		$poNumber = (string)($purchaseOrder['poNumber'] ?? '');
		$currency = (string)($purchaseOrder['currency'] ?? 'EUR');
		$lines = (array)($purchaseOrder['lines'] ?? []);

		$totalAmount = (float)($purchaseOrder['totalAmount'] ?? 0);
		$notes = trim((string)($purchaseOrder['notes'] ?? ''));
		$costCenter = trim((string)($purchaseOrder['costCenter'] ?? ''));
		$projectCode = trim((string)($purchaseOrder['projectCode'] ?? ''));

		[$buyerScheme, $buyerId] = $this->splitParticipantId(participantId: $buyerParticipantId);
		[$supplierScheme, $supplierId] = $this->splitParticipantId(participantId: $supplierParticipantId);

		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		$xml .= '<Order xmlns="urn:oasis:names:specification:ubl:schema:xsd:Order-2"'
			. ' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
			. ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">';

		$xml .= $this->element(name: 'cbc:CustomizationID', value: self::CUSTOMIZATION_ID);
		$xml .= $this->element(name: 'cbc:ProfileID', value: self::PROFILE_ID);
		$xml .= $this->element(name: 'cbc:ID', value: $poNumber);
		$xml .= $this->element(name: 'cbc:IssueDate', value: $issueDate);
		$xml .= $this->element(name: 'cbc:OrderTypeCode', value: '220');

		if ($notes !== '') {
			$xml .= $this->element(name: 'cbc:Note', value: $notes);
		}

		$xml .= $this->element(name: 'cbc:DocumentCurrencyCode', value: $currency);

		// Buyer and seller parties (REQ-PO3W-002).
		$xml .= '<cac:BuyerCustomerParty><cac:Party>';
		$xml .= '<cbc:EndpointID schemeID="' . $this->attr(value: $buyerScheme) . '">'
			. $this->escape(value: $buyerId) . '</cbc:EndpointID>';
		$xml .= '<cac:PartyName><cbc:Name>Buyer</cbc:Name></cac:PartyName>';
		$xml .= '</cac:Party></cac:BuyerCustomerParty>';

		$xml .= '<cac:SellerSupplierParty><cac:Party>';
		$xml .= '<cbc:EndpointID schemeID="' . $this->attr(value: $supplierScheme) . '">'
			. $this->escape(value: $supplierId) . '</cbc:EndpointID>';
		$xml .= '<cac:PartyName><cbc:Name>Supplier</cbc:Name></cac:PartyName>';
		$xml .= '</cac:Party></cac:SellerSupplierParty>';

		// Cost-centre + project tags so the supplier can echo them on the
		// invoice (REQ-PO-010 — dimensional reporting carries through).
		if ($costCenter !== '' || $projectCode !== '') {
			$xml .= '<cac:Contract>';
			if ($costCenter !== '') {
				$xml .= $this->element(name: 'cbc:ID', value: $costCenter);
			}

			if ($projectCode !== '') {
				$xml .= '<cac:ContractDocumentReference>'
					. $this->element(name: 'cbc:ID', value: $projectCode)
					. '</cac:ContractDocumentReference>';
			}

			$xml .= '</cac:Contract>';
		}

		$xml .= '<cac:AnticipatedMonetaryTotal>';
		$xml .= '<cbc:PayableAmount currencyID="' . $this->attr(value: $currency) . '">'
			. $this->money(amount: $totalAmount)
			. '</cbc:PayableAmount>';
		$xml .= '</cac:AnticipatedMonetaryTotal>';

		foreach ($lines as $line) {
			if (is_array($line) === false) {
				continue;
			}

			$xml .= $this->renderLine(line: $line, currency: $currency);
		}

		$xml .= '</Order>';

		return $xml;
	}//end toUblOrderXml()

	/**
	 * Render one OrderLine.
	 *
	 * @param array<string,mixed> $line One normalised PO line entry.
	 * @param string $currency ISO 4217 currency code.
	 *
	 * @return string
	 */
	private function renderLine(array $line, string $currency): string {
		$lineNumber = (int)($line['lineNumber'] ?? 0);
		$productCode = (string)($line['productCode'] ?? '');
		$quantity = (float)($line['quantity'] ?? 0);
		$unitPrice = (float)($line['unitPrice'] ?? 0);
		$lineTotal = (float)($line['lineTotal'] ?? ($quantity * $unitPrice));

		$xml = '<cac:OrderLine>';
		$xml .= '<cac:LineItem>';
		$xml .= $this->element(name: 'cbc:ID', value: (string)$lineNumber);
		$xml .= '<cbc:Quantity unitCode="EA">' . $this->number(value: $quantity) . '</cbc:Quantity>';
		$xml .= '<cbc:LineExtensionAmount currencyID="' . $this->attr(value: $currency) . '">'
			. $this->money(amount: $lineTotal)
			. '</cbc:LineExtensionAmount>';

		$xml .= '<cac:Price>';
		$xml .= '<cbc:PriceAmount currencyID="' . $this->attr(value: $currency) . '">'
			. $this->money(amount: $unitPrice)
			. '</cbc:PriceAmount>';
		$xml .= '</cac:Price>';

		$xml .= '<cac:Item>';
		$xml .= '<cac:SellersItemIdentification>'
			. $this->element(name: 'cbc:ID', value: $productCode)
			. '</cac:SellersItemIdentification>';
		$xml .= $this->element(name: 'cbc:Name', value: $productCode);
		$xml .= '</cac:Item>';

		$xml .= '</cac:LineItem>';
		$xml .= '</cac:OrderLine>';

		return $xml;
	}//end renderLine()

	/**
	 * Split a Peppol participant id into (scheme, identifier).
	 *
	 * Accepts `0192:1234567890` or a bare id (which defaults to scheme `9999`).
	 *
	 * @param string $participantId Raw participant id.
	 *
	 * @return array{0:string,1:string}
	 */
	private function splitParticipantId(string $participantId): array {
		$participantId = trim($participantId);
		if ($participantId === '') {
			return ['9999', ''];
		}

		if (strpos($participantId, ':') === false) {
			return ['9999', $participantId];
		}

		[$scheme, $id] = explode(':', $participantId, 2);
		$scheme = trim($scheme);
		$id = trim($id);
		if ($scheme === '') {
			$scheme = '9999';
		}

		return [$scheme, $id];
	}//end splitParticipantId()

	/**
	 * Render a single text element with proper XML escaping.
	 *
	 * @param string $name Qualified element name (e.g. cbc:ID).
	 * @param string $value Text content.
	 *
	 * @return string
	 */
	private function element(string $name, string $value): string {
		return '<' . $name . '>' . $this->escape(value: $value) . '</' . $name . '>';
	}//end element()

	/**
	 * Escape a value for an XML attribute (entities + quotes).
	 *
	 * @param string $value Raw value.
	 *
	 * @return string
	 */
	private function attr(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
	}//end attr()

	/**
	 * Escape a value for an XML text node.
	 *
	 * @param string $value Raw value.
	 *
	 * @return string
	 */
	private function escape(string $value): string {
		return htmlspecialchars($value, ENT_NOQUOTES | ENT_XML1, 'UTF-8');
	}//end escape()

	/**
	 * Render a money amount as a UBL-conform decimal string (2 fraction digits).
	 *
	 * @param float $amount Amount in euro.
	 *
	 * @return string
	 */
	private function money(float $amount): string {
		return number_format($amount, 2, '.', '');
	}//end money()

	/**
	 * Render a numeric value as a UBL-conform decimal string (up to 4 fraction
	 * digits, trimmed of trailing zeros except the integer marker).
	 *
	 * @param float $value Numeric value.
	 *
	 * @return string
	 */
	private function number(float $value): string {
		$formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
		if ($formatted === '' || $formatted === '-') {
			return '0';
		}

		return $formatted;
	}//end number()
}//end class
