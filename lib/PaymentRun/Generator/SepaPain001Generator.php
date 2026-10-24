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
		$currency = $this->currency(paymentRun: $paymentRun);
		$initiator = $this->initiatorName(paymentRun: $paymentRun);
		$lines = $this->lines(paymentRun: $paymentRun);
		$executionDt = (string)($paymentRun['executionDate'] ?? gmdate('Y-m-d'));
		$controlSum = $this->money(value: $this->controlSum(paymentRun: $paymentRun, lines: $lines));
		$txCount = (string)count($lines);

		$writer = new XMLWriter();
		$writer->openMemory();
		$writer->setIndent(true);
		$writer->setIndentString('  ');
		$writer->startDocument('1.0', 'UTF-8');

		$writer->startElementNs(null, 'Document', self::PAIN_NS);
		$writer->startElement('CstmrCdtTrfInitn');

		// --- GrpHdr ---.
		$writer->startElement('GrpHdr');
		$writer->writeElement('MsgId', $this->messageId(paymentRun: $paymentRun));
		$writer->writeElement('CreDtTm', gmdate('Y-m-d\TH:i:s'));
		$writer->writeElement('NbOfTxs', $txCount);
		$writer->writeElement('CtrlSum', $controlSum);
		$writer->startElement('InitgPty');
		$writer->writeElement('Nm', $initiator);
		$writer->endElement();
		// End InitgPty.
		$writer->endElement();
		// End GrpHdr.
		// --- PmtInf: one block per requested execution date (REQ-BPR-006) ---.
		$groups = $this->groupByExecutionDate(lines: $lines, runDate: $executionDt);
		$blockIndex = 0;
		foreach ($groups as $date => $groupLines) {
			$blockIndex++;
			$paymentInfoId = 'PMT';
			if ($runNumber !== '') {
				$paymentInfoId = $runNumber;
			}

			if (count($groups) > 1) {
				$paymentInfoId .= '-' . $blockIndex;
			}

			$this->writePaymentBlock(
				writer: $writer,
				block: [
					'id' => $paymentInfoId,
					'date' => (string)$date,
					'lines' => $groupLines,
					'initiator' => $initiator,
					'debtorIban' => (string)($paymentRun['debtorAccountIban'] ?? ''),
				],
				runNumber: $runNumber,
				currency: $currency
			);
		}//end foreach

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
	 * Write a single CdtTrfTxInf transaction block for one payment line.
	 *
	 * @param XMLWriter $writer Open XML writer positioned inside PmtInf.
	 * @param string $runNumber The PaymentRun runNumber (EndToEndId stem).
	 * @param int $index 1-based line index (EndToEndId suffix).
	 * @param array<string, mixed> $line The payment line.
	 * @param string $currency ISO 4217 currency code.
	 *
	 * @return void
	 */
	private function writeTransaction(XMLWriter $writer, string $runNumber, int $index, array $line, string $currency): void {
		$stem = 'PR';
		if ($runNumber !== '') {
			$stem = $runNumber;
		}

		$endToEnd = $stem . '-' . $index;
		$amount = $this->toFloat(value: ($line['amount'] ?? 0));
		$payeeName = (string)($line['payeeName'] ?? '');
		$iban = (string)($line['creditorIban'] ?? '');
		$remit = (string)($line['remittanceInfo'] ?? '');
		$bic = trim((string)($line['creditorBic'] ?? ''));

		$writer->startElement('CdtTrfTxInf');

		$writer->startElement('PmtId');
		$writer->writeElement('EndToEndId', $endToEnd);
		$writer->endElement();
		// End PmtId.
		$writer->startElement('Amt');
		$writer->startElement('InstdAmt');
		$writer->writeAttribute('Ccy', $currency);
		$writer->text($this->money(value: $amount));
		$writer->endElement();
		$writer->endElement();
		// End Amt.
		// BIC is OPTIONAL in pain.001 — only emit CdtrAgt when present.
		if ($bic !== '') {
			$writer->startElement('CdtrAgt');
			$writer->startElement('FinInstnId');
			$writer->writeElement('BIC', $bic);
			$writer->endElement();
			$writer->endElement();
		}

		$writer->startElement('Cdtr');
		$writer->writeElement('Nm', $payeeName);
		$writer->endElement();

		$writer->startElement('CdtrAcct');
		$writer->startElement('Id');
		$writer->writeElement('IBAN', $iban);
		$writer->endElement();
		$writer->endElement();
		// End CdtrAcct.
		if ($remit !== '') {
			$writer->startElement('RmtInf');
			$writer->writeElement('Ustrd', $remit);
			$writer->endElement();
		}

		$writer->endElement();
		// End CdtTrfTxInf.
	}//end writeTransaction()

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
	 * @param XMLWriter            $writer    The open writer.
	 * @param array<string, mixed> $block     id, date, lines (index + line), initiator, debtorIban.
	 * @param string               $runNumber The run number for end-to-end ids.
	 * @param string               $currency  The run currency.
	 *
	 * @return void
	 */
	private function writePaymentBlock(XMLWriter $writer, array $block, string $runNumber, string $currency): void {
		$sum = 0.0;
		foreach ($block['lines'] as $entry) {
			$sum += $this->toFloat(value: ($entry['line']['amount'] ?? 0));
		}

		$writer->startElement('PmtInf');
		$writer->writeElement('PmtInfId', (string)$block['id']);
		$writer->writeElement('PmtMtd', 'TRF');
		$writer->writeElement('BtchBookg', 'true');
		$writer->writeElement('NbOfTxs', (string)count($block['lines']));
		$writer->writeElement('CtrlSum', $this->money(value: round($sum, 2)));
		$writer->writeElement('ReqdExctnDt', (string)$block['date']);

		$writer->startElement('Dbtr');
		$writer->writeElement('Nm', (string)$block['initiator']);
		$writer->endElement();

		$writer->startElement('DbtrAcct');
		$writer->startElement('Id');
		$writer->writeElement('IBAN', (string)$block['debtorIban']);
		$writer->endElement();
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
		foreach ($block['lines'] as $entry) {
			$this->writeTransaction(
				writer: $writer,
				runNumber: $runNumber,
				index: (int)$entry['index'],
				line: $entry['line'],
				currency: $currency
			);
		}

		$writer->endElement();
		// End PmtInf.
	}//end writePaymentBlock()
}//end class
