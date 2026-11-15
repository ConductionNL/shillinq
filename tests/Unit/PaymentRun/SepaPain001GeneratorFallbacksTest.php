<?php

/**
 * Unit tests for the fallbacks of SepaPain001Generator that the canonical and
 * golden runs do not reach: the administration name as initiator, today's
 * date for a run without an execution date, the message id and creation
 * time stamped at render time, and blank BICs and requested dates.
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
use SimpleXMLElement;

/**
 * Fallbacks of the pain.001 generator.
 *
 * @covers \OCA\Shillinq\PaymentRun\Generator\SepaPain001Generator
 * @uses   \OCA\Shillinq\PaymentRun\Generator\Pain001Document
 * @uses   \OCA\Shillinq\PaymentRun\RenderedFile
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SepaPain001GeneratorFallbacksTest extends TestCase {

	/**
	 * Render a run and parse it without namespaces.
	 *
	 * @param array<string, mixed> $run The payment run.
	 *
	 * @return SimpleXMLElement The CstmrCdtTrfInitn element.
	 */
	private function render(array $run): SimpleXMLElement {
		$xml = (new SepaPain001Generator())->render($run)->content;
		$element = simplexml_load_string((string)preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $xml));
		$this->assertNotFalse($element);

		return $element->CstmrCdtTrfInitn;
	}//end render()

	/**
	 * Without an initiator name the administration name initiates and is the debtor.
	 *
	 * @return void
	 */
	public function testTheAdministrationNameStandsInForTheInitiator(): void {
		$doc = $this->render(
			[
				'runNumber' => 'PR-1', 'executionDate' => '2026-03-01', 'administrationName' => '  Gemeente Voorbeeld ',
				'administrationId' => 'adm-1', 'paymentLines' => [['amount' => 1]],
			]
		);

		$this->assertSame('Gemeente Voorbeeld', (string)$doc->GrpHdr->InitgPty->Nm);
		$this->assertSame('Gemeente Voorbeeld', (string)$doc->PmtInf->Dbtr->Nm);
	}//end testTheAdministrationNameStandsInForTheInitiator()

	/**
	 * A run without an execution date is requested for today (UTC).
	 *
	 * @return void
	 */
	public function testARunWithoutAnExecutionDateIsRequestedForToday(): void {
		$before = gmdate('Y-m-d');
		$doc = $this->render(['runNumber' => 'PR-2', 'paymentLines' => [['amount' => 1], ['amount' => 2, 'requestedExecutionDate' => '   ']]]);
		$after = gmdate('Y-m-d');

		$this->assertCount(1, $doc->PmtInf, 'a blank requested date joins the run date');
		$this->assertContains((string)$doc->PmtInf->ReqdExctnDt, [$before, $after]);
		$this->assertSame('PR-2', (string)$doc->PmtInf->PmtInfId);
		$this->assertSame('2', (string)$doc->PmtInf->NbOfTxs);
	}//end testARunWithoutAnExecutionDateIsRequestedForToday()

	/**
	 * The message id is the run number and the render time; the creation time is that moment in UTC.
	 *
	 * @return void
	 */
	public function testTheMessageIdAndCreationTimeAreStampedAtRenderTime(): void {
		$before = time();
		$doc = $this->render(['runNumber' => 'PR-3', 'executionDate' => '2026-03-01', 'paymentLines' => []]);
		$after = time();

		$msgId = (string)$doc->GrpHdr->MsgId;
		$this->assertMatchesRegularExpression('/^PR-3-\d{14}$/', $msgId);
		$stamped = \DateTimeImmutable::createFromFormat('YmdHis', substr($msgId, 5), new \DateTimeZone('UTC'));
		$this->assertNotFalse($stamped);
		$this->assertGreaterThanOrEqual($before, $stamped->getTimestamp());
		$this->assertLessThanOrEqual($after, $stamped->getTimestamp());

		$created = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', (string)$doc->GrpHdr->CreDtTm, new \DateTimeZone('UTC'));
		$this->assertNotFalse($created);
		$this->assertGreaterThanOrEqual($before, $created->getTimestamp());
		$this->assertLessThanOrEqual($after, $created->getTimestamp());
		$this->assertSame('0', (string)$doc->GrpHdr->NbOfTxs);
		$this->assertCount(0, $doc->PmtInf, 'a run without lines has no payment block');
	}//end testTheMessageIdAndCreationTimeAreStampedAtRenderTime()

	/**
	 * A whitespace BIC is no BIC; a missing or text amount is read as a number.
	 *
	 * @return void
	 */
	public function testABlankBicIsOmittedAndAmountsAreReadAsNumbers(): void {
		$doc = $this->render(
			[
				'runNumber' => 'PR-4', 'executionDate' => '2026-03-01',
				'paymentLines' => [
					['payeeName' => 'A', 'creditorBic' => '   ', 'amount' => '10.5'],
					['payeeName' => 'B', 'creditorBic' => ' INGBNL2A ', 'amount' => null],
				],
			]
		);

		$tx = $doc->PmtInf->CdtTrfTxInf;
		$this->assertFalse(isset($tx[0]->CdtrAgt));
		$this->assertSame('INGBNL2A', (string)$tx[1]->CdtrAgt->FinInstnId->BIC);
		$this->assertSame(['10.50', '0.00'], [(string)$tx[0]->Amt->InstdAmt, (string)$tx[1]->Amt->InstdAmt]);
		$this->assertSame(['PR-4-1', 'PR-4-2'], [(string)$tx[0]->PmtId->EndToEndId, (string)$tx[1]->PmtId->EndToEndId]);
		$this->assertSame('10.50', (string)$doc->GrpHdr->CtrlSum);
	}//end testABlankBicIsOmittedAndAmountsAreReadAsNumbers()
}//end class
