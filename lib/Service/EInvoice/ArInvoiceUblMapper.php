<?php

/**
 * NLCIUS UBL 2.1 Invoice Mapper
 *
 * Pure transformation of a persisted `ARInvoice` (plus its EN 16931 seller/buyer
 * identifiers and `invoiceLines`/`vatBreakdown` groups already carried on the
 * schema — see add-shillinq-invoice-lines.json) into a UBL 2.1 `Invoice` XML
 * document restricted to the NLCIUS customisation (REQ-EINV-001). Mirrors the
 * existing {@see \OCA\Shillinq\Service\PurchaseOrder\PeppolBisOrderMapper}
 * structure and reuses the CBC/CAC vocabulary already parsed by
 * {@see \OCA\Shillinq\Service\SupplierInvoiceService::parseUblInvoice()} so the
 * outbound and inbound UBL surfaces stay symmetric.
 *
 * The mapper is deliberately I/O-free: it consumes the ARInvoice record (with
 * euro-float monetary fields, as persisted by this app — never integer cents)
 * and returns an XML string. It never touches OpenRegister or the transmission
 * port directly.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\EInvoice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\EInvoice;

use RuntimeException;

/**
 * Stateless ARInvoice -> UBL 2.1 / NLCIUS Invoice document mapper.
 *
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 */
final class ArInvoiceUblMapper {
	/**
	 * NLCIUS customisation identifier (EN 16931 compliant, NEN NLCIUS v1.0).
	 *
	 * @var string
	 */
	public const CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:nen.nl:nlcius:v1.0';

	/**
	 * Peppol BIS Billing 3.0 profile identifier.
	 *
	 * @var string
	 */
	public const PROFILE_ID = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

	/**
	 * Lifecycle state an ARInvoice must be in before it may be rendered for
	 * transmission (REQ-EINV-001 scenario 2).
	 *
	 * @var string
	 */
	public const REQUIRED_LIFECYCLE_STATE = 'issued';

	/**
	 * Map a persisted ARInvoice into a NLCIUS-restricted UBL 2.1 Invoice XML string.
	 *
	 * @param array<string,mixed> $arInvoice The persisted ARInvoice record (EN 16931
	 *                                       seller/buyer fields + invoiceLines +
	 *                                       vatBreakdown from add-shillinq-invoice-lines.json).
	 *
	 * @return string The UBL 2.1 Invoice XML document.
	 *
	 * @throws RuntimeException When the invoice is not in the required `issued`
	 *                          lifecycle state — no XML is produced (REQ-EINV-001).
	 *
	 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
	 */
	public function toNlciusXml(array $arInvoice): string {
		$lifecycleState = (string)($arInvoice['lifecycleState'] ?? '');
		if ($lifecycleState !== self::REQUIRED_LIFECYCLE_STATE) {
			throw new RuntimeException(
				'ARInvoice must be in lifecycleState "' . self::REQUIRED_LIFECYCLE_STATE . '" to render a NLCIUS document (got "' . $lifecycleState . '")'
			);
		}

		$invoiceNumber = (string)($arInvoice['invoiceNumber'] ?? '');
		$currency = (string)($arInvoice['currency'] ?? 'EUR');
		$netAmount = (float)($arInvoice['netAmount'] ?? 0);
		$vatAmount = (float)($arInvoice['vatAmount'] ?? 0);
		$grossAmount = (float)($arInvoice['grossAmount'] ?? 0);

		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		$xml .= '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
			. ' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
			. ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">';

		$xml .= $this->element(name: 'cbc:CustomizationID', value: self::CUSTOMIZATION_ID);
		$xml .= $this->element(name: 'cbc:ProfileID', value: self::PROFILE_ID);
		$xml .= $this->element(name: 'cbc:ID', value: $invoiceNumber);
		$xml .= $this->element(name: 'cbc:IssueDate', value: (string)($arInvoice['invoiceDate'] ?? ''));
		$xml .= $this->element(name: 'cbc:DueDate', value: (string)($arInvoice['dueDate'] ?? ''));
		$xml .= $this->element(
			name: 'cbc:InvoiceTypeCode',
			value: (string)($arInvoice['invoiceTypeCode'] ?? '380')
		);
		$xml .= $this->element(name: 'cbc:DocumentCurrencyCode', value: $currency);

		$xml .= $this->supplierParty(arInvoice: $arInvoice);
		$xml .= $this->customerParty(arInvoice: $arInvoice);

		$paymentTerms = trim((string)($arInvoice['paymentTerms'] ?? ''));
		if ($paymentTerms !== '') {
			$xml .= '<cac:PaymentTerms>' . $this->element(name: 'cbc:Note', value: $paymentTerms) . '</cac:PaymentTerms>';
		}

		$xml .= $this->taxTotal(arInvoice: $arInvoice, currency: $currency, vatAmount: $vatAmount);

		$xml .= '<cac:LegalMonetaryTotal>';
		$xml .= $this->amount(name: 'cbc:LineExtensionAmount', value: $netAmount, currency: $currency);
		$xml .= $this->amount(name: 'cbc:TaxExclusiveAmount', value: $netAmount, currency: $currency);
		$xml .= $this->amount(name: 'cbc:TaxInclusiveAmount', value: $grossAmount, currency: $currency);
		$xml .= $this->amount(name: 'cbc:PayableAmount', value: $grossAmount, currency: $currency);
		$xml .= '</cac:LegalMonetaryTotal>';

		$lines = (array)($arInvoice['invoiceLines'] ?? []);
		$index = 0;
		foreach ($lines as $line) {
			if (is_array($line) === false) {
				continue;
			}

			$index++;
			$xml .= $this->renderLine(line: $line, currency: $currency, fallbackLineNumber: $index);
		}

		$xml .= '</Invoice>';

		return $xml;
	}//end toNlciusXml()

