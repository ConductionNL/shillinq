<?php

/**
 * Unit tests for SepaPain001Generator.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentRun
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
 * @spec openspec/changes/payment-run-sepa-export/specs/payment-run-sepa-export/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\PaymentRun;

use OCA\Shillinq\PaymentRun\Generator\SepaPain001Generator;
use PHPUnit\Framework\TestCase;

/**
 * Tests REQ-SEPA-001 / REQ-SEPA-002 — the pain.001.001.03 canonical structure.
 *
 * All fixture values are SAFE placeholders (IBAN NL00BANK0123456789 /
 * NL00TEST0222222222, BIC BANKNL2A, MsgId derived from runNumber).
 */
class SepaPain001GeneratorTest extends TestCase {
	/**
	 * The seeded approved PR-2026-001 fixture (SAFE placeholders).
	 *
	 * @return array<string, mixed>
	 */
	private function paymentRun(): array {
		return [
			'runNumber' => 'PR-2026-001',
			'administrationId' => 'adm-consultancy',
			'initiatorName' => 'Consultancy Demo B.V.',
			'executionDate' => '2026-07-01',
			'debtorAccountIban' => 'NL00BANK9999999999',
			'status' => 'approved',
			'lifecycleState' => 'approved',
			'totalAmount' => 1497.50,
			'currency' => 'EUR',
			'paymentLines' => [
				[
					'payeeName' => 'Eneco Energie B.V.',
					'creditorIban' => 'NL00BANK0123456789',
					'creditorBic' => 'BANKNL2A',
					'amount' => 892.50,
					'remittanceInfo' => 'ENECO-2026-04-0001',
					'apTransactionRef' => 'ap-txn-eneco-2026-04-0001',
				],
				[
					'payeeName' => 'Jan de Vries (ZZP)',
					'creditorIban' => 'NL00TEST0222222222',
					'amount' => 605.00,
					'remittanceInfo' => 'JDV-2026-06-0003',
					'apTransactionRef' => 'ap-txn-jdv-2026-06-0003',
				],
			],
		];
	}//end paymentRun()

	/**
	 * Parse the rendered XML namespace-agnostically.
	 *
	 * @param string $xml The rendered pain.001 document.
	 *
	 * @return \SimpleXMLElement
	 */
	private function load(string $xml): \SimpleXMLElement {
		$stripped = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $xml);
		$element = simplexml_load_string((string)$stripped);
		$this->assertNotFalse($element, 'Rendered pain.001 is not well-formed XML');

		return $element;
	}//end load()

	/**
	 * HAPPY: the document declares the canonical GrpHdr totals + one
	 * CdtTrfTxInf per line, each carrying the required children.
	 *
	 * @return void
	 */
	public function testCanonicalStructureAndTotals(): void {
		$generator = new SepaPain001Generator();
		$rendered = $generator->render($this->paymentRun());

		$this->assertSame('sepa-pain001', $rendered->format);
		$this->assertSame('PR-2026-001.pain001.xml', $rendered->fileName);
		$this->assertStringContainsString('pain.001.001.03', $rendered->content);

		$xml = $this->load($rendered->content);
		$init = $xml->CstmrCdtTrfInitn;

		// GrpHdr totals (REQ-SEPA-002).
		$this->assertSame('2', (string)$init->GrpHdr->NbOfTxs);
		$this->assertSame('1497.50', (string)$init->GrpHdr->CtrlSum);
		$this->assertSame('Consultancy Demo B.V.', (string)$init->GrpHdr->InitgPty->Nm);

		// PmtInf header.
		$this->assertSame('PR-2026-001', (string)$init->PmtInf->PmtInfId);
		$this->assertSame('TRF', (string)$init->PmtInf->PmtMtd);
		$this->assertSame('2026-07-01', (string)$init->PmtInf->ReqdExctnDt);
		$this->assertSame('NL00BANK9999999999', (string)$init->PmtInf->DbtrAcct->Id->IBAN);

		// One CdtTrfTxInf per line.
		$transactions = $init->PmtInf->CdtTrfTxInf;
		$this->assertCount(2, $transactions);

		$first = $transactions[0];
		$this->assertSame('PR-2026-001-1', (string)$first->PmtId->EndToEndId);
		$this->assertSame('892.50', (string)$first->Amt->InstdAmt);
		$this->assertSame('EUR', (string)$first->Amt->InstdAmt['Ccy']);
		$this->assertSame('Eneco Energie B.V.', (string)$first->Cdtr->Nm);
		$this->assertSame('NL00BANK0123456789', (string)$first->CdtrAcct->Id->IBAN);
		$this->assertSame('ENECO-2026-04-0001', (string)$first->RmtInf->Ustrd);

		$second = $transactions[1];
		$this->assertSame('PR-2026-001-2', (string)$second->PmtId->EndToEndId);
		$this->assertSame('605.00', (string)$second->Amt->InstdAmt);
	}//end testCanonicalStructureAndTotals()

	/**
	 * EDGE: a line without a BIC omits CdtrAgt (BIC is optional in pain.001),
	 * while a line with a BIC emits it.
	 *
	 * @return void
	 */
	public function testBicOptionalPerLine(): void {
		$generator = new SepaPain001Generator();
		$xml = $this->load($generator->render($this->paymentRun())->content);

		$transactions = $xml->CstmrCdtTrfInitn->PmtInf->CdtTrfTxInf;

		// Line 1 carries a BIC → CdtrAgt present.
		$this->assertTrue(isset($transactions[0]->CdtrAgt), 'Line with BIC should emit CdtrAgt');
		$this->assertSame('BANKNL2A', (string)$transactions[0]->CdtrAgt->FinInstnId->BIC);

		// Line 2 has no BIC → CdtrAgt absent.
		$this->assertFalse(isset($transactions[1]->CdtrAgt), 'Line without BIC should omit CdtrAgt');
	}//end testBicOptionalPerLine()

	/**
	 * ERROR/EDGE: with no totalAmount set, CtrlSum falls back to the sum of the
	 * line amounts (still 2 transactions).
	 *
	 * @return void
	 */
	public function testControlSumFallsBackToLineSum(): void {
		$run = $this->paymentRun();
		unset($run['totalAmount']);

		$generator = new SepaPain001Generator();
		$xml = $this->load($generator->render($run)->content);

		$this->assertSame('2', (string)$xml->CstmrCdtTrfInitn->GrpHdr->NbOfTxs);
		$this->assertSame('1497.50', (string)$xml->CstmrCdtTrfInitn->GrpHdr->CtrlSum);
	}//end testControlSumFallsBackToLineSum()
}//end class
