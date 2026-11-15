<?php

/**
 * SEPA Direct Debit pain.008.001.02 generator
 *
 * Renders a direct debit batch into an ISO 20022 customer direct debit
 * initiation document (pain.008.001.02) with XMLWriter, the way
 * SepaPain001Generator writes the credit transfer file: a GrpHdr (MsgId,
 * CreDtTm, NbOfTxs, CtrlSum, InitgPty), one PmtInf block for the batch's
 * scheme and sequence type (PmtMtd=DD, SvcLvl SEPA, LclInstrm CORE or B2B,
 * SeqTp, ReqdColltnDt, the creditor, its account, agent and creditor scheme
 * identifier) and one DrctDbtTxInf per collection (EndToEndId, InstdAmt,
 * mandate reference and signing date, debtor agent, debtor, debtor account,
 * remittance). NbOfTxs equals the collection count; CtrlSum equals the sum of
 * the collection amounts.
 *
 * @category Receivables
 * @package  OCA\Shillinq\Receivables
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
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-the-batch-is-written-as-a-validated-pain008-file-req-sdd-012
 */

declare(strict_types=1);

namespace OCA\Shillinq\Receivables;

use OCA\Shillinq\PaymentRun\RenderedFile;
use XMLWriter;

/**
 * Native pain.008.001.02 SEPA Direct Debit XML generator.
 *
 * A collection passed to render() is the DirectDebitCollection with two keys
 * added: `mandate` (its SepaMandate) and `remittanceInfo` (the text the debtor
 * sees, usually the invoice number).
 *
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-the-batch-is-written-as-a-validated-pain008-file-req-sdd-012
 */
final class SepaPain008Generator {

	/**
	 * The pain.008.001.02 namespace URI.
	 *
	 * @var string
	 */
	public const PAIN_NS = 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.02';

	/**
	 * The short format label of the rendered file.
	 *
	 * @var string
	 */
	public const FORMAT = 'sepa-pain008';

	/**
	 * The longest identifier the message carries (Max35Text).
	 *
	 * @var int
	 */
	private const MAX_ID = 35;

	/**
	 * The longest name the EPC rulebook allows (70 characters).
	 *
	 * @var int
	 */
	private const MAX_NAME = 70;

	/**
	 * The longest unstructured remittance text (Max140Text).
	 *
	 * @var int
	 */
	private const MAX_REMITTANCE = 140;

	/**
	 * Render a batch and its collections into a pain.008.001.02 document.
	 *
	 * @param array<string, mixed>             $batch       The DirectDebitBatch.
	 * @param list<array<string, mixed>>       $collections Its collections, each with `mandate` and `remittanceInfo`.
	 * @param array<string, string>            $creditor    name, iban, bic (optional) and creditorIdentifier.
	 *
	 * @return RenderedFile
	 *
	 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-1.2
	 */
	public function render(array $batch, array $collections, array $creditor): RenderedFile {
		$writer = new XMLWriter();
		$writer->openMemory();
		$writer->setIndent(true);
		$writer->setIndentString('  ');
		$writer->startDocument('1.0', 'UTF-8');

		$messageId = $this->identifier(value: (string)($batch['messageId'] ?? ''), fallback: 'DD-BATCH');
		$writer->startElementNs(null, 'Document', self::PAIN_NS);
		$writer->startElement('CstmrDrctDbtInitn');
		$this->writeGroupHeader(writer: $writer, batch: $batch, collections: $collections, creditor: $creditor);
		$this->writePaymentBlock(writer: $writer, batch: $batch, collections: $collections, creditor: $creditor);
		$writer->endElement();
		// End CstmrDrctDbtInitn.
		$writer->endElement();
		// End Document.
		$writer->endDocument();

		return new RenderedFile(
			fileName: $messageId . '.pain008.xml',
			mimeType: 'text/xml',
			format: self::FORMAT,
			content: $writer->outputMemory(),
		);

	}//end render()

	/**
	 * The sum of the collection amounts, rounded to cents.
	 *
	 * @param list<array<string, mixed>> $collections The collections.
	 *
	 * @return float
	 *
	 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-1.2
	 */
	public function controlSum(array $collections): float {
		$sum = 0.0;
		foreach ($collections as $collection) {
			$sum += (float)($collection['amount'] ?? 0);
		}

		return round($sum, 2);

	}//end controlSum()

	/**
	 * Write the GrpHdr.
	 *
	 * @param XMLWriter                  $writer      The open writer.
	 * @param array<string, mixed>       $batch       The batch.
	 * @param list<array<string, mixed>> $collections The collections.
	 * @param array<string, string>      $creditor    The creditor.
	 *
	 * @return void
	 */
	private function writeGroupHeader(XMLWriter $writer, array $batch, array $collections, array $creditor): void {
		$writer->startElement('GrpHdr');
		$writer->writeElement('MsgId', $this->identifier(value: (string)($batch['messageId'] ?? ''), fallback: 'DD-BATCH'));
		$writer->writeElement('CreDtTm', $this->creationTime(batch: $batch));
		$writer->writeElement('NbOfTxs', (string)count($collections));
		$writer->writeElement('CtrlSum', $this->money(value: $this->controlSum(collections: $collections)));
		$writer->startElement('InitgPty');
		$writer->writeElement('Nm', $this->name(value: (string)($creditor['name'] ?? '')));
		$writer->endElement();
		// End InitgPty.
		$writer->endElement();
		// End GrpHdr.
	}//end writeGroupHeader()

