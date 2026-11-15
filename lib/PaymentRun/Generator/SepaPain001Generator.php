<?php

/**
 * SEPA Credit Transfer pain.001.001.03 generator
 *
 * Renders an approved PaymentRun into an ISO 20022 customer credit transfer
 * initiation document (pain.001.001.03) natively via XMLWriter — no XML DOM or
 * office library, and no new composer dependency. It emits the canonical
 * structure (REQ-SEPA-002): a GrpHdr (MsgId, CreDtTm, NbOfTxs, CtrlSum,
 * InitgPty), one PmtInf block (PmtInfId, PmtMtd=TRF, BtchBookg, ReqdExctnDt,
 * Dbtr/DbtrAcct[IBAN]/DbtrAgt), and one CdtTrfTxInf per payment line
 * (EndToEndId, InstdAmt[Ccy], CdtrAgt[BIC optional], Cdtr, CdtrAcct[IBAN],
 * RmtInf/Ustrd). NbOfTxs equals the line count; CtrlSum equals the run total.
 *
 * The EndToEndId of each transaction is the deterministic
 * <runNumber>-<lineIndex> value (1-based) that the reconciliation flow
 * (REQ-SEPA-007) matches the imported CAMT.053 statement entries back against.
 *
 * @category PaymentRun
 * @package  OCA\Shillinq\PaymentRun\Generator
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

namespace OCA\Shillinq\PaymentRun\Generator;

use OCA\Shillinq\PaymentRun\RenderedFile;
use XMLWriter;

/**
 * Native pain.001.001.03 SEPA Credit Transfer XML generator.
 */
final class SepaPain001Generator implements PaymentRunGeneratorInterface {

	/**
	 * The pain.001.001.03 namespace URI (SEPA Credit Transfer rulebook).
	 *
	 * @var string
	 */
	private const PAIN_NS = 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public static function format(): string {
		return 'sepa-pain001';
	}//end format()

	/**
	 * Render the approved PaymentRun into a pain.001.001.03 document.
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 *
	 * @return RenderedFile
	 */
	public function render(array $paymentRun): RenderedFile {
		$runNumber = (string)($paymentRun['runNumber'] ?? '');
		$document = new Pain001Document(
			writer: $this->openWriter(),
			runNumber: $runNumber,
			currency: $this->currency(paymentRun: $paymentRun)
		);
		$writer = $document->writer;

		$writer->startElementNs(null, 'Document', self::PAIN_NS);
		$writer->startElement('CstmrCdtTrfInitn');
		$this->writeGroupHeader(document: $document, paymentRun: $paymentRun);
		$this->writePaymentBlocks(document: $document, paymentRun: $paymentRun);
		$writer->endElement();
		// End CstmrCdtTrfInitn.
		$writer->endElement();
		// End Document.
		$writer->endDocument();

		$stem = 'payment-run';
		if ($runNumber !== '') {
			$stem = $runNumber;
		}

		return new RenderedFile(
			fileName: $stem . '.pain001.xml',
			mimeType: 'text/xml',
			format: self::format(),
			content: $writer->outputMemory(),
		);

	}//end render()

	/**
	 * A memory XML writer, indented by two spaces, with the document started.
	 *
	 * @return XMLWriter
	 */
	private function openWriter(): XMLWriter {
		$writer = new XMLWriter();
		$writer->openMemory();
		$writer->setIndent(true);
		$writer->setIndentString('  ');
		$writer->startDocument('1.0', 'UTF-8');
		return $writer;

	}//end openWriter()

	/**
	 * Write the GrpHdr: message id, creation time, count, control sum, initiator.
	 *
	 * @param Pain001Document      $document   The document being written.
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 *
	 * @return void
	 */
	private function writeGroupHeader(Pain001Document $document, array $paymentRun): void {
		$lines = $this->lines(paymentRun: $paymentRun);
		$writer = $document->writer;
		$writer->startElement('GrpHdr');
		$writer->writeElement('MsgId', $this->messageId(paymentRun: $paymentRun));
		$writer->writeElement('CreDtTm', gmdate('Y-m-d\TH:i:s'));
		$writer->writeElement('NbOfTxs', (string)count($lines));
		$writer->writeElement('CtrlSum', $this->money(value: $this->controlSum(paymentRun: $paymentRun, lines: $lines)));
		$writer->startElement('InitgPty');
		$writer->writeElement('Nm', $this->initiatorName(paymentRun: $paymentRun));
		$writer->endElement();
		// End InitgPty.
		$writer->endElement();
		// End GrpHdr.
	}//end writeGroupHeader()