	/**
	 * Render the AccountingSupplierParty (seller) block.
	 *
	 * @param array<string,mixed> $arInvoice The ARInvoice record.
	 *
	 * @return string
	 */
	private function supplierParty(array $arInvoice): string {
		$name = (string)($arInvoice['sellerName'] ?? '');
		$identifier = (string)($arInvoice['sellerIdentifier'] ?? '');
		$vatId = (string)($arInvoice['sellerVatId'] ?? '');
		$taxRegId = (string)($arInvoice['sellerTaxRegId'] ?? '');
		$address = (string)($arInvoice['sellerAddress'] ?? '');
		$countryCode = (string)($arInvoice['sellerCountryCode'] ?? 'NL');

		$xml = '<cac:AccountingSupplierParty><cac:Party>';
		if ($identifier !== '') {
			$xml .= $this->element(name: 'cbc:EndpointID', value: $identifier);
		}

		$xml .= '<cac:PartyName>' . $this->element(name: 'cbc:Name', value: $name) . '</cac:PartyName>';
		if ($address !== '') {
			$xml .= '<cac:PostalAddress>'
				. $this->element(name: 'cbc:StreetName', value: $address)
				. '<cac:Country>' . $this->element(name: 'cbc:IdentificationCode', value: $countryCode) . '</cac:Country>'
				. '</cac:PostalAddress>';
		}

		if ($vatId !== '') {
			$xml .= '<cac:PartyTaxScheme>'
				. $this->element(name: 'cbc:CompanyID', value: $vatId)
				. '<cac:TaxScheme>' . $this->element(name: 'cbc:ID', value: 'VAT') . '</cac:TaxScheme>'
				. '</cac:PartyTaxScheme>';
		}

		$xml .= '<cac:PartyLegalEntity>' . $this->element(name: 'cbc:RegistrationName', value: $name);
		if ($taxRegId !== '') {
			$xml .= $this->element(name: 'cbc:CompanyID', value: $taxRegId);
		}

		$xml .= '</cac:PartyLegalEntity>';
		$xml .= '</cac:Party></cac:AccountingSupplierParty>';

		return $xml;
	}//end supplierParty()

	/**
	 * Render the AccountingCustomerParty (debtor/buyer) block.
	 *
	 * @param array<string,mixed> $arInvoice The ARInvoice record.
	 *
	 * @return string
	 */
	private function customerParty(array $arInvoice): string {
		$customerId = (string)($arInvoice['customerId'] ?? '');
		$vatId = (string)($arInvoice['buyerVatId'] ?? '');
		$legalRegId = (string)($arInvoice['buyerLegalRegId'] ?? '');
		$address = (string)($arInvoice['buyerAddress'] ?? '');
		$participant = (string)($arInvoice['buyerPeppolParticipantId'] ?? '');

		$xml = '<cac:AccountingCustomerParty><cac:Party>';
		$endpointId = $participant;
		if ($endpointId === '') {
			$endpointId = $customerId;
		}

		if ($endpointId !== '') {
			$xml .= $this->element(name: 'cbc:EndpointID', value: $endpointId);
		}

		$xml .= '<cac:PartyName>' . $this->element(name: 'cbc:Name', value: $customerId) . '</cac:PartyName>';
		if ($address !== '') {
			$xml .= '<cac:PostalAddress>' . $this->element(name: 'cbc:StreetName', value: $address) . '</cac:PostalAddress>';
		}

		if ($vatId !== '') {
			$xml .= '<cac:PartyTaxScheme>'
				. $this->element(name: 'cbc:CompanyID', value: $vatId)
				. '<cac:TaxScheme>' . $this->element(name: 'cbc:ID', value: 'VAT') . '</cac:TaxScheme>'
				. '</cac:PartyTaxScheme>';
		}

		if ($legalRegId !== '') {
			$xml .= '<cac:PartyLegalEntity>' . $this->element(name: 'cbc:CompanyID', value: $legalRegId) . '</cac:PartyLegalEntity>';
		}

		$xml .= '</cac:Party></cac:AccountingCustomerParty>';

		return $xml;
	}//end customerParty()