	/**
	 * Write the one PmtInf block of the batch.
	 *
	 * @param XMLWriter                  $writer      The open writer.
	 * @param array<string, mixed>       $batch       The batch.
	 * @param list<array<string, mixed>> $collections The collections.
	 * @param array<string, string>      $creditor    The creditor.
	 *
	 * @return void
	 */
	private function writePaymentBlock(XMLWriter $writer, array $batch, array $collections, array $creditor): void {
		$writer->startElement('PmtInf');
		$writer->writeElement('PmtInfId', $this->identifier(value: (string)($batch['messageId'] ?? ''), fallback: 'DD-BATCH'));
		$writer->writeElement('PmtMtd', 'DD');
		$writer->writeElement('BtchBookg', 'true');
		$writer->writeElement('NbOfTxs', (string)count($collections));
		$writer->writeElement('CtrlSum', $this->money(value: $this->controlSum(collections: $collections)));
		$this->writePaymentType(writer: $writer, batch: $batch);
		$writer->writeElement('ReqdColltnDt', substr((string)($batch['requestedCollectionDate'] ?? ''), 0, 10));
		$this->writeCreditor(writer: $writer, creditor: $creditor);
		foreach ($collections as $collection) {
			$this->writeTransaction(writer: $writer, collection: $collection);
		}

		$writer->endElement();
		// End PmtInf.
	}//end writePaymentBlock()

	/**
	 * Write PmtTpInf: service level SEPA, the scheme and the sequence type.
	 *
	 * @param XMLWriter            $writer The open writer.
	 * @param array<string, mixed> $batch  The batch.
	 *
	 * @return void
	 */
	private function writePaymentType(XMLWriter $writer, array $batch): void {
		$scheme = 'CORE';
		if ((string)($batch['scheme'] ?? '') === 'B2B') {
			$scheme = 'B2B';
		}

		$writer->startElement('PmtTpInf');
		$writer->startElement('SvcLvl');
		$writer->writeElement('Cd', 'SEPA');
		$writer->endElement();
		$writer->startElement('LclInstrm');
		$writer->writeElement('Cd', $scheme);
		$writer->endElement();
		$writer->writeElement('SeqTp', (string)($batch['sequenceType'] ?? 'RCUR'));
		$writer->endElement();
		// End PmtTpInf.
	}//end writePaymentType()

	/**
	 * Write the creditor, its account and agent, the charge bearer and the
	 * creditor scheme identifier.
	 *
	 * @param XMLWriter             $writer   The open writer.
	 * @param array<string, string> $creditor The creditor.
	 *
	 * @return void
	 */
	private function writeCreditor(XMLWriter $writer, array $creditor): void {
		$writer->startElement('Cdtr');
		$writer->writeElement('Nm', $this->name(value: (string)($creditor['name'] ?? '')));
		$writer->endElement();
		$writer->startElement('CdtrAcct');
		$this->writeIban(writer: $writer, iban: (string)($creditor['iban'] ?? ''));
		$writer->endElement();
		$writer->startElement('CdtrAgt');
		$this->writeAgent(writer: $writer, bic: (string)($creditor['bic'] ?? ''));
		$writer->endElement();
		$writer->writeElement('ChrgBr', 'SLEV');
		$writer->startElement('CdtrSchmeId');
		$writer->startElement('Id');
		$writer->startElement('PrvtId');
		$writer->startElement('Othr');
		$writer->writeElement('Id', $this->normalise(value: (string)($creditor['creditorIdentifier'] ?? '')));
		$writer->startElement('SchmeNm');
		$writer->writeElement('Prtry', 'SEPA');
		$writer->endElement();
		$writer->endElement();
		// End Othr.
		$writer->endElement();
		// End PrvtId.
		$writer->endElement();
		// End Id.
		$writer->endElement();
		// End CdtrSchmeId.
	}//end writeCreditor()