	/**
	 * Write one PmtInf block per requested execution date (REQ-BPR-006).
	 *
	 * @param Pain001Document      $document   The document being written.
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 *
	 * @return void
	 */
	private function writePaymentBlocks(Pain001Document $document, array $paymentRun): void {
		$runDate = (string)($paymentRun['executionDate'] ?? gmdate('Y-m-d'));
		$groups = $this->groupByExecutionDate(lines: $this->lines(paymentRun: $paymentRun), runDate: $runDate);
		$ids = $this->paymentInfoIds(document: $document, blockCount: count($groups));
		foreach (array_keys($groups) as $position => $date) {
			$this->writePaymentBlock(
				document: $document,
				block: [
					'id' => $ids[$position],
					'date' => (string)$date,
					'lines' => $groups[$date],
					'initiator' => $this->initiatorName(paymentRun: $paymentRun),
					'debtorIban' => (string)($paymentRun['debtorAccountIban'] ?? ''),
				]
			);
		}

	}//end writePaymentBlocks()

	/**
	 * The PmtInfId of each block: the run number (PMT without one), numbered
	 * from 1 when there is more than one block.
	 *
	 * @param Pain001Document $document   The document being written.
	 * @param int             $blockCount The number of blocks.
	 *
	 * @return list<string>
	 */
	private function paymentInfoIds(Pain001Document $document, int $blockCount): array {
		$stem = 'PMT';
		if ($document->runNumber !== '') {
			$stem = $document->runNumber;
		}

		if ($blockCount <= 1) {
			return array_fill(0, $blockCount, $stem);
		}

		return array_map(static fn (int $number): string => $stem . '-' . $number, range(1, $blockCount));

	}//end paymentInfoIds()

	/**
	 * Write a single CdtTrfTxInf transaction block for one payment line.
	 *
	 * @param Pain001Document                                $document The document being written, positioned inside PmtInf.
	 * @param array{index: int, line: array<string, mixed>} $entry    The 1-based line index (EndToEndId suffix) and the line.
	 *
	 * @return void
	 */
	private function writeTransaction(Pain001Document $document, array $entry): void {
		$writer = $document->writer;
		$line = $entry['line'];
		$stem = 'PR';
		if ($document->runNumber !== '') {
			$stem = $document->runNumber;
		}

		$writer->startElement('CdtTrfTxInf');
		$writer->startElement('PmtId');
		$writer->writeElement('EndToEndId', $stem . '-' . (int)$entry['index']);
		$writer->endElement();
		// End PmtId.
		$this->writeAmount(document: $document, amount: $this->toFloat(value: ($line['amount'] ?? 0)));
		$this->writeCreditor(writer: $writer, line: $line);
		$writer->endElement();
		// End CdtTrfTxInf.
	}//end writeTransaction()

	/**
	 * Write the instructed amount in the run currency.
	 *
	 * @param Pain001Document $document The document being written.
	 * @param float           $amount   The amount.
	 *
	 * @return void
	 */
	private function writeAmount(Pain001Document $document, float $amount): void {
		$writer = $document->writer;
		$writer->startElement('Amt');
		$writer->startElement('InstdAmt');
		$writer->writeAttribute('Ccy', $document->currency);
		$writer->text($this->money(value: $amount));
		$writer->endElement();
		$writer->endElement();
		// End Amt.
	}//end writeAmount()