	/**
	 * Render the cac:TaxTotal block from vatAmount + the vatBreakdown groups.
	 *
	 * @param array<string,mixed> $arInvoice The ARInvoice record.
	 * @param string $currency ISO 4217 currency code.
	 * @param float $vatAmount Total VAT amount.
	 *
	 * @return string
	 */
	private function taxTotal(array $arInvoice, string $currency, float $vatAmount): string {
		$xml = '<cac:TaxTotal>';
		$xml .= $this->amount(name: 'cbc:TaxAmount', value: $vatAmount, currency: $currency);

		$breakdown = (array)($arInvoice['vatBreakdown'] ?? []);
		foreach ($breakdown as $group) {
			if (is_array($group) === false) {
				continue;
			}

			$category = (string)($group['category'] ?? 'S');
			$taxableAmount = (float)($group['taxableAmount'] ?? 0);
			$taxAmount = (float)($group['taxAmount'] ?? 0);
			$rate = $group['rate'] ?? null;

			$xml .= '<cac:TaxSubtotal>';
			$xml .= $this->amount(name: 'cbc:TaxableAmount', value: $taxableAmount, currency: $currency);
			$xml .= $this->amount(name: 'cbc:TaxAmount', value: $taxAmount, currency: $currency);
			$xml .= '<cac:TaxCategory>';
			$xml .= $this->element(name: 'cbc:ID', value: $category);
			if ($rate !== null) {
				$xml .= $this->element(name: 'cbc:Percent', value: $this->number(value: (float)$rate));
			}

			$xml .= '<cac:TaxScheme>' . $this->element(name: 'cbc:ID', value: 'VAT') . '</cac:TaxScheme>';
			$xml .= '</cac:TaxCategory>';
			$xml .= '</cac:TaxSubtotal>';
		}//end foreach

		$xml .= '</cac:TaxTotal>';

		return $xml;
	}//end taxTotal()

	/**
	 * Render one cac:InvoiceLine from an `invoiceLines[]` entry.
	 *
	 * @param array<string,mixed> $line One EN 16931 invoice line
	 *                                  (add-shillinq-invoice-lines.json shape).
	 * @param string $currency ISO 4217 currency code.
	 * @param int $fallbackLineNumber Position in the document (1-based),
	 *                                used when the line carries no lineId.
	 *
	 * @return string
	 */
	private function renderLine(array $line, string $currency, int $fallbackLineNumber): string {
		$lineId = trim((string)($line['lineId'] ?? ''));
		if ($lineId === '') {
			$lineId = (string)$fallbackLineNumber;
		}

		$quantity = (float)($line['quantity'] ?? 0);
		$unitCode = (string)($line['unitCode'] ?? 'EA');
		$netAmount = (float)($line['netAmount'] ?? 0);
		$itemName = (string)($line['itemName'] ?? '');
		$netPrice = (float)($line['netPrice'] ?? 0);
		$vatCat = (string)($line['vatCategory'] ?? 'S');
		$vatRate = $line['vatRate'] ?? null;

		$xml = '<cac:InvoiceLine>';
		$xml .= $this->element(name: 'cbc:ID', value: $lineId);
		$xml .= '<cbc:InvoicedQuantity unitCode="' . $this->attr(value: $unitCode) . '">'
			. $this->number(value: $quantity)
			. '</cbc:InvoicedQuantity>';
		$xml .= $this->amount(name: 'cbc:LineExtensionAmount', value: $netAmount, currency: $currency);

		$xml .= '<cac:Item>';
		$xml .= $this->element(name: 'cbc:Name', value: $itemName);
		$xml .= '<cac:ClassifiedTaxCategory>';
		$xml .= $this->element(name: 'cbc:ID', value: $vatCat);
		if ($vatRate !== null) {
			$xml .= $this->element(name: 'cbc:Percent', value: $this->number(value: ((float)$vatRate * 100)));
		}

		$xml .= '<cac:TaxScheme>' . $this->element(name: 'cbc:ID', value: 'VAT') . '</cac:TaxScheme>';
		$xml .= '</cac:ClassifiedTaxCategory>';
		$xml .= '</cac:Item>';

		$xml .= '<cac:Price>' . $this->amount(name: 'cbc:PriceAmount', value: $netPrice, currency: $currency) . '</cac:Price>';
		$xml .= '</cac:InvoiceLine>';

		return $xml;
	}//end renderLine()

	/**
	 * Render a currency-qualified monetary element.
	 *
	 * @param string $name Qualified element name (e.g. cbc:PayableAmount).
	 * @param float $value Amount.
	 * @param string $currency ISO 4217 currency code.
	 *
	 * @return string
	 */
	private function amount(string $name, float $value, string $currency): string {
		return '<' . $name . ' currencyID="' . $this->attr(value: $currency) . '">' . $this->money(amount: $value) . '</' . $name . '>';
	}//end amount()

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
	 * @param float $amount Amount in the invoice currency.
	 *
	 * @return string
	 */
	private function money(float $amount): string {
		return number_format($amount, 2, '.', '');
	}//end money()

	/**
	 * Render a numeric value as a UBL-conform decimal string (up to 4 fraction
	 * digits, trimmed of trailing zeros).
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