	/**
	 * Write one DrctDbtTxInf for a collection.
	 *
	 * @param XMLWriter            $writer     The open writer.
	 * @param array<string, mixed> $collection The collection with its `mandate` and `remittanceInfo`.
	 *
	 * @return void
	 */
	private function writeTransaction(XMLWriter $writer, array $collection): void {
		$mandate = ($collection['mandate'] ?? []);
		if (is_array($mandate) === false) {
			$mandate = [];
		}

		$writer->startElement('DrctDbtTxInf');
		$writer->startElement('PmtId');
		$writer->writeElement('EndToEndId', $this->identifier(value: (string)($collection['endToEndId'] ?? ''), fallback: 'NOTPROVIDED'));
		$writer->endElement();
		$writer->startElement('InstdAmt');
		$writer->writeAttribute('Ccy', strtoupper((string)($collection['currency'] ?? 'EUR')));
		$writer->text($this->money(value: round((float)($collection['amount'] ?? 0), 2)));
		$writer->endElement();
		$writer->startElement('DrctDbtTx');
		$writer->startElement('MndtRltdInf');
		$writer->writeElement('MndtId', $this->identifier(value: (string)($mandate['mandateReference'] ?? ''), fallback: 'NOTPROVIDED'));
		$writer->writeElement('DtOfSgntr', substr((string)($mandate['signedAt'] ?? ''), 0, 10));
		$writer->endElement();
		$writer->endElement();
		// End DrctDbtTx.
		$writer->startElement('DbtrAgt');
		$this->writeAgent(writer: $writer, bic: (string)($mandate['debtorBic'] ?? ''));
		$writer->endElement();
		$writer->startElement('Dbtr');
		$writer->writeElement('Nm', $this->name(value: (string)($mandate['debtorName'] ?? '')));
		$writer->endElement();
		$writer->startElement('DbtrAcct');
		$this->writeIban(writer: $writer, iban: (string)($mandate['debtorIban'] ?? ''));
		$writer->endElement();
		$this->writeRemittance(writer: $writer, text: (string)($collection['remittanceInfo'] ?? ''));
		$writer->endElement();
		// End DrctDbtTxInf.
	}//end writeTransaction()

	/**
	 * Write FinInstnId with the BIC, or Othr/Id NOTPROVIDED without one.
	 *
	 * @param XMLWriter $writer The open writer, inside the agent element.
	 * @param string    $bic    The BIC, or ''.
	 *
	 * @return void
	 */
	private function writeAgent(XMLWriter $writer, string $bic): void {
		$bic = $this->normalise(value: $bic);
		$writer->startElement('FinInstnId');
		if ($bic !== '') {
			$writer->writeElement('BIC', $bic);
			$writer->endElement();
			return;
		}

		$writer->startElement('Othr');
		$writer->writeElement('Id', 'NOTPROVIDED');
		$writer->endElement();
		$writer->endElement();

	}//end writeAgent()

	/**
	 * Write an account's Id/IBAN, upper-cased without spaces.
	 *
	 * @param XMLWriter $writer The open writer, inside the account element.
	 * @param string    $iban   The IBAN.
	 *
	 * @return void
	 */
	private function writeIban(XMLWriter $writer, string $iban): void {
		$writer->startElement('Id');
		$writer->writeElement('IBAN', $this->normalise(value: $iban));
		$writer->endElement();

	}//end writeIban()

	/**
	 * Write the unstructured remittance information when there is any.
	 *
	 * @param XMLWriter $writer The open writer.
	 * @param string    $text   The remittance text.
	 *
	 * @return void
	 */
	private function writeRemittance(XMLWriter $writer, string $text): void {
		$text = trim($text);
		if ($text === '') {
			return;
		}

		$writer->startElement('RmtInf');
		$writer->writeElement('Ustrd', mb_substr($text, 0, self::MAX_REMITTANCE));
		$writer->endElement();

	}//end writeRemittance()

	/**
	 * The batch's creation time as an ISO date time, else now in UTC.
	 *
	 * @param array<string, mixed> $batch The batch.
	 *
	 * @return string
	 */
	private function creationTime(array $batch): string {
		$stamp = trim((string)($batch['creationDateTime'] ?? ''));
		if ($stamp !== '') {
			return $stamp;
		}

		return gmdate('Y-m-d\TH:i:s');

	}//end creationTime()

	/**
	 * An identifier cut to 35 characters, or the fallback when empty.
	 *
	 * @param string $value    The identifier.
	 * @param string $fallback The value when it is empty.
	 *
	 * @return string
	 */
	private function identifier(string $value, string $fallback): string {
		$value = trim($value);
		if ($value === '') {
			return $fallback;
		}

		return substr($value, 0, self::MAX_ID);

	}//end identifier()

	/**
	 * A party name cut to 70 characters.
	 *
	 * @param string $value The name.
	 *
	 * @return string
	 */
	private function name(string $value): string {
		return mb_substr(trim($value), 0, self::MAX_NAME);

	}//end name()

	/**
	 * An IBAN, BIC or identifier upper-cased without whitespace.
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 */
	private function normalise(string $value): string {
		return strtoupper((string)preg_replace('/\s+/', '', $value));

	}//end normalise()

	/**
	 * Format a monetary amount with two decimals.
	 *
	 * @param float $value The amount.
	 *
	 * @return string
	 */
	private function money(float $value): string {
		return number_format($value, 2, '.', '');

	}//end money()
}//end class