	/**
	 * Write the creditor's agent (only with a BIC), name, account and the
	 * remittance information (only when present).
	 *
	 * @param XMLWriter            $writer The open writer.
	 * @param array<string, mixed> $line   The payment line.
	 *
	 * @return void
	 */
	private function writeCreditor(XMLWriter $writer, array $line): void {
		$this->writeCreditorAgent(writer: $writer, line: $line);
		$writer->startElement('Cdtr');
		$writer->writeElement('Nm', (string)($line['payeeName'] ?? ''));
		$writer->endElement();
		$writer->startElement('CdtrAcct');
		$this->writeIbanId(writer: $writer, iban: (string)($line['creditorIban'] ?? ''));
		$writer->endElement();
		// End CdtrAcct.
		$this->writeRemittance(writer: $writer, line: $line);

	}//end writeCreditor()

	/**
	 * Write the creditor's agent when the line carries a BIC (optional in pain.001).
	 *
	 * @param XMLWriter            $writer The open writer.
	 * @param array<string, mixed> $line   The payment line.
	 *
	 * @return void
	 */
	private function writeCreditorAgent(XMLWriter $writer, array $line): void {
		$bic = trim((string)($line['creditorBic'] ?? ''));
		if ($bic === '') {
			return;
		}

		$writer->startElement('CdtrAgt');
		$writer->startElement('FinInstnId');
		$writer->writeElement('BIC', $bic);
		$writer->endElement();
		$writer->endElement();

	}//end writeCreditorAgent()

	/**
	 * Write the unstructured remittance information when the line carries it.
	 *
	 * @param XMLWriter            $writer The open writer.
	 * @param array<string, mixed> $line   The payment line.
	 *
	 * @return void
	 */
	private function writeRemittance(XMLWriter $writer, array $line): void {
		$remit = (string)($line['remittanceInfo'] ?? '');
		if ($remit === '') {
			return;
		}

		$writer->startElement('RmtInf');
		$writer->writeElement('Ustrd', $remit);
		$writer->endElement();

	}//end writeRemittance()

	/**
	 * Write an account's Id/IBAN.
	 *
	 * @param XMLWriter $writer The open writer, inside the account element.
	 * @param string    $iban   The IBAN.
	 *
	 * @return void
	 */
	private function writeIbanId(XMLWriter $writer, string $iban): void {
		$writer->startElement('Id');
		$writer->writeElement('IBAN', $iban);
		$writer->endElement();

	}//end writeIbanId()

	/**
	 * The payment lines of the run as a 0-indexed list.
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function lines(array $paymentRun): array {
		$lines = ($paymentRun['paymentLines'] ?? []);
		if (is_array($lines) === false) {
			return [];
		}

		return array_values($lines);
	}//end lines()

	/**
	 * Resolve the message id (SAFE placeholder default in fixtures).
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 *
	 * @return string
	 */
	private function messageId(array $paymentRun): string {
		$runNumber = (string)($paymentRun['runNumber'] ?? '');
		if ($runNumber === '') {
			return 'MSGID-PLACEHOLDER';
		}

		return $runNumber . '-' . gmdate('YmdHis');
	}//end messageId()

	/**
	 * The initiating party / debtor display name.
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 *
	 * @return string
	 */
	private function initiatorName(array $paymentRun): string {
		$name = trim((string)($paymentRun['initiatorName'] ?? $paymentRun['administrationName'] ?? ''));
		if ($name !== '') {
			return $name;
		}

		$admin = trim((string)($paymentRun['administrationId'] ?? ''));
		if ($admin !== '') {
			return $admin;
		}

		return 'Shillinq administration';
	}//end initiatorName()

	/**
	 * The run currency (EUR default per T2 scope).
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 *
	 * @return string
	 */
	private function currency(array $paymentRun): string {
		$currency = strtoupper(trim((string)($paymentRun['currency'] ?? 'EUR')));
		if ($currency !== '') {
			return $currency;
		}

		return 'EUR';
	}//end currency()

	/**
	 * The control sum: the run totalAmount when set, else the sum of line amounts.
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 * @param array<int, array<string, mixed>> $lines The payment lines.
	 *
	 * @return float
	 */
	private function controlSum(array $paymentRun, array $lines): float {
		if (isset($paymentRun['totalAmount']) === true && is_numeric($paymentRun['totalAmount']) === true) {
			return round((float)$paymentRun['totalAmount'], 2);
		}

		$sum = 0.0;
		foreach ($lines as $line) {
			$sum += $this->toFloat(value: ($line['amount'] ?? 0));
		}

		return round($sum, 2);
	}//end controlSum()

	/**
	 * Format a monetary amount to a fixed 2-decimal string (ISO 20022 amount).
	 *
	 * @param float $value The amount.
	 *
	 * @return string
	 */
	private function money(float $value): string {
		return number_format($value, 2, '.', '');
	}//end money()

	/**
	 * Coerce a mixed stored value to float.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return float
	 */
	private function toFloat(mixed $value): float {
		if (is_float($value) === true || is_int($value) === true) {
			return (float)$value;
		}

		return (float)($value ?? 0);
	}//end toFloat()

	/**
	 * Group the lines by requested execution date, in first-seen order.
	 *
	 * A line without a requested date takes the run's date, so a run whose
	 * lines share one date renders one block, as before.
	 *
	 * @param array<int, array<string, mixed>> $lines   The payment lines.
	 * @param string                           $runDate The run's execution date.
	 *
	 * @return array<string, array<int, array{index: int, line: array<string, mixed>}>>
	 */
	private function groupByExecutionDate(array $lines, string $runDate): array {
		$groups = [];
		foreach ($lines as $position => $line) {
			$date = trim((string)($line['requestedExecutionDate'] ?? ''));
			if ($date === '') {
				$date = $runDate;
			}

			$groups[$date][] = ['index' => ($position + 1), 'line' => $line];
		}

		return $groups;
	}//end groupByExecutionDate()

	/**
	 * Write one PmtInf block with its own count, control sum and date.
	 *
	 * @param Pain001Document      $document The document being written.
	 * @param array<string, mixed> $block    id, date, lines (index + line), initiator, debtorIban.
	 *
	 * @return void
	 */
	private function writePaymentBlock(Pain001Document $document, array $block): void {
		$writer = $document->writer;
		$writer->startElement('PmtInf');
		$this->writePaymentBlockHeader(writer: $writer, block: $block);
		$this->writeDebtor(writer: $writer, block: $block);
		foreach ($block['lines'] as $entry) {
			$this->writeTransaction(document: $document, entry: $entry);
		}

		$writer->endElement();
		// End PmtInf.
	}//end writePaymentBlock()

	/**
	 * Write the block's id, method, booking, count, control sum and date.
	 *
	 * @param XMLWriter            $writer The open writer, inside PmtInf.
	 * @param array<string, mixed> $block  The block.
	 *
	 * @return void
	 */
	private function writePaymentBlockHeader(XMLWriter $writer, array $block): void {
		$sum = 0.0;
		foreach ($block['lines'] as $entry) {
			$sum += $this->toFloat(value: ($entry['line']['amount'] ?? 0));
		}

		$writer->writeElement('PmtInfId', (string)$block['id']);
		$writer->writeElement('PmtMtd', 'TRF');
		$writer->writeElement('BtchBookg', 'true');
		$writer->writeElement('NbOfTxs', (string)count($block['lines']));
		$writer->writeElement('CtrlSum', $this->money(value: round($sum, 2)));
		$writer->writeElement('ReqdExctnDt', (string)$block['date']);

	}//end writePaymentBlockHeader()

	/**
	 * Write the debtor, its account and its (not provided) agent.
	 *
	 * @param XMLWriter            $writer The open writer, inside PmtInf.
	 * @param array<string, mixed> $block  The block.
	 *
	 * @return void
	 */
	private function writeDebtor(XMLWriter $writer, array $block): void {
		$writer->startElement('Dbtr');
		$writer->writeElement('Nm', (string)$block['initiator']);
		$writer->endElement();
		$writer->startElement('DbtrAcct');
		$this->writeIbanId(writer: $writer, iban: (string)$block['debtorIban']);
		$writer->endElement();
		// End DbtrAcct.
		// pain.001.001.03 wants Othr/Id; a bare Othr text node fails the XSD.
		$writer->startElement('DbtrAgt');
		$writer->startElement('FinInstnId');
		$writer->startElement('Othr');
		$writer->writeElement('Id', 'NOTPROVIDED');
		$writer->endElement();
		$writer->endElement();
		$writer->endElement();
		// End DbtrAgt.
	}//end writeDebtor()
}//end class
